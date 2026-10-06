<?php

declare(strict_types=1);

namespace IanFoxDev\SloKit\Tests;

use IanFoxDev\SloKit\Recorder;
use IanFoxDev\SloKit\SloFile;
use IanFoxDev\SloKit\SloMiddleware;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Prometheus\Storage\InMemory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class RecorderTest extends TestCase
{
    private Recorder $recorder;

    protected function setUp(): void
    {
        $this->recorder = new Recorder(SloFile::fromYaml(__DIR__ . '/fixtures/checkout.yaml'), new InMemory());
    }

    public function testTheHistogramHasABucketAtEveryThreshold(): void
    {
        $this->recorder->observe('checkout_confirm', 200, 0.29, 'post');
        $this->recorder->observe('checkout_confirm', 200, 0.31, 'post');
        $this->recorder->observe('checkout_confirm', 503, 1.2, 'post');

        $text = $this->recorder->render();

        self::assertStringContainsString('app_http_requests_total{route="checkout_confirm",method="POST",code="200"} 2', $text);
        self::assertStringContainsString('app_http_requests_total{route="checkout_confirm",method="POST",code="503"} 1', $text);
        // Exactly one of the three is within 300 ms; the default buckets (0.25, 0.5) could not say so.
        self::assertStringContainsString('app_http_request_duration_seconds_bucket{route="checkout_confirm",method="POST",le="0.3"} 1', $text);
        self::assertStringContainsString('app_http_request_duration_seconds_bucket{route="checkout_confirm",method="POST",le="0.8"} 2', $text);
        self::assertStringContainsString('app_http_request_duration_seconds_count{route="checkout_confirm",method="POST"} 3', $text);
    }

    public function testUrlsAreNotRoutes(): void
    {
        $this->recorder->observe(null, 404, 0.01);
        $this->recorder->observe('GET /orders/{id}', 200, 0.01);
        self::assertStringContainsString('route="unmatched"', $this->recorder->render());

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('looks like a URL');
        $this->recorder->observe('/orders/88123', 200, 0.01);
    }

    public function testMiddlewareRecordsResponsesAndExceptions(): void
    {
        $middleware = new SloMiddleware($this->recorder);
        $ok = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response(201);
            }
        };
        $failing = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                throw new \RuntimeException('database is down');
            }
        };

        $middleware->process((new ServerRequest('POST', '/checkout'))->withAttribute('_route', 'checkout_confirm'), $ok);
        try {
            $middleware->process((new ServerRequest('POST', '/checkout'))->withAttribute('_route', 'checkout_confirm'), $failing);
            self::fail('expected the exception');
        } catch (\RuntimeException $e) {
            self::assertSame('database is down', $e->getMessage());
        }
        $middleware->process(new ServerRequest('GET', '/nowhere'), $ok);

        $text = $this->recorder->render();
        self::assertStringContainsString('{route="checkout_confirm",method="POST",code="201"} 1', $text);
        self::assertStringContainsString('{route="checkout_confirm",method="POST",code="500"} 1', $text);
        self::assertStringContainsString('{route="unmatched",method="GET",code="201"} 1', $text);
    }

    public function testRouteFromAClosure(): void
    {
        $middleware = new SloMiddleware($this->recorder, static fn(ServerRequestInterface $r): string => 'search');
        $middleware->process(new ServerRequest('GET', '/search?q=x'), new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response(200);
            }
        });

        self::assertStringContainsString('route="search"', $this->recorder->render());
    }
}

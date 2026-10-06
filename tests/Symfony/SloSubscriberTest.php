<?php

declare(strict_types=1);

namespace IanFoxDev\SloKit\Tests\Symfony;

use IanFoxDev\SloKit\Recorder;
use IanFoxDev\SloKit\SloFile;
use IanFoxDev\SloKit\Symfony\SloSubscriber;
use PHPUnit\Framework\TestCase;
use Prometheus\Storage\InMemory;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;

final class SloSubscriberTest extends TestCase
{
    public function testRecordsTheRouteStatusAndTimeOnTerminate(): void
    {
        $recorder = new Recorder(SloFile::fromYaml(__DIR__ . '/../fixtures/checkout.yaml'), new InMemory());
        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber(new SloSubscriber($recorder));
        $kernel = new class implements HttpKernelInterface {
            public function handle(Request $request, int $type = self::MAIN_REQUEST, bool $catch = true): Response
            {
                return new Response();
            }
        };

        $checkout = Request::create('/checkout', 'POST', server: ['REQUEST_TIME_FLOAT' => microtime(true) - 0.42]);
        $checkout->attributes->set('_route', 'checkout_confirm');
        $dispatcher->dispatch(new TerminateEvent($kernel, $checkout, new Response('', 502)), KernelEvents::TERMINATE);
        $dispatcher->dispatch(new TerminateEvent($kernel, Request::create('/nope'), new Response('', 404)), KernelEvents::TERMINATE);

        $text = $recorder->render();
        self::assertStringContainsString('app_http_requests_total{route="checkout_confirm",method="POST",code="502"} 1', $text);
        self::assertStringContainsString('app_http_requests_total{route="unmatched",method="GET",code="404"} 1', $text);
        // 0.42 s: above the 0.3 s threshold, within 0.5 s.
        self::assertStringContainsString('route="checkout_confirm",method="POST",le="0.3"} 0', $text);
        self::assertStringContainsString('route="checkout_confirm",method="POST",le="0.5"} 1', $text);
    }
}

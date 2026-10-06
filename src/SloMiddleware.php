<?php

declare(strict_types=1);

namespace IanFoxDev\SloKit;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * PSR-15 middleware: times every request and records it. An exception is recorded as a 500
 * and thrown on.
 *
 * Requests are immutable in PSR-15: a router that sets a route attribute passes a new
 * request down, and middleware above it never sees the attribute. Put this one after your
 * router, or pass a closure that reads the route from where your router keeps it. A request
 * no route matched is recorded as "unmatched".
 */
final readonly class SloMiddleware implements MiddlewareInterface
{
    /** @var \Closure(ServerRequestInterface): ?string */
    private \Closure $route;

    /**
     * @param ?\Closure(ServerRequestInterface): ?string $route
     * @param list<string> $attributes request attributes to read the route from, in order
     */
    public function __construct(
        private Recorder $recorder,
        ?\Closure $route = null,
        array $attributes = ['_route', 'route_name', 'route'],
    ) {
        $this->route = $route ?? static function (ServerRequestInterface $request) use ($attributes): ?string {
            foreach ($attributes as $name) {
                $value = $request->getAttribute($name);
                if (\is_string($value) && $value !== '') {
                    return $value;
                }
            }

            return null;
        };
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $started = hrtime(true);
        try {
            $response = $handler->handle($request);
        } catch (\Throwable $e) {
            $this->recorder->observe(($this->route)($request), 500, (hrtime(true) - $started) / 1e9, $request->getMethod());
            throw $e;
        }
        $this->recorder->observe(($this->route)($request), $response->getStatusCode(), (hrtime(true) - $started) / 1e9, $request->getMethod());

        return $response;
    }
}

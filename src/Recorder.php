<?php

declare(strict_types=1);

namespace IanFoxDev\SloKit;

use Prometheus\CollectorRegistry;
use Prometheus\RenderTextFormat;
use Prometheus\Storage\Adapter;

/**
 * Records requests the way the SLO file measures them: a counter by route, method and
 * status code, and a duration histogram whose buckets include every latency threshold.
 * What counts as an error is decided by the SLO's query, not here, so two SLOs can define
 * errors differently.
 *
 *     $recorder->observe('checkout_confirm', 200, 0.214, 'POST');
 */
final class Recorder
{
    /** A route the application could not name: recorded under one label, never as a URL. */
    public const string UNMATCHED = 'unmatched';

    private readonly CollectorRegistry $registry;

    public function __construct(private readonly SloFile $slos, CollectorRegistry|Adapter $registry)
    {
        $this->registry = $registry instanceof Adapter ? new CollectorRegistry($registry, false) : $registry;
    }

    /**
     * @param ?string $route the route template or name, not the URL: "checkout_confirm",
     *                       "GET /orders/{id}". Null when no route matched.
     */
    public function observe(?string $route, int $status, float $seconds, string $method = 'GET'): void
    {
        $labels = [self::route($route), strtoupper($method), (string) $status];
        $names = $this->slos->metricNames();
        $this->registry
            ->getOrRegisterCounter($this->slos->prefix, substr($names['requests'], \strlen($this->slos->prefix) + 1), 'HTTP requests by route, method and status code.', ['route', 'method', 'code'])
            ->inc($labels);
        $this->registry
            ->getOrRegisterHistogram($this->slos->prefix, substr($names['duration'], \strlen($this->slos->prefix) + 1), 'HTTP request duration in seconds; the buckets include every SLO threshold.', ['route', 'method'], $this->slos->buckets())
            ->observe(max(0.0, $seconds), [$labels[0], $labels[1]]);
    }

    /**
     * The metrics in the Prometheus text format, for a /metrics endpoint.
     */
    public function render(): string
    {
        return (new RenderTextFormat())->render($this->registry->getMetricFamilySamples());
    }

    public function registry(): CollectorRegistry
    {
        return $this->registry;
    }

    private static function route(?string $route): string
    {
        if ($route === null || $route === '') {
            return self::UNMATCHED;
        }
        // A label must not carry a URL: a path with ids makes one series per id.
        if (preg_match('#/[0-9]{2,}(/|$)|/[0-9a-f]{8}-[0-9a-f]{4}-#i', $route) === 1 || \strlen($route) > 120) {
            throw new \InvalidArgumentException(\sprintf('"%s" looks like a URL, not a route template; pass the route name or "/orders/{id}".', $route));
        }

        return $route;
    }
}

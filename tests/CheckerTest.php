<?php

declare(strict_types=1);

namespace IanFoxDev\SloKit\Tests;

use IanFoxDev\SloKit\Checker;
use IanFoxDev\SloKit\Exposition;
use IanFoxDev\SloKit\Finding;
use IanFoxDev\SloKit\Recorder;
use IanFoxDev\SloKit\SloFile;
use PHPUnit\Framework\TestCase;
use Prometheus\CollectorRegistry;
use Prometheus\RenderTextFormat;
use Prometheus\Storage\InMemory;

final class CheckerTest extends TestCase
{
    private SloFile $slos;

    protected function setUp(): void
    {
        $this->slos = SloFile::fromYaml(__DIR__ . '/fixtures/checkout.yaml');
    }

    public function testMetricsFromTheRecorderPass(): void
    {
        $recorder = new Recorder($this->slos, new InMemory());
        $recorder->observe('checkout_confirm', 200, 0.2, 'POST');
        $recorder->observe('api_payment_capture', 200, 0.1, 'POST');
        $recorder->observe('search', 200, 0.5);

        self::assertSame([], $this->check($recorder->render()));
    }

    /**
     * The usual setup: the same metric names, promphp's default buckets (0.25, 0.5, ...).
     */
    public function testDefaultBucketsMissTheThreshold(): void
    {
        $registry = new CollectorRegistry(new InMemory(), false);
        $registry->getOrRegisterCounter('app', 'http_requests_total', 'h', ['route', 'method', 'code'])->inc(['checkout_confirm', 'POST', '200']);
        $registry->getOrRegisterCounter('app', 'http_requests_total', 'h', ['route', 'method', 'code'])->inc(['search', 'GET', '200']);
        $registry->getOrRegisterHistogram('app', 'http_request_duration_seconds', 'h', ['route', 'method'])->observe(0.2, ['checkout_confirm', 'POST']);

        $findings = $this->check((new RenderTextFormat())->render($registry->getMetricFamilySamples()));

        self::assertSame([
            'error checkout-latency: Threshold 0.3 s has no bucket (nearest: 0.25 and 0.5). The SLI can only count requests under 0.25 s or 0.5 s, not under 0.3 s: the dashboard is green or red for a threshold nobody chose. Add le="0.3".',
        ], array_filter($findings, static fn(string $f): bool => str_contains($f, 'checkout-latency')));
        self::assertNotEmpty(array_filter($findings, static fn(string $f): bool => str_starts_with($f, 'error search-latency: Threshold 0.8 s has no bucket (nearest: 0.75 and 1)')));
    }

    public function testAWrongPrefixPointsAtTheRealName(): void
    {
        self::assertSame(
            ['error No app_http_requests_total in /metrics; found myapp_http_requests_total (is metrics.prefix right?). Every SLO query reads it.'],
            $this->check("myapp_http_requests_total{route=\"a\",code=\"200\"} 1\n"),
        );
    }

    public function testUrlsAndTooManyRoutes(): void
    {
        $lines = [];
        for ($i = 100; $i < 220; $i++) {
            $lines[] = "app_http_requests_total{route=\"/orders/$i\",method=\"GET\",code=\"200\"} 1";
        }
        $findings = (new Checker(maxRoutes: 100))->check($this->slos, Exposition::parse(implode("\n", $lines)));
        $messages = array_map(static fn(Finding $f): string => $f->message, $findings);

        self::assertContains('120 distinct routes, more than 100: a label with ids or URLs makes one series per value.', $messages);
        self::assertContains('Route "/orders/100" looks like a URL; record the route template instead.', $messages);
    }

    public function testUnmatchedTrafficAndSlosWithoutTraffic(): void
    {
        $findings = $this->check(implode("\n", [
            'app_http_requests_total{route="unmatched",method="GET",code="404"} 30',
            'app_http_requests_total{route="checkout_confirm",method="POST",code="200"} 70',
        ]));

        self::assertContains('warning 30.0% of requests have no route ("unmatched"); SLOs that select routes do not see them.', $findings);
        self::assertNotEmpty(array_filter($findings, static fn(string $f): bool => str_starts_with($f, 'warning search-latency: No traffic on routes matching search')));
    }

    /**
     * @return list<string>
     */
    private function check(string $metrics): array
    {
        return array_map(
            static fn(Finding $f): string => ($f->error ? 'error ' : 'warning ') . ($f->slo === null ? '' : $f->slo . ': ') . $f->message,
            (new Checker())->check($this->slos, Exposition::parse($metrics)),
        );
    }
}

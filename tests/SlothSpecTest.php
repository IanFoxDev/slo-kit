<?php

declare(strict_types=1);

namespace IanFoxDev\SloKit\Tests;

use IanFoxDev\SloKit\SloFile;
use IanFoxDev\SloKit\SlothSpec;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class SlothSpecTest extends TestCase
{
    public function testQueriesReadTheRecorderMetrics(): void
    {
        $spec = SlothSpec::spec(SloFile::fromYaml(__DIR__ . '/fixtures/checkout.yaml'));

        self::assertSame('prometheus/v1', $spec['version']);
        [$availability, $latency] = $spec['slos'];
        self::assertSame([
            'error_query' => 'sum(rate(app_http_requests_total{route=~"checkout_.*|api_payment_.*",code=~"5..|429"}[{{.window}}]))',
            'total_query' => 'sum(rate(app_http_requests_total{route=~"checkout_.*|api_payment_.*"}[{{.window}}]))',
        ], $availability['sli']['events']);
        self::assertSame([
            'error_query' => '(sum(rate(app_http_request_duration_seconds_count{route=~"checkout_.*"}[{{.window}}])) - sum(rate(app_http_request_duration_seconds_bucket{route=~"checkout_.*",le="0.3"}[{{.window}}])))',
            'total_query' => 'sum(rate(app_http_request_duration_seconds_count{route=~"checkout_.*"}[{{.window}}]))',
        ], $latency['sli']['events']);
        self::assertSame('CheckoutLatencyBudgetBurn', $latency['alerting']['name']);
    }

    public function testRouteTemplatesBecomeEscapedRegexes(): void
    {
        self::assertSame('api\\\\.v1\\\\.orders_.*|GET /orders/\\\\{id\\\\}', SlothSpec::routeRegex(['api.v1.orders_*', 'GET /orders/{id}']));
    }

    public function testTheYamlRoundTrips(): void
    {
        $file = SloFile::fromYaml(__DIR__ . '/fixtures/checkout.yaml');

        self::assertSame(SlothSpec::spec($file), Yaml::parse(SlothSpec::yaml($file)));
    }

    /**
     * Sloth turns the spec into rules, and promtool accepts them. Needs Docker; runs when
     * SLOKIT_DOCKER is set (in CI and with make test-docker).
     */
    public function testSlothAndPromtoolAcceptTheSpec(): void
    {
        if (getenv('SLOKIT_DOCKER') !== '1') {
            self::markTestSkipped('SLOKIT_DOCKER not set');
        }
        $dir = sys_get_temp_dir() . '/slo-kit-sloth-' . getmypid();
        @mkdir($dir);
        chmod($dir, 0777);
        file_put_contents("$dir/spec.yaml", SlothSpec::yaml(SloFile::fromYaml(__DIR__ . '/fixtures/checkout.yaml')));

        exec(\sprintf('docker run --rm -v %s:/w ghcr.io/slok/sloth:v0.16.0 generate -i /w/spec.yaml -o /w/rules.yaml 2>&1', escapeshellarg($dir)), $out, $code);
        self::assertSame(0, $code, implode("\n", $out));
        $rules = (string) file_get_contents("$dir/rules.yaml");
        self::assertSame(6, substr_count($rules, '- alert: '), 'a page and a ticket alert per SLO');
        self::assertStringContainsString('14.4 * 0.000999', $rules);

        exec(\sprintf('docker run --rm --entrypoint promtool -v %s:/w prom/prometheus:v3.15.0 check rules /w/rules.yaml 2>&1', escapeshellarg($dir)), $out, $code);
        self::assertSame(0, $code, implode("\n", $out));
    }
}

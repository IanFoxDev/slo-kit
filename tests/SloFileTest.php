<?php

declare(strict_types=1);

namespace IanFoxDev\SloKit\Tests;

use IanFoxDev\SloKit\InvalidSloFile;
use IanFoxDev\SloKit\SloFile;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SloFileTest extends TestCase
{
    public function testReadsTheFile(): void
    {
        $file = SloFile::fromYaml(__DIR__ . '/fixtures/checkout.yaml');

        self::assertSame('checkout', $file->service);
        self::assertSame(['requests' => 'app_http_requests_total', 'duration' => 'app_http_request_duration_seconds'], $file->metricNames());
        self::assertCount(3, $file->slos);
        [$availability, $latency] = $file->slos;
        self::assertFalse($availability->isLatency());
        self::assertSame(0.001, $availability->errorBudget());
        self::assertSame(['5xx', 429], $availability->errorStatuses);
        self::assertSame(0.3, $latency->threshold);
        self::assertSame(0.05, $latency->errorBudget());
    }

    public function testEveryThresholdIsABucket(): void
    {
        $buckets = SloFile::fromYaml(__DIR__ . '/fixtures/checkout.yaml')->buckets();

        self::assertContains(0.3, $buckets);
        self::assertContains(0.8, $buckets);
        $sorted = $buckets;
        sort($sorted);
        self::assertSame($sorted, $buckets);
        self::assertSame(array_values(array_unique($buckets, \SORT_REGULAR)), $buckets);
    }

    public function testRoutesAndErrors(): void
    {
        $file = SloFile::fromYaml(__DIR__ . '/fixtures/checkout.yaml');
        [$availability] = $file->slos;

        self::assertSame(['checkout-availability', 'checkout-latency'], array_map(static fn($s): string => $s->name, $file->covering('checkout_confirm')));
        self::assertSame(['checkout-availability'], array_map(static fn($s): string => $s->name, $file->covering('api_payment_capture')));
        self::assertSame([], $file->covering('admin_dashboard'));
        self::assertTrue($availability->isError(500));
        self::assertTrue($availability->isError(503));
        self::assertTrue($availability->isError(429));
        self::assertFalse($availability->isError(404));
        self::assertFalse($availability->isError(200));
    }

    /**
     * @return iterable<string, array{array<mixed>, string}>
     */
    public static function broken(): iterable
    {
        $slo = ['name' => 'api', 'objective' => 99.9, 'routes' => ['api_*']];
        yield 'no service' => [['slos' => [$slo]], 'service: a name is needed'];
        yield 'no slos' => [['service' => 'a', 'slos' => []], 'at least one SLO'];
        yield 'objective 100' => [['service' => 'a', 'slos' => [['objective' => 100] + $slo]], 'api: objective is a percentage between 0 and 100'];
        yield 'objective as text' => [['service' => 'a', 'slos' => [['objective' => '99.9%'] + $slo]], 'objective is a percentage'];
        yield 'no routes' => [['service' => 'a', 'slos' => [['routes' => []] + $slo]], 'api: routes is a list'];
        yield 'bad error class' => [['service' => 'a', 'slos' => [['errors' => ['server']] + $slo]], '"server" in errors'];
        yield 'latency with errors' => [['service' => 'a', 'slos' => [['threshold_ms' => 300, 'errors' => [500]] + $slo]], 'a latency SLO counts slow requests'];
        yield 'duplicate name' => [['service' => 'a', 'slos' => [$slo, $slo]], '"api" appears twice'];
        yield 'bad prefix' => [['service' => 'a', 'metrics' => ['prefix' => 'my-app'], 'slos' => [$slo]], 'metrics.prefix "my-app"'];
        yield 'upper-case name' => [['service' => 'a', 'slos' => [['name' => 'API'] + $slo]], 'slos[0].name'];
    }

    /**
     * @param array<mixed> $data
     */
    #[DataProvider('broken')]
    public function testBrokenFilesSayWhatIsWrong(array $data, string $message): void
    {
        $this->expectException(InvalidSloFile::class);
        $this->expectExceptionMessage($message);
        SloFile::fromArray($data);
    }
}

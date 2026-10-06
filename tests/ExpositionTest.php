<?php

declare(strict_types=1);

namespace IanFoxDev\SloKit\Tests;

use IanFoxDev\SloKit\Exposition;
use PHPUnit\Framework\TestCase;

final class ExpositionTest extends TestCase
{
    public function testReadsTheTextFormat(): void
    {
        $samples = Exposition::parse(<<<'TXT'
            # HELP app_http_requests_total HTTP requests.
            # TYPE app_http_requests_total counter
            app_http_requests_total{route="checkout_confirm",method="POST",code="200"} 1027
            app_http_request_duration_seconds_bucket{route="a \"quoted\", name",le="+Inf"} 12 1700000000000
            go_goroutines 42
            up NaN
            TXT);

        self::assertCount(4, $samples);
        self::assertSame(['route' => 'checkout_confirm', 'method' => 'POST', 'code' => '200'], $samples[0]->labels);
        self::assertSame(1027.0, $samples[0]->value);
        self::assertSame('a "quoted", name', $samples[1]->labels['route']);
        self::assertSame('+Inf', $samples[1]->labels['le']);
        self::assertSame([], $samples[2]->labels);
        self::assertNan($samples[3]->value);
    }

    public function testGarbageIsAnError(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('Line 2');
        Exposition::parse("ok 1\n<html>Not Found</html>\n");
    }
}

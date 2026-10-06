<?php

declare(strict_types=1);

namespace IanFoxDev\SloKit\Tests;

use IanFoxDev\SloKit\Recorder;
use IanFoxDev\SloKit\SloFile;
use PHPUnit\Framework\TestCase;
use Prometheus\Storage\InMemory;

final class CliTest extends TestCase
{
    public function testCheckExitCodes(): void
    {
        $dir = sys_get_temp_dir() . '/slo-kit-cli-' . getmypid();
        @mkdir($dir);
        $recorder = new Recorder(SloFile::fromYaml(__DIR__ . '/fixtures/checkout.yaml'), new InMemory());
        $recorder->observe('checkout_confirm', 200, 0.2, 'POST');
        $recorder->observe('search', 200, 0.5);
        file_put_contents("$dir/good.txt", $recorder->render());
        file_put_contents("$dir/bad.txt", "app_http_requests_total{route=\"/orders/1234\",method=\"GET\",code=\"200\"} 1\n");

        [$code, $out] = $this->cli('check ' . __DIR__ . "/fixtures/checkout.yaml --metrics=$dir/good.txt");
        self::assertSame(0, $code, $out);
        self::assertStringEndsWith("3 SLOs checked against $dir/good.txt: 0 errors, 0 warnings\n", $out);

        [$code, $out] = $this->cli('check ' . __DIR__ . "/fixtures/checkout.yaml --metrics=$dir/bad.txt");
        self::assertSame(1, $code);
        self::assertStringContainsString("error   Route \"/orders/1234\" looks like a URL", $out);
        self::assertStringContainsString('error   checkout-latency: No app_http_request_duration_seconds histogram', $out);

        self::assertSame(2, $this->cli('check ' . __DIR__ . '/fixtures/checkout.yaml')[0]);
        self::assertSame(2, $this->cli('check /nonexistent.yaml --metrics=x')[0]);
        self::assertSame(2, $this->cli('lint ' . __DIR__ . '/fixtures/checkout.yaml')[0]);
    }

    /**
     * @return array{int, string}
     */
    private function cli(string $args): array
    {
        $process = proc_open(\PHP_BINARY . ' ' . __DIR__ . '/../bin/slo-kit ' . $args, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);

        return [proc_close($process), $out . $err];
    }
}

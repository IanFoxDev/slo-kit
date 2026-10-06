<?php

declare(strict_types=1);

namespace IanFoxDev\SloKit\Tests;

use IanFoxDev\SloKit\Recorder;
use IanFoxDev\SloKit\SloFile;
use PHPUnit\Framework\TestCase;
use Predis\Client;
use Prometheus\Storage\Predis;

/**
 * Two recorders that share Redis, as two PHP-FPM workers or two hosts do, add up in one
 * /metrics. Runs when SLOKIT_REDIS is set (make redis-up).
 */
final class RedisStorageTest extends TestCase
{
    public function testRecordersOnSharedRedisAddUp(): void
    {
        $address = getenv('SLOKIT_REDIS');
        if (!\is_string($address) || $address === '') {
            self::markTestSkipped('SLOKIT_REDIS not set');
        }
        [$host, $port] = explode(':', $address) + [1 => '6379'];
        $client = new Client(['host' => $host, 'port' => (int) $port]);
        $client->flushdb();
        $slos = SloFile::fromYaml(__DIR__ . '/fixtures/checkout.yaml');

        $worker1 = new Recorder($slos, Predis::fromExistingConnection($client));
        $worker2 = new Recorder($slos, Predis::fromExistingConnection(new Client(['host' => $host, 'port' => (int) $port])));
        $worker1->observe('checkout_confirm', 200, 0.12, 'POST');
        $worker2->observe('checkout_confirm', 200, 0.42, 'POST');

        $text = $worker1->render();
        self::assertStringContainsString('app_http_requests_total{route="checkout_confirm",method="POST",code="200"} 2', $text);
        self::assertStringContainsString('le="0.3"} 1', $text);
    }
}

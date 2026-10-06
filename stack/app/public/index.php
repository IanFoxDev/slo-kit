<?php

declare(strict_types=1);

// A tiny shop with two endpoints, measured to ../../slo.yaml. Metrics live in Redis, so
// every request of the built-in server adds to the same counters, as FPM workers would.
// GET /chaos?errors=0.2&slow=0.3 sets the share of failing and slow checkouts.

use IanFoxDev\SloKit\Recorder;
use IanFoxDev\SloKit\SloFile;
use Predis\Client;
use Prometheus\Storage\Predis;

require '/repo/vendor/autoload.php';

$redis = new Client(['host' => getenv('REDIS_HOST') ?: 'redis']);
$recorder = new Recorder(SloFile::fromYaml(__DIR__ . '/../../slo.yaml'), Predis::fromExistingConnection($redis));
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

if ($path === '/metrics') {
    header('Content-Type: text/plain; version=0.0.4');
    echo $recorder->render();
    return;
}
if ($path === '/chaos') {
    $redis->hset('chaos', 'errors', (string) (float) ($_GET['errors'] ?? 0));
    $redis->hset('chaos', 'slow', (string) (float) ($_GET['slow'] ?? 0));
    echo "ok\n";
    return;
}

$started = hrtime(true);
$route = match ($path) {
    '/checkout' => 'checkout',
    '/search' => 'search',
    default => null,
};
$status = 200;
if ($route === 'checkout') {
    $chaos = $redis->hgetall('chaos');
    usleep(mt_rand(0, 100) / 100 < (float) ($chaos['slow'] ?? 0) ? 450_000 : mt_rand(20_000, 150_000));
    $status = mt_rand(0, 1000) / 1000 < (float) ($chaos['errors'] ?? 0) ? 503 : 200;
} elseif ($route === 'search') {
    usleep(mt_rand(50_000, 300_000));
} else {
    $status = 404;
}
http_response_code($status);
echo $status === 200 ? "ok\n" : "error\n";
$recorder->observe($route, $status, (hrtime(true) - $started) / 1e9, $_SERVER['REQUEST_METHOD'] ?? 'GET');

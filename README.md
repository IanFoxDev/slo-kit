# slo-kit

[![php](https://github.com/IanFoxDev/slo-kit/actions/workflows/php.yml/badge.svg)](https://github.com/IanFoxDev/slo-kit/actions/workflows/php.yml)

SLOs for PHP services, measured right. Latency histograms get a bucket at every SLO
threshold, routes are templates, and what counts as an error is written down. A check reads
`/metrics` against the SLO file, and a generated [Sloth](https://github.com/slok/sloth) spec
gives the multi-window, multi-burn-rate alerts. A docker-compose stack runs it end to end.

> Status: v0.1. Until 1.0 a minor version may change the API or the SLO file; such changes
> are marked **BREAKING** in the [CHANGELOG](CHANGELOG.md). A change to the generated
> queries is listed there too, because it changes what your alerts measure.

Collecting metrics in PHP is solved (promphp, artprima/prometheus-metrics-bundle,
spatie/laravel-prometheus), and so is turning an SLO into burn-rate alerts (Sloth, Pyrra).
What breaks is in between:

- **No bucket at the threshold.** "95% of checkouts within 300 ms" is read from the bucket
  `le="0.3"`. promphp's default buckets have 0.25 and 0.5. On the same 1,027 requests, 78.0%
  are under 0.25 s and 97.7% under 0.5 s, so the SLO is failing or met depending on which
  bucket the query happens to use. Nobody chose either threshold.
- **URLs instead of routes.** `/orders/8812` as a label is one series per order.
- **Errors nobody defined.** A bot's 404 eats the budget; a 429 that turned users away does
  not.
- **Names that drift.** The alert reads `http_requests_total`, the app exports
  `app_http_requests_total`. Valid PromQL that never fires.

## Install

```bash
composer require ianfoxdev/slo-kit
```

PHP 8.3 or later. Metrics go through
[promphp/prometheus_client_php](https://github.com/PromPHP/prometheus_client_php): APCu,
Redis or in memory, as you already use it.

## The SLO file

```yaml
service: checkout
metrics:
  prefix: app                 # app_http_requests_total, app_http_request_duration_seconds
slos:
  - name: checkout-availability
    objective: 99.9           # percent
    routes: ['checkout_*', 'api_payment_*']
    errors: ['5xx', 429]      # the default
  - name: checkout-latency
    objective: 95
    threshold_ms: 300
    routes: ['checkout_*']
```

## Measuring

```php
use IanFoxDev\SloKit\{Recorder, SloFile, SloMiddleware};
use Prometheus\Storage\Redis;

$recorder = new Recorder(SloFile::fromYaml('slo.yaml'), new Redis(['host' => 'redis']));

// Any framework, Yaf included:
$recorder->observe('checkout_confirm', 200, 0.214, 'POST');

// PSR-15, after your router:
$middleware = new SloMiddleware($recorder);

// GET /metrics:
echo $recorder->render();
```

The duration histogram's buckets are a fixed grid plus every `threshold_ms` in the file. A
missing route is recorded as `unmatched`; a route that looks like a URL throws. Symfony:
register `IanFoxDev\SloKit\Symfony\SloSubscriber`, which records on `kernel.terminate`, after
the response is sent under PHP-FPM.

## Checking

```bash
vendor/bin/slo-kit check slo.yaml --metrics=http://checkout:8080/metrics
```

```
error   checkout-latency: Threshold 0.3 s has no bucket (nearest: 0.25 and 0.5). The SLI can
        only count requests under 0.25 s or 0.5 s, not under 0.3 s: the dashboard is green
        or red for a threshold nobody chose. Add le="0.3".
2 SLOs checked against http://checkout:8080/metrics: 1 error, 0 warnings
```

It also reports a missing counter (and the similar names it did find), missing `route` or
`code` labels, routes that look like URLs, more routes than `--max-routes`, a large share of
`unmatched` requests, and SLOs whose routes get no traffic. Exit code 1 on errors, so it
runs in CI against a test instance. It reads any Prometheus exporter, so it checks a Go
service with the same metric names too.

## Alerts

```bash
vendor/bin/slo-kit sloth slo.yaml > sloth/checkout.yaml
sloth generate -i sloth/checkout.yaml -o prometheus/rules/checkout.yaml
```

An availability SLO counts responses whose code is in its `errors`; a latency SLO counts
requests slower than its threshold (all requests minus the threshold's bucket). Sloth turns
that into recording rules and the alerts of the Google SRE Workbook: page at 14.4 times the
budget rate over 1 hour and 5 minutes or 6 times over 6 hours and 30 minutes; ticket at 3
times over 1 day and 2 hours or once over 3 days and 6 hours. Tested against Sloth v0.16.0
and promtool 3.15.0.

## The stack

```bash
composer install
docker compose -f stack/compose.yaml up -d
curl 'http://localhost:18080/chaos?errors=0.3'
```

An example service with the SLO file above, a load generator, Sloth, Prometheus,
Alertmanager and Grafana with an SLO dashboard (budget left, burn rate, SLI over 5 minutes
and 1 hour) at http://localhost:13000. With 30% of checkouts failing, the page alert fires in
about half a minute; `stack/e2e.sh` checks exactly that in CI.

More: [docs/usage.md](docs/usage.md). Why it is built this way:
[docs/adr/0001-the-sli-is-measured-in-the-app.md](docs/adr/0001-the-sli-is-measured-in-the-app.md).

## Not yet

Laravel, queue consumers and outgoing HTTP calls, OpenTelemetry metrics instead of
Prometheus, FrankenPHP worker mode storage, Pyrra. Open an issue if you need one of them
first.

## License

[MIT](LICENSE)

# Usage

## The SLO file

| Field | Meaning |
|---|---|
| `service` | the service name; Sloth labels rules and alerts with it |
| `metrics.prefix` | metric names are `<prefix>_http_requests_total` and `<prefix>_http_request_duration_seconds`; default `app` |
| `slos[].name` | lower-case letters, digits and `-`, unique |
| `slos[].objective` | a percentage between 0 and 100, such as `99.9` |
| `slos[].threshold_ms` | makes it a latency SLO: the share of requests within this time |
| `slos[].routes` | route templates; `*` matches any characters |
| `slos[].errors` | availability SLOs only: status codes and classes that count as errors; default `['5xx', 429]` |

`SloFile::fromYaml($path)` or `SloFile::fromArray($array)`. A wrong file throws
`InvalidSloFile` naming the SLO and the field.

## Recording

`new Recorder(SloFile $file, CollectorRegistry|Adapter $storage)`. Give it a promphp storage
(`Prometheus\Storage\APCng`, `Redis`, `Predis`, `InMemory`) or your own `CollectorRegistry`.

- `observe(?string $route, int $status, float $seconds, string $method = 'GET')`. Pass the
  route template or name, never the URL. `null` is recorded as `unmatched`. A route that
  looks like a URL (a numeric segment, a UUID, over 120 characters) throws.
- `render()` returns the text for `/metrics`.

Metrics:

- `<prefix>_http_requests_total{route, method, code}`, a counter. Errors are decided by the
  SLO's query on `code`, so two SLOs can define errors differently.
- `<prefix>_http_request_duration_seconds{route, method}`, a histogram with buckets 5 ms to
  10 s plus every threshold in the file. Changing a threshold changes the buckets; series
  before and after the change do not compare.

Storage under PHP-FPM: APCu keeps one copy per pool on one host; several pools or hosts need
Redis, or one scrape per pool. That is promphp's model.

## PSR-15

`new SloMiddleware(Recorder $recorder, ?Closure $route = null, array $attributes = ['_route',
'route_name', 'route'])`. Put it after your router: requests are immutable, and a router
passes a new request with the route attribute down, which middleware above it never sees.
To time the whole stack, put it first and pass a closure that reads the route from wherever
your router keeps it. An exception is recorded as a 500 and thrown on.

## Symfony

```yaml
services:
  IanFoxDev\SloKit\Recorder:
    arguments: ['@IanFoxDev\SloKit\SloFile', '@Prometheus\Storage\Adapter']
  IanFoxDev\SloKit\SloFile:
    factory: ['IanFoxDev\SloKit\SloFile', 'fromYaml']
    arguments: ['%kernel.project_dir%/slo.yaml']
  IanFoxDev\SloKit\Symfony\SloSubscriber:
    autoconfigure: true
```

It records main requests on `kernel.terminate` with the `_route` attribute and the time since
`REQUEST_TIME_FLOAT`.

## slo-kit check

`vendor/bin/slo-kit check SLO_FILE --metrics=URL|FILE [--max-routes=100]`

| Finding | Level |
|---|---|
| the request counter is missing (similar names are listed) | error |
| the counter has no `route` or `code` label | error |
| a latency SLO's routes have no duration histogram | error |
| a latency threshold has no bucket on the SLO's routes | error |
| more distinct routes than `--max-routes` | error |
| a route looks like a URL | error |
| more than 5% of requests are `unmatched` | warning |
| no traffic on an SLO's routes | warning |

Exit codes: 0 no errors, 1 errors, 2 usage or input error.

## slo-kit sloth

`vendor/bin/slo-kit sloth SLO_FILE` prints a Sloth `prometheus/v1` spec. Alerts are named
after the service and the SLO (`CheckoutLatencyBudgetBurn`) and labelled `severity: page` or
`severity: ticket`. Generate the rules with Sloth and load them into Prometheus; the stack in
`stack/` does that on start.

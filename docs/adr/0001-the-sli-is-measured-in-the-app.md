# 0001. The SLI is measured in the application, the alerts come from Sloth

Date: 2026-10-06. Status: accepted.

## Context

An SLO for an HTTP service usually says two things: "99.9% of requests succeed" and "95% of
requests finish within 300 ms". Prometheus computes both from what the application exports,
and the alerts fire on how fast the error budget burns. Collecting metrics in PHP is solved
(promphp, artprima/prometheus-metrics-bundle, spatie/laravel-prometheus), and so is turning
an SLO into burn-rate alerts (Sloth, Pyrra), following the Google SRE Workbook.

What goes wrong is in between, where neither side looks:

- **No bucket at the threshold.** A latency SLO of 300 ms is computed from the histogram
  bucket `le="0.3"`. With the default buckets (0.25, 0.5) there is none, so the SLI counts
  either the requests under 250 ms or those under 500 ms. The SLO dashboard is green or red
  for a threshold nobody chose.
- **URLs instead of routes.** `/orders/8812` as a label makes one series per order. The
  scrape slows down, then Prometheus runs out of memory.
- **Errors nobody defined.** A 404 from a bot counted as an error eats the budget; a
  timeout the client gave up on counted as a success hides an outage.
- **Names that drift.** The SLO's query says `http_requests_total`, the application exports
  `app_http_requests_total`. The alert is valid PromQL and never fires.

## Decision

- **The SLO file is the single source.** It names each SLO, its objective, its latency
  threshold if any, the routes it covers and what counts as an error.
- **The application measures to the SLO file.** The histogram buckets include every
  latency threshold in the file, so the SLI is exact at the threshold. Routes are recorded
  as templates. Errors follow the file: by default a 5xx response or an uncaught exception
  is an error, a 4xx is not, except 429.
- **`slo-kit check` compares /metrics with the SLO file** before anything alerts on them:
  the metrics exist, every threshold has a bucket, route cardinality is under a limit,
  names match. It exits non-zero, so it runs in CI against a test instance.
- **Sloth makes the rules.** `slo-kit sloth` writes a Sloth spec from the same file. Sloth
  turns it into recording rules and multi-window, multi-burn-rate alerts (1 hour and 5
  minutes at 14.4, 6 hours and 30 minutes at 6, for paging). We do not write another
  generator.
- **Metrics go through promphp.** Its storage (APCu, Redis, in memory) and exposition
  format are used as they are.

## Consequences

- A dependency on promphp/prometheus_client_php, psr/http-server-middleware and
  symfony/yaml.
- Buckets come from the SLO file, so changing a threshold changes the buckets: the old
  series and the new one do not compare across the change. The check says so.
- APCu keeps metrics per PHP-FPM pool on one host; several pools or hosts need Redis or a
  scrape per pool. This is promphp's model, documented, not solved here.
- Burn-rate alerting is only as good as the traffic: a service with a few requests an hour
  pages on one failure. The SRE Workbook's advice for low-traffic services applies.

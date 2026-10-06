# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[Semantic Versioning](https://semver.org/). Before 1.0, minor versions may break the
API; such changes are marked **BREAKING**.

## [Unreleased]

### Added

- The SLO file: availability and latency SLOs over route templates, with an explicit
  definition of an error.
- `Recorder` over promphp: a request counter by route, method and code, and a duration
  histogram whose buckets include every SLO threshold; `SloMiddleware` (PSR-15) and
  `Symfony\SloSubscriber`.
- `slo-kit check`: compares /metrics with the SLO file (missing metrics and labels, a
  threshold without a bucket, URL-like routes, route cardinality, unmatched traffic, SLOs
  without traffic).
- `slo-kit sloth`: a Sloth `prometheus/v1` spec from the SLO file, tested against Sloth
  v0.16.0 and promtool 3.15.0.
- `stack/`: docker-compose with an example service, Sloth, Prometheus, Alertmanager and a
  Grafana SLO dashboard; `stack/e2e.sh` waits for the page alert.

# Contributing

## Running locally

You need PHP 8.3 or later, Composer and Docker.

```bash
composer install
make redis-up        # Redis on 63791 for the shared-storage test
make check           # PHP-CS-Fixer, PHPStan at level max, PHPUnit
make test-docker     # also runs Sloth and promtool in Docker against the generated spec
stack/e2e.sh         # the whole stack: the page alert must fire
make redis-down
```

## Where things are

| Path | What |
|---|---|
| `src/SloFile.php`, `src/Slo.php` | The SLO file and its buckets |
| `src/Recorder.php`, `src/SloMiddleware.php`, `src/Symfony` | Recording |
| `src/Exposition.php`, `src/Checker.php` | `slo-kit check` |
| `src/SlothSpec.php` | `slo-kit sloth` |
| `stack/` | docker-compose: example service, Sloth, Prometheus, Alertmanager, Grafana |

## Changing the queries

A change to `SlothSpec` has to keep `tests/SlothSpecTest.php` green with `SLOKIT_DOCKER=1`:
the real Sloth must accept the spec and promtool the rules. `stack/e2e.sh` must still see
the page alert fire. A query that compiles and never fires is the failure this project
exists to prevent.

## Pull requests

- One logical change per pull request; Conventional Commits.
- `make check` passes. Add a line to `CHANGELOG.md`.

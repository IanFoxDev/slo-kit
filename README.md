# slo-kit

SLOs for PHP services, measured right. Latency histograms get a bucket at every SLO
threshold, routes are templates, and what counts as an error is written down. A check
reads `/metrics` against the SLO file, and a generated Sloth spec gives the burn-rate
alerts.

> Status: in development, nothing released yet.

Why the SLI is computed in the application and the alerts in Sloth:
[docs/adr/0001-the-sli-is-measured-in-the-app.md](docs/adr/0001-the-sli-is-measured-in-the-app.md).

## License

[MIT](LICENSE)

# Security

slo-kit records metrics through the promphp storage you give it, and `slo-kit check` reads
the /metrics URL or file you name. It sends nothing anywhere else.

If you find a vulnerability, for example SLO file content that makes the generated Sloth
spec run a different query than the file describes, do not open a public issue. Report it
privately through [GitHub](https://github.com/IanFoxDev/slo-kit/security/advisories/new), or
write to ianfoxdeveloper@gmail.com.

Protect `/metrics` yourself (a separate port or a token): route names and error rates are
information about your service.

## Supported versions

Fixes go into the latest release only. Until 1.0 that is the latest `0.x` tag.

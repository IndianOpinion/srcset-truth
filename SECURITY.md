# Security policy

## Reporting a vulnerability

Email **hello@indianopinion.org** with a description, the affected version,
and steps to reproduce. Please do not open a public issue for a security
problem.

We acknowledge reports within 3 working days and aim to ship a fix or a
mitigation within 30 days, sooner for anything actively exploited. We credit
reporters in the release notes unless asked not to.

## Supported versions

| Version | Supported |
|---|---|
| 0.1.x | Yes |

## Scope notes

- Page requests never decode or encode images; only uploads, the backfill
  and WP-CLI do, under pixel, byte and frame limits.
- File operations stay inside the uploads directory.
- The audit fetches only this site's own host and refuses redirects elsewhere.

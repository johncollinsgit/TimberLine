# Intuit Security Readiness

Last reviewed: 2026-07-11

This record covers the QuickBooks Online read/import integration used by Everbranch. It is not an App Store approval claim.

## Implemented Controls

- HTTPS is enforced in production and application debug mode is disabled.
- Dynamic web responses use `Cache-Control: no-store, no-cache`; explicitly public cacheable resources retain their declared public policy.
- Unsupported HTTP methods, including `TRACE`, are rejected by the production server.
- Laravel session cookies are Secure and HttpOnly in production.
- OAuth access tokens, refresh tokens, and QuickBooks realm IDs are encrypted at rest using Laravel encrypted casts and the application key stored outside source control.
- The searchable QuickBooks account identifier is a keyed fingerprint, not the realm ID.
- The OAuth callback exchanges the authorization code and returns a bodyless `302` redirect.
- QuickBooks records and connections are tenant-scoped. The integration is read/import only and does not use the Payments API or write transactions back to QuickBooks.
- QuickBooks tokens and imported data are not intentionally written to application logs.
- Public privacy and terms pages describe QuickBooks access, retention, disconnection, and deletion requests.

## Operational Obligations

- Keep OS, nginx, PHP, Laravel, Composer, npm, and dependency security patches current.
- Permit Intuit vulnerability scans within two weeks of a request or provide acceptable scan results from the prior year.
- Complete requested security affidavits within two weeks.
- Remediate critical, high, and medium findings within Intuit's required timeframe.
- Recheck TLS, response headers, cookies, unsupported HTTP methods, callback behavior, raw database encryption, tenant access controls, and logging before each production assessment submission.
- Record and investigate any suspected QuickBooks data exposure under the incident-response requirements in the Intuit Developer Terms.

## Submission Gate

Do not attest that all Intuit security policies have been reviewed and confirmed until the production deployment containing these controls passes tests and the live header, method, callback, and encryption checks are complete.

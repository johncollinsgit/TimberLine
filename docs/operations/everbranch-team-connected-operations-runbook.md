# Connected operations candidate runbook

## Rollout controls

`TEAM_OPERATIONS_ENABLED=false` requires premium Teams plus current tenant entitlement/allowlist. Additional independent controls default off: `TEAM_OPERATIONS_SEND_ENABLED`, `TEAM_OPERATIONS_ASANA_CREATE_ENABLED`, `TEAM_OPERATIONS_SHEET_IDS_ENABLED`, `TEAM_OPERATIONS_AI_ENABLED`, `TEAM_WEATHER_ENABLED`, `TEAM_WEATHER_COMMERCIAL`, and the Teams ClamAV scanner setting. Disabling operations hides the new surfaces and stops scheduled synchronization/reminders without deleting records, credentials or evidence.

Configure Google OAuth and verified scopes using `TEAM_GOOGLE_CLIENT_ID`, `TEAM_GOOGLE_CLIENT_SECRET`, and HTTPS callbacks. Sending and Sheets identifier writes require separate consent. Gmail watch configuration uses `TEAM_GOOGLE_PUBSUB_TOPIC`, `TEAM_GOOGLE_PUSH_SERVICE_ACCOUNT`, and `TEAM_GOOGLE_PUSH_AUDIENCE`; unsigned notifications are rejected. Drive notifications request a new source read, never apply cell changes from notification payloads. Scheduled reconciliation remains necessary. Renew watches and run the existing scheduler/queue workers. `TEAM_OPERATIONS_SYNC_PAGES` bounds pages per run (default 5, maximum 20).

Do not reuse Codex plugin credentials as application tokens. Tokens/settings/cursors are encrypted and tenant/user/connection scoped. Never log source notes, mailbox content, files, financial rows or tokens. Safe sync telemetry includes connection/provider IDs, status, page count, duration and failure class.

## Pilot order

1. Resolve current Events project access and inspect mappings privately. Verify Wholesale project-per-client organization and selected projects with the owning user.
2. Connect each runtime account; verify source selection before refresh. Test a second user with different access, private/revoked projects, refresh failures, source switching and incomplete pages.
3. Preview and back up Sheets mapping. Review copied/reordered/deleted IDs and shared allocations. Do not trust event totals while ambiguous rows are excluded. If identifier write outcome is unknown, reconcile against the retained backup; do not repeat the POST.
4. Verify scanner and quota behavior with safe/unsafe files. Files without a clean scan must not be downloaded as published documents. Review uncertain email attachment matches individually.
5. Publish an operational email template, preview against current permitted work and a verified recipient, then prepare a reviewed outbox draft. Preparation is not delivery. A changed record/recipient/connection requires fresh review. Provider acceptance is not confirmed delivery. Reconcile uncertain attempts using the existing action; do not construct a new attempt to force a resend.
6. Preview internal reminder matches and schedule. Enable a rule only after checking its audience and quiet hours. External sequences remain drafts until canonical campaign stop/suppression controls are integrated and accepted.
7. Validate financial completeness, Square attribution/refunds/offline delays, confirmed schedule, forecast sample sizes and weather horizons. AI is optional; check the existing tenant budget and uncertain-spend reservation behavior.
8. Complete browser, MySQL recovery, Linux and protected release checks before tenant-scoped pilot enablement. Never deploy directly to the live release directory.

## Local evidence

The isolated loopback fixture at port 8774 contains synthetic Events/Wholesale data and fake compose accounts, with outbound delivery disabled and HTTP fakes preventing external requests. It is separate from the previous port 8773 preview. Do not treat it as real source verification.

Automated checks: `php -d memory_limit=1G vendor/bin/pest --configuration=phpunit.team.xml tests/Feature/Team tests/Feature/Wholesale tests/Feature/Tenancy/ModuleCatalogConfigConsistencyTest.php tests/Feature/Billing/TenantAiUsageInvoiceTest.php tests/Feature/FieldService/MobileVoiceDispatchTest.php --compact`; `npm run test:team-work-local`; `npm run build`; migration safety lint against the working tree. The MySQL recovery manifest/test is present, but a passing real MySQL run has not been claimed.

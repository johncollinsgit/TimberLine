# Operator Alert SMS Runbook

Status: live on `main` as of 2026-07-25.

## Purpose

Operator SMS alerts are for real Everbranch site activity that needs an
operator's attention. They are not a test harness, fixture signal, demo
notification, or proof that a background job ran.

`App\Services\Operations\OperatorAlertService` is the single gate for these
texts. It records an `operator_alert_logs` row before sending, suppresses
non-real activity with a visible reason, and coalesces repeated identical
alerts so a loop cannot flood an operator's phone.

## Configuration

```dotenv
EVERBRANCH_OPERATOR_ALERT_SMS_ENABLED=true
EVERBRANCH_OPERATOR_ALERT_PHONE=+15551234567
EVERBRANCH_OPERATOR_ALERT_SMS_REPEAT_WINDOW_MINUTES=360
```

- `EVERBRANCH_OPERATOR_ALERT_PHONE` is required for live SMS delivery. There is
  no hardcoded fallback phone number.
- `EVERBRANCH_OPERATOR_ALERT_SMS_ENABLED` may be set to `false` to keep alert
  logs without texting. If the variable is omitted, texting is enabled only
  when `EVERBRANCH_OPERATOR_ALERT_PHONE` is explicitly configured.
- `EVERBRANCH_OPERATOR_ALERT_SMS_REPEAT_WINDOW_MINUTES` controls same-event,
  same-tenant, same-message coalescing. The default is six hours.

## Real-event contract

An operator alert should send only when all of these are true:

- the event came from real production customer/operator activity;
- the caller passes a stable `dedupe_key`;
- the alert log reservation succeeds before the SMS send;
- the destination phone is explicitly configured;
- SMS delivery is enabled; and
- no recent identical alert exists inside the repeat window.

Callers should pass context that lets the service decide whether the alert is
real:

```php
$alerts->notify('agreement.accepted', $message, [
    'dedupe_key' => 'agreement-accepted:'.$agreement->id.':'.$version->id,
    'tenant_id' => (int) $tenant->id,
    'tenant_name' => $tenant->name,
    'tenant_slug' => $tenant->slug,
    'target_type' => 'agreement',
    'target_id' => (int) $agreement->id,
    'agreement_type' => $agreement->agreement_type,
    'agreement_template_key' => $agreement->template_key,
    'agreement_title' => $agreement->title,
    'signer_email' => $signerEmail,
    'request_host' => $request->getHost(),
]);
```

The service can hydrate tenant/agreement/ticket context from known target rows,
but callers should still provide obvious context at the event site.

## Suppression rules

These cases are logged as `suppressed` and must not text:

- missing or disabled operator SMS configuration;
- duplicate `dedupe_key` reservation;
- cache dedupe hit;
- repeated same-event/same-tenant/same-message alert inside the repeat window;
- request host `localhost` or a `.test` domain;
- tenant account mode `demo`, `sandbox`, or `test`;
- known fixture tenants such as `tenant-a`, `tenant-b`, `needs-help`,
  `branch-preview-tenant`, and `front-yard-foods-expired`;
- tenant names/slugs containing obvious test, sandbox, demo, fixture, fake,
  dummy, sample, or example tokens;
- signer email domains such as `.test`, `example.com`, `example.net`,
  `example.org`, `example.test`, `test.com`, or `invalid`;
- request email domains with the same test-only patterns;
- agreement type `sandbox_validation`;
- agreement template `front_yard_foods_sandbox_validation`; and
- agreement titles containing `TEST MODE ONLY`.

Suppressed logs include `metadata.reason` and, for non-real events,
`metadata.reasons[]` so an operator can see why the text did not send.

## Current alert sources

- Agreement acceptance: `AgreementAcceptanceService`.
- Support-ticket creation and Bud escalations:
  `TenantMobileSupportService`.
- Bud activation requests: `TenantBudService`.
- Guided walkthrough and workspace-access requests:
  `PlatformAccessRequestController`.
- Public contact and meeting requests: `EvergroveServiceInquiryController`
  (Everbranch sources only).
- Custom Branch requests: `CustomModuleRequestController`.
- Known Branch access requests: `MarketingModuleStoreController`.
- Weekly operator snapshot: `operator:send-weekly-snapshot`.

New operator-text sources should use `OperatorAlertService` and add focused
coverage in `tests/Feature/Operations/OperatorAlertServiceTest.php`.

## Related support-alert routing

Modern Forestry's older mobile support-alert setting is separate from
`OperatorAlertService`, but it follows the same no-hardcoded-phone rule:

- default config comes only from `MODERN_FORESTRY_SUPPORT_ALERT_PHONE`;
- tenant-specific routing can be saved through
  `ModernForestryMobileSupportSettingsService`;
- if neither is configured, the mobile support path should not text; and
- example values in `.env.example` and tests must use placeholders, not real
  operator phone numbers.

## Incident check

If texts start arriving unexpectedly:

1. Temporarily set `EVERBRANCH_OPERATOR_ALERT_SMS_ENABLED=false` in production
   if the volume is unsafe. This should stop SMS while preserving logs.
2. Inspect recent `operator_alert_logs` rows for `event_key`, `dedupe_key`,
   `tenant_id`, `target_type`, `target_id`, `status`, and `metadata.reason`.
3. Confirm whether the event is a real tenant/operator action or fixture/demo
   activity that needs a new suppression signal.
4. Add or tighten the event context at the caller before changing the central
   service, when possible.
5. Add a regression test that proves the fake path logs `suppressed` and the
   real path still sends once.

Useful focused verification:

```bash
php artisan test tests/Feature/Operations/OperatorAlertServiceTest.php
php artisan test tests/Feature/Public/PlatformPlansAndAccessRequestsTest.php
php artisan test tests/Feature/Everbranch/CustomModuleRequestWorkflowTest.php
php artisan test tests/Feature/MarketingModuleStoreControllerTest.php
```

For production-release verification, run the normal GitHub `Deploy Production`
gate or the local CI-shaped suite described in `README_FOR_AGENTS.md`.

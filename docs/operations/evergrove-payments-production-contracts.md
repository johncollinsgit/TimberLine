# Evergrove Payments durable server contracts

Source milestone, 2026-10-22. This follows the [iPhone foundation](evergrove-payments-core.md). Its native app and reused Android register remain offline rehearsals. These APIs are not connected to that app. Nothing here establishes a live Alpha merchant, tax configuration, native payment SDK, signed install, deployment, or transaction acceptance.

## Implemented boundary

`PaymentJournal` separates orchestration from persistence. The MySQL implementation encrypts immutable binding/terms snapshots, reserves sale/request/refund identities, serializes tenant admission and original-payment value, and commits the once-only dispatch claim before an external call. Provider evidence is deduplicated across tenant/register boundaries for the same provider/merchant/environment. Recovery uses the original durable request and immutable merchant binding. Inspection never contacts a provider. Caller-owned transactions are refused before provider work. Fixed audit events record verified transitions; this is an operational journal, not an accounting ledger or settlement report.

The application container supplies MySQL persistence only on MySQL. Other databases receive an unavailable repository; the SQLite reference is installed explicitly by tests only. The global core stays closed. The mobile service uses one fresh request authorizer and an empty provider registry. No provider class, merchant account, secret, reader token, or online Stripe fallback is configured.

Server quotes select existing tenant/site-owned Website variants by ID, quantity and expected catalog revision. Prices, currency and line kind come from the server. Quotes own their sale UUID, actor, site, register, binding fingerprint, expiration, line terms and explicit tax evidence. Snapshots are encrypted; request replay cannot change them. A new attempt rechecks current catalog/tax/stock availability. An exact existing attempt retry checks historical quote identity and returns the original operation, even after quote expiry or repricing. A quote does not hold, commit or restock inventory.

The Website adapter uses its existing USD-cent contract. Physical goods map to merchandise. Fixed-price printing services require a separately reviewed server mapping; the default mapping is unavailable. Quote-only/variable services and postage have no instant-sale adapter. Missing tax throws `tax_policy_missing`; it is never silently zero. The only tax implementations exercising rates are clearly fictional test fixtures.

## Mobile API and login

Endpoints live beneath `/api/mobile/v1/workspaces/{tenant}/payments`:

| Method | Path | Contract |
| --- | --- | --- |
| GET | `/registers` | Assigned register summaries; collection unavailable |
| GET | `/registers/{register}/catalog` | Bounded catalog page or exact SKU lookup; ambiguous SKUs fail |
| POST | `/registers/{register}/quotes` | UUID request, strict item selections; no client prices/tax/provider |
| POST | `/registers/{register}/attempts` | UUID request and existing quote ID/version |
| GET | `/registers/{register}/attempts/{attempt}` | Pure durable status projection |
| POST | `/registers/{register}/attempts/{attempt}/reconcile` | Explicit original-provider reconciliation |
| POST | `/registers/{register}/attempts/{attempt}/refunds` | Original-payment value, UUID request and supported reason |

Every operation rechecks a persisted, unexpired, explicitly scoped personal token, active verified user, active tenant membership, allowed role and explicit register assignment. Wildcard-only tokens and cookie/transient sessions do not authorize this lane. GET requires `mobile:read` plus `payments:read`; POST requires `mobile:write` plus `payments:write`. Refunds additionally require a current manager/owner/admin role. Managed Website entitlement permits catalog access; it does not authorize payment collection. Servicing has its own gate and uses historical routing, so changing the current merchant does not redirect refunds. Responses contain safe projections and `no-store` caching directives.

`config/evergrove_payments.php` ships all read/collection/servicing/fixture flags false and its register directory empty. New collection is additionally hard-blocked outside explicit local/testing fixtures because the atomic Website inventory/payment integration is unfinished. Turning flags on cannot activate production collection. Test fixtures use a `fixture_` provider and test binding; application configuration registers no fixture provider.

`config/mobile_authorization.php` keeps the new PKCE client disabled. Code recognizes only the complete exact pair `evergrove-payments-ios` / `evergrovepayments://auth/callback`, independently of the retained Everbranch pair. Existing system-browser authentication, S256, state and one-time short-lived codes remain required. Payment exchange rechecks current email verification. New exchange grants the four explicit mobile/payment abilities; payment refresh preserves its original allowed subset and respects the disabled configuration. This milestone does not register a native callback, change an Apple account, create credentials, issue a real OAuth grant, or store a token on an iPhone.

## Migration and test acceptance

Two additive migrations create the journal and encrypted quote tables. Guarded DDL repairs missing named indexes/constraints while retaining durable evidence, rejects incompatible definitions or duplicate identities, and is registered in the migration recovery manifest. The committed dump/fingerprint is data-free. The baseline verification script accepts `DB_SOCKET` for isolated local testing while preserving its existing TCP default and disposable-name guard.

Run meaningful source checks with:

```sh
php tests/PaymentsCore/run.php
php tests/PaymentsCore/adversarial.php
php tests/PaymentsCore/security-boundaries.php
php vendor/bin/pest tests/Feature/PaymentsCore tests/Feature/Mobile/EverbranchMobilePlatformTest.php --compact
php scripts/ci/lint-migrations.php --base=<review-base> --working-tree
```

On a newly named disposable MySQL database only, opt in with `PAYMENTS_CORE_DISPOSABLE_MYSQL=1` and run `PaymentsCoreMySqlRecoveryTest`, `PaymentsCoreQuotesMySqlRecoveryTest` and `PaymentsCoreMySqlConcurrencyTest` in `tests/Integration`. Concurrency uses separate processes/connections and verifies committed claims, request/sale uniqueness, refundable-value races and original-provider effect uniqueness. Refresh/verify the schema baseline and rehearse the prior-release upgrade with the existing `scripts/ci` workflows. The mandatory release migration gate includes these checks; this source change does not run or authorize a release.

The [independent server security review](evergrove-payments-production-security-review.md) records adversarial HTTP, quote, PKCE and actual-MySQL integrity results. Feature/HTTP fixtures and provider effects in journal races remain simulated. Even a green exact-head GitHub run is source acceptance, not live payment acceptance. No desktop, browser UI, simulator UI or physical-phone control is needed for this server milestone.

## Unresolved owner/provider decisions

| Decision | Required concrete input |
| --- | --- |
| Real Alpha workspace/catalog | Confirm the actual merchant tenant and Website catalog/site; fictional tenant16 is not activation authority. Do not reuse Wren tenant13 or its Stripe account. |
| Printing lines | Identify reviewed fixed-price Website service variants for an explicit mapping, or require issued authoritative custom quotes for variable jobs. The latter adapter remains to be built. |
| Postage | Identify whether the line represents a counter service charge, an externally quoted pass-through amount, or carrier label purchase; confirm its price/fulfillment authority before implementing that route. |
| Tax | Name the responsible reviewed tax source/policy, applicable scope, line treatment and rounding. An owner/provider review must supply actual authority; no rate or exemption is inferred here. |
| Provider and inventory | Confirm one Alpha-supported iPhone provider/merchant configuration, then implement its native/server adapter, canonical evidence and atomic Website stock hold/commit/release. Online Stripe Connect alone is not Terminal acceptance. |
| iPhone distribution | Confirm supported physical device/iOS, owner-controlled Apple signing/distribution and any provider-required entitlement. Native callback/token storage and end-to-end API integration remain to be built and accepted. |

The customer enters passwords, identity/bank details and legal/financial acceptances through official secure flows. Use the existing [on-site and remote-completion checklist](evergrove-payments-iphone-launch.md) before a signed install or merchant acceptance. Deployment, actual onboarding, entitlement/account changes and real charge/refund acceptance each require their applicable authorization. An installed app alone does not make payments live.

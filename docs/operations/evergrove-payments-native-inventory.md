# Native API rehearsal and atomic shared inventory

This source milestone continues the disabled [server contracts](evergrove-payments-production-contracts.md). It uses simulated providers only. It creates no real merchant, financial order, PSP connection, Apple account configuration or live transaction. Production collection remains hard-denied even with fixture flags; application provider registries are empty and inventory authority defaults unavailable.

## Native contract

The native checkout uses typed API v1 catalog selections, server quotes, immutable request UUIDs, status, reconciliation and original-payment refund requests. Fixture transport provides durable response-loss and restart scenarios. The request builder accepts only the canonical HTTPS origin and scoped paths, redacts bearer credentials and refuses redirects. Actual network execution remains unavailable; no password screen, OAuth grant, native SDK or token configuration is added.

The existing Android register JavaScript/CSS and offline navigation remain canonical. An outside-WebKit native gesture may read only bounded fixture SKU/quantity selections; browser prices, tax, paid status, card data, provider IDs and transaction UUIDs have no authority. Native code resolves a fresh typed quote and requires a separate, clearly simulated native confirmation. No privileged script-message handler connects the browser to payments.

Request identity is persisted before an asynchronous mutation. A timeout or crash keeps the original request; pure status never reconciles, and an unknown result cannot create a replacement sale. Canonical success requires the server's matching scope, quote/sale/money and positive evidence revision. Simulator/phone UI acceptance is separate and remains unrun under the user's Mac UI restriction.

## Shared stock authority

`PaymentCore::startQuoted` requires a typed quoted journal. The MySQL journal reserves immutable payment intent and shared Website stock holds in its own transaction, then commits before calling the fixture provider. Inside that transaction it locks site, quote and sorted variants, validates current catalog terms and scoped unexpired tax evidence, and consults a pure local reservation authority. Authority can be revoked during a wait; no provider/tax HTTP belongs inside these transactions.

The additive ownership migration makes existing Website reservation order/expiry columns nullable and adds a Core operation owner. Exactly one owner is required; no Website order is fabricated. Core holds have no TTL. Legacy Website/POS other-order queries include NULL-order holds, and Marketplace allocation includes active Core holds with a current locking read. Existing Website stock is the shared counter; no private replacement inventory is introduced.

Canonical provider evidence and stock settlement commit together. Unknown/processing results retain holds. Definitive failure/cancellation releases them. Paid evidence commits every tracked line exactly once; all lines are validated before any stock write. Missing stock/site or revoked authority retains canonical provider identity in review with holds intact. Repeated canonical evidence can repair reviewed inventory without issuing a second charge. Global duplicate provider effects cannot consume stock twice. Refunds reserve original-payment value and never implicitly restock.

The Website movement references the numeric shared reservation ID and is labeled `payments_fixture_sale`. The implementation creates no financial Website order or accounting revenue entry. All fixtures are disposable and fictional; no Alpha catalog, postage or tax policy is inferred.

## Verification and gates

The new migration participates in named MySQL recovery, schema fingerprint, dump-restore and prior-release upgrade checks. Inventory/HTTP tests require `APP_ENV=testing`, a local database with a `ci`/`test`/`testing` name segment, and both `PAYMENTS_CORE_DISPOSABLE_MYSQL=1` and `PAYMENTS_INVENTORY_DISPOSABLE_MYSQL=1`. Each test lane uses its own database.

Required checks include real repeatable-read multi-process stock competition with Website checkout, intent/hold rollback, durable response loss, canonical identity/stock deduplication, multi-line review/repair, retained unknown holds, refund without restock, ownership partial-DDL recovery, native request/response validation, crash/replay and busy-state protection, and provenance of unchanged register assets. Exact-head receipts and independent findings determine acceptance; earlier green PRs do not validate a later integration.

Remaining launch gates: real Alpha tenant/catalog and service/postage mapping, reviewed current tax authority, one merchant-supported PSP/native SDK, supported physical iPhone/iOS, Apple distribution/entitlement, secure official customer onboarding and explicit live financial acceptance. Native networking and production inventory authority are not activated by this milestone. An installed fixture app does not make payments live.

Independent findings, resolved issues and test limits are recorded in [the native/inventory security review](evergrove-payments-native-inventory-security-review.md).

# Evergrove Payments core: foundation contract

This document records the PR #465 foundation checkpoint. The subsequent
[durable server contracts](evergrove-payments-production-contracts.md) add
MySQL persistence, server quotes and disabled mobile API/PKCE contracts. Neither
milestone supplies a payment SDK, live adapter, atomic inventory integration or
real merchant. The durable
SQLite reference journal **refuses live bindings**. It is only for disposable
local/test fixtures. This is implemented/tested source, not production payment
acceptance. It leaves `PosService`, `SimulatedReader`, the Alpha browser demo and
the production POS prohibition unchanged.

## Ownership and request boundary

`PaymentCore` defaults to `ClosedPaymentAccess` and an empty provider registry.
Every collection/recovery/refund must first pass an injected server authorizer;
dispatch rechecks it after the durable claim. No permissive implementation ships
outside the isolated test file. A future authenticated adapter must resolve the
actor/device's active tenant membership, canonical entitlement, explicit register
assignment and current merchant/provider/collection or original-payment servicing
permission. Landlord access and client tenant/provider/merchant assertions cannot
supply authority. The authorizer is a required integration, not an existing
production readiness check.

`MerchantBinding` freezes tenant, register, provider, merchant account, location
and test/live environment. A register identity cannot silently acquire a new
binding. New collection requires a separately reviewed register identity when
merchant routing changes. Recovery and refunds require the exact original
binding and provider, independently of permission to accept new sales. No fallback
to Wren/Watershed's tenant 13 Stripe account or another configured account exists.
Alpha's fixture identity in tests is fictional and grants no tenant 16 access.

`SaleSnapshot` is an internal, server-priced value. It must never be hydrated
from a client request without independent catalog/tax resolution. Its lines
explicitly distinguish `merchandise`, `printing_service` and `postage`; none
mutate stock or purchase postage. Tax is a reviewed line total, added once to
quantity × unit amount. The fixture tax is illustrative, not a tax conclusion.
The future API must resolve authoritative prices, tax, inventory holds, service
fulfillment and carrier semantics before calling this package. Shipping labels,
postage purchase, receipt delivery and inventory commit are unimplemented.

The native rehearsal contract uses `request_id`, `sale_id`, lowercase `currency`
and `lines` with `line_id`, `kind`, `quantity`, `unit_amount_minor`, and
`tax_amount_minor`. Client totals are a rehearsal display only. The shared
fictional fixture is `tests/PaymentsCore/fixtures/native-checkout.json` and totals
3730 USD minor units. No provider or merchant fields appear in that client DTO.
Core line fields are strictly whitelisted; it accepts no card/contact data.
Money is integer minor units, bounded at 100,000,000; quantities are 1–99.

## Durability, uncertainty and original-payment servicing

The journal serializes admission and claims through SQLite `BEGIN IMMEDIATE`.
Immutable request hashes bind original terms, one attempt owns a sale identity,
and any unresolved operation blocks another sale on that register. New requests
cannot replace an unknown attempt. Provider work occurs only after the durable
once-only dispatch claim commits. A lost response or process crash after claim
is never blindly retried. Explicit recovery searches the original durable request
identity, including when no provider ID was returned. No result means `unknown`,
not failed, cancelled or permission to retry.

Only a future trusted `PaymentProvider` may produce `ProviderEvidence`, after
authenticated canonical retrieval or exact verified-event normalization. The
journal is an internal trusted repository, not a public client evidence API.
Every evidence application matches original request, full binding fingerprint,
amount, currency, provider ID and original charge for refunds. Canonical provider
effects are unique for exact provider/merchant/environment and payment/refund
namespace across tenants, registers and locations. Reuse quarantines the second
operation for review. Fixed append-only audit events record verified transitions,
mismatch, reused reference and contradictory terminal evidence without raw
provider payloads, card data or secrets.

Adapters **must provide trustworthy monotonically ordered canonical revisions**.
Webhook delivery/arrival timestamps are not revisions. A provider without an
equivalent revision must serialize a fresh authenticated canonical retrieval
before returning evidence. `failed` and `cancelled` mean definitive terminal
provider outcomes; timeouts, missing events and uncertain cancellation must stay
`unknown`/`review`. This is essential before failed refunds can release reserved
value. Confirmed terminal states never regress due to later events. A newer
contradiction is retained as a fixed audit event for operator reconciliation,
while the original terminal state is preserved. No operator review UI ships.

Refunds inherit the verified original charge, binding and currency. Pending,
unknown, review and succeeded refunds reserve original-payment value; concurrent
refunds cannot exceed it. A UUID replay retains its original amount/reason.
Refunds neither restock nor imply payout/settlement. A separate servicing gate
must remain available for original payments when new collection is disabled.

## Verification and next gates

Run without `.env`, Laravel boot, Composer installation or any network call:

```bash
php tests/PaymentsCore/run.php
```

The harness creates mode-0700 random temporary fixture directories, cleans only
its own files, uses an independent durable fake-provider ledger and verifies
that dispatch claims are visible on a separate database connection before the
adapter runs. It includes changed-key conflicts, all binding dimensions,
response-loss/restart recovery, post-claim crash, mismatched evidence, provider
effect reuse, monotonic status, partial-refund reservations and three races with
eight actual processes each. Native/HTTP/provider/MySQL/physical acceptance is
not implied by these tests.

Before production integration: add the canonical authenticated mobile API and
server catalog/tax adapter; a production journal with additive reviewed MySQL
migrations, recovery/concurrency/baseline evidence and encrypted sensitive
provider material; exact merchant-specific provider implementation/SDK and
verified canonical evidence; inventory/fulfillment/receipt/refund operations;
independent device and provider test acceptance; and a separately authorized
protected release. The reference `installForTesting()` is not a production
migration or upgrade strategy. No production schema dump changes belong to this
milestone. Alpha owner/provider onboarding, entitlement/distribution, tax and
receipt policy approval and explicit financial-test authority remain external
gates. Never send passwords, bank/identity information or card data into chat.

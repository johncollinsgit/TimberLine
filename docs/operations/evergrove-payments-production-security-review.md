# Evergrove Payments production-contract independent acceptance

Scope: the isolated `task/production` branch based on `aec390f8`, not the preserved PR #465 source. This is source and background-test review only. The user restricted Mac computer use; no desktop, browser UI, app/simulator UI or connected phone control is part of this work.

## Hard acceptance boundaries

- Every payment HTTP operation uses current authenticated active/verified actor, active tenant membership, explicit register assignment and operation-specific roles/abilities. Global operator/admin status is not tenant membership. The request-scoped authorizer must re-read current database authority after mutex waits, not use a cached User/pivot or actor retained by a singleton.
- Existing Managed Website access permits reading that tenant/site catalog only; it does not authorize new payment collection. A saved online Stripe or Wren/Watershed account supplies no Terminal/payment authority. No POS simulation flag/provider may become a production fallback.
- New collection remains unavailable even if rollout, merchant and provider fixture flags are injected, because the real Website stock/hold authority is not integrated. Quote checks against current stock do not replace atomic hold/revalidation. Original-attempt inspection, reconciliation and manager refunds have separately controlled servicing gates.
- Only immutable server-issued quote ID/version and canonical request UUID may identify a collection. Client-supplied price, tax, semantic kind, tenant, site, merchant/provider or replacement sale identity is rejected. Normalize UUID case at the API edge. Replayed original attempt/refund terms must remain recoverable after the quote expires or catalog changes; replays must not create another operation.
- Missing tax authority is unavailable, never zero tax. Generic `service` and title/SKU/category text cannot establish printing-service semantics; unmapped postage and variable services remain unavailable. Durable quotes freeze exact authorized price/tax policy version/evidence, tenant/site/register/owner and expiry; no real rate or taxability is selected here.
- MySQL admission locks serialize missing request/sale/register checks before locking absent keys under REPEATABLE READ. Refund budget uses current locked original/refund rows; stale consistent reads cannot release reserved value. Canonical provider-effect uniqueness spans tenant/register/location within exact provider/account/environment+kind. Intent/claim commits precede any provider effect; caller-owned transactions are rejected at external-dispatch boundaries.
- New provider registry and tax policy default closed independently of test fixtures. No raw card data, tokens, credentials, arbitrary provider error bodies or sensitive routing evidence in plain public projections/logs. MySQL immutable routing snapshots follow encrypted storage conventions.
- Migration double-up, interrupted partial DDL, retained rows without indexes/FKs, prior-release upgrades and data-free schema baseline must pass the repository's supported MySQL recovery gate. Passing SQLite feature tests alone is insufficient for MySQL race/migration claims.
- The new PKCE app client/redirect pair remains disabled until identity ownership is verified. Both authorization and exchange use one exact-pair predicate, not independent client/redirect allowlists or a Cartesian product. Preserve legacy app behavior. No native password screen or new OAuth grant is introduced.

## Required adversarial evidence

| Boundary | Cases |
| --- | --- |
| Auth | no token, expired/revoked token, read-only/write-only abilities, inactive/unverified actor, global operator without tenant membership, revoked membership and role/assignment after wait |
| Tenant/register | foreign tenant, foreign register/site/catalog/quote/attempt, disabled register, guessed original provider/merchant binding |
| HTTP | no state-changing GET, GET status never recovers, fixed errors/public projection does not leak routing/provider data |
| Quote | client authority fields, excessive/duplicate quantities and identities, cross-site products, ambiguous SKU, retired variant, stale revision/price, unknown stock, unreviewed service/postage, missing/expired tax, explicit zero-tax evidence only |
| Attempt | old immutable quote replay after expiry, mixed quote/version/key, arbitrary client sale ID, unknown outcome blocks replacement, stale quote/new-collection returns requote, no stock integration cannot be flag-enabled |
| Refund | role and ability separation, uppercase UUID replay, original-account amount/reason freeze, pending/unknown reservations, overrefund, original provider unavailable never reroutes |
| MySQL | eight-worker replay and competing register/sale/refund contention, cross-tenant provider reference reuse, durable post-claim death/response loss, stale RR reads, no external dispatch inside retrying/outer transaction |
| Migration | double up, partial multi-table create, missing indexes/FKs with retained rows, recovery manifest, lint, prior upgrade and baseline |
| PKCE | disabled new tuple, enabled exact new tuple, both legacy/new cross-pairs rejected, case/query/fragment/suffix redirect mutations rejected, wrong verifier, consumed/expired code, no code/token creation on denial |

## Review result

No unresolved new security blocker was found in the bounded server-contract milestone after the corrections below. This is acceptance for continued code review and test-fixture use; **production collection remains unavailable**. Final exact-head commit/CI acceptance belongs to the coordinating owner. The independent tests below ran against the implementation working tree based on `aec390f8` before its combined commit.

Default dependency injection was reviewed directly: the global `PaymentCore` retains `ClosedPaymentAccess`; the mobile core receives a fresh request authorizer with an empty provider array; MySQL is the only application journal and unsupported drivers receive `UnavailablePaymentJournal`, never a SQLite/POS simulator fallback. Default tax and service mapping remain unavailable. All shipped feature/servicing/fixture flags are false and the register directory is empty. Production collect additionally fails the missing-stock-integration gate even when all fixture/collection flags and a test merchant binding are supplied. Original inspection/recovery/refund authorization is independently controlled and does not require continued catalog entitlement.

### Corrected findings and verified safeguards

1. **Recovery could perform a provider lookup inside a caller-owned transaction.** Independent domain testing reproduced this in the reference journal. Both journal implementations now reject external transaction boundaries on public reads/find-by-request before core recovery reaches the provider, while preserving their private owned transaction reads. Independent SQLite and raw-PDO MySQL tests both confirm zero provider lookups.
2. **The new journal migration broke the normal SQLite feature-test baseline.** MySQL-specific collation was initially applied unconditionally. It now selects case-sensitive MySQL `utf8mb4_bin` or SQLite `binary`; the independent HTTP/quote feature suite migrates and passes normally. Real MySQL checks remain mandatory separately.
3. **Encrypted terms were not fully cross-checked against scalar payment fields.** The MySQL reader now reconstructs the immutable payment and validates its scalar amount/currency/sale/kind/original identifiers and registered binding. Refund budget calculations decode each current locked reservation before summing. Independent MySQL corruption cases prove an altered scalar amount/currency/sale, binding fingerprint or pending refund amount cannot silently change authority or free refundable value.
4. **Refresh could broaden a restricted payment token.** Refresh now recognizes any explicit payment ability as that lane, checks its enabled/verified state and preserves only the original allowed subset. Explicit legacy personal-token refresh also preserves its mobile subset. Independent full/read-only/missing-mobile-write/legacy-read-only tests confirm no new ability is added. Exact PKCE client/redirect pairs and callback mutations were tested independently on both issuance and exchange.
5. **Exact-SKU ambiguity needed to be independent of pagination.** The final catalog contract explicitly rejects combining exact SKU with a cursor, uses a binary exact match on MySQL and detects multiple matches before selection. Independent cases prove both duplicate detection and rejection of the cursor combination; no client can use pagination to silently select a different duplicate SKU.

The MySQL admission/identity path was reviewed after the backend owner's concurrency fixes. It upserts immutable binding/operation identities before absent-key locking reads, retains a tenant admission mutex, and validates replay terms against unique request/sale keys. Canonical-effect uniqueness is enforced by a database unique key across exact provider/account/environment and effect kind. If a concurrent effect assignment loses with duplicate key 1062, only evidence application is retried in a fresh journal transaction; provider creation is never retried. Refunds sum current locked, integrity-checked reservations. Explicit index widths fit MySQL limits. Migration recovery rejects incompatible same-name indexes/foreign keys, including cascade semantics, and retains evidence instead of deleting or rewriting it.

### Independently executed evidence

| Check | Result | Artifact |
| --- | --- | --- |
| HTTP/auth/quote feature tests | **47 passed, 152 assertions** | `review/production-independent-final-feature.log` |
| Domain boundary harness | **7/7 passed** | `review/production-security-domain-results.json` |
| Actual MySQL 8.4.11 / REPEATABLE READ integrity harness | **7/7 passed** | `review/production-independent-mysql-results.json` |
| Existing standalone reference core after interface extraction | **16/16 passed**, including three eight-process races | `review/production-reference-core.log` |
| Owned test formatting and whitespace | scoped Pint and `git diff --check` | `review/production-independent-pint-final.log` |

The 47 feature cases cover real persisted PAT expiry/revocation and dedicated abilities; active/verified actors; membership/global-operator separation; explicit assignment even for administrators; member refund denial; safe projections and no mutation GET routes; hard collection denial under production flags; servicing without collection/catalog authority; missing tax; client authority-field rejection; immutable quote replay after repricing and expiry; encrypted/scalar quote corruption; unreviewed services/postage; unknown inventory/foreign sites; SKU ambiguity; refresh scope preservation; and exact PKCE pair/redirect constraints.

The independent MySQL harness performs **no schema mutations**, uses only newly created fictional tenant/payment rows and cleans up those rows. It verifies the actual server version/isolation, encrypted snapshots, immutable scalar/binding integrity, corrupted pending-refund budget rejection, cross-tenant canonical-effect quarantine and raw outer-transaction refusal before provider lookup. It refuses execution unless `APP_ENV=testing`, `PAYMENTS_CORE_DISPOSABLE_MYSQL=1`, MySQL is selected, host is localhost/127.0.0.1, and the database name contains a separate `ci`, `test` or `testing` segment. It deliberately bootstraps without loading an application `.env`; provide test settings explicitly, including the public PHPUnit fixture key. Socket access needed the normal local sandbox escalation; it was approved. An initial guard refused an existing empty `.env` before any database work; the harness was changed to skip environment-file loading explicitly. No action remained denied or uncertain.

Backend/quote owners separately report their MySQL migration, recovery and multi-process suites passing, including eight-worker races and retained partial DDL. Those owner-run results are complementary evidence, not relabeled as independent runs. The coordinator owns data-free schema baseline verification, prior-release upgrade and the final exact-head release checks.

### Deliverables and remaining gates

Own implementation additions are test files only:

- `tests/Feature/PaymentsCore/IndependentAuthorizationTest.php`
- `tests/Feature/PaymentsCore/IndependentMobileBoundaryTest.php`
- `tests/Feature/PaymentsCore/IndependentQuoteAuthorityTest.php`
- `tests/PaymentsCore/security-boundaries.php`
- `tests/PaymentsCore/security-mysql-integrity.php`

The native app remains an offline rehearsal and is not connected to these APIs. Real Alpha workspace/catalog mappings, reviewed printing/postage and tax policies, merchant/provider selection and onboarding, atomic Website stock hold/commit, native PSP/token integration, Apple callback/signing/entitlement approval, physical-device and provider acceptance remain outstanding. These are explicit live gates, not reasons to stop credential-free engineering. No compliance certification is claimed.

The Mac computer-use restriction blocked no server source/test work. No desktop, browser UI, app/simulator UI, physical phone, production merge/deploy, credentials, account changes, live provider requests or real financial transactions were used or performed by this review.

# Original Square purchases, shared customers and event results

This adapter resolves the tenant's canonical `MarketingProfile` through original
Square order/payment/customer relationships. It never matches a card/name or
copies a provider order. Conflicting claims remain in review. Source-only import
markers and consent remain unchanged. Reviewed Core duplicate merges preserve
source references, alias history and the existing rewards ledger; preview hashes
canonicalize object keys across native MySQL JSON storage and reject changed
values. List order remains significant. Source, ledger and merge admission use current locked
rows under the shared tenant account fence, including MySQL repeatable reads.
Core review operations use an inactive `preview` state, so an abandoned or
repeated review cannot strand a Pay wallet. Only that server-owned source/state
is excluded from the pending-merge hold check; existing provider merges still
block holds. Approval revalidates the preview and rejects held or spent rewards
under the original account/profile fence, with a reviewable conflict response.

## Explicit enrollment and repair

After the protected release, an active tenant owner/admin publishes purchase
terms through `TenantRewardsPolicyService`, then previews enrollment:

```sh
php artisan customer-sources:reconcile-square-purchases TENANT_ID --actor=USER_ID \
  --enroll --event=OCCURRENCE_UUID --campaign=CAMPAIGN_ID --location=SQUARE_LOCATION \
  --from=2026-11-20T00:00:00-05:00
```

Review the completed original imports, original merchant/environment, event
scope and terms, then repeat with `--apply`. Enrollment records the reviewed
occurrence scope, an audited campaign association and a frozen current policy
for the explicitly authorized event backfill. It does not backdate policy
history, modify sent-message snapshots or resend anything. Earlier history can
be linked using `--customer=ORIGINAL_CUSTOMER_ID` without earning. Other past
events do not earn merely because they share a location. Future eligible sales
use the published policy at purchase time. Reconcile in bounded `--limit`
batches with `--after` cursors and `--from`; default mode is preview.

Confirmed original imports enqueue purchase effects only for enrolled tenants.
The `square_order` source link stores frozen terms, award ceiling, source revision,
refund high-water mark and attribution evidence. The shared Candle Cash ledger
uses an original tenant/merchant/environment/order key, so retries and customer
merges cannot award twice. Native Pay operations retain their own shared ledger
authority; their Square mirrors are excluded. Tax, tips and service charges do
not earn. Full refunds reverse earning once; partial refunds need coherent
merchandise allocation. A spent refund balance requires review and cannot create
a negative wallet. Milestones and membership bonuses require complete prior
purchase and dated membership evidence; this original-source adapter does not
infer them from old tags or partial history.

Modern Forestry's owner-selected setup is 300 basis points (3%) on eligible
merchandise, a $10 purchase-earning wallet cap and partial redemption. Existing
balances above the cap are retained. Each original purchase freezes its allowed
award, including zero at the cap, so spending later cannot replay old earning.
Partial codes reserve available whole dollars, up to the existing channel/order
limits; remaining cents carry forward. The existing minimum online merchandise
subtotal, daily use limit, recurring-charge exclusion and original issued code
values remain enforced. No schema or provider scope change is needed.

## Customer and marketing surfaces

Core Customers shows original purchase lines/dates, rewards and paged sent text
history across merged aliases. Marketing history needs current customer and
messaging access; financial event access alone cannot expose private texts.
The existing campaign page shows observed sends/deliveries/failures, clicks,
responses, attributed buyers, net sales and purchase rewards. A seven-day latest
qualifying sent/delivered text receives primary modeled attribution. Sent-only
credit is explicitly delivery-unconfirmed; failed, canceled, future and expired
touches are excluded. Reconciliation supersedes withdrawn primary credit without
resending or reearning. These are observed correlations, not causal lift.

Teams event details expose customer/campaign results for current authorized
managers even when the original Square show has no native Pay event/register.
The same show buyer cohort can rank by show spending or recorded linked lifetime
spending. Background EventPerformance workers aggregate before pagination;
lifetime customers are counted once across selected shows. Existing reviewed
source versions, dependency witnesses, issuer checks, stale-read invalidation and
coverage notices remain mandatory. Assigned staff retain only their approved
summary scope. Owned MySQL customer read views resolve original Pay receipt pool
membership without cache writes, including cold or expired database caches.
Comparisons reuse the existing event/per-day reports; unknown or
incomplete coverage does not establish zero sales or campaign lift. Customer
history and later online/membership windows reuse CustomerEventValueService.

Acceptance: focused feature/replay/RBAC tests, actual Chromium and WebKit Teams
navigation, Modern Forestry guard, and independent-process MySQL races for
competing reconciliations and merge-versus-earning. Protected CI must pass before
normal Forge release; verify exact SHA at `/ready`, then repair Sydney first and
replay before bounded Charleston backfill. Record live balances and source
coverage separately from source-test success.

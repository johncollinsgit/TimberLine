# Stockist locator

Classification: shared Shopify capability with tenant/store configuration, included
under the existing Shopify module. No new plan or subscription charge. Management
uses the module catalog's Shopify access plus an active owner/admin membership;
public reads require the verified existing Shopify app proxy and a published
store snapshot. Non-Forestry Shopify tenants use the same implementation.

Reuse the unpublished StockistLocation and StockistLocatorSetting schema from the
September replacement branch. Import snapshots preserve provenance but do not
publish. The draft list is separate from a published immutable snapshot held in
StockistLocatorSetting.configuration. Edits and archive actions affect drafts;
publishing atomically replaces the snapshot and retains the previous publication
for rollback. Optimistic revisions prevent stale edits/publications. Existing
LandlordOperatorActionAuditService records scoped before/after changes.

The public locator is served as HTML through the signed Shopify proxy. It uses
server-rendered retailer/directions fallback plus a responsive synchronized map,
text/address/ZIP search, optional location-distance search and keyboard controls.
Google Maps uses the merchant's existing restricted browser key; the key is
stored encrypted, never returned in staff JSON or committed. No new Google account
or access key is created. Ordinary Maps provider usage remains with the merchant.
There is no automatic geolocation prompt. Provider failure retains the retailer
list and directions. Shopper coordinates are not sent to Everbranch or stored.

The live Shopify page URL and menu target are retained. A page-body candidate
replaces only the Omnium mount with a same-store proxy iframe. Save the original
page body/template and verify current fingerprints before changing it. Keep
Omnium installed and unchanged during observation. Restore the saved page body
immediately if customer smoke fails. This isolated locator release does not
activate any other replacement, commerce, shipping, rewards or billing feature.

Verification covers imports and replay, duplicate validation, fresh-source
reconciliation, draft/published separation, rollback, stale edits, tenant/store
isolation, owner/admin permissions, unsafe HTML, map failure, search, mobile,
keyboard, directions, current menu discovery and a post-cutover crawl. Production
code follows the protected CI/Forge release; imports are explicit operational
commands after migration. No source exports enter Git.

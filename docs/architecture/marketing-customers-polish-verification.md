# Marketing Customers Polish Verification (2026-03-11)

## Runtime verification method
- Automated feature tests on `MarketingCustomersManagementExperienceTest`.
- Code-path inspection for routes, filters, and source-badge derivation.
- Manual browser verification was requested but could not be completed in this CLI-only environment.

## Browser checklist status
1. Open Customers page
- Verified by feature tests (`GET /marketing/customers` returns 200 and renders canonical rows).

2. Confirm real customers render
- Verified through canonical `marketing_profiles` fixtures in tests (Shopify-only, Square-only, Growave-enriched, manual).

3. Search by name/email/phone
- Verified by tests for external-id search and phone-fragment search.

4. Open Shopify-only / Square-only / Growave-enriched customer
- Verified by index/detail tests and source-filter tests.

5. Edit canonical fields and save
- Verified by PATCH tests for `first_name`, `last_name`, `email`, `phone`, `notes`.

6. Confirm Growave enrichment remains read-only
- Verified by update test that attempts to mutate `points_balance`, `vip_tier`, and `referral_link`; external snapshot remains unchanged.

## Data-path notes
- Index and detail are powered by canonical `marketing_profiles`.
- Source linkage comes from `marketing_profile_links`.
- Growave enrichment comes from `customer_external_profiles` (`integration = growave`) and is additive.
- Source badges are derived from canonical channels/links plus Growave enrichment presence.

## Local env limitation
A direct operational count query against the local app sqlite file failed due a malformed database image (`database/database.sqlite`).
Automated tests still pass because they run against isolated test databases.

# Modern Forestry Birthday Club consolidation

Birthday Club (`/pages/birthday-club`) is the only published birthday page after cutover. The two retained page records become hidden and redirect to it. Source assets live in `resources/shopify/birthday-club`; deploy them only after the compatible backend is active. The old Lottie assets remain self-hosted and unchanged.

## Policy and preservation

The tenant setting `birthday_club_config` defaults off. Modern Forestry retains its $10 annual coupon and existing 30-day window; signup bonuses stop prospectively. Existing coupons, credited balances, approved commitments, dates and history remain unchanged. Expired, cancelled and redeemed records never become usable through consolidation. Unknown ownership requires review; email alone does not merge customers. The existing unique verified retail catchup recovery remains bounded to one provider customer, one imported donor and matching known birthday dates.

`birthday_cycle_coverage` is additive durable evidence, unique per tenant/canonical customer/year. All annual issuance enters the same real database lock and checks existing rewards across types and retained aliases. Imported annual awards count; signup task credits do not. Code rollback retains coverage. No data migration creates rewards or sends messages.

## Release and cutover

1. Keep private before snapshots of the three pages, theme assets, redirects, discounts, birthday profiles/issuances/audits, signup credits, tenant settings and email events/deliveries. Record hashes and aggregate effective states. Never commit customer data or production credentials.
2. Pass focused PHP contracts, real disposable MySQL recovery/concurrency, Chromium/WebKit desktop and phone scenarios, migration lint and normal CI. Generate the data-free schema baseline via the existing protected baseline workflow, review it and commit it. Release through normal GitHub/Forge gates; verify `/ready` and the active commit.
3. Run `php artisan birthday-club:reconcile --tenant-id=1` and review ownership exceptions. The command is read-only by default. Run `php artisan birthday-club:configure --tenant-id=1` to preview the exact tenant policy. Only after acceptance use `--apply` on configure and `--apply-coverage` on reconcile. Neither sends email nor changes an existing gift. Capture outputs privately.
4. Verify the candidate theme, then upload the four Birthday Club source files to the current live theme. Assign the existing Birthday Club page template `forestry-birthday-club`; keep its handle and page id. Update birthday-only FAQ/link copy. Verify guest login return and rendered loading/recovery without impersonating a real customer or sending a test campaign.
5. Hide the two old page records and immediately upsert `/pages/birthday-gift` and `/pages/birthday-celebration` redirects to Birthday Club. Keep the pre-existing malformed legacy page redirect. Test bare and tracked URLs, including login returns, for loops or 404s. Only one birthday page is published/searchable. Do not redirect or repoint the working app proxy.
6. Reconcile before/after immutable entitlement fields and account for ordinary customer redemptions/new daily issuances separately. Monitor read-only issuance, duplicate primary sends, activation failures, redemptions and redirects for seven days. Notify only on meaningful regressions or required action; stop at the end of the observation window.

## Compatible interfaces

The birthday status/capture/claim routes and scalar issuance fields remain. Status responses add owned `issuances`; payloads add effective expiry and regular/belated presentation. Claim accepts optional `issuance_id` but resolves it only inside the verified customer ownership set. Previous-year usable coupons are included. Theme actions require unexpired, unused, Shopify-confirmed codes and use the server's `apply_path`.

Primary and belated emails share durable annual send reservation; followup remains separately once per cycle. Historical successful/unknown events prevent resend. A failed attempt requires deliberate retry. Future rendered HTML/plaintext links use Birthday Club; delivered content and event history stay intact.

## Recovery

If the page or redirect acceptance fails, restore the original page/template visibility and exact saved theme assets. Remove only the two new redirects as needed. Keep the signup retirement, coverage, balances, codes and historical evidence. Never clear broad caches, reset observations, extend expired gifts, rerun catchup or issue replacements as rollback.

# Modern Forestry 2026 birthday coupon catchup

The March 13–October 2, 2026 missed birthday cohort contains 1,658 profiles;
1,475 are currently consented and unrewarded. These contain 11 duplicate
recipient addresses and one profile without an email, leaving at most 1,463
distinct deliverable addresses. The command chooses one profile per address,
preferring a retail-linked profile, and skips any address already rewarded.
Recount before execution because consent and reward state can change. The
nominal maximum coupon face value at this count is $14,630, redeemable only
if claimed and used. The last recorded successful historical $10 birthday email
was an imported event dated March 12, 2026; it is not independent provider proof.

Owner review is required before any `--execute` run. Use the HTML preview at
`output/birthday-coupon-catchup-preview.html` and the plaintext fallback at
`output/birthday-coupon-catchup-preview.txt`. The belated message apologizes
for a system hiccup and thanks customers for supporting a small business.

Run from the production release only after the release gate has activated the
change and `/ready` reports its commit:

```sh
php artisan marketing:backfill-birthday-coupons --tenant-id=1 --from=2026-03-13 --through=2026-10-02 --limit=2000
```

The command is read-only unless `--execute` is present. Review the consented
outstanding count, existing reward exclusions, and suppressed nonconsented count.
Process in batches by adding `--execute --limit=100`. Repeat until outstanding
is zero, then review `email_sent` and `email_failed` per batch and the birthday
message/delivery records. Each new reward gets a 30-day window from issuance,
ending at the close of day 30. Existing annual rewards are not duplicated.

The default rerun skips any catchup email event, including a failed attempt, so
one failed address cannot block later batches. Review failures, then use
`--retry-failed --execute` deliberately; successful events remain excluded.
The dispatcher rechecks consent and provider readiness at send time. The
customer claims through `/pages/birthday-gift`, which syncs the Shopify code,
plays the prebuilt balloons and confetti animation, and links to candle bundles.
Regular future birthday messages use `/pages/birthday-celebration` with the
same flow and no belated wording. A saved future birthday shows its next
planned email date; an unset birthday can be entered on either page.
The theme's animation is a prebuilt balloons and confetti Lottie asset by
MD Abdur Rahim from LottieFiles (`celebration-balloon-confetti-animation-fQ35dRqK68`)
under the Lottie Simple License. The theme self-hosts that asset and its
`lottie-player` runtime; the runtime license is retained in the theme source.

The coupon allows shipping discounts and disallows product and order discounts.
Shopify requires reciprocal combination settings. The active retail free
shipping offers `Retailover75` and `FREEOVER75` allow product discounts;
individually named free shipping codes may differ. Candle Cash is a separate
product discount and cannot stack with the birthday coupon.

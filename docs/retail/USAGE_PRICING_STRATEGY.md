# Owner discussion: selectable services and shared usage credit

Status: proposal for future customers, not an approved rate card or a change to
existing customer contracts. The owner requested this discussion on October 18.
Do not enable new recurring services or rewrite the issued Avery agreement/invoice
until the selected services, prices, start date and customer approval are recorded.

## Recommended commercial structure

- Keep Standard at $499 one-time setup and $299/month for confirmed platform scope.
- Offer a single dollar-denominated prepaid Everbranch service balance, initially
  using the existing $25/$50/$100 credit choices. Funds pay only for Everbranch's
  approved consumable services; they are not transferable money or a payment method
  for Avery's knives, shipping vendors or other third-party purchases.
- Separate four usage meters: email messages, SMS/MMS billing units, phone minutes,
  and AI usage. Show the actual billing unit and customer rate. A shared balance
  does not mean all services cost the same or have the same unit.
- Separate fixed connection/number/managed-service fees from usage. Avoid charging
  a monthly sending-access fee without a clear ongoing feature/service benefit.
  Any future replacement of the current optional SMS/email subscriptions applies
  only to a newly approved offer version; preserve existing agreements/allowances.
- Customer-facing voice and chat remain quote-required. The existing proposed
  internal AI request tiers are not an approved price for shopper chat or phone AI.
- Default automatic reload off, with voluntary threshold/reload amount and a monthly
  spending ceiling. Give balance alerts and pause optional paid activity when the
  available balance or approved cap is exhausted. Show pending reserved cost and
  reconcile it when the provider confirms actual usage.
- Unused purchased credit should carry forward under the agreed refund/cancellation
  terms. Do not silently expire it or reset it as if it were a monthly allowance.

## Selectable services and Stripe

At the end of Present mode, use initially unchecked Email, Text, Phone/AI voice,
Customer-facing AI and usage-preload options. Show one-time total, recurring total,
usage balance purchase, usage unit/rate and quote-required items separately.
Checking a box only changes a quote preview. The explicit reviewed confirmation
creates the revised commercial documents; a subsequent payment/acceptance action
provides authorization. No unknown-rate service becomes a $0 included service.

The issued $798 Avery invoice is already open/finalized. Its two one-time lines
must not be patched as if it were a draft. Use Stripe's supported revision or
replacement workflow, retaining the old document link/audit and avoiding two payable
versions. A selected recurring service needs a real subscription item or scheduled
subscription arrangement, not merely a label on the setup invoice. Start renewal
after the service month already purchased; do not collect the first month twice.

Fund service credit only on verified paid-invoice/payment events, replay-safe and
scoped to the right tenant/customer. Reuse the existing
`TenantMessagingUsageService` credit ledger, reservation/settlement and pricing
snapshots where applicable. Add phone/AI meters and reconcile provider cost data;
do not build a second parallel messaging wallet.

Stripe Billing credit grants apply to subscription metered prices, not the
one-time setup or licensed base subscription. Stripe applies grants at invoice
finalization; Everbranch still needs its own immediate reservations, affordability
checks and spending caps before provider requests. Keep fixed-fee subscriptions
and prepaid usage accounting distinct.

## Honest price comparison, checked October 18

- Shopify Basic is $39/month on monthly billing. It is a mature ecommerce product;
  the Everbranch proposition needs value beyond reproducing a store.
- Square Free/Plus/Premium are $0/$49/$149 per location per month. Verify the chosen
  features, processing and other add-on charges when comparing an actual scope.
- HighLevel starts at $97/month and has separate communications/AI charges. Its
  agency $297 tier is not a one-business managed-service equivalent.
- MRPeasy Starter is $49/user/month for a mature production/inventory system. Do
  not imply a fictional tablet production board equals a full manufacturing ERP.
- $499 is plausible for bounded onboarding/configuration; custom implementation
  needs separate scope. The $299 offer's existing weekly one-hour strategy promise
  has material labor cost. Four hours at the existing $50/hour work rate represent
  $200 in time value before platform/support costs; that is not a measured expense
  or a margin calculation.

The current buyer references are $0.005 per additional email and $0.05 per SMS
segment, plus separate optional subscriptions/allowances. Twilio's published US
SMS transport starts at $0.0083/segment before carrier/registration fees. SendGrid
Essentials starts at $19.95/month; that is provider delivery infrastructure, not a
customer marketing application. Retell advertises roughly $0.07–$0.31/minute for
voice configurations; actual model/voice/telephony choices must be measured before
a retail rate is committed. These are comparisons, not new approved Everbranch fees.

## Dashboard customers should see

Available and reserved credit; purchases/adjustments; email, SMS segments, MMS,
voice minutes and AI units; customer unit rates and charges; date/service/campaign
filters; permitted attribution to outcomes such as replies, orders or service work;
balance forecasts clearly labeled estimates; caps and auto-reload controls; and
exportable itemized usage tied to invoices. Provider wholesale cost and gross-margin
analytics belong in the owner view. Keep delivered, accepted, failed and billed
states distinct; do not promise perfect attribution or charge a second time for
usage already covered by an allowance.

## Primary sources

- https://www.shopify.com/pricing
- https://squareup.com/us/en/pricing
- https://www.gohighlevel.com/pricing
- https://help.gohighlevel.com/support/solutions/articles/155000001156-highlevel-pricing-guide
- https://www.mrpeasy.com/pricing/
- https://www.twilio.com/en-us/sms/pricing/us
- https://www.twilio.com/en-us/products/email-api/pricing
- https://www.retellai.com/pricing
- https://docs.stripe.com/billing/subscriptions/usage-based/billing-credits
- https://docs.stripe.com/billing/subscriptions/usage-based/billing-credits/implementation-guide
- https://docs.stripe.com/invoicing/invoice-edits

# Owner discussion: selectable services and shared usage credit

Status: the owner approved the shared prepaid structure, no separate SMS setup
fee, and OpenAI at 2× actual API cost on October 18. Email/text rate changes below
are still a discussion. Approval of a commercial model is not deployment or
activation. Existing contracts and the already-issued $798 Avery documents stay
unchanged until an explicitly reviewed revision is prepared.

## Approved commercial structure

- Standard remains $499 one-time setup and $299/month for confirmed platform scope.
- One dollar-denominated prepaid Everbranch service balance pays for approved
  consumable services. The existing optional credit choices are $25/$50/$100.
  This is not transferable money or a payment method for Avery's knives, shipping
  vendors or other third-party purchases.
- Future Standard has no separate SMS setup fee and no separate email/SMS monthly
  sending-access subscription. Preserve existing customers' subscriptions,
  allowances and terms. Fixed number, connection or managed-service fees need
  their own written quote.
- OpenAI usage is billed at 2× actual API cost. This is a 100% cost markup and
  50% gross margin on that provider cost, before payment processing, operations,
  support and business taxes. Applicable customer sales tax is separate through
  Stripe Tax; do not invent a company-tax surcharge.
- Pending the current email/text discussion, retain the existing buyer usage
  references: $0.005/email, $0.05/SMS segment, $0.15/MMS. Do not silently extend
  2× pricing to these channels. A long or Unicode SMS can use multiple segments.
- Show meters separately: email, SMS/MMS billing units, AI model input/output,
  and future voice/phone usage. A shared balance does not create equal unit rates.
- Automatic reload defaults off. The planned controls include optional reload,
  balance alerts, reserved spend and monthly limits, pausing optional activity at
  the available balance or approved cap. Provider actuals reconcile reservations.
- Customer-facing AI and phone service need agreed scope/provider readiness and
  a separate quote for any fixed connection/management costs. AI pricing policy
  alone does not make a complete telephone minute price.
- Unused purchased credit and refund/cancellation terms must be written before
  live sale; do not silently expire funds or treat purchases as a reset allowance.

## Future voice buildout: owner-approved model choice

The owner requested team choice between cheaper and more capable models. This is
part of the eventual voice buildout, not a request to change the verified current
tablet model. Keep the live demo model fixed until the alternative passes tool,
latency, interruption, context and permission checks.

The owner/admin approves an allowed model set and defaults for each team role.
A permitted team member can select within that set. The server reauthorizes model,
tenant, role, tools and current pricing; a model choice must never widen data or
action permissions. Do not trust arbitrary model IDs or client-provided prices.
Show the selected model and a usage estimate before starting, then provider usage,
the 2× charge and the shared balance after settlement. No automatic upgrade to a
more expensive model; ask for a reviewed switch within the approved budget.

Reserve a capped session estimate before provider activity. Enforce tenant balance
and owner caps across concurrent users; one user cannot spend another user's
reservation. Keep provider totals, rate versions and replay-safe session IDs for
settlement/refunds. Interrupted sessions and ambiguous provider usage require
reconciliation, not a second charge. Provider keys remain server-side. Test model
selection, denied roles, stale rate cards, concurrent caps, cancellation, duplicate
usage events and all existing action/confirmation boundaries before live rollout.

Current illustrations (not flat fees): a 5,000-input/500-output short-context
GPT-6.1 Sol task is about $0.03 at 2×. For an elapsed minute split equally between
user and assistant speech, audio alone is about 9.6¢ with gpt-realtime-2.1 or 3¢
with gpt-realtime-2.1-mini at 2×; ten balanced minutes are $0.96/$0.30. Text/context,
repeated history, transcription and telephone transport can add cost. Model
estimates and source date are exported from `StandardUsagePricing` to the demo.

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

The retained buyer references for the new wallet discussion are $0.005/email and
$0.05/SMS segment. Separate legacy subscriptions and allowances remain valid for
existing contracts. Twilio's published US
SMS transport starts at $0.0083/segment before carrier/registration fees. SendGrid
Essentials starts at $19.95/month; that is provider delivery infrastructure, not a
customer marketing application. Retell advertises roughly $0.07–$0.31/minute for
voice configurations; actual model/voice/telephony choices must be measured before
a retail rate is committed. These are comparisons, not new approved Everbranch fees.

## Email/text benchmark and pending decision

Shopify Messaging includes 10,000 marketing emails/month, then $1 per 1,000 up to
its first volume threshold. US SMS is $0.012 per billable message, with a separate
$2.15/month approved toll-free number. Our retained $5/1,000 email and $0.05/SMS
references are materially higher for delivery alone. The $299 platform value must
come from the tailored workflow and service rather than claims of cheaper delivery.

The latest recommendation is a published $1/1,000 email rate (not yet approved),
and consideration of 2× actual SMS/MMS transport plus carrier cost (also not yet
approved). A 2× illustration allocating a $19.95/50,000 provider plan over its full
capacity gives $15.96 for 20,000 emails. This is not an account quote or measured
actual cost: dedicated plans, under-used capacity, required subuser features and
other charges can change the result. The flat proposed $1/1,000 would be $20 for
20,000; Shopify is $10 after its included first 10,000. No new free allowance has
been approved for Everbranch.

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

- https://help.shopify.com/en/manual/promoting-marketing/create-marketing/shopify-messaging/email/pricing
- https://help.shopify.com/en/manual/promoting-marketing/create-marketing/shopify-messaging/sms/pricing
- https://developers.openai.com/api/docs/pricing

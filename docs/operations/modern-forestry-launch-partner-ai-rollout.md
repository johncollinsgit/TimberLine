# Modern Forestry — Launch Partner and $99 AI Branch rollout

Date: October 16, 2026 (America/New_York). This is the execution plan for the
user-selected first production AI workspace. Commercial registration is recorded;
payment collection and AI activation remain pending the explicit gates below.

## Approved selection and exact pricing

Use the existing Modern Forestry workspace (`modern-forestry`, verified production
tenant ID 1). Do not provision a replacement tenant, migrate customer data, or
change its `shopify` operating mode. The selected service offer is Launch Partner
and the additional AI tier is `ai_branch_growth` at USD 99/month.

The current public offer is defined in `config/product_surfaces.php` and the
October 13 pricing audit. AI pricing and included allowances remain canonical in
`config/module_catalog.php`.

| Period | Launch Partner service | AI Branch Growth | Monthly total before applicable tax |
| --- | --- | --- | --- |
| Billing cycles 1–6 | $89 | $99 | **$188** |
| Billing cycle 7 onward | $149 | $99 | **$248** |

The published one-time setup fee is $499. Modern Forestry is an existing client:
review its prior setup/payment history and explicitly settle whether that fee is
applicable or waived. No setup charge is authorized by the registration record.
Do not substitute the older $299 managed-website template default. Do not restart
or backdate a promotional period from an invented historical payment date.

Growth currently includes 1,000 requests/month and a maximum $15/month provider
allowance. Recommend that initial provider cap, but an owner must approve it in AI
settings; the $99 subscription price is not permission to spend $99 on tokens.
No automated overages. The AI price remains $99 during both base-service phases.

## Production evidence and registration

Read-only checks on October 16 found:

- The existing workspace is on canonical Starter in Shopify mode, with 52 module
  grants from `modern_forestry_alpha_default`, all marked `add_on_comped`.
- No paid subscription or accepted agreement is recorded in the application.
- A Stripe customer reference exists, but a read under the configured live Stripe
  account returned `resource_missing` for the customer. Existing external
  subscriptions therefore remain unverified; this is not proof that none exist.
- Agreement checkout is globally available but Modern Forestry is not allowlisted.
- AI tables are not deployed, AI provider/model configuration is absent, and both
  AI rollout flags are disabled. There is no verified AI price mapping.

An audited commercial-intent registration now links Launch Partner client record
**314** to tenant **1**, with operator audit record **620**. Profile metadata
`launch_partner_enrollment` records the selected rates, pending acceptance/billing,
setup review and unapproved provider cap. The client list shows the existing tenant
as converted; that directory status does not represent paid provider evidence.
The repeated registration returned no-op, with no duplicate client or audit row.
A before/after digest confirmed operational module access was unchanged.

No invoice, charge, subscription, customer notification, provider call for AI,
new entitlement or AI opt-in was created by registration. The existing Stripe
customer was not silently replaced. County is explicitly `Not configured` in the
client directory rather than inferred.

Client record: <https://app.theeverbranch.com/landlord/onboarding?q=Modern%20Forestry>

## Phase 1 — deliver and stage the existing AI implementation

1. Review the optional AI pilot and workspace-context commits, including additive
   AI migrations, encrypted storage, existing metering and the Modern Forestry
   regression evidence. Release through GitHub and Forge with all AI flags off.
   Do not hot-copy PHP or assets into a production release.
2. Keep the paid `ai_branch` module out of Modern Forestry's automatic alpha
   entitlement grants. Its new bootstrap exclusion preserves existing paid grants
   and revocations. The separate legacy Shopify assistant (`ai`) and all existing
   rewards/Shopify features retain their current access.
3. Apply pending additive AI schema only through the normal migration safety gate.
   Do not run disposable fixtures, schema restores, or migration down methods on
   the live database.
4. Validate a fictional Shopify-mode customer-follow-up workspace and a second
   tenant in staging: workspace/record/memory isolation, read-only chat/briefings,
   source limitations, caps, replay, uncertain usage and in-flight revocation.
5. Re-run Modern Forestry protection and inspect its live Shopify/rewards/import
   health after the disabled release. Existing tests and the protected Forge gate
   remain mandatory. Full-repository formatter debt is separately recorded and
   must be resolved or dispositioned through normal release review.

Exit: deployed release ID and staged acceptance evidence, with no production AI
provider requests yet.

## Phase 2 — prepare the exact paid agreement and billing path

1. Repair or verify the existing Stripe customer reference against the correct
   live account and verified billing contact. Search for existing customer and
   subscription records before creating anything; do not duplicate charges or
   fabricate a provider-confirmed ledger row.
2. Prepare a Modern Forestry Launch Partner agreement with the exact rates above,
   applicable setup decision, recurring start trigger, renewal, cancellation,
   data-sharing terms and retained legacy access. Use its existing workspace.
   Do not reuse a managed-website or electrical-services scope. Do not rewrite any
   accepted agreement; a later change must use a new accepted version.
3. Review the draft and obtain client acceptance through the existing agreement
   flow before collecting payment. No email or SMS is authorized by this plan;
   send only after separate delivery instructions.
4. Implement and test one tenant-scoped subscription with two recurring line items:
   Launch Partner base service and AI Growth. Schedule the base price at $89 for
   six monthly cycles, then $149, while AI remains $99 in every phase. The current
   `AgreementStripeScheduleService` only handles a single recurring item: extend
   exact-version authorization and phase construction before using it here.
   Merely appending an AI line would risk losing it at the phase transition.
5. Preserve both purchase keys in verified webhook metadata and ledger records:
   canonical `plan.starter` and `addon.ai_branch_growth`. Reconcile the full
   subscription state together. Independent subscription events must not disable
   the other purchase. Do not globally enable tier purchases or public checkout
   as a shortcut for this named pilot.
6. Test exact totals, setup decision, cycle-seven transition, cancellation,
   past-due access, signed webhook identity, idempotency and event ordering in a
   sandbox. Verify production provider/tax/webhook readiness through the existing
   guarded billing process. Keep all existing grandfathered operational module
   grants until an explicit reviewed contract changes them.

Exit: an exact accepted version, correct real provider subscription, settled
billing evidence, configured phase schedule and audited fulfillment. Registration
alone must never satisfy these conditions.

## Phase 3 — activate the named AI pilot

1. Store a dedicated AI provider key, explicit model and verified positive rates
   in the production secret store. Do not borrow another assistant's credentials
   or paste secrets into this plan, chat, source code or audit notes.
2. Verify exactly one current AI Growth add-on from genuine Stripe fulfillment and
   exactly one active matching AI ledger entry. Keep feature access owner/admin
   only; never manually label an unpaid record as paid.
3. Configure the verified workspace timezone and have its owner approve AI sharing
   and a provider cap, initially proposed at $15/month. Do not infer that approval
   from the pricing selection or mutate private memory on the owner's behalf.
4. Enable `EVERBRANCH_AI_BRANCH_ENABLED` and
   `EVERBRANCH_AI_WORKSPACE_CONTEXT_ENABLED`, with tenant **1 only** in each
   corresponding allowlist. Leave action approval execution disabled for this
   first read-only pilot. Voice, phone and workflow agents remain later phases.
5. Verify the standard workspace launcher, Context used, one chat and one attention
   briefing. Check evidence scope and links, owner privacy, actual provider usage
   settlement, monthly limits and the kill switch. Dedicated Shopify embedded and
   soccer surfaces remain unchanged.

Initial context covers the approved operational adapters. Shopify sales totals,
external calendar retrieval, financial summaries and Website order/customer data
remain outside this phase; empty or unsupported coverage must be shown clearly.

Exit: a recorded readiness check with release ID, named owner, approved cap,
actual provider reconciliation and a passing Modern Forestry regression check.

## Phase 4 — operating verification

Review actual requests and allowance against provider billing before expanding
access. Use request IDs and content-free metrics; never log retrieved content or
prompts. Maintain UTC billing periods and the configured timezone for briefings.
A failed or uncertain provider call retains its reservation until the existing
operator reconciliation process establishes its outcome. Do not auto-refund or
resend it.

For AI isolation, spending or provider incidents, disable AI/context rollout and
retain usage evidence. Keep Modern Forestry's existing services available. Billing
cancellation/refund changes require their own exact reviewed action; an AI kill
switch must not silently cancel, refund or replace the subscription.

## Authoritative billing references

Stripe supports sequential subscription phases for changing terms over time:
[Subscription schedules](https://docs.stripe.com/billing/subscriptions/subscription-schedules).
Check existing customer subscriptions using the official read endpoint before
any creation: [List subscriptions](https://docs.stripe.com/api/subscriptions/list).
Repository-specific release controls are in `everbranch-ai-runbook.md`,
`agreement-stripe-activation.md` and `modern-forestry-production-guard.md`.

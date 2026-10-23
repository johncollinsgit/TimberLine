# Usage source findings — October 22, 2026

Read-only inspection at source base `4753ba5`; no credentials, runtime environment
or provider account was read or changed. These are repository defaults and dated
operational evidence, not current account rates or a live provider verification.
No new prices are approved for Lees-McRae by this document.

## AI

`config/ai_branch.php`, `AiBranchProvider`, `AiBranchChat`, `AiBranchOffer` and
`TenantMessagingUsageService` implement versioned model choice and measured
uncached input/cached input/output billing. The dated source rate card per million
tokens is Luna $0.10/$0.01/$0.50, Sol $2/$0.10/$10, Astra $10/$1/$50. It is version
`openai-standard-2026-10-20`, expiring November 20. These figures are source
findings only; another task is researching official current provider costs.

The existing ordinary lane requires owner-approved rates/model/customer cap,
uses the shared `TenantMessagingCreditAccount` and immutable ledger, reserves
worst-case cost and settles **2× actual provider cost**. Accepted prepaid usage is
excluded from the older monthly invoice path. The new Lees-McRae no-markup offer
does not rewrite that existing lane.

Conversation history and retrieved memory consume measured input usage. Output
includes provider-reported reasoning usage without a second charge. Standard
service tier, bounded context/output, no external tools and no implicit
long-context upgrade. `AiBranchProvider` deliberately makes no retry after
uncertain provider dispatch. Unknown outcomes retain their credit reservation
for evidence-based reconciliation. Browser speech recognition/playback is
separate from paid audio/telephony APIs. See
`docs/operations/ai-voice-pricing-and-rollout.md` for dated policy and limits.

## SMS and email

`config/marketing.php` has buyer defaults $0.05/SMS segment, $0.15/MMS and
$0.005/email. Accepted tenant usage-contract rates/allowances override defaults
in `TenantMessagingUsageService`. They are not a Lees-McRae quote.

Configured provider-cost estimates: Twilio $0.0133/SMS segment and $0.032/MMS;
SES tenant $0.000175/email; SendGrid $0.0011/email. Separate SMS planning
guardrails use $0.0083 outbound + $0.00395 carrier/segment; MMS $0.022 + $0.009.
The difference between these planning defaults and ledger estimates warrants
current carrier/provider verification rather than treating either as an actual
bill. `SmsMessageSafetyService` evaluates encoding/segmentation, long messages
and Unicode, and may recommend MMS where configured and cheaper.

Source packages include messaging/email allowances and a separate SMS setup/
monthly package. A2P registration, phone-number rental and carrier-specific fees
are not established as an all-in current vendor cost by those usage defaults;
verify them separately. Existing agreement templates explicitly exclude certain
carrier registration/special fees. Do not import their allowances into LMC.

The messaging resolver requires a ready tenant account. Legacy configured
tenants use `sendgrid_global` / `twilio_global`; SES needs its own account,
feature gate and exact allowlist. `config/mail.php` defaults to `log` unless the
environment chooses another mailer. The dated SES runbook records October 20
critical mail on SendGrid SMTP and an SES migration still requiring external
readiness. A source `ses_tenant` default does **not** prove active SES or production
approval. User history reports SES denial; no new live verification was made.

## Choices still open

Separately billed measured usage versus a flat monthly allowance per workspace
or pooled across the three-workspace bundle remains a commercial choice. Any
flat allowance needs explicit included quantities/provider-cost budget, approval
behavior at its limit and treatment of unused balance. No unlimited commitment,
automatic unapproved overage, invented $150 fee or cap has been added. Usage is
distinct from custom development; integrations/migrations/major programs require
their own scoped estimate.

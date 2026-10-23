# Read-only COO assistant candidate

This is a separate source candidate for review after the Enterprise usability release.
It is not a deployed or live-verified assistant. `ENTERPRISE_READ_ONLY_ASSISTANT_ENABLED`
defaults false. Changing that flag is a rollout operation, not part of this source work.

## First slice

The private Ask pane offers three explicit record checks. It uses no model to
interpret a question. No assistant, conversation or preference memory is stored.

- **Current work overview** reuses `Briefing::build`, never its paid refresh.
- **What's happening in this building?** requires a currently authorized, active,
  saved facility ID in the selected department. Only requests carrying that exact
  persisted `data.location_id` contribute checked open/urgent counts. Text labels,
  public catalog names and proximity never establish a link. Native job detail
  comes through current `Operations::show`; unknown priority/due/next action remain
  explicit, including the count of open requests with unrecorded priority. Costs are not allocated to buildings through an inferred association.
- **Recorded costs versus estimates** is owner/admin-only under existing Planning
  policies. It sums at most 200 valid current cost records from a 1000-record scan,
  once per canonical record ID, separately per native project, currency and basis.
  Amounts are integer currency minor units; no conversion or floating arithmetic.
  The difference is recorded-cost subtotal minus estimate subtotal when both
  bases exist. Missing estimates differ from an explicit zero estimate. This is
  not an approved-budget overrun, an accounting total, or a staffing-cost model.

Results identify sample data when configured, include an as-of time, fixed bounded
coverage, current server-generated source links and all cost calculation inputs.
The same tenant, department, native project/task and financial policies apply to
source reads and clicks. Fresh actor/scope checks and a second identical bounded canonical read reject a
role change, object-grant revocation or changed source calculation during retrieval.
The per-pass bounds are identical; the result is still a checked sample, not a total. No browser-selected role, tool, amount, URL or actor can grant
access. The role-preview surface cannot enable these tools for another actor.
Results clear on scope reload, denial and selection change; late responses cannot
restore them. The client also rejects returned identity/scope mismatches or an
unexpected provider mode. Source text uses `textContent`; external/executable links
are not rendered. No record creation, update, send, ledger claim or provider request
occurs along the new endpoint. The POST expresses an ephemeral read, not a write.

## Feature and provider boundaries

The read-only flag grants no related feature or membership. Overview requires the
existing Briefing flag; building checks require Resources; native job details require
Operations; cost checks require Planning plus current owner/admin membership.
The UI disables unavailable choices and always uses the actual signed-in actor.
Campus coordinator membership does not acquire financial access from the COO label.

Chosen paid paths for this first slice: **none**. New spend is zero. The existing
`TrialBudget` is not advertised as a cap for unrelated ledgers. No credentials,
OAuth scopes, provider configuration or paid infrastructure are copied or activated.
Existing billable paths below remain outside this first-slice approval and must stay
server-disabled for a demo until their own aggregate-cap and provider gates pass:

| Path | Existing gate to keep off | Missing shared-cap requirement |
| --- | --- | --- |
| `POST /enterprise/ask` model selection | `ENTERPRISE_AI_ENABLED=false` | Ordinary Intelligence ledger is not the shared trial campaign ledger. |
| `POST /enterprise/briefing/refresh` generation | `ENTERPRISE_AI_ENABLED=false` | Same ordinary Intelligence ledger distinction. Plain `Briefing::build` remains a free read. |
| `POST /enterprise/voice/turns` generation | `ENTERPRISE_VOICE_GENERATION_ENABLED=false` | Tokens, streaming and audio duration must have safe pre-dispatch bounds in the common gate. |
| Gmail/ordinary mailbox email confirmation | Both send flags false; email preview only | Must reserve maximum service exposure through the same trial campaign if ever used. |
| Trial AI/SMS/email confirmation | Trial channel flags false until separate approval | Existing trial reservation is reusable only for its exact approved campaign/rates/destinations; not proof ordinary routes are capped. |

No new code enables these paths. Existing deterministic VoiceJobs, Ask context,
Briefing and WorkGraph reads were assessed and reused through their canonical
services rather than duplicating an AI stack. The first slice has no chat/voice
provider, general-purpose tool router, mailbox reads, automatic send, saved assistant,
persistent memory, accounting import or staffing forecast.

## Required paid-gate acceptance before a later provider milestone

There must be one authoritative aggregate lifetime trial campaign with a hard
5,000,000 micro-USD ($5 total) ceiling across every actually used service and silo.
Prior verified spend, pending reservations, uncertain outcomes and completed spend
all consume that same ceiling. Never rely on a UI warning or a separate tenant cap.

Before dispatch, atomically reserve a conservative worst-case bound including
input/output tokens, streaming limit, audio duration, per-request overhead and any
service fees. Expired or absent rate evidence and an unsafe bound deny dispatch.
Persist a claim before network I/O. Exact idempotent retries bind the same actor,
scope, channel and input digest, and must never dispatch twice. Ambiguous failure,
provider completion with lost response, timeout and delayed completion retain the
reservation until verified reconciliation; never refund merely because HTTP failed.

The paid milestone must exercise real shared-store concurrency across channels and
silos, cap exhaustion, exact-key replay/input mismatch, crash before/after dispatch,
rejected versus ambiguous outcomes, late/duplicate reconciliation, provider-reported
cost above the bound, and current-role revocation before return. No live acceptance
claim follows from mocks. Email stays preview-only; voice may stay off. Specific
credentials/provider/destination approval remains separate from routine implementation.

## Local validation

```
php vendor/bin/pest -c phpunit.enterprise.xml tests/Feature/Enterprise/EnterpriseReadOnlyAssistantTest.php
node --test tests/e2e/enterprise-read-only-assistant.test.mjs tests/e2e/enterprise-workspace-tour.test.mjs
node tests/browser/enterprise-read-only-assistant.smoke.mjs
```

PHP uses the real isolated Enterprise schema/routes, canonical current policies and
persisted fictional records; every test blocks/asserts zero external HTTP. Browser
QA composes the actual new markup/script/styles with a fictional read API and current
private-tour adapter in Chromium/WebKit at desktop, iPad, mobile and laptop reflow
sizes. It covers keyboard submit, exact saved ID, delayed reset, current role loss,
clickable source URL, cost labels, target/copy and horizontal/touch geometry. It is
not a live API, full installed-device tour, production persistence or provider test.
CI registers both the isolated PHP class and new lifecycle/browser checks. The new
shared-renderer source changes the immutable campus review fingerprint through its
existing fixed view/JavaScript source groups; a later approved publication must bind
the resulting exact reviewed build rather than bypass stale-version rejection.

Tour copy before: “Ask Where is it? or Who is assigned? against the chosen job.”
With the read-only feature, after: “Choose a record check, then inspect its sources.
This read-only assistant makes no changes or paid AI calls.” The target changes to
`#read-only-assistant`; financial copy reflects actual access. Existing Talk copy
remains only for the separately enabled job-lookup feature.

## Later milestones and estimates

First useful reviewed source slice estimate was 8–16 focused engineering hours,
including ACL/calculation/UI/tour tests and independent review fixes. It is separate
from the earlier deployment estimate. Saved assistants and provenance memory are
later work: purpose/instructions/bounded tools; inspect/edit/delete with retention,
source provenance and secret exclusions; preferences distinguished from current
facts; fresh access checks after role changes. Chat and two-way voice follow only
after the common paid gate. These later milestones are not implied by the current
record-check controls or included in the first-slice estimate.

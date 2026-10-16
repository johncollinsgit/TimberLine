# Everbranch AI Branch implementation instruction and phased plan

Date: October 15, 2026. Base reviewed: TimberLine origin/main f8bf74f3. This is the optional paid Everbranch AI Branch inside the existing Laravel 12 application. Bud Core remains included and deterministic. The existing Shopify AI Assistant retains its routes, alpha access, drafts, and behavior.

## Product contract and comparison

HighLevel currently separates operator assistance, customer conversations, voice, and automation. Everbranch should share one permission and usage service across future surfaces while introducing each surface independently.

| HighLevel capability | Verified current behavior | Everbranch implementation direction |
| --- | --- | --- |
| Conversation AI | Customer messaging on supported channels, lead qualification and booking; guided, prompt and flow setup; Suggestive and Auto-Pilot modes | Begin with operator chat and drafts; add customer channel adapters only after consent, suppression, handoff and delivery controls pass |
| Voice AI | Voice agents support calls, booking, transfers and configured actions; public APIs expose agents, actions and call evidence | Separate future speech and telephone adapters; reuse authorization, action approvals and usage reservation |
| Workflow AI | Prompt-based workflow building plus specialized reasoning actions and a multi-tool AI Agent action | Later register bounded AI steps with the existing workflow engine; enforce step budgets, idempotency and approval pauses |
| Content AI | Text and images in social, blogs, email and website/funnel editors | Produce editable drafts now through chat; publishing and sending stay separate reviewed operations |
| Ask AI | Operator copilot for content and supported CRM actions | Closest initial comparison: a tenant workspace with contextual chat and specific reviewed actions |
| Agent Studio and Managed Agents | Flow agents and event-triggered agents with knowledge and connector tools | Later introduce tenant-owned agent definitions, versioned allowlists and sandbox evaluations; no arbitrary executable tools |

Sources: [Conversation AI setup](https://help.gohighlevel.com/support/solutions/articles/155000004401), [Voice AI setup and actions](https://help.gohighlevel.com/support/solutions/articles/155000008604-how-to-use-voice-ai-flow-builder-templates-and-guided-setup), [Voice APIs](https://help.gohighlevel.com/support/solutions/articles/155000006379-voice-ai-public-apis), [Workflow AI Agent](https://help.gohighlevel.com/support/solutions/articles/155000007600-workflow-action-ai-agent), [Content AI surfaces](https://help.gohighlevel.com/support/solutions/articles/155000005517-thumbs-up-down-feedback-for-content-ai), [Ask AI and Managed Agents comparison](https://help.gohighlevel.com/support/solutions/articles/155000008362-super-agents-conversation-ai-ask-ai-what-s-the-difference-), [Skills Platform](https://help.gohighlevel.com/support/solutions/articles/155000008315). Research is a dated snapshot; verify availability for a particular account before promising parity.

HighLevel's October 13 pricing guide lists AI Employee Growth at $50/location/month and Unlimited at $97/location/month. Growth includes 1,000 conversation responses and 100 voice minutes; unlimited is subject to fair use. Agent Studio stays metered, certain workflow actions cost extra, and telephone charges remain separate. These are competitor prices, not Everbranch cost estimates. [HighLevel pricing](https://help.gohighlevel.com/support/solutions/articles/155000006652-ai-product-pricing).

## Commercial contract

Preserve the requested $49/$99/$199 monthly USD prices. The referenced conversation exposes the optional Laravel AI Branch concept but not the original detailed allowances. The following names and limits are proposed conservative pilot defaults, subject to owner review before sale.

| Tier | Monthly price | AI requests per UTC month | Provider cost ceiling | Initial actions |
| --- | --- | --- | --- | --- |
| Starter | $49 | 300 | $5 | Chat, retrieved job summaries, editable drafts, explicit personal memory |
| Growth | $99 | 1,000 | $15 | Starter plus reviewed owner-only internal job notes |
| Pro | $199 | 3,000 | $35 | Growth with higher allowance; future agents separately gated |

These are maximum allowances, not a promise that both ceilings can be reached. A tenant also chooses a lower provider cap. Use positive, operator-verified model rates and reserve worst-case input/output cost before requests. No automatic overages, paid voice minutes or telephone service are included. Keep platform revenue, provider cost, and customer charges distinct. Included requests write buyer_charge_micros=0 to the existing tenant_ai_usage_events ledger to avoid duplicate usage invoicing. Existing field voice billing stays unchanged.

The canonical module_catalog owns tier prices, limits, hidden add-ons and the ai_branch module. Confirmed Stripe fulfillment grants the chosen add-on through the current lifecycle. Require exactly one active stripe_fulfillment AI add-on and matching current active billing ledger, canonical module access, global enablement, tenant allowlist and explicit owner opt-in on every request. Ambiguous duplicate tiers fail closed. Inactive/cancelled entitlements block the next request; in-flight work already reserved may finish and be recorded. No AI-specific unsigned webhook or checkout-success entitlement shortcut is allowed. New purchases remain disabled pending catalog, tax, checkout, cancellation, refund and signed webhook release checks. Never create live Stripe products or change a customer subscription during development.

## Codex implementation instruction

1. Read AGENTS.md, SYSTEM_SNAPSHOT.md, README_FOR_AGENTS.md, the canonical catalog, tenant resolver, Bud services, usage/invoice services and existing Stripe fulfillment. Use an isolated branch from current main. Preserve dirty work and avoid mixed releases.
2. Add a default-off ai_branch module and tier catalog without changing the existing ai module. Use TenantModuleAccessResolver for entitlement decisions and UnifiedAppNavigationService for navigation. Hide AI from public/mobile stores until release approval.
3. Resolve tenant only from tenant.access middleware. Check active tenant membership and tenant owner/admin role on every service call, even with a platform role. Client-supplied tenant IDs, models, prices, permission scopes or approval claims never confer access.
4. Store conversations and memory encrypted in LONGTEXT, private to tenant and user. Bound history and explicit memory size/count; support deletion. Memory is user-entered and never extracted silently. Expire conversations and memories; exclude expired records from retrieval. No model-managed global thread or shared vector store.
5. Retrieve through narrow allowlisted adapters with canonical module and existing row permission checks. Initial adapter returns at most ten visible non-archived field jobs (ID, title, status and schedule), no financial, lock-box, credential or customer contact fields. Attach source references server-side, separate source material from instructions and display evidence as plain text. Do not imply exhaustive reporting. Add customer, Website and accounting adapters only with their own permission contracts and tests.
6. Call the fixed OpenAI Responses endpoint using a dedicated server key, operator-selected model and rate snapshot, store=false, bounded output and timeout. No external tools, URL fetches, arbitrary SQL, generated code or provider retries. Keep instructions above user/retrieved data. Parse only completed text responses with valid usage. Never equate store=false with a provider zero-retention commitment. [Responses documentation](https://developers.openai.com/api/docs/guides/migrate-to-responses).
7. Serialize quota reservations by locking a tenant account row. Use existing usage events with a unique request UUID and request hash. Bind replay to tenant, user and conversation. Commit reservation before network I/O; retain ambiguous failures for operator reconciliation instead of retrying or assuming zero spend. Snapshot model and rates; meter actual tokens. Keep the request ceiling reserved if provider usage is missing or outside the contract. Block concurrent requests on the same conversation; count uncertain attempts against limits.
8. Expose an allowlisted action registry. Initially only prepare/approve/reject an owner-visible internal job note, with no notification, status change or external delivery. Bind approval to immutable encrypted payload digest, tenant, requesting user, target version and short expiry. Recheck all permissions, module access, tier and target state under locks. Record result atomically and replay approval without repeating the note. Unrecognized actions, sending, money movement, refunds, payroll, deletion and publishing fail closed until separate adapters and approval gates exist.
9. Build an accessible workspace using the existing app shell, escaped model text, CSRF-protected forms, clear allowance/status display, new chat, history, memory delete, and exact action preview before confirmation. Offer a compact launcher through canonical navigation. Do not accept arbitrary HTML from generated output.
10. Add meaningful adversarial tests: cross-tenant and cross-user reads/writes, inactive membership, changed roles, disabled flags/allowlist/entitlements, duplicate billing tiers, cap exhaustion, reservation replay/conflict, provider error/timeout, malformed usage, stale/replayed approvals, model text injection and memory deletion. Use fake HTTP only. Run catalog, Bud, tenant, field and billing regressions plus MySQL migration lint/recovery/baseline checks. Existing CI remains release authority.

## Phases and acceptance gates

| Phase | Deliverable | Gate before advancing |
| --- | --- | --- |
| 0 Research and contract | Dated comparison, repository map, tiers, explicit exclusions, implementation instruction | Review proposed allowances, provider model/rates, data processing and unit economics before commercial launch |
| 1 Safe operator pilot | Hidden module, owner opt-in, chat workspace, narrow cited retrieval, private explicit memory, durable quotas and reviewed internal notes | Tests pass; disposable MySQL migration and rollback evidence; owner approves one named tenant for rollout |
| 2 Commercial pilot | Existing signed Stripe lifecycle for tier add-ons, verified checkout and cancellation/downgrade behavior, usage reporting and support reconciliation | Owner approves live prices and a real paid pilot; verify no duplicate invoices, webhook replays, failed payment and cancellation access revocation |
| 3 Broader retrieval and drafts | Permission adapters for customers, tasks, reporting and channel drafts; evaluations by industry | Data owner approves sources, role matrix and redaction; measured grounded-answer quality and injection resistance |
| 4 Permissioned operational actions | Versioned adapters for approved tasks, appointments and outbound drafts; durable action ledger | Exact preview, actor/target recheck, consent/suppression readiness, approval expiry, duplicate and partial-failure tests |
| 5 Workflow agents | Versioned agent definitions, event triggers, bounded tool steps and human handoffs | Sandbox runs, per-run cost/time/tool caps, cancellation and kill-switch drill; no unattended consequential actions without explicit tenant policy |
| 6 Voice and phone | Dictation, then in-app voice, then inbound phone; outbound phone is a later launch | Separate flags, phone provider/number ownership, verified webhook signatures, caller identity rules, recording consent, retention, human transfer, minute caps and emergency stop |

All phases use the existing Laravel application, database, queues and release process; no new server is assumed necessary. Capacity and queue isolation must be measured during pilot. Voice/phone flags do not themselves create a supported transport.

## Repository integration map

- Catalog and access: config/module_catalog.php, TenantModuleAccessResolver, TenantAccessAddon and StripeCommercialFulfillmentService.
- Runtime: app/Services/AiBranch; private records in app/Models/AiBranch*. Dedicated config/ai_branch.php.
- Web: AiBranchController, routes/ai-branch.php included within authenticated routes, resources/views/ai-branch and canonical navigation.
- Metering: existing TenantAiUsageEvent; branch reservations use feature=ai_branch_chat with zero incremental buyer charge. Do not route chat settlement through the duration-based voice service.
- Safety: additive guarded migrations, tests/Feature/AiBranch, MySQL recovery contract and operation runbook. The branch does not alter Bud Core, Shopify Assistant, customer delivery, QuickBooks or checkout behavior.

# Everbranch AI Branch operations and rollout

## Current status

The branch is implemented for an owner/admin pilot and disabled by default. New AI tier purchases are blocked with the existing CatalogPurchasePolicy. Chat and notes use no live credentials in automated tests. No production tenant, subscription, Stripe product, message delivery, voice or telephone integration is activated by this change.

The implementation and phased contract is in `docs/architecture/everbranch-ai-implementation.md`. Starter, Growth and Pro cost $49, $99 and $199 monthly. Limits of 300/1,000/3,000 requests and $5/$15/$35 provider budgets are proposed pilot defaults requiring commercial review. Voice and telephone costs are separate future work.

## Pilot activation gates

1. Review the implementation diff, model choice, exact provider rates, data processing terms, retention, test report and named tenant. The feature must pass the normal GitHub test/build and migration safety gates. Preserve the atomic Forge release path.
2. Before migrations, back up using the established release runbook. Apply the four additive AI migrations through the release gate; the MySQL dump and fingerprint are data-free schema artifacts. Never run fresh migrations or recovery fixtures against a customer database.
3. Approve commercial launch separately. The catalog's AI add-ons have `purchase_enabled=false`, hidden store visibility, and no new live Stripe products. A billing pilot requires approved Stripe price mappings and one current signed Stripe lifecycle record. Enable only approved tier purchases in a reviewed change. Upgrade/downgrade checkout orchestration remains a release task: do not let multiple tier subscriptions overlap. Cancel or replace the old tier through verified billing operations and prove the new state before access resumes.
4. Set a dedicated `EVERBRANCH_AI_BRANCH_API_KEY`, explicit model and positive verified rates in microdollars per million tokens. No default model or assumed provider price is supplied. Set `EVERBRANCH_AI_BRANCH_TENANT_IDS` to the single reviewed tenant ID, then enable the global feature. Never borrow Bud, Shopify, phone or another tenant's credentials. Test fake-only until an owner approves actual provider spend.
5. Verify that exactly one current `TenantAccessAddon` comes from `stripe_fulfillment` and exactly one active `TenantBillingSubscription` matches its purchase key. A known billing period must be current. A checkout without a period is provisional for 24 hours while the subscription event arrives. Past due/unpaid/cancelled ledger entries block access. Keep the canonical ai_branch module enabled for the approved tenant.
6. The tenant owner opens `/ai-branch?tenant=TENANT_SLUG`, reads the data sharing notice, chooses a provider cap, and explicitly opts in. Existing Bud approval or Growth/Pro platform access does not grant AI Branch access.
7. Start with chat and private memory. Retrieval initially supplies only ten recent visible non-archived operational jobs and their title/status/schedule. No customer contacts, lock-box codes, financial amounts, provider secrets, Website data or QuickBooks transactions are supplied. Treat results as a sample and inspect evidence.
8. Enable `EVERBRANCH_AI_BRANCH_ACTIONS_ENABLED` only after the tenant approves the note pilot. Review an exact owner-visible note and its job, approve within 15 minutes, then verify one note and no status change or notification. Replayed approval must not duplicate it. Starter cannot use this action.

`voice_enabled` and `phone_enabled` remain hard-disabled placeholders. No HTTP voice/call endpoint, number purchase, recording or outbound-call capability exists in this release. Complete Phase 6 before introducing these transports.

## Usage and ambiguous failures

Budget reservations serialize under the AI account row. The monthly window is UTC and independent of invoice/renewal dates. Each accepted attempt consumes a request slot, including uncertain or provider-confirmed no-charge attempts; no-charge reconciliation releases provider spend only. Included AI Branch usage has zero buyer charge and cannot produce an extra usage invoice. Existing field voice metering is unchanged.

Timeouts, incomplete responses, invalid usage and provider HTTP failures keep their maximum cost reserved with status `uncertain`. The same UUID cannot dispatch again. A crash after dispatch can leave `reserved`; this is also an ambiguous request. A different chat can proceed only while the remaining cap permits it. Do not delete or reset a reservation to make a request retryable.

An active platform operator can preview one resolution with `php artisan ai-branch:reconcile-usage EVENT_ID --actor=OPERATOR_ID --evidence=PROVIDER_CASE_REFERENCE --input-tokens=VERIFIED_COUNT --output-tokens=VERIFIED_COUNT`. Use `--no-charge` only when provider evidence confirms no charge. Add `--apply` after reviewing the preview. The request must be unresolved and older than two minutes. The command snapshots before/after state, clears the matching pending marker and never resends the request. It does not fabricate a chat answer or replay the old UUID. Costs exceeding the reservation stay blocked for a pricing investigation. Never put credentials, prompts or customer records in the evidence reference.

## Memory and retention

Conversations and memories are private to a tenant and user and encrypted in LONGTEXT. Keep APP_KEY available under the established backup/key policy; this implementation does not add key rotation. Memory is explicit, at most ten entries of 500 characters, expires after 90 days and is never inferred automatically. Memory changes invalidate previous conversation context; prior replies remain in old chats until deleted or expired. Active chats are limited to 30 per user and 40 messages each, with a conservative input byte ceiling.

Chats expire after 30 days. The daily `ai-branch:prune --apply` task deletes expired chat/memory and redacts old action text after its expiry plus 30 days, retaining action digests, decisions and usage evidence. The task runs while runtime flags are disabled. `php artisan ai-branch:prune` previews counts without deleting. Individual memory/chat delete endpoints retain active owner membership checks even after paid access is revoked. A pending chat requires usage reconciliation before manual deletion. `store=false` disables stored Responses state; it is not a claim of provider zero data retention.

## Kill switch and rollback

For an incident, set `EVERBRANCH_AI_BRANCH_ENABLED=false` and remove the tenant allowlist. Disable actions separately if only mutations are affected. The navigation and new provider/action requests fail closed. An already dispatched request may still finish; its cost is recorded, but authorization and data-scope checks prevent returning it after access revocation. Avoid changing provider rates while reconciling older requests; their rate snapshot remains in the usage event.

Keep additive records, usage evidence and approval history. Roll back application code with the normal retained Forge release, leaving tables in place. Do not run the migration down methods on customer data. Bud Core and the Shopify assistant do not depend on these new flags or tables. Before declaring recovery, check Bud, tenant navigation, existing voice invoices and the affected workspace, and reconcile pending provider requests.

## Required release evidence

Run AI Branch features, tenant/catalog/access tests, billing lifecycle and usage-invoice tests, Bud tests, field workspace tests, Modern Forestry regression protection, lint/format checks, the application build, and the full normal CI gate. The MySQL job includes `AiBranchMySqlMigrationRecoveryTest.php`, real-process quota/approval concurrency, prior-schema rehearsal and committed schema fingerprint restoration. Live answer quality, latency, provider billing reconciliation, real Stripe purchase/renewal/tier-change tests and voice/telephone acceptance require separate approved pilots; fake provider tests establish control flow, not model quality.

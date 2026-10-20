# Connected operations local verification — 2026-10-20

This records local candidate evidence. It does not claim full Phase 2 completion, live provider acceptance or production readiness.

## Automated checks

- Broad Laravel regression: **230 tests / 2,180 assertions** across Teams, Wholesale, module-catalog consistency, shared AI billing and mobile voice dispatch.
- Subsequent focused operations run: **32 tests / 170 assertions**, including an additional Square test for tenant separation and known-zero versus missing refund evidence.
- Teams JavaScript checks: **60 passed**; work-graph assertions: **47 passed**.
- Build: TypeScript check, **11** trajectory tests and Vite build passed. Existing third-party Rollup annotation warnings remain non-blocking.
- Migration safety lint: **6** changed migrations passed. MySQL recovery test PHP syntax passed; an actual disposable MySQL recovery run and Linux checks remain unperformed.
- Cumulative review patch is checked against its reconstructed candidate baseline. It is not a patch against main or deployment authorization.

The operations tests cover default-off gates, cross-tenant/area access, source read-only behavior, encrypted tokens, stale connection generations, source cursors, resumable multi-project identity, withdrawal after complete enumeration, missing/zero/copy-ID sheet normalization, prefill omissions, per-attachment matching, document destination access, uncertain action gates, HTML sanitization, weather horizons, complete-dataset financial aggregation, unavailable Sheets, permission-filtered reminders, shared AI usage reservations, unsigned push denial, template publication, private-token exclusion, recipient permissions, quiet hours, cancellation, dated/undated pagination, temporary versus revoked OAuth refresh, idempotent formatted preparation, stale dispatch rejection and disabled external campaign execution.

## Human browser evidence

Used the real Laravel/Vite application with a new, marked disposable SQLite fixture on loopback port 8774. Provider requests are faked and external sending remains disabled.

- Opened both Events and Wholesale and their grouped workflow navigation.
- Entered an event title, selected historical prefill and confirmed manual venue text remained intact; acceptance/payment/date values were not carried forward.
- Retained separate Events and Wholesale record drafts while switching areas; saved fictional event/account records.
- Customized and published a fictional email template and verified it appeared in the template library.
- Selected its sender and verified workspace recipient, rendered current event/recipient tokens, reviewed formatted and plain-text versions, and prepared an outbox draft without sending it.
- Closed and resumed an email draft on embedded `/team`; its message and recipient remained intact.
- Previewed a missing-logistics reminder audience/schedule and saved the rule paused. Reviewed the persisted rule table.
- Inspected event statistics, expense categories and explicit source completeness notices.
- Reviewed desktop 1440×1000, phone 390×844 and embedded tablet 1024×900 layouts. Tested phone navigation open and Home return.

Screenshots in the review handoff use fictional data only. This is targeted workflow testing, not a claim that every Phase 2 acceptance case has been exercised.

## Unverified / incomplete

Current Events project access is denied through the connected Asana account. Runtime OAuth, selected real projects, both mailbox grants, live Sheets identities and permission changes require a private pilot. No real Asana creation, Gmail/SMS send, spreadsheet write or production deploy occurred. Commercial weather, real scanner protocol/runtime, MySQL durable-DDL recovery, Linux and protected release gates remain required. External campaign/reply-stop execution, verified staff/contact mapping, allocation/comparable-history review and the remaining Phase 2 capabilities are tracked in the architecture document.

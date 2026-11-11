# Connected event sales

This source connects Pay, Teams, Customers and Marketing through tenant-scoped
canonical customer identities and confirmed original purchase evidence. Protected
release, the exact signed phone build, physical counts and supervised acceptance
are separate requirements. Source checks alone do not establish event readiness.

## Checkout and counts

The register keeps product/scent/cart actions local. Customer lookup is optional;
name, phone or email search uses bounded server results and session-scoped caches.
A new customer needs one contact method, with name optional. Customer capture,
receipt contact, receipt delivery and marketing permission remain separate acts.

Authorized event staff can confirm an existing saved contact and verbal redemption
approval. `POS_STAFF_REWARDS_ENABLED` and the reviewed register setting are both
required. The audited authorization lasts 120 seconds and uses the existing
one-use reservation, identity fence and shared rewards journal. It grants no
customer-account or wallet sign-in authority. QR/OTP remains available.

`pos_candle_cash_register_cap` can lower the published tenant allowance for a
register. Its `max_minor`, `reviewed_by`, `reviewed_at` and `policy_version` must be
reviewed together. Preview, reservation, final admission and original receipt
disclosures enforce that lower allowance. Columbia uses 500 minor units for every
customer, including members; other registers keep their published tenant rules.
Redemption remains limited to eligible merchandise and the actual available balance.

Cash is independently default-off behind `POS_CASH_ENABLED` and the register's
`cash_enabled`. It checks tenant/device access, catalog/tax review, original quote,
stock and rewards. It records received/change and internal evidence without a
Square payment. Repeating an exact uncertain request cannot record another sale.
Full cash returns reverse the original earned and redeemed journals once; stock
returns need an explicit physical adjustment. Mixed card/cash tender and cash-drawer
management are outside this release.

The stock wizard recognizes count-sheet text on the phone. Ask Everbranch receives
only that text and bounded catalog labels to propose a draft. Every row requires
manual review; operators can add, change or remove rows. Only the reviewed atomic
batch changes stock. Counts never come from packing plans or historical sales.
The saved request freezes API origin, register, physical pool, catalog context and
per-item balance versions. An unknown result retains that exact intent. A known
rolled-back context/version rejection refreshes the draft and requires review again.
The existing single-count endpoint keeps its contract and accepts the context pair
additively. Catalog reads batch balances and select only the register's catalog.

## Sales and customers

Completion offers explicit customer confirmation and Text, Email or Done. Supported
Square customer associations can suggest a returning customer asynchronously;
they do not block the next sale or replace an explicit selection. The server-backed
Register receipts area survives sessions and supports later attribution and receipt
requests. Durable native intents preserve exact customer, contact and delivery
requests through lost responses. Receipt delivery never opts a customer into Marketing.

Teams event Sales and Customers views link the occurrence, original receipt,
canonical customer and Marketing audience while retaining event context. Event
totals combine confirmed Pay/cash and explicitly reviewed Square allocations.
`SalesChannelSummaryService` is the cross-channel reporting adapter. Original
provider IDs remove imported Pay mirrors. Conflicting allocations, missing tender
evidence, unknown refund/tax allocations and incomplete source coverage are visible.
Historical workbook comparisons remain separate from current revenue.

`PayEventReceiptIndex` caches immutable original sale-to-pool membership by runtime
and tenant. Every financial read reloads current payment, refund and customer
evidence; late confirmation and attribution therefore remain fresh. Following an
audited original-data repair, `forPools($tenant, $pools, true)` rebuilds that specific
index. It is not authority to change original sales or broadly clear caches.

## Reconciliation and audiences

Core Customers and the Marketing desk use the same canonical identities, source
provenance, consent and suppression records. `ConfirmedCustomerPurchaseProjection`
provides a rebuildable five-second customer reporting projection. Stable event,
series, product and scent membership uses exact keys, with acquisition capture
kept separate from purchases and repeat attendance. Unknown source coverage is not zero.

Owner-authorized original Square reads support full resumable directory pagination,
recent/updated orders and per-location payments. Reconcile proven original legacy
source ownership before creating genuinely unmatched customers. Contact/card
similarity proposes review, never a merge or consent. Historical imports do not
award rewards. Unchanged generic legacy system definitions can be copied as tenant
draft audiences without assigning ambiguous original records or changing saved groups.

The typed audience builder saves dynamic buyers, captured contacts, product/scent
purchasers and previous-show-or-nearby rules. It displays membership reasons,
eligible channels and missing locations/permissions. The editable radius defaults
to 50 miles using the included 2026 Census ZCTA reference; ZIP distance is approximate.
Only saved customer locations are used, with original-source provenance and
conflicting locations retained for review. Saving or refreshing an audience sends
no campaign. Automated invitations remain a later phase.

## Ongoing source updates and launch

An owner must explicitly configure `square_source_refresh` for the original reader,
merchant, environment and exact locations. OAuth alone enrolls no tenant. The
source-only scheduler scans enabled policies and retains pagination checkpoints.
The HMAC-verified Square webhook can enqueue exact original objects only when
`POS_EXTERNAL_SALES_INGEST_ENABLED` is enabled. `RefreshSquareEventSource` uses the
dedicated `event-sales-history` queue on a validated asynchronous connection
(`POS_EXTERNAL_SALES_QUEUE_CONNECTION`, default `database`), independent of a sync
default. A running worker is required for prompt external updates. Policy/runtime
and current provider evidence are rechecked before import; source writes cannot
rewind a newer original revision. Source refresh does not guess physical events,
charge/refund, change stock, run AI or send messages.

Release through the protected PR and normal pinned Forge deployment, then verify
the exact merged `/ready` SHA. Verify the installed signed build, named device,
merchant and event-use evidence separately. Review register tax calculation against
the venue, enter real opening counts through the wizard, bind the actual tenant
program and receipt channels, and enable only the reviewed register's policies.
Supervised acceptance must include guest and attributed card/cash sales, partial
rewards, receipt delivery, next sale, returning-customer suggestions, retries and
matching customer/event/audience history. Dashboard targets apply after confirmed
evidence is admitted; source timestamps and delayed/offline provider coverage stay visible.

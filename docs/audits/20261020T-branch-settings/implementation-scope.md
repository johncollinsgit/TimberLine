# Approved workspace plan and branch settings

The owner explicitly requested understandable tab-based settings for what the
customer has, what can be added, what is happening and the process for adding.
Use small amounts of information and existing guided steps. The owner approved
recommended fixes, push and deploy, but cross-workspace release coordination
currently holds all merges and deployment until one owner/order is assigned.

Classification: shared core tenant presentation in the existing Branches screen
(`/marketing/modules`), not a parallel settings or billing application. Reuse
TenantModuleCatalogService, TenantModuleAccessResolver, the existing view/mutate
module-store gates, TenantFinancialAccess and existing audited activation/request
handlers. No tenant grant, provider connection, rollout or price is changed by
viewing a tab, previewing a branch or moving through a wizard.

Tenant scope is resolved by the existing authenticated context. Financial links
are shown only through TenantFinancialAccess. Current package/access evidence
must remain distinct from the prospective Simple public offer; never infer that
an existing customer changed agreements. Core public branches are included in
new Simple offers; Teams, Trajectory and Markets & Makers have separate premium
pricing and readiness. Unknown premium prices remain unquoted.

Tabs: Your plan (current package and summary), Your branches (owned/readiness),
Add software (other choices and separate premium products), Setup (unfinished
steps and requests). Preserve catalog categories/search and the existing three-
step confirmation flow. Improve keyboard tabs, focus containment/return and
clear request/purchase/activation wording. Keep Shopify embedded surfaces intact.

Non-Forestry applicability: all eligible shared workspaces, with canonical
focus/channel restrictions and no customer-specific forks. Verify synthetic
owner/member and A/B tenant boundaries; exercise small-screen and keyboard
flows in an isolated fixture before review. Existing release #436 is the parent;
this branch must be integrated after its owner-approved release ordering.

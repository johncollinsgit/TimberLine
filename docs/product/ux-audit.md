# Everbranch Product Exploration and UX Audit

Status: implementation pass in progress on 2026-07-25.

## Decisions and safety boundaries

- The existing demo workspace is intentionally unchanged. The owner is not
  using it now, so anonymous exploration ends in a guided-meeting request
  instead of simulated access.
- Public exploration is definition-only. It reads modules that
  `TenantModuleCatalogService` confirms are safe for the `public_site` surface
  in `config/module_catalog.php`. It does not query a tenant.
- Landlord search is a separate backend domain. It may query control-plane
  workspace/setup records, ticket headers, requests, module definitions, and
  landlord destinations. It may not query tenant customers, orders, work,
  messages, files, workflows, or reports.
- Ticket search includes subject, category, priority, status, and workspace
  identity. Message bodies and attachments are deliberately excluded.
- Browsing Branches does not grant access, change billing, connect an account,
  or mutate a workflow. Existing server authorization remains authoritative.

## Persona and route findings

| Surface | Persona | Finding | Correction in this pass | Status |
|---|---|---|---|---|
| `/explore/modules` | Anonymous visitor | No safe, tenant-free way to understand the modular product | Added a compact searchable/filterable public catalog with dedicated module URLs, pricing, dependencies, integrations, data expectations, setup effort, and meeting CTA | Fixed |
| Public module detail | Anonymous visitor | “Try demo” would imply a maintained experience the owner is not using | Routes to the contact/meeting form; it creates a landlord inquiry only, not a demo user, workspace, or entitlement | Fixed |
| Workspace home | New owner/admin | Purchasable capabilities were not prominent enough | Added **Branches**, an Explore Branches action, and visible included/add-on/upgrade/request state and pricing | Fixed |
| Tenant sidebar | Workspace member/admin | Branch discovery was nested under Marketing and easy to miss | Added a permission-aware top-level **Branches** destination while retaining existing module authorization | Fixed |
| Top search | Tenant user | Visible search and command overlay behaved as different controls; the overlay root could remain hidden and had incomplete keyboard state | Unified the entry points and added Command/Ctrl+K, focus return/trap, arrow/Enter/Escape behavior, recent destinations, debouncing, cancellation, and complete request states | Fixed |
| Top search | Landlord operator | Landlord shell pointed at tenant search, whose middleware and data domain were inappropriate | Added `/landlord/search` with a landlord-only coordinator and providers | Fixed |
| Landlord search | Landlord operator | Tickets and natural task phrases were not discoverable | Added ticket-header results and actions including “add a user” and “see requested Branches” | Fixed |
| Workflow catalog/navigation | Customer | “Order Calendar” described only one current outcome and would misrepresent future providers/actions | Renamed the Branch **Workflow Automations** and retained **Workflow Studio** for the builder; internal keys are unchanged | Fixed |
| Branch/walkthrough request | Owner/operator | Real product interest did not consistently create an operator text | Routed real requests through `OperatorAlertService` with reservation, stable dedupe keys, fake/test suppression, and failure isolation | Fixed |
| Existing synthetic demo | Demo user | Owner is not actively using the demo | No changes; revisit only after a separate demo-product decision | Deferred by decision |

## Interaction protocol applied

- Links remain links for shareable destinations and browser history.
- Search is a dialog with an accessible title, combobox/listbox semantics,
  focus containment, Escape close, and focus restoration.
- Results are rendered through DOM text nodes rather than HTML injection.
- Stale search requests are aborted and ignored.
- Explorer filters update the URL without a full reload; module details keep
  dedicated URLs.
- Empty, loading, and failure states give the next useful action.
- Primary product and pricing copy comes from the canonical module catalog.
- Alert delivery cannot make the customer request fail; delivery exceptions
  are reported after the request has safely persisted.

## Performance observations

- Public module discovery is configuration-backed and performs no customer
  data query.
- Search providers select only the fields they render, use bounded result
  limits, and eager-load only the associated workspace label where required.
- Landlord search fans out across a fixed provider set; each provider fails
  closed and the coordinator caps the combined result count.
- The command palette waits 170 ms before querying and cancels superseded
  requests, reducing duplicate work while preserving responsive feedback.
- No new external search service or frontend dependency was introduced.
- `npm audit fix` refreshed vulnerable packages within the existing semver
  ranges, including Axios and Vite. TypeScript and the production Vite build
  pass afterward.
- Seven high-severity audit findings remain in the transitive
  `@glideapps/glide-data-grid` → Linaria → minimatch/brace-expansion path.
  npm's proposed automatic resolution is a breaking downgrade of the data-grid
  package, so it was not forced into this UX pass. Track that dependency
  separately and test every grid before changing its major behavior.

These are structural improvements, not a substitute for production APM.
Server timing and query-count baselines for high-volume customer and messaging
routes remain a separate performance pass.

## Security review and open decision

The pre-existing tenant search providers deserve a separate data-minimization
decision. Some currently match sensitive operational free text, including job
notes, lock-box codes, private financial notes, and workspace asset search
text. This pass does not broaden, copy, or re-index those fields, and landlord
search cannot reach them.

Recommendation: exclude sensitive free text from ordinary tenant omnibox
matching by default, retaining only safe identifying fields such as names,
titles, addresses, and document numbers. Add narrowly authorized deep search
later if customers genuinely need it. This change should be approved
explicitly because it removes existing search behavior.

## Remaining product-wide follow-up

- Decide the tenant-search sensitive-field policy described above.
- Run an authenticated production-like audit for limited member, workspace
  administrator, and landlord roles using synthetic fixtures only.
- Add route-level performance budgets for customers, messaging, and workflow
  history after representative data volumes are agreed.
- Inventory destructive actions and migrate remaining generic confirmations to
  record-specific language.
- Audit forms and dense card layouts one domain at a time; do not combine that
  work with customer-data migrations.
- Revisit a resettable synthetic demo only when it becomes an actively
  maintained acquisition or sales tool.

## Verification

Focused automated coverage includes:

- public-safe module visibility and hidden-module 404 behavior;
- no tenant identifiers in the public catalog payload;
- landlord versus tenant search isolation;
- exclusion of ticket message bodies and tenant operational records from
  landlord search;
- operator-only landlord search access;
- real request alerts send once and test/demo/fake alerts are suppressed;
- module-store tenant scoping and billing-neutral browsing; and
- keyboard/reduced-motion friendly markup and styles, followed by an in-browser
  click-path review before release.

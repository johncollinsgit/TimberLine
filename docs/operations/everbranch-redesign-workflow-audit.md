# Everbranch redesign workflow audit

## Delivery boundary

This increment redesigns the public Everbranch website with a white canvas,
restrained watercolor edges, rounded photo cards, short copy, and a keyboard-
operable capability guide. It adds a saved branch-selection basket, workspace
guide, contextual help, support escalation, and safer custom-domain connection.
It does **not** establish unrestricted production signup, paid-plan activation,
live customer checkout, Cloudflare readiness, or successful SMS delivery.

Prices and branch visibility come from the canonical module catalog. The public
offer remains $499 setup plus $299/month, included core branches, usage fees as
disclosed, and separately agreed premium products. Existing agreements remain
authoritative. The branch basket records intent; it cannot grant entitlements,
confirm a payment, or bypass publishing/provider gates.

## Source workflow map

| Workflow | Entry and resulting state | Verification / remaining acceptance |
| --- | --- | --- |
| Discover capabilities | Public home → Customers / Work / Growth / Websites guide → branch detail | Responsive cards, hash navigation, keyboard tabs and reduced-motion behavior require browser smoke on built assets. Only canonical public-safe catalog entries are shown. |
| Compare pricing | `/platform/plans` → current offer / premium pricing request | Public offer and plans regression suites cover source behavior; no contract or charge is changed. |
| Choose branches | `/platform/start` or `/platform/build` → saved session basket → account setup | `BranchSelectionTest` covers inclusion filtering, validation, persistence and isolation of intent from access. A saved basket is not a paid cart. |
| Assisted onboarding | `/platform/request-access` → `/platform/access-request` | Existing access-request workflow remains available. Staff agreement and billing operations remain separate. |
| Account / first workspace | Auth / verified account → `/workspace/create` → tenant workspace | Google account creation requires the configured provider and auto-provision setting; otherwise the saved basket leads to assisted intake. First-login provisioning creates the existing base workspace and records module interests, without starting billing. New-account production signup requires a real browser acceptance path. |
| Daily work / setup | `/dashboard`, `/start`, `/workspace/guide` | Existing role and tenant middleware plus canonical catalog states apply. Direct and Shopify workspaces must retain their correct destination surfaces. |
| Public help | Ask Everbranch dialog → `/platform/bud/conversations` | Public help exposes product guidance only, with bounded input, safe text rendering, and durable inquiry handling. It does not read authenticated customer records. |
| Workspace help | Account Help → Bud / support ticket → landlord ticket console | `OnboardingSupportTest` covers durable tickets, dedupe, queued notification, retry boundaries, context and tenant isolation. Provider readiness and queue worker acceptance remain required. |
| Website CMS | `/website` → private draft / editor / preview → publish or rollback | Existing managed website services own media, draft versions and immutable published snapshots. Editor, entitlement, publishing and public-render gates remain independent. |
| Domain connection | Website → Domains → TXT + CNAME → Check connection → Activate | Local domain and Cloudflare tests pass. Activation rechecks ownership, exact DNS target, active hostname and TLS. Owned-domain HTTPS acceptance remains pending. |
| Assisted domains | Website → Domains → Handle this for me → tenant support queue | Stable per-domain dedupe key; no registrar password, registration, DNS mutation or automatic activation. See domain runbook. |
| Customer storefront cart | Published tenant website → products / variants → native cart → checkout | Existing `WebsiteCommerceSafetyTest` covers server prices, stock, payment replay and legacy-order separation. No commerce behavior is expanded in this increment; provider-backed pilot acceptance remains required. |
| Customer payments / fulfillment | Native website checkout → authoritative provider event → order / receipt | Existing payment, signed-webhook, tax, connected-account and tenant rollout gates stay closed until verified. Redirects and bank feeds cannot establish payment. |
| Integrations / imports | Canonical branch setup destinations | Existing provider-specific permissions, readiness, imports and recovery contracts remain authoritative. This redesign does not activate or reconnect providers. |

## Checks and live rollout

Domain acceptance completed locally: 18 tests and 64 assertions across
`ManagedWebsiteCustomDomainsTest` and `CloudflareWebsiteDomainsTest`, including
website overview rendering. DNS and provider responses are fictional; no real
domain, charge, message or publication is implied by these results.

Local browser acceptance passed all 38 protected public scenarios in Chromium
and mobile WebKit, including phone/tablet/desktop widths, contrast, keyboard
navigation, reduced motion, dark system preference, branch selection persistence
and the retained assisted-intake path. All public pages checked returned 200,
with no page errors or horizontal overflow. Both production asset builds and
the package's typecheck/JavaScript regression chain passed. Protected remote CI
and exact-release live acceptance remain separate from these local results.

Before public release, run the scoped public/branch/support regressions, build
assets, inspect desktop/mobile and reduced-motion behavior, and verify every
primary link. Record those actual results with the release rather than treating
this source map as completed live click-through evidence. Keep release changes
behind protected CI, normal Forge deployment and exact merged-SHA `/ready`.

Provider rollout needs an owned test tenant and domain, Cloudflare for SaaS zone
and fallback origin, scoped server token, correct DNS, certificate and HTTPS
evidence, queue worker, approved operator SMS sender, and an explicitly enabled
operator notification destination. The domain walkthrough defaults to DNS-only
`www`; apex/flattened/proxied cases remain assisted. See
`website-domain-self-service.md` and `onboarding-help.md` for configuration.

The end-to-end paid self-service journey remains a separate acceptance milestone:
verified signup, reviewed terms, authoritative subscription payment, auditable
entitlement fulfillment, resumable provisioning, first publication, and recovery
after interruption. Do not label these complete from the visual redesign, saved
branch basket, base workspace creation, or sandbox/source checks alone.

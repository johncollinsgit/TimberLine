# Carolina Barrel connected website

The existing Carolina Barrel Cloudflare/vinext renderer stays at
`https://carolina-barrel-co.theeverbranch.com`. Everbranch owns its editable
content, immutable published snapshots, product catalog, and new inquiries.
The renderer source remains in the separate `carolina-barrel-preview` project.

## Release and activation

1. Release this backend through the normal GitHub test/build gate and Forge.
2. Run `php artisan website:connect-carolina-barrel --actor=1` as a dry run.
   Review its result, then repeat with `--apply` for the approved connection.
   The command pins the existing tenant by slug, requires its existing owner/admin
   membership and editor/publish gates, and does nothing on replay.
3. Deploy the matching renderer to its existing public Worker and private Sites
   preview. The two copies of `content-manifest.json` / `carolina-barrel.json`
   must match. No credentials are embedded in either manifest.
4. Run `website:initialize-connected-catalog --actor=1` (dry run), then `--apply`.
   Verify Website opens page editing and Products opens the separate catalog for
   both authorized accounts. Save/preview a draft and confirm public content stays published.
   Do not create production test inquiries or send welcome messages.

The shared catalog release adds collection and membership tables; no entitlement
changes are required. Previous generic site/page
snapshots remain intact. Activation versions the reviewed live baseline from the
manifest; it does not migrate the unrelated native template into the live design.
Initial connection imports the five quote products into `website_products` and
variants. Subsequent catalog editing is independent; page publishing never changes
product records. See `website-catalog-admin.md`. New quote, wholesale,
and partner requests become `form_submissions` and collect no payment. The anonymous
catalog response omits retail and variant price values, so every product is quote-only.
Carolina Barrel quote requests require name, email, phone, product, quantity, finish, and
notes. They appear in **Orders → Quotes**, where an authorized staff member may
send and record a direct email response. A quote is attributed only when both its
normalized email and phone exactly match a tenant-owned Website customer; when a
later Website order for that customer exists, the quote links to that order. This
never reads or writes legacy orders, Shopify, or marketing profiles. Initial live D1 inspection
found zero applications and zero order requests; retain the D1 tables and existing
referral-click history. Review/archive any superseded native scaffold product
separately rather than removing unrecognized records during activation.

## Editing and preview

`/website` uses the connected page editor after opt-in through
`tenant_sites.settings.connected_renderer=carolina_barrel_v1`. Content is stored in
`tenant_site_versions.settings.connected_content`; Save always creates a new
private snapshot, Publish clones it into an immutable published snapshot, and
Restore creates a draft. Optimistic draft IDs reject stale writes/publishes.
Generic theme/page mutations and legacy catalog imports are blocked for this renderer.
Products, collections, customers and orders use the shared native administration. Other tenant
sites keep the existing editor. Publish audit events retain previous pointer IDs.

The anonymous content endpoint is pinned to this renderer and tenant. It only
returns published page content and the current active catalog unless given an encrypted preview token bound to the
site, tenant, version, active editor and a 15-minute expiry. Preview and editor
responses are private/no-store. The renderer removes incoming preview headers,
sets its internal header from the URL token, preserves it on internal links,
disables forms, and sets noindex/no-referrer. Draft data never enters the public
fallback cache. Public SSR rechecks the backend each request; a previously verified
published snapshot may survive a transient network/5xx outage for at most five
minutes. Explicit public disablement/entitlement failures purge that fallback.

## Gates and rollback

Editor, publishing freeze, tenant rollout, canonical module access, public
rendering, site published status and public_enabled remain enforced. An editor
freeze does not remove the last published public content. Public disablement
blocks the content API and new inquiries. The native renderer refuses to render
legacy pages for a connected site.

Restore a previous connected version in Website, review its private preview, and
publish it. For code rollback retain this backend content contract while rolling
back to a compatible Worker version. Reverting to the old hardcoded Worker also
reverts its independent D1 form behavior and stops content updates; do not do so
silently. To disconnect entirely, use the recorded `connected.imported` event's
before pointers and settings during a coordinated rollback. Preserve all versions
and submissions. Never replace published content with the old generic template.

Validation: `ConnectedWebsiteTest`, existing Managed Website safety/commerce tests,
full backend suite/build, and the renderer's typecheck, lint, build, content-isolation
and inquiry-proxy tests. Confirm the deployed readiness release before activation.

## Carolina Heritage theme selector (2026-10-08)

The connected editor's Website theme selector lists Original, Heritage (Wine & Oak),
and saved designs. A selection preserves the complete previous draft in an
immutable `tenant_site_versions` row with `status=saved_theme`; selecting a saved
row creates a new draft, never edits that row or the public pointer. Saved rows
are scoped to the same tenant/site. `connected_theme` is cloned through Save,
Publish and Restore, and the content API returns the style for the exact public
or signed preview version. Historical rows without the key use Original.

Release the backend through the GitHub/Forge gate and deploy the compatible
Carolina renderer before activating Heritage. Then use the existing authorized
operator with `php artisan website:prepare-carolina-heritage --actor=<id>` for a
transactional dry run and add `--apply` to prepare a draft. Preparation saves both
the prior live design and any distinct draft, uses the prepared maker photos,
and never modifies catalog, checkout, wholesale pricing, or payouts. Replays
with an existing Heritage draft do nothing. Review the private preview and
publish through the normal editor/service; `--apply --publish` supports the
initial authorized one-step activation after renderer verification.

To roll back, select the saved previous live design, preview, and publish. Retain
both renderer layouts; never revert the backend's field/preview contract. The
renderer keeps referral codes on internal navigation and quote requests for the
browser session. Wholesale/affiliate applications still save as inquiries;
automated commissions/payouts and a private wholesale customer login have not
been activated by this theme work.

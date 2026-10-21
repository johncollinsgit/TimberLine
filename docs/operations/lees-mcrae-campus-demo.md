# Fictional Lees-McRae campus demo

Approved public destination: `https://app.theeverbranch.com/platform/demos/lees-mcrae`.
This is one GET/HEAD document on the existing application host, not a college
tenant rollout. Source preparation does not establish deployment; verify the
protected release and exact `/ready` SHA before calling the URL live.

## Isolation

`LeesMcRaeCampusDemoController` serves a fixed self-contained HTML resource.
It resolves no account, user, tenant, entitlement, credential or provider. The
route retains normal host/security middleware, accepts only the configured
canonical landlord host (plus explicit local/testing loopback), and rejects all
query strings. There are no nested API or server mutation routes.

Only this GET/HEAD route excludes session start/error sharing, CSRF-cookie
issuance and terminating scheduler-heartbeat evaluation, following the existing
standalone POS demo pattern. Canonical anonymous and signed-in requests emit no
SQL queries, cookies or business writes. Existing authentication, tenant data,
production Teams/POS access and the Alpha Printing demo remain separate.

The document uses a fresh response nonce on its three script elements and one
stylesheet. Inline style attributes are allowed for the existing Leaflet and
prototype markup. Network connections, form submissions, framing, workers,
fonts and external assets are denied by CSP; map/photo/library assets are
embedded. Responses use no-store/noindex/no-referrer headers. Retained Livewire
assets are flushed for this standalone request without disabling ordinary pages.

All interactions run in a clearly labeled browser simulation. The namespaced
`lees-mcrae-fictional-hosted-v3` sessionStorage belongs to one tab. Refresh keeps
that tab's fictional work; a new independent tab starts from the fixed fixture.
Reset affects only this demo key. This is not server authorization, durable
campus storage or a production identity/department permission model: inspecting
the public source reveals every synthetic role fixture.

## Demonstration scope

Requests have List/Calendar/Map views, a validated submission modal, fourteen
additional campus-crew examples across departments/stages, and one explicit
duplicate-safe Dorm pipe burst incoming action. Assignment links the request,
task and existing fictional J05 job. Operations demonstrates priorities,
transparent weekday capacity estimates, ready workers, active/upcoming project
rosters, contractors, simulated physical-campus IT tickets, illustrative
materials/labor/contractor estimates and budget variance.

The official college campus plan is the basemap; job/van positions are fictional
normalized drawing placements, not GPS. The M8 housing area is conceptual, with
links to the original public 2020 proposal. No surveyed footprint, approval,
funding or current construction status is asserted. Only the pipe-repair job
shows the relevant CC0 maintenance photograph, explicitly stock rather than
incident evidence. Seeded discussions are labeled fictional; session comments
can be added. The guided tour is eight minutes, presenter-paced.

Google Maps, real SMS, live helpdesk/Bouncie, AI generation, email campaigns,
payments and college systems remain disconnected. Do not add credentials or
relax this page's CSP to activate them. Google origin/API permission needs
separate verification. Public Maps/PDF source links open only after a click.
Private presenter pricing, personal data and commercial commitments are absent.

## Reproduction and verification

The reviewed v3 source is `a0e3119ca86a267182e0be5d46cac98a3fd8861f`, preserved
in the original isolated campus worktree and delivered private source archive.
Its offline build SHA256 is
`2c9fd4bf642688097a80f771069f54448143ebd59a33eeacb985b641fa0c295e`.
`scripts/demos/build-campus-hosted.php` accepts only that checksum, changes the
public title/banner/error text and session namespace, and writes the resource.
It never fetches remote HTML, credentials or runtime tenant data.

```sh
php scripts/demos/build-campus-hosted.php /path/to/reviewed/lees-mcrae-campus-v3.html
php vendor/bin/pest tests/Feature/LeesMcRaeCampusDemoTest.php tests/Feature/PointOfSale/PosBrowserDemoTest.php --compact
DEMO_URL=https://app.theeverbranch.com/platform/demos/lees-mcrae DEMO_EVIDENCE=/tmp/campus-live-evidence node tests/e2e/campus-hosted-demo.test.mjs
```

Local actual-middleware tests passed 38 cases / 320 assertions including the
existing POS demo. Actual Laravel browser response passed 58 checks, with zero
CSP/JavaScript errors, actual POSTs, API/provider requests or external assets.
All browser requests were GETs for the one campus document. Review, required
GitHub quality/PHP/MySQL gates, protected deployment and exact-SHA live checks
are still mandatory for release. No migrations, dependencies, flags, credentials
or phone apps are changed by this feature.

Rollback uses the normal reviewed code/retained-release process. No database
rollback or customer-data deletion is needed; browser demo state can be reset
with the page's explicit fictional reset action.

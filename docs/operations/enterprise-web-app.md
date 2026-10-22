# Enterprise standalone web-app foundation

`ENTERPRISE_PWA_ENABLED=false` is the default. This dedicated-runtime feature
publishes generic install metadata and existing Everbranch leaf icons. It does
not install anything on a device, register a native application, create an App
Store submission, bypass sign-in or provision a client. Browser installation
availability and device behavior require their own acceptance checks.

The manifest name is **Everbranch Enterprise**, with standalone display mode,
fixed theme/background colors and actual 192/512 PNG icons. A fixed 180 PNG is
available for an Apple touch-icon link. Icon provenance is in
`resources/images/enterprise-app/README.md`; existing repository branding is
reused rather than inventing campus ownership of a mark.

Manifest `id`, `start_url` and `scope` use only the configured canonical HTTPS
origin and trusted runtime prefix. The trailing slash selects that application
root; the existing authenticated app decides whether to show login or the
current permitted workspace. No tenant ID, recipient, token, identity or access
grant is installed in a shortcut. Distinct origins/mounts have distinct IDs.
Same-origin clients need distinct reviewed mounts; this feature does not create
their routing or grant process/file isolation.

| Dedicated endpoint | Content and boundary |
| --- | --- |
| `GET /enterprise/app.webmanifest` | Generic no-store manifest; no operational schema or session needed, with the global silo guard still enforced. |
| `GET /enterprise/app-icon-{180,192,512}.png` | Fixed reviewed PNG bytes, same-origin resource policy; no arbitrary file access. |
| `GET /enterprise/app-version` | Generic no-store version, manual reload strategy, offline_support=false. |

Endpoints require the full dedicated runtime, enabled flag and exact trusted
origin/mount. Standalone root and full prefixes are supported. Trial-only mounts,
misorigin/scheme/port/base URL, unsupported sizes and query overrides fail closed.
No cookie/session middleware or operational/provider service is resolved by
these routes. The existing global runtime/client-silo guard remains enforced;
a managed client may still require its immutable binding readiness check. Public
sample rendering and retail do not become installable private workspaces.

`AppManifest::links(request())` provides guarded manifest/apple-icon/theme/name
and version metadata for the existing shell. It returns empty when unavailable.
The existing shell and full workflow workspace include accessible installation
instructions and explicit update/reload choices. The UI never refreshes itself;
it checks a no-store current-version response only on request, explains retained
drafts and uncertain receipts/file re-selection, requires a confirmation, and
blocks refresh while any shared private transport mutation is still pending.
Network failure releases the in-flight count without claiming the write failed:
the existing exact retry receipt remains the reconciliation mechanism. Existing
workflow before-unload warnings remain in force. Browser install events are
reported as browser events, not proof of an actual device acceptance test.
This source adds **no service worker, operational cache,
offline write queue, background synchronization or automatic reload**. An internet
connection and the user's current authorized session remain required. A shortcut
is not an assurance of offline functionality or retained file bytes.

The optional nonsecret `ENTERPRISE_RELEASE_ID` becomes `app.release_id`; a safe
1-100 character identifier is returned without filesystem/command lookup. When
missing/invalid/unknown, the version is a deterministic SHA256 over a fixed
allowlist of Enterprise shell/workspace source and the built asset manifest.
It never interprets a request/config value as a path. This fallback tracks that
bounded presentation bundle, not every backend-only change; production release
automation should provide a reviewed release identifier if complete release
identification is required. Version responses are never cached. The client must
wait for active writes and explicitly protect unsent drafts/file re-selection
before a user-approved reload; detecting a new version never authorizes an action.

`EnterpriseAppManifestTest` boots the actual dedicated HTTP kernel without a
schema and verifies root/prefix IDs/scopes, actual PNG dimensions/provenance,
disabled/runtime/trial gates, host/scheme/port/base/query denials, current safe
release versions, deterministic fallback, no operational SQL, no cookies and no
provider calls. The test inspects every SQL statement and permits only the exact
global silo-table existence probe in the unmanaged no-schema fixture. It does
not filter queries out or claim zero SQL. A selected but invalid client runtime
still fails its original global binding guard with 503; no metadata exemption
is added. A dedicated-kernel view fixture also checks guarded head links and
sample/disabled suppression. Physical iPad/device installation, real hosted headers and update behavior
are separate acceptance gates. No installation, deployment or activation is
established by these tests.

Implementation references checked October 22, 2026:
[MDN installability requirements](https://developer.mozilla.org/en-US/docs/Web/Progressive_web_apps/Guides/Making_PWAs_installable)
describe the manifest/icon/secure-origin requirements and distinguish service
workers from installation; [Apple's iPad instructions](https://support.apple.com/guide/ipad/open-as-web-app-ipad8f1f7a29/ipados)
describe Add to Home Screen and Open as Web App. Browser versions and management
policies vary; these references do not replace an actual college-device test.

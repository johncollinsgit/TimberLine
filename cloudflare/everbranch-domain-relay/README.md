# Everbranch public Website domain relay

This ordinary Workers Free script forwards public customer websites to the fixed
`https://app.theeverbranch.com` origin. TLS SNI and HTTP Host remain the app's
hostname, so the origin needs no per-customer certificate or Nginx virtual host.
The matching Laravel `WebsiteDomainRelay` middleware authenticates a dedicated
HMAC envelope before restoring an active custom hostname. The public route guard
then admits only public Website routes for that tenant.

This is source ready for protected release and an owned-domain acceptance test;
it is not evidence of a deployed Worker or working customer domain. Publishing
the checked-in Wrangler configuration leaves the Worker disabled and unrouted.

## Boundaries

- The destination is a constant in `src/index.mjs`; visitors cannot select it.
- Only GET/HEAD with empty bodies and POST to `/website/forms/[positive page ID]`
  are relayed. Form bodies are buffered up to 1 MiB. The app still checks CSRF,
  throttling, the exact page's tenant/site/active host, and normal publishing gates.
- Forwarding, relay, authorization, Cloudflare Access assertion, method-override
  and hop headers supplied by visitors are stripped. The original URL hostname
  is authoritative; a supplied Host or forwarded-host header cannot claim it.
- The envelope authenticates the edge's host assertion, not a visitor. Its short
  validity window does not make public contact submissions one-time operations.
- Upstream redirects are returned without being followed. Requests bypass cache;
  relayed responses and errors use `private, no-store`. Response bodies stream.
- HTTP public reads redirect to HTTPS. Cloudflare certificate-validation requests
  under `/.well-known/acme-challenge/` and `/.well-known/pki-validation/` pass
  through untouched before redirect/signing/readiness checks.
- Apex and first-party subdomains bypass the script unchanged as a fallback
  defense. Direct requests to `sites.theeverbranch.com` return 404; that hostname
  is a SaaS routing target, never an authenticated claim of a customer's host.
- No generic proxy, login, operator/API access, new customer master, commerce
  authority, DNS ownership shortcut, or payment entitlement is introduced.

## Signature contract (v1)

Send exactly these headers to the fixed origin:

| Header | Value |
| --- | --- |
| `X-Everbranch-Website-Host` | ASCII lowercase external hostname, no port |
| `X-Everbranch-Website-Timestamp` | Unix seconds in decimal |
| `X-Everbranch-Website-Signature` | Lowercase hex HMAC-SHA256 |

The UTF-8 HMAC message is the following six values joined by newline, with **no
trailing newline**:

```text
v1
timestamp
UPPERCASE_ACTUAL_HTTP_METHOD
original.customer.host
raw/encoded path and query beginning with /
lowercase_hex_sha256_of_raw_body
```

Preserve URL encoding, duplicate query keys, query order and a bare trailing `?`.
Never decode or sort the signed target or change body bytes. The dedicated raw
secret must contain at least 32 bytes and is not trimmed; generate an ASCII
random secret privately so both runtimes use identical bytes. The app admits a
timestamp aged at most 60 seconds or at most 5 seconds in the future, inclusive.
Both clocks must remain synchronized.

Independent Node/WebCrypto/PHP fixture (fixture key only, never a production key):

```text
secret: fixture-only-relay-secret-32-bytes-long
timestamp: 1794690000
method: GET
host: shop.customer.test
target: /catalog/a%2Fb%25?b=2&a=1&a=%2F
body: empty
signature: 34cd3e6bcb5a49c5f45a1a60e8890f581dde69528af496f03036b07ed29ade64
```

## Preflight and rollout

1. Release the matching application middleware/config/public-route tests through
   protected CI and the normal Forge deployment. Confirm `/ready` is the exact
   release SHA. Keep `MANAGED_WEBSITE_RELAY_ENABLED=false` initially. Keep the
   existing direct custom-domain path and publishing/provider gates intact.
2. Inventory and privately retain the existing zone DNS, fallback-origin and
   Worker route IDs/patterns/script assignments. Preserve existing redirect and
   Forestry Workers. Confirm the account is on **Workers Free**. This script needs
   no Workers for Platforms, paid plan, service binding, storage or dispatch API.
3. Confirm the app origin has a valid certificate for `app.theeverbranch.com`,
   works with Full (strict) TLS and the existing canonical-host guard, and is
   explicitly excluded from this Worker. Never use Flexible TLS or disable origin
   certificate verification.
4. Generate and transfer a dedicated secret through private provider/Forge secret
   controls. Set Worker secret `WEBSITE_ORIGIN_RELAY_SECRET` and application secret
   `MANAGED_WEBSITE_RELAY_SECRET` to the same raw value. Do not commit it, put it
   in a URL/command argument, print it, or reuse the Cloudflare API token/app key.
   Secret names alone may be inspected; secret values must not appear in logs.
5. Run the local checks below. Publish the reviewed script with routes empty,
   `workers_dev=false`, `preview_urls=false`, and Worker variable
   `WEBSITE_ORIGIN_RELAY_ENABLED=false`. Attach no routes until the complete
   exclusion table is reviewed. Wrangler keeps the flag false on subsequent
   deployments unless an explicit reviewed configuration override sets it true.
6. In the SaaS zone, retain or create the reviewed proxied fallback DNS target
   `sites.theeverbranch.com` and make it the active SaaS fallback origin. An
   originless AAAA `100::` record is the documented Worker-only option; switching
   an existing origin record requires checking that no other service depends on
   it. Do not copy or delete unrelated records. Keep custom-host ownership and
   provider certificate activation checks required by the app.
7. Create/check the **scriptless exclusions first**, then attach the following
   relay routes. Preserve all other more-specific existing Worker routes. Apply
   each route by ID/pattern rather than replacing the zone's entire route list.

   | Zone route | Script |
   | --- | --- |
   | `theeverbranch.com/*` | None |
   | `*.theeverbranch.com/*` | None |
   | `app.theeverbranch.com/*` | None (explicit origin recursion fence) |
   | `www.theeverbranch.com/*` | Preserve the existing www redirect Worker |
   | Existing more-specific first-party routes | Preserve their current scripts |
   | `sites.theeverbranch.com/*` | `everbranch-domain-relay` |
   | `*/*` | `everbranch-domain-relay` |

   The exact `sites` assignment is more specific than the subdomain exclusion and
   handles customers whose CNAME is itself Cloudflare-proxied. Cloudflare should
   still expose the original customer's hostname in the request URL. The owned
   probe below must prove this; never substitute a client-supplied host header if
   it does not. A direct `sites` request deliberately stays 404.
8. Set both **relay routes to Fail closed** for request-limit exhaustion. Do not
   call `passThroughOnException()` or configure Fail open. Confirm the Free plan
   and route fail mode again after saving. There is no automatic paid upgrade in
   this runbook. On Workers Free the shared account allowance is 100,000 requests
   per UTC day; exhausted fail-closed routes return Cloudflare error 1027. Every
   relayed asset also consumes requests, so observe usage before broad rollout.
9. Enable `MANAGED_WEBSITE_RELAY_ENABLED=true` through the existing reviewed Forge
   configuration flow; refresh its config using the normal deployment process.
   Set Worker variable `WEBSITE_ORIGIN_RELAY_ENABLED=true` only when the origin is
   ready. The short intermediate outage fails closed. Verify the exact deployed
   Worker version and both gates without displaying secrets.

The dashboard's route table is operational configuration; the repository's empty
`routes` array intentionally cannot install the zone-wide catch-all accidentally.
Do not run an ordinary `wrangler deploy` against a live reviewed route table
without verifying the route diff and explicit enabled-variable override first.

## Owned-domain acceptance (no customer changes or charges)

Use an owned, reversible test hostname already proven eligible by the app. Save
its exact prior DNS and app-domain state first. Do not repurpose a customer's
domain or create a payment to manufacture eligibility.

1. Prove app home, `/ready`, login and the existing Forestry entry/www redirect
   still follow their original routes; no recursive Worker invocations occur.
2. Point the owned hostname at `sites.theeverbranch.com`, complete the exact app
   ownership TXT proof, and let its existing provider checks reach active TLS.
   Test a normal DNS-only customer CNAME, then an owned Cloudflare-proxied CNAME
   where available. Both must render the correct published tenant with valid
   public TLS and Full (strict) origin TLS.
3. Verify `/`, a published page, Website media and optional public shop routes.
   Check encoded paths/queries, redirects and `Cache-Control: private, no-store`.
   Check an unknown/inactive hostname does not render any tenant. A direct
   `sites.theeverbranch.com` request must return 404.
4. Confirm HTTP DCV paths are handled by Cloudflare without a Worker HTTPS
   redirect or an HMAC-readiness rejection. Confirm ordinary HTTP page reads
   redirect to the identical HTTPS customer URL.
5. Check `/login`, `/landlord`, `/workspace/purchase`, `/api`, `/ready` and all
   unsupported writes are blocked on the customer host. Supplying forged relay,
   Host or forwarded headers must not select another tenant or open an app route.
6. With explicit permission for a single fictional public-form submission, obtain
   the customer-host session/CSRF token and submit to its own published page.
   Confirm CSRF still rejects absent/invalid tokens and another site's page ID.
   This step writes only the approved test form; defer it without that permission.
7. Briefly disable the Worker gate on the owned route and confirm a generic 503,
   then restore it. Do not exhaust the real account allowance to test quotas.
   Inspect aggregate status/CPU/usage only; do not log bodies, cookies or envelopes.
8. Record the app SHA, Worker version, route/fail-mode evidence and pass/fail
   results without credentials. Remove only the owned test artifacts when done.

Keep activation closed if either CNAME mode fails host preservation, if any
first-party route changes, or if verified public traffic can reach private routes.

## Recovery

For an incident, first disable `WEBSITE_ORIGIN_RELAY_ENABLED` and retain fail-closed
routes; customer relay traffic then stops without falling through to an unverified
origin. First-party exclusions and existing Workers remain unchanged. Disable the
application relay gate after edge traffic is stopped. Preserve site snapshots and
domain records. Restore a previous Worker version only with the same signature
contract, gates and route exclusions. Restore DNS/fallback configuration only from
the saved reviewed state; removing the catch-all alone can expose an unusable
origin path. Rotate a compromised shared secret on both sides with the gates
closed, then repeat the owned-domain checks. Keep source and runtime evidence
separate; a passing unit test never establishes live TLS or SaaS routing.

## Local checks

From the repository root:

```sh
node --test cloudflare/everbranch-domain-relay/relay.test.mjs
git diff --check
```

The tests use WebCrypto and independently verify the signature using Node's
`createHmac`. They perform no provider calls. Run the matching PHP relay/domain
tests as part of the same release. An installed, reviewed Wrangler version may
also package this script with `wrangler deploy --dry-run` from this directory;
do not use remote preview or publish merely to validate syntax.

Current provider references, checked 2026-11-14:

- [SaaS Worker fallback origin and route precedence](https://developers.cloudflare.com/cloudflare-for-platforms/cloudflare-for-saas/start/advanced-settings/worker-as-origin/)
- [Workers Free request limits and fail-closed behavior](https://developers.cloudflare.com/workers/platform/limits/#daily-requests)
- [Fetch cache controls](https://developers.cloudflare.com/workers/runtime-apis/fetch/)
- [Cloudflare for SaaS initial setup](https://developers.cloudflare.com/cloudflare-for-platforms/cloudflare-for-saas/start/getting-started/)

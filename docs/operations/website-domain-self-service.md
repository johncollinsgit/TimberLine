# Website domain setup

The Website page keeps the included Everbranch address visible and offers a
guided DNS connection or **Handle this for me**. Assistance uses the existing
tenant support queue with a stable domain-specific dedupe key; it never asks for
registrar passwords, changes email DNS, buys a domain, or activates a website.
The connection request retains its original proof on retries, including after
the domain is live. Removing an unfinished setup retains its audit history.

## Provider configuration

All existing editor, publishing, custom-domain availability, tenant allowlist,
and activation gates remain independent and default closed. Configure:

- `MANAGED_WEBSITE_CUSTOM_DOMAIN_TARGET`: the provider-reviewed DNS target.
- `MANAGED_WEBSITE_CLOUDFLARE_ENABLED=true`.
- `MANAGED_WEBSITE_CLOUDFLARE_ZONE_ID`: the platform SaaS zone's ID.
- `MANAGED_WEBSITE_CLOUDFLARE_API_TOKEN`: server-only scoped token with SSL and
  Certificates read/edit access to that zone. Never place it in browser payloads.

Cloudflare for SaaS and its fallback origin must first be configured in that
zone. **Check connection** verifies the tenant's TXT proof before looking up or
preparing its exact custom hostname. A failed or ambiguous lookup never creates
a replacement. HTTP validation is used for certificate issuance. Provider error
bodies and credentials are not shown to customers.

Activation independently rechecks ownership TXT, the exact DNS CNAME target,
Cloudflare hostname status `active`, SSL status `active`, published site state,
and the activation gate. Ownership alone cannot make a domain live. An existing
unverified hostname cannot resolve another tenant's public site. The Everbranch
address and immutable published versions are unchanged by connection failures.

The guided first release supports a DNS-only `www` CNAME. Apex/flattened/proxied
records require assisted setup and provider review; the UI must not claim those
are automatically supported. No nameserver migration or registrar transfer is
required. Connection checks are explicit and repeatable; there is no background
DNS mutation. Disablement retains the hostname and published snapshots.

## Acceptance

Run `ManagedWebsiteCustomDomainsTest` and `CloudflareWebsiteDomainsTest` using
fictional fixtures. They cover tenant isolation, replay, removed proof, incorrect
routing, pending TLS, failed provider lookup, strict hostname parsing, and
published page host boundaries. HTTP requests are faked. Production activation
still requires an owned test domain proving TXT, CNAME, certificate issuance,
HTTPS rendering, and rollback with the exact deployed release.

The CMS already provides draft editing, media, preview, published versions,
rollback, and native cart inventory/payment controls. This increment adds page,
design, shop, and domain shortcuts and image-based setup design choices; it does
not replace the commerce lane or enable payment/provider gates.

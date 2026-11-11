# Property customer demo

This release serves the existing sanitized, fictional Hi-Lo customer demo through the normal Everbranch Laravel release. It does not create a production property tenant or import an owner directory. The eventual property module belongs to the regular Everbranch tenant architecture, with one ownership organization per tenant and properties inside it.

## Host boundary

`ServePropertyCustomerDemo` is the first global middleware. It compares the actual Host authority against the three literal configured hosts: `property.theeverbranch.com`, `everbranchproperties.com`, and `www.everbranchproperties.com`. It does not use `host_map`, tenant fallback, or forwarded host headers to identify the demo. Requests for other hosts continue through the existing application unchanged.

On a demo host, Laravel serves GET/HEAD requests only from the explicit asset map in `config/property_customer_demo.php`. The only recognized query string is `portal=1` on `/`. Unknown paths return an empty 404 and other methods return an empty 405. The middleware returns before tenant lookup, database, session, authentication, and application routes. Mapping validation and realpath containment restrict reads to the curated `public/property-demo` directory. PHP and arbitrary public files cannot be configured as demo assets.

Forge Nginx can serve an existing physical public file before Laravel through `try_files`. Therefore the PHP allowlist does not claim to restrict the complete Nginx virtual host. Probe representative existing static paths during release verification. Existing public build assets do not grant access to authenticated APIs or protected owner files. Do not change Nginx or create a tenant host mapping as a shortcut.

## Demo behavior and data

The HTML contains the source marker `everbranch-demo-source=63695ea04f4becddb6bde95a4e392d2c1120a69b`. The copied bundle is an explicit asset selection from the published public customer demo. No private Site source, environment, database, user upload, real contacts, API routes, or R2 objects are included.

All demo changes and sample uploads stay in the visitor's browser in IndexedDB. Preview roles simulate Vicki, an owner, and a maintenance member. They are not authentication. Payments, email, SMS, and rentals do not call providers. Reset demo clears the fictional browser workspace. Browser-only uploads are limited to supported image/PDF/text formats, 2 MB each, 8 MB total, and 20 files. Photo cards, square file previews, entry popouts, and sticky stay comment composers are retained.

Responses carry no-store, nosniff, no-referrer, noindex, and a CSP allowing the bundled app, Google font assets, and browser-local image/file previews. No session cookie is created. Do not enter real owner information in this public demonstration.

## Release and verification

Use the README_FOR_AGENTS workflow: focused branch and PR, local PHP/style/isolation tests, all required checks on the exact PR head (`quality`, `ci (8.4)`, and `mysql migration recovery`), merge, then the protected GitHub Actions to Forge deployment. This global middleware change requires the full validation tier. Do not hotpatch Forge, bypass CI, or use direct push-to-deploy.

Verify the merged SHA at `https://app.theeverbranch.com/ready` and observe deployment success. Then verify demo host TLS, source marker, assets, empty 404s for `/login` and `/api/demo`, 405 for a write, and unchanged regular tenant behavior. Inspect signed-out desktop and mobile layouts, navigation, and a browser-local demo interaction. The public demo upload interaction was not verified; private authenticated preview upload tests do not establish this production check. Physical phone installation is also unverified.

Domain registration in Cloudflare does not establish that DNS records, the Forge domain aliases, or certificate coverage are ready. Inspect existing records and certificate coverage before making those claims or changing DNS. The configured host allowlist is application readiness only.

Rollback uses a normal reviewed revert and protected deployment. No schema migration, production data cleanup, DNS deletion, or tenant deletion is needed for the application rollback.

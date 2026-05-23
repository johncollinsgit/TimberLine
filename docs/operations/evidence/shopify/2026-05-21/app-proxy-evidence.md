# App Proxy Evidence

Date: 2026-05-21.
Status: captured partial live storefront evidence; Partner Dashboard configuration screenshot remains pending.

Related operator docs:
- `screenshot-manifest.md`
- `operator-checklist.md`

## Expected Configuration

| Item | Expected value |
| --- | --- |
| App proxy URL | `https://app.theeverbranch.com/shopify/marketing/v1` |
| Prefix/subpath | `apps` / `forestry` |
| Dev store | `modernforestry.myshopify.com` |
| Primary storefront observed | `theforestrystudio.com` |

## Commands Run

| Command | Status | Result |
| --- | --- | --- |
| `curl -I --max-time 15 https://app.theeverbranch.com/shopify/marketing/v1` | captured | `HTTP/2 404`; base proxy root has no route. |
| `curl -sS --max-time 15 ... https://app.theeverbranch.com/shopify/marketing/v1` | captured | `http_code=404`, Laravel 404 page returned. |
| `curl -I --max-time 15 https://app.theeverbranch.com/shopify/marketing/v1/health` | captured | `HTTP/2 401`; route exists and rejects missing Shopify app-proxy signature headers. |
| `curl -sS --max-time 15 ... https://app.theeverbranch.com/shopify/marketing/v1/health` | captured | JSON response: `unauthorized_storefront_request`, reason `missing_signature_headers`. |
| `curl -I --max-time 15 https://modernforestry.myshopify.com/apps/forestry/health` | captured | `HTTP/2 301` to `https://theforestrystudio.com/apps/forestry/health`. |
| `curl -sS --max-time 15 ... https://modernforestry.myshopify.com/apps/forestry/health` | captured | `http_code=301`; redirects to primary domain. |
| `curl -I --max-time 15 https://theforestrystudio.com/apps/forestry/health` | captured | `HTTP/2 200`, `content-type: application/json`. |
| `curl -sS --max-time 15 ... https://theforestrystudio.com/apps/forestry/health` | captured | `http_code=200`; JSON health payload returned through Shopify storefront app proxy. |

## Captured Response Summary

Direct canonical route without Shopify app-proxy signature:

```json
{
  "ok": false,
  "version": "v1",
  "error": {
    "code": "unauthorized_storefront_request",
    "message": "Storefront request signature validation failed.",
    "details": {
      "reason": "missing_signature_headers"
    }
  }
}
```

Storefront app proxy health via primary domain:

```json
{
  "ok": true,
  "version": "v1",
  "data": {
    "transport": "ok",
    "state": "unknown_customer",
    "runtime": {
      "app_proxy_enabled": true,
      "has_signing_secret": true,
      "has_app_proxy_secret": true,
      "contract_version": "v1"
    }
  },
  "meta": {
    "auth_mode": "app_proxy",
    "integration_mode": "shopify_app_proxy"
  }
}
```

## Remaining Evidence

- Partner Dashboard app proxy screenshot is still pending.
- A screenshot or browser capture from the dev-store storefront is still useful.
- This evidence does not prove install/reinstall or Partner Dashboard settings by itself.

PR 19 screenshot slot:
- `10-app-proxy-health-primary-domain.png`

Expected screenshot:
- Browser at `https://theforestrystudio.com/apps/forestry/health`.
- JSON shows `ok=true`, `app_proxy_enabled=true`, and `integration_mode=shopify_app_proxy`.
- Operator note records that `https://modernforestry.myshopify.com/apps/forestry/health` redirects to the primary domain.

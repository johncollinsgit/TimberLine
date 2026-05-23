# Privacy Webhook Delivery Evidence

Date: 2026-05-21.
Status: pending operator approval.

Related operator docs:
- `screenshot-manifest.md`
- `operator-checklist.md`

## Local Readiness

Local code and tests already prove:

- Privacy webhook routes exist for `customers/data_request`, `customers/redact`, and `shop/redact`.
- HMAC verification uses the raw request body and `X-Shopify-Hmac-Sha256`.
- Valid local test payloads create `shopify_privacy_webhook_events` records.
- Invalid or missing HMAC requests are rejected and do not create records.
- Records store minimal summary and hashes rather than full raw sensitive payloads.
- Events default to `manual_review_required` / `action_required=true`.
- No destructive deletion/redaction is automated.

## Live Delivery Status

| Topic | Endpoint | Status |
| --- | --- | --- |
| `customers/data_request` | `https://app.theeverbranch.com/webhooks/shopify/customers/data-request` | pending_operator_approval |
| `customers/redact` | `https://app.theeverbranch.com/webhooks/shopify/customers/redact` | pending_operator_approval |
| `shop/redact` | `https://app.theeverbranch.com/webhooks/shopify/shop/redact` | pending_operator_approval |

No live webhook trigger was run by Codex in PR 18.

No live webhook trigger was run by Codex in PR 19.

## Why Pending

`shopify app webhook trigger` sends real HTTP requests to the configured endpoint and may require the Shopify app client secret to generate a valid HMAC. This PR must not use or expose secrets, and should not trigger production/staging webhooks without operator approval.

## Exact Commands To Run Later

```bash
shopify app webhook trigger \
  --client-id 197d01d6597c938c96b3b35fae6a087c \
  --api-version 2026-01 \
  --topic customers/data_request \
  --delivery-method http \
  --address https://app.theeverbranch.com/webhooks/shopify/customers/data-request

shopify app webhook trigger \
  --client-id 197d01d6597c938c96b3b35fae6a087c \
  --api-version 2026-01 \
  --topic customers/redact \
  --delivery-method http \
  --address https://app.theeverbranch.com/webhooks/shopify/customers/redact

shopify app webhook trigger \
  --client-id 197d01d6597c938c96b3b35fae6a087c \
  --api-version 2026-01 \
  --topic shop/redact \
  --delivery-method http \
  --address https://app.theeverbranch.com/webhooks/shopify/shop/redact
```

If prompted for a client secret, retrieve it from a secure secret manager. Do not commit or paste it into this repository.

## Evidence Query

After each trigger, capture a sanitized query result:

```sql
select topic, shop_domain, status, action_required, handled_at, reviewed_at
from shopify_privacy_webhook_events
order by id desc
limit 10;
```

PR 19 screenshot slot:
- `11-privacy-webhook-event-row.png`

Capture only sanitized row evidence. Do not store raw sensitive webhook payloads.

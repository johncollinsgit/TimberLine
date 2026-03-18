# Tenant Access Boundary (Step 9A)

## Membership model
- Membership is stored in `tenant_user` (`tenant_id`, `user_id`, optional `role`).
- `User::tenants()` and `Tenant::users()` are the canonical membership relations.

## Runtime resolution
- Middleware alias: `tenant.access`.
- Resolver: `App\Services\Tenancy\AuthenticatedTenantContextResolver`.
- Resolution order:
1. explicit tenant token in request (`tenant` / `tenant_id` query, optional tenant headers)
2. session `tenant_id`
3. first tenant membership

- If no valid membership is found:
  - when tenants exist -> request is denied (`403`)
  - when no tenants exist yet -> middleware allows legacy access

## Boundary enforcement
- `tenant.access` is applied to high-risk Backstage routes:
  - Marketing Customers
  - Marketing Providers/Integrations
  - Shopify customer sync health page
- Middleware blocks:
  - store-key access outside current tenant scope
  - route model access where `tenant_id` is missing/mismatched

## Controller scoping
- `MarketingCustomersController` now scopes index/data/duplicate matching by current tenant and enforces tenant checks on profile actions.
- `MarketingProvidersIntegrationsController` now scopes Shopify sync health and key profile/link analytics by current tenant.

## Implementation note for new internal pages
- Add `tenant.access` middleware.
- Read tenant context from request attributes:
  - `current_tenant`
  - `current_tenant_id`
- Apply `forTenantId($tenantId)` on tenant-owned models.

# Everbranch Tenant Boundary Audit

Status: PR 1 audit document.

## Mission

Prove tenant safety before self-service signup, imports, module installation, or billing activation are broadened.

## Current State

- Global host middleware resolves landlord, tenant, or none before auth.
- Canonical runtime hosts are enforced.
- Landlord host is `app.theeverbranch.com`.
- Tenant hosts are `<slug>.theeverbranch.com`.
- Legacy hosts should be edge redirects only and are rejected by runtime.
- Landlord routes are host-locked and protected by `landlord.operator`.
- Module access should use canonical tenant module services.

## Risk Areas To Inspect Further

- Any fallback to first tenant when host, session, or request tenant is unclear.
- Query helpers that become unscoped when tenant id is null.
- Mutations that trust client-provided tenant, module, store, host, or channel identifiers.
- Legacy user-to-tenant backfills that may grant wider membership than intended.
- Support/impersonation flows, if enabled.

## Pass Criteria

- Unknown hosts return 404 and do not resolve any tenant.
- Legacy runtime hosts return 404.
- Landlord routes are inaccessible from tenant hosts.
- Non-landlord users are forbidden on landlord host routes.
- Tenant-facing mutations resolve tenant scope server-side.
- Search, dashboards, modules, and navigation are permission-aware and tenant-scoped.

## Fail Criteria

- Unknown host falls back to Modern Forestry or the first tenant.
- Client-provided tenant id controls a mutation without server-side validation.
- A landlord page renders on a tenant host.
- A tenant can read or mutate another tenant's records.

## Exact Areas For Follow-Up

- Tenant resolver and host resolver middleware.
- Authenticated tenant context resolver.
- Query scopes and helpers that accept nullable tenant ids.
- Route groups that are tenant-facing but not protected by tenant access middleware.
- Store-to-tenant mapping in Shopify and import flows.

## Recommended Next PR

Add focused tenant-boundary tests around fallback behavior, nullable tenant scopes, and the highest-risk tenant-facing mutation routes. Do not change behavior without a failing test that proves the risk.


# Everbranch UI Continuity Audit

Status: PR 1 audit document.

## Mission

Make Everbranch feel like one professional product without destabilizing Modern Forestry or rewriting the UI in the readiness phase.

## Current State

- The shared UI system is documented in `docs/ui/UI_SYSTEM.md`.
- Shared styles live in `resources/css/forestry-ui.css`.
- Layout ownership is documented in `README_FOR_AGENTS.md`.
- Product surfaces exist across public pages, auth pages, tenant pages, landlord pages, and Shopify embedded pages.
- Branding still uses Backstage, Forestry Backstage, Forestry Studio, and Modern Forestry in places that should become Everbranch or tenant-specific.

## Gaps

- The product brand is not consistently expressed as Everbranch.
- Modern Forestry tenant-specific language is sometimes used like platform language.
- Public, tenant, landlord, and embedded pages do not always share the same hierarchy, copy tone, button treatment, empty states, or form patterns.
- Some pages feel operational or technical rather than calm and human.
- Mobile app readiness is not represented visually as an Android/iOS setup concept.

## Proposed Style Standard

- Everbranch is the product/platform brand.
- Modern Forestry is the flagship tenant and should remain tenant-specific.
- Use clear nouns in navigation: Home, Setup, Modules, Integrations, Customers, Settings.
- Use shared shell/layout components before adding page-local UI.
- Prefer calm, high-trust SaaS UI: readable spacing, clear table states, useful empty states, and direct copy.
- Keep cards for repeated items, modals, and framed tools only.
- Do not add large inline styles to shell/layout files.

## Pass Criteria

- New UI work uses shared tokens/components and updates `docs/ui/UI_CHANGELOG.md`.
- Brand copy distinguishes platform and tenant.
- Empty states tell users what to do next.
- Pages remain responsive and readable.

## Fail Criteria

- A UI change rewrites broad shells without focused scope.
- Backstage or Modern Forestry platform language expands into new Everbranch surfaces.
- A UI change hides guardrails, billing-disabled status, or tenant context.

## Recommended Next PR

Add a small brand-label inventory and centralize obvious product display names where safe. Avoid route changes and broad redesign.


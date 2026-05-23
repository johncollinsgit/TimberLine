# Everbranch Web App Readiness Audit

Status: PR 1 audit document.

## Mission

Find the rough edges that make the app feel unfinished to a small business owner, then sequence polish without feature sprawl.

## Current State

- Many tenant and embedded workflows exist.
- Navigation and dashboards are capability-aware in several areas.
- Setup/onboarding pages exist but are not yet the clear front door for a new business.
- Error/empty/loading states vary by surface.
- Public and auth surfaces still need brand coherence.

## Biggest Rough Edges

- Product identity feels split between Everbranch, Backstage, Forestry, and Modern Forestry.
- Too many useful pages feel like separate ideas rather than a guided product.
- Self-service setup does not yet clearly answer: what do I connect, what is next, what is blocked, and what is optional?
- Some pages are technical/operator-first where tenant owners need plain-English next actions.
- Mobile is not represented as a future Android/iOS setup path.

## Polish Priorities

1. Brand and navigation consistency.
2. Setup progress and empty states.
3. Integrations/import readiness clarity.
4. Module App Store detail and safe request states.
5. Landlord dashboard readability.
6. Mobile readiness copy and status.
7. Error pages and validation messages.

## Pass Criteria

- Pages explain the next action in human language.
- Empty states are useful.
- Forms have clear validation.
- Mobile/responsive states are checked for shared pages.
- No polish change weakens tenant, module, Shopify, or billing guardrails.

## Fail Criteria

- UI polish hides important disabled/blocked states.
- New pages are added without route ownership.
- Copy implies unavailable billing, generic mobile, or connector automation is live.

## Recommended Next PR

After PR 1, make a focused brand/navigation coherence pass on one shared shell or one small page group. Update UI changelog if UI changes are included.


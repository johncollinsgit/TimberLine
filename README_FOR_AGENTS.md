# START HERE

Read `SYSTEM_SNAPSHOT.md` before making changes.

This repo has important architectural and deployment realities that must be understood first:
- Laravel backend is the canonical system of truth
- Shopify theme repo is separate from backend repo
- Production deploys from GitHub `main`, not from local dirty branches
- Storefront features typically use signed app-proxy endpoints
- Customer identity must reuse canonical marketing identity models
- Do not create parallel systems for reviews, rewards, wishlist, or identity

Before building anything:
1. Audit existing models, services, controllers, routes, migrations, commands, and views
2. Confirm whether the work belongs in backend repo, theme repo, or both
3. Confirm whether the code is only local or actually deployed
4. Reuse existing architecture before inventing new systems

## Current Priority TODOs

### Immediate launch goal
- [ ] Launch Candle Cash tomorrow in a way that is visibly working on the live storefront and in the Laravel admin/backstage system
- [ ] Confirm the full customer-facing reward loop is functioning end to end:
  - earn behavior occurs
  - reward state updates correctly
  - customer-visible storefront state is accurate
  - admin/backstage visibility reflects the same truth
- [ ] Keep Growave-replacement parity in place for the launch-critical features already being replaced, so the program can be observed working in a real customer flow

### Current execution priority
- [ ] Finish/verify the launch-critical Candle Cash flow before expanding scope
- [ ] Prioritize operational visibility over new feature invention
- [ ] Prefer concrete verification of live behavior over additional architecture changes

### Next task after Candle Cash launch
- [ ] Get email working correctly
- [ ] Audit the current email flow end to end:
  - what sends
  - what triggers sends
  - what provider/config is in use
  - where failures or gaps exist
- [ ] Make email operational enough to support the customer/reward workflow after launch

### Platform direction (important but NOT current focus)
- [ ] Expand beyond Shopify into a general small-business operating system
- [ ] Support in-person onboarding for non-Shopify clients
- [ ] Allow flexible data models per business type (example: lawn care company storing:
  - customer
  - property photos
  - service history
  - plants installed / materials used)
- [ ] Ensure the system can adapt to different verticals without creating separate systems per industry
- [ ] Keep Laravel backend as the canonical system of truth across all verticals

### Scope discipline for agents
- [ ] Stay inside the current priority scope unless explicitly told otherwise
- [ ] Do not start broad multi-tenant refactors yet
- [ ] Do not start Shopify App Store packaging yet
- [ ] Do not expand into speculative AI automation work yet
- [ ] Reuse existing Candle Cash / marketing / identity architecture before creating anything new

### Operating principle
- [ ] The immediate business goal is not abstract architecture progress
- [ ] The immediate goal is a working, visible, revenue-adjacent customer system:
  - storefront
  - admin/backstage
  - email follow-through

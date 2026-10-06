# Landlord Prospect Acquisition

## Classification and boundary

- **Capability:** core Everbranch platform capability for the internal landlord/operator sales workflow.
- **Scope:** landlord-global prospect records before conversion. A prospect may reference `converted_tenant_id` only after the existing guarded tenant-creation flow succeeds.
- **Commercial model:** internal operator tooling. It does not install a tenant module, change entitlements, start billing, or depend on Shopify.
- **Canonical contracts reused:** landlord prospect and communication records, append-only landlord operator audit records, the existing tenant conversion flow, the existing Google Places transport/configuration, and existing landlord host/operator middleware.
- **Non-Forestry behavior:** trade-agnostic. HVAC, electrical, plumbing, roofing, landscaping, and other service companies work without Modern Forestry or Shopify state.

## Workflow

1. An operator runs a bounded Google Places search with a visible estimated API cost.
2. Results are deduplicated by Google Place ID and retained as prospects. Missing website data is a research signal, not proof that a business has never owned a website.
3. The operator reviews the public evidence, confirms fit, and creates an evidence-informed draft.
4. Drafts never send automatically. The operator may open the draft in their mail client, then explicitly mark it sent or log another channel.
5. Replies, calls, meetings, notes, next follow-up, and stage changes remain on the prospect timeline.
6. Only the existing guarded conversion action creates a tenant workspace.

## Outreach and compliance guardrails

- Google Maps/Places data is public research evidence and must be rechecked before outreach.
- No scraped personal contact data, inferred private email addresses, autonomous cold email, or cold SMS is added.
- SMS is not a prospecting channel here; existing consent, opt-out, provider, and delivery readiness rules remain unchanged.
- “No website” means the reviewed Maps/Places result supplied no website URL at the recorded verification time.
- Templates are starting points. They use observable facts, one relevant Everbranch outcome, and one low-friction question. Operators must personalize and approve every message.
- Engagement should be measured against Everbranch’s own reply and meeting history instead of treating third-party benchmarks as guarantees.

The template structure follows current prospecting guidance to use a specific reason for contact, observable personalization, one relevant value proposition, one low-friction call to action, and follow-ups that add a new angle:
https://blog.hubspot.com/sales/sales-prospecting-email-templates-you-can-start-using-today

## Discovery cost and failure behavior

- A run is limited to one Google Places text-search request and at most 20 returned places.
- The UI shows the configured estimated request cost and requires explicit operator acknowledgement.
- Failed runs are recorded with their error and do not change existing prospect stages.
- Re-running the same search refreshes provider evidence for exact Place-ID matches without overwriting operator notes, communication history, or lifecycle status.

## Visual source

The operator page uses “Two construction workers review plans on a tablet” by alan boyce under the Unsplash License:
https://unsplash.com/photos/two-construction-workers-review-plans-on-a-tablet-BNWApafHwgI

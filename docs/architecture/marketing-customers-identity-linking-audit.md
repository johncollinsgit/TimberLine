# Marketing Customers Identity Linking Audit (2026-03-11)

## Scope
Audit of canonical customer matching/linking behavior used by Customers index/detail and sync pipelines.

Primary code paths:
- `App\Services\Marketing\MarketingProfileMatcher`
- `App\Services\Marketing\MarketingProfileSyncService`
- `marketing_profiles` + `marketing_profile_links`

## Current matching rules
1. Exact email match
- Uses `normalized_email`.
- Single exact match => auto-link to existing profile.
- Multiple exact matches => identity review.

2. Exact phone match
- Uses `normalized_phone`.
- Single exact match => auto-link to existing profile.
- Multiple exact matches => identity review.

3. Exact email + phone conflict
- If email and phone each match a different profile, sync creates a pending identity review (`email_phone_conflict`).

4. Trusted source-link precedence
- Existing `marketing_profile_links` for incoming `source_type/source_id` are checked first.
- If source-link points to profile A but matcher resolves profile B, sync opens review (`source_link_vs_match_conflict`) rather than auto-merging.

5. Missing email/phone behavior
- If identity has no normalized email/phone, sync can still create/update canonical profile when source is trusted.
- Trusted channels/types include Shopify, Square, Growave, wholesale, event, manual (`shopify_*`, `square_*`, `growave_customer`, `wholesale_*`, `event_*`, `manual_customer`, `internal_customer`).

## Why this preserves canonical architecture
- Canonical person record remains `marketing_profiles`.
- External systems are attached via `marketing_profile_links` and optional enrichment (`customer_external_profiles`).
- Growave snapshots decorate profiles; they do not control profile existence.

## Duplicate/merge risk edge cases
1. Shared phone number (family/business)
- Multiple people with one phone can trigger ambiguous phone matches.
- Outcome today: review queue, not auto-merge.

2. Email typo + no phone
- New typo email with missing phone can create a second canonical profile.
- Mitigation: source-link reuse and identity review for source conflicts.

3. Square identities without email/phone
- Square customer/order/payment rows can create source-linked canonical profiles even without strong identity.
- Risk: later Shopify/Growave identity may map to a different profile and require review.

4. External identifier reassignment
- If a provider reuses/changes customer IDs unexpectedly, source-link ownership conflict is raised.
- Outcome today: review queue (`source_link_owned_by_other_profile`), no destructive relink.

5. Manual imports with sparse identity
- Manual/internal source links can create profiles with limited contact data.
- Risk: duplicate profiles until additional identifiers arrive.

## Operational recommendations
1. Monitor `marketing_identity_reviews` volume and reason distribution weekly.
2. Prioritize unresolved reasons:
- `source_link_vs_match_conflict`
- `source_link_owned_by_other_profile`
- `email_phone_conflict`
3. Add merge assistant tooling for reviewers before enabling aggressive auto-merge behavior.
4. Keep normalization strict (lowercase email, normalized phone) before expanding fuzzy matching.

## Current confidence statement
The current pipeline favors safety over aggressive auto-merge: uncertain identity cases are routed to review rather than silently collapsing profiles.

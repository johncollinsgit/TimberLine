# Shopify Embedded Messaging: Email Composer Design Note

Date: 2026-04-04

## Current state
- The Shopify embedded messaging page (`resources/views/shopify/messaging.blade.php`) used a raw `Template HTML` textarea as the primary email editing path.
- Preview HTML was client-generated only and not represented by a structured block model.
- Send APIs accepted subject/body but had no first-class section JSON model.

## OSS options evaluated

### 1) Easy Email (React + MJML)
- Repo: https://github.com/zalify/easy-email-editor
- License: MIT
- Activity snapshot: pushed `2026-02-27` (GitHub API)
- Pros:
  - Strong block editor feature set.
  - MJML compatibility focus.
  - Mature ecosystem and active repo.
- Cons:
  - Heavy integration footprint for this Blade + inline-JS surface.
  - Requires larger React/MJML embedding strategy and lifecycle control.
  - Custom Shopify block behavior still requires adaptation.

### 2) EmailBuilder.js (Waypoint)
- Repo: https://github.com/usewaypoint/email-builder-js
- License: MIT
- Activity snapshot: pushed `2026-02-09` (GitHub API)
- Pros:
  - Block-based model aligns with target UX.
  - TypeScript-first and modern.
  - Good fit for custom blocks (e.g., Shopify product).
- Cons:
  - Still a substantial framework jump for current embedded page architecture.
  - Adds new runtime/dependency complexity for one page.

### 3) GrapesJS + grapesjs-mjml
- Repo: https://github.com/GrapesJS/mjml
- License: BSD-3-Clause
- Activity snapshot: pushed `2026-04-02` (GitHub API)
- Pros:
  - Powerful editor primitives and plugin model.
  - MJML rendering path is available.
- Cons:
  - Most heavyweight option here.
  - Higher risk of overbuilding for operational messaging needs.
  - Custom UX simplification for non-technical admins is extra work.

### 4) Mosaico
- Repo: https://github.com/voidlabs/mosaico
- License: GPL-3.0
- Activity snapshot: pushed `2025-08-22` (GitHub API)
- Pros:
  - Purpose-built email template editor.
- Cons:
  - GPL-3.0 licensing is a blocker for this project.
  - Integration and customization cost is high relative to desired UX.

## Recommendation
Build a lightweight custom section composer in the existing page stack (implemented in this change).

Why:
- Fastest path to ship a simpler UX now without moving the page to a new frontend runtime.
- Keeps generated HTML conservative and deterministic.
- Lets us define exact product-specific block behavior for Shopify.
- Preserves backward compatibility with legacy raw HTML mode behind an advanced toggle.

## Implemented section model

```ts
type EmailSection =
  | { id: string; type: "image"; imageUrl: string; alt?: string; href?: string; padding?: string }
  | { id: string; type: "product"; productId?: string; title?: string; imageUrl?: string; price?: string; href?: string; buttonLabel?: string }
  | { id: string; type: "button"; label: string; href: string; align?: "left" | "center" | "right" }
  | { id: string; type: "text"; html: string }
  | { id: string; type: "divider" }
  | { id: string; type: "heading"; text: string; align?: "left" | "center" | "right" }
  | { id: string; type: "spacer"; height: number };
```

## Data flow
- Source of truth in UI: structured `email_sections` JSON + `email_template_mode`.
- HTML is generated from sections server-side via `ShopifyEmbeddedEmailComposerService` for preview/send consistency.
- Legacy compatibility: `email_template_mode=legacy_html` with `email_advanced_html` supported (hidden behind advanced toggle).
- Send metadata now stores template mode + section snapshot for traceability.

## API changes
- Added product lookup endpoint:
  - `GET /shopify/app/api/messaging/products/search`
- Extended messaging payloads (group preview/send and individual send):
  - `email_template_mode` (`sections` or `legacy_html`)
  - `email_sections` (array)
  - `email_advanced_html` (string)

## Backward compatibility
- Existing legacy template behavior remains available in advanced mode.
- Existing body/subject-only email sends continue to work.
- No schema migration required; template model is persisted in delivery metadata.

## Follow-up opportunities
- Persist reusable tenant-level email templates from section JSON (not only per-send payloads).
- Add drag-and-drop ordering.
- Add richer Shopify product selection (variants, compare-at price, badges).
- Add safe "convert legacy HTML to sections" helper for simple templates.

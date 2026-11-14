# Everbranch public experience

The public site uses a white canvas, restrained forest accents, rounded photo cards,
and a subtle watercolor mountain edge. It should explain Everbranch with examples
and clear actions rather than long headings. Customer websites retain tenant branding.

## Structure and behavior

- `platform.promo` has a split photo hero, the interactive demo shared on LinkedIn,
  a Customers / Work / Growth / Websites capability guide, business examples,
  a short Customer Loop invitation, and the existing interactive workflow story.
- Both guides enhance ordinary anchor links into keyboard-accessible tablists.
  Arrow keys, Home and End change the selected tab. Capability tabs support deep
  links, including the public Websites navigation link. Without JavaScript, all
  sections and their destinations remain available.
- Capability cards use the canonical public module payload, so hiding a branch in
  the catalog also hides its guide card. The guide does not grant entitlements.
- Ask Everbranch is available from the hero and persistent public launcher. It
  uses the existing server-owned public conversation endpoint.
- Plans and contact share the same public navigation and restrained typography.

## Visual tokens

Inter is the interface typeface; the official wordmark stays artwork. Primary
ink is `#183e32`, secondary ink `#496154`, working surfaces white, and supporting
surfaces `#f5f7f1`. Photo and capability cards use 20px corners. Public surfaces
retain a light color scheme under dark system preferences without changing a
customer's workspace preference.

The four guide images are responsive WebP derivatives of existing Everbranch
photos, with decorative alternative text because adjacent content supplies their
meaning. The hero's first photo loads eagerly; remaining photos load lazily.

## Motion source

Editable source: [Everbranch branch connection motion](https://www.figma.com/design/hQexZ4imrDqGI05FUNuhnz?node-id=1-2).

The Figma file contains editable Inter text and four branch cards with native
opacity and translation keyframes. Exported `everbranch-branches-motion.mp4`
is a two-second, 960px-wide MP4. Its static poster is the same frame's resting
state. The public guide reveals the player only when the user opens “See how it
connects”; it has controls, no autoplay, and `preload="none"`.

Tab transitions last 200ms and become static under reduced motion. The existing
hero/story controls retain their reduced-motion behavior.

## Validation

Public Playwright coverage exercises guide keyboard navigation, deep links,
demo destinations, responsive layout, contrast, dark preference, reduced motion,
and the existing story dialog/workflow interactions. PHP coverage checks public
routes, canonical pricing, and existing contact submission behavior.

# Public-site media runbook

The Everbranch public marketing site may use only owned footage, licensed
stock, or customer material with written approval. It is never a source for
tenant/workspace screenshots, customer names, orders, contact data, or Shopify
data. Product walkthrough captures use demo or anonymized data.

## Preparing media

- Keep an original, rights-tracked source outside `public/`. Commit only the
  compressed derivatives needed by the page.
- Create responsive image derivatives with:

  ```bash
  npm run media:optimize-public -- path/to/approved-source.jpg everbranch-story
  ```

- The hero may use a small crossfading still-image sequence. Keep the first
  image eager with `fetchpriority="high"`, use no more than three scenes, and
  retain a static first frame when JavaScript or motion is unavailable. Disable
  the sequence entirely for `prefers-reduced-motion`.

- For essential muted loops, produce WebM first and H.264 MP4 fallback with
  deterministic FFmpeg settings. Include a poster image, `playsinline`, and
  `preload="metadata"`. Do not autoplay sound.
- Non-hero video must be lazy-loaded. Any sound-on film needs visible captions,
  a transcript, and a static fallback before it can replace the current story
  dialog.

### Everbranch story film

- The public story is a 90-second, silent-first Remotion render at
  `public/media/everbranch-story.mp4`, with a poster image beside it. The
  homepage dialog does not attach the source until a visitor opens it.
- `remotion/` owns the scene plan, source composition, design tokens, and
  replaceable narration script. It uses fictional, public-safe product data;
  never replace those visuals with customer or tenant captures without the
  appropriate approval and release evidence.
- Pexels photo provenance for the story is recorded in
  `docs/operations/everbranch-story-photo-sources.md`. Keep any replacement
  assets rights-tracked, public-safe, and free of customer or tenant context.
- The separate `/story/field-notes-7c8b` page is deliberately unlinked and
  `noindex`. It is a link-only surprise that uses the official YouTube embed
  after four seconds of self-produced intro. It opens with an explicit
  sound-on start control for mobile; the page still exposes a one-tap sound
  fallback if a browser blocks delayed embedded audio. It must not download,
  transcode, or host third-party music-video footage.

## Release checks

- Run `npm run build`, focused public feature tests, `npm run test:visual`, and
  `npm run test:lighthouse:public`. Lighthouse blocks accessibility or layout
  instability regressions and warns before performance/SEO budgets are crossed.
- Review desktop Chrome and mobile-Safari baselines with reduced motion enabled.
- Confirm the access-request form still posts to `platform.access-request` and
  that Plans, Modules, Contact, and Login retain their public routes.
- Public-site work must not change Shopify apps, embedded apps, tenant websites,
  authenticated workspace UI, pricing source data, or access-request handling.
- Interactive marketing demos must use fictional, public-safe data and remain
  entirely client-side. They may illustrate a website, workspace, email, or
  text workflow, but cannot send, store, publish, resolve a tenant, or call
  Managed Website, Shopify, customer, messaging, or marketing APIs.
- Industry-example pages must retain their contextual top control bar: return
  to Everbranch, business-type switcher, and Website / Operations workspace
  view control. Views switch immediately; a short entrance transition may not
  delay controls or content.

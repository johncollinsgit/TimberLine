# Public campus photographs and slideshows

The standalone public Lees-McRae browser demo uses relevant illustrative photographs
on campus priority cards, task boards and lists, task/job details, initiative rows,
operations projects, contractor scope cards and known request rows. Compact lists
use 64×48 thumbnails, project covers use 170px images, and task board images use
125px covers. Map markers and date grids retain their existing compact controls.
People, financial metrics and file-access controls do not receive unrelated photos.

These are labeled stock examples, not photographs of actual college work, workers
or incidents. The enlarged viewer includes the photographer, source and license.
The reviewed source catalog is `resources/data/campus-media.json`; ten Pexels JPEGs
are checked in under `resources/demos/campus-media/`. The existing CC0 plumber
photo is reused from the original embedded demo asset. Binary checksums, byte
counts and a 1.6MB new-photo budget are verified during embedding. The ordinary
demo response remains self-contained; no photo-provider requests, credentials,
new CSP allowances or backend storage are introduced.

The gallery uses only currently projected rows. Contractor imagery derives from
visible assigned work. Unknown/custom records have no invented photo association.
Persona changes retire the viewer, clear photo state and discard the previous
viewer contents. No business record, attachment, receipt or browser session state
is written by gallery browsing. Existing forms, DOM nodes, handlers and drafts are
preserved. The shared observer inserts gallery nodes without a workspace reload.

Multiple photos have previous/next controls, a counter, keyboard navigation and an
expanded dialog with optional play/pause at four-second intervals. Playback starts
only on request and stops on close, manual navigation or page hiding. Escape closes
the photo dialog and returns focus, including when a task editor is underneath.
Single-photo items omit slideshow controls. Touch swipes work in the viewer.

After changing assets, mappings, gallery JS or CSS, run:

```sh
php scripts/demos/embed-campus-search.php
node --test tests/e2e/enterprise-campus-media.test.mjs
node tests/e2e/campus-media.browser.mjs
```

The search embed command includes `embed-campus-media.php`, so the existing pinned
offline build also embeds the same assets and gallery hooks. Repeated embedding
is byte-identical. It uses marker offsets to handle the full multi-megabyte media
block without a PCRE backtracking limit. Browser evidence covers desktop/phone,
decoded photos, slideshows, nested dialogs, unsaved edits, row sizing, current-scope
retirement, business-state preservation and response network policy. Normal
Enterprise CI runs the contracts and the browser scenario in its components lane.

For the public hosted acceptance, set `CAMPUS_MEDIA_URL` to the existing demo URL
and `CAMPUS_MEDIA_EVIDENCE` to an external evidence directory. The hosted checker
identifies only the already-observed Cloudflare edge analytics beacon as an edge
request; the demo still blocks its RUM connection. No application CSP is relaxed.
Release follows the protected GitHub-to-Forge process. Verify `/ready` and the
actual served document and interactions before describing the update as live.


## Public link preview (October 30)

The public demo controller adds Open Graph/Twitter metadata for the fixed public
campus share image `/images/demos/lees-mcrae-share-v1.jpg` (1200×630 JPEG). It uses
an actual Lees-McRae campus photograph, the official college wordmark and the
existing transparent Everbranch leaf. The photograph was retrieved from
https://www.lmc.edu/_images/full-width-impact/about.jpg via the college's About
page on October 30, 2026. The optimized source and self-contained layout are
retained under `resources/images/demos/`. Logos and photo belong to their
respective owners. The PNG favicon is the existing transparent brand mark,
embedded as a data URL to preserve the public demo's self-contained browser
network policy and CSP. The old opaque site-wide icon is unchanged for other
applications. No browser script, fictional state or immutable private edition
is changed. Social previews require a deployed image accessible without login;
source/render checks do not prove that Messages has refreshed its cached card.
Existing sent previews can remain cached by their messaging app.

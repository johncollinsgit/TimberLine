# Campus map and month calendars

This is a source presentation follow-up. Release, hosted acceptance and real
campus account grants remain separate operations. All example staffing and work
are fictional; the UI does not infer real staff locations or activate providers.

## Work calendar

Requests and Projects → Calendar display a seven-column, six-week month with
Previous, Next, Today and a month picker. The fixed `month=YYYY-MM` navigation
field survives reload and browser Back/Forward, alongside current search, area,
view and selection. Literal task/request due dates are not converted through the
browser timezone. The campus current date uses America/New_York.

Project events come from the current allowed task projection. The compatibility
fallback reads only currently allowed requests linked to each visible project;
it does not scan other projects or grant department access. Duplicate native task
IDs render once. An empty project uses its recorded project due date or appears
under Unscheduled. Each work card shows the current assignee and opens the same
native work detail dialog. A bounded result renders at most 500 current events;
source truncation remains visible in Calendar information.

## Official source plan

The existing official-campus-plan.png is reused from the existing source image
DOM element. No duplicate base64 asset, mapping API, remote tile, CSP expansion or
paid provider is added. All 68 marker positions are printed map-number label
centers normalized from page 1 of the exact official parking PDF, not geographic,
surveyed or live people positions. The JSON source records the document URL,
page, dimensions and SHA-256 and is explicitly part of CampusReviewManifest.

A work marker requires an explicit saved building ID and version matching a
current same-workspace building returned by the authorized Buildings DTO. The
DTO's map number and exact official PDF source URL must also match the source
plan. Free text, arbitrary coordinates, supplied reference labels, missing/stale
versions and foreign IDs cannot create campus markers. A building marker opens
its filtered jobs; a job opens the normal current-permission detail. Unmapped
work remains available as a clearly separate list. A workspace transition clears
old job names and pins before checking the next authorized read.

Buildings → Tickets uses actual saved-ID links in the current authorized
workspace result. Zero means no tickets in that result, shown concisely. Exact
text matches stay in a closed separate section and are never counted as links.
The 20-job demonstration dataset and the same-area building DTO are authored in
the separate job/queue packet. This packet does not invent or seed production
relationships and does not expand current department read authority.

## Resource calendar

The sidebar Calendar uses the same month controls and grid for authorized room
and asset reservations. These timed bookings stay separate from task due dates.
Saved UTC instants and immutable history remain unchanged. Displayed days are
Eastern, and intervals are half open: a booking ending exactly at Eastern
midnight does not occupy the next day, including the 25-hour fall DST day.
Reservation conflicts retain their explicit cancellation/resolution controls.
Empty months still show the complete grid; read/write and stale/version checks
remain on the native resource transport.

## Acceptance

Normal Enterprise CI registers the pure date/provenance tests. Native isolated
HTTP browser checks cover Work Calendar, source-map markers and filtered jobs,
Buildings ticket links, sidebar Calendar, UTC/Eastern boundaries, current-scope
refusal, immediate stale-map clearing, URLs/history and empty months in Chromium
and WebKit. Existing Resources and Navigation browser scenarios remain registered
and exercise their real transport; their synthetic map fixture now creates
explicit same-area source references instead of assigning GPS to the campus plan.
Phone/tablet results are viewport simulations, not physical-device acceptance.

Root integrates the source packets, updates the combined orientation documents,
and runs normal exact-final-head review/build/checks before a normal GitHub-to-
Forge deployment. No source fixture result establishes hosted acceptance.

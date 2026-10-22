# Enterprise briefing and PDF

Source candidate, October 22, 2026. This is a permission-scoped workspace
briefing, not evidence of complete campus activity or a live SIS/ERP feed.

`Briefing::build` resolves current tenant membership and department access,
then authorizes every project and linked task through WorkGraph. Unhomed tasks,
discussion backing tasks, other departments and unreadable records are excluded.
Requests reuse RequestWorkflow::view, including the assigned task's current ACL.
Authorization precedes result caps. Counts describe the checked sample: up to
100 readable projects, 1,000 readable linked tasks, and 100 readable requests
from a 1,000-request scan. Display and PDF show up to eight projects and four
requests. Multi-project tasks count once overall and in each visible project's
progress. Archived projects remain explicitly labeled. Date-only overdue checks
use the application date; timestamp deadlines compare actual instants.

GET `/enterprise/briefing?area_id=...` computes current record facts without a
provider call. POST `/enterprise/briefing/refresh` accepts `area_id` and a stable
UUID `request_id`; the separately budgeted Intelligence service may prioritize
only exact supplied record references. It receives a bounded field allowlist,
never notes, files or mailbox bodies. Displayed counts, prose and links remain
server-generated. Current evidence and permissions are rebuilt after provider
processing; a changed evidence digest refuses the old selection. A 503 provider
failure yields an explicit fresh record-derived fallback. A model selector cannot
invent claims, URLs or actions. Volatile timestamps are omitted from provider
idempotency input. Validated selection reorders the displayed records and returns
priority items with server-computed reasons, such as a count of overdue tasks.
An uncertain provider outcome retains its reserved maximum cost and request key;
the response distinguishes this from an unavailable connection. The UI must keep
an uncertain key for reconciliation and must not create a replacement paid
request automatically. No summary is stored globally or reused across users.

GET `/enterprise/briefing.pdf?area_id=...` regenerates a new one-page Letter
snapshot from current authorization and records. It does not reuse a previous AI
selection or spend model credits. The PDF says it is a current record summary,
contains an as-of timestamp and server-owned source links, and explains its
bounded coverage. Downloads are private/no-store and require the signed-in
context every time. A downloaded file remains an as-of snapshot; refreshing or
downloading again creates a new snapshot. It cannot become a perpetually live PDF.

The renderer writes a fixed PDF text layout with built-in fonts, conservative
width bounds, quoted content strings and URI annotations restricted to HTTP(S).
There is no HTML interpreter, JavaScript, file import, external asset fetch or
model-authored link. Titles transliterate to the built-in Latin font; the footer
states this limitation. Max-page rendering was checked with Poppler: exactly one
612 x 792 point page, no JavaScript, readable at 120 dpi including maximum rows
and a pathological wide-character title. There is no claim of tagged-PDF screen
reader conformance; the accessible HTML home screen supplies the same records.

`enterprise.briefing_enabled` defaults off. Trusted deployment configuration
`enterprise.sample_data` controls the prominent sample-data label; request input
cannot relabel real records as fictional or fictional records as verified live
college data. Test/render fixtures are explicitly synthetic and do not create
real campus organizations or users. Model/provider, scanner and mailbox readiness
are independently reported; source tests do not establish live delivery or AI
provider acceptance.

# Structured operational period exports

This is a reusable factual export, not an asserted Andrew-specific reporting
template, fiscal-quarter policy, compliance report or historical reconstruction.
It is independently default-off under `enterprise.reports_enabled`. A current
organization manager or selected-workspace coordinator can preview and download;
operational writes may remain paused. Existing tenant, department, project and
task authorization is rechecked for every generated download.

The user explicitly selects one workspace, optionally a project, and either a
calendar year/quarter or inclusive local start/end dates (at most 366 days).
Timezone is required and validated. The UI may show America/New_York initially;
the service never infers a fiscal calendar. Local midnight boundaries convert to
UTC half-open event windows, including daylight-saving transitions. Observation
also stops at the actual server generation timestamp. The period state explicitly
distinguishes future, in-progress and closed windows; no future events are claimed.

The JSON separates current project/task/request facts from recorded actions in
the selected window. Current records remain current even if updated outside that
window. Event titles are explicitly current titles. Native `task.complete` records
do not distinguish completion from reopening, so no historical completion totals
or quarter-end states are inferred. Project updated timestamps are unknown when
the canonical source does not store one. Deleted or currently inaccessible records
are not reconstructed. This is not complete campus or quarterly activity.
Current task status reflects native completion rather than operational checkpoints;
the sources are read during generation, not reconstructed as an atomic old snapshot.

Only bounded current fields and allowlisted action/time/version metadata are
exported. No raw audit before/after payloads, descriptions, comments, files,
certificates, insurance, financial/cost records, private contractor evaluations,
mailboxes or external feeds are included. Authorized titles remain user-entered
content; this export does not classify, sanitize or anonymize their meaning.
Sample labeling comes from trusted server configuration, never request input.

Output limits: 100 projects, 500 tasks, 200 requests from at most 2,000 scanned
requests. History checks the latest 10,000 native actions for included records and
up to 2,000 request revisions in the selected window; at most 1,000 merged events
are returned. Source bounds, matching counts and omissions accompany both JSON
and CSV. Permission filtering precedes displayed-record limits.

GET `/enterprise/reports/quarterly` previews JSON. The `.json` and `.csv` variants
download freshly generated snapshots, with private/no-store and nosniff headers.
Required query: `area_id`, `mode`, `timezone`; `calendar_quarter` uses `year` and
`quarter`, while `date_range` uses `start_date` and `end_date`. Optional `project_id`
must be currently readable in the selected workspace. Client-provided rows,
sample labeling and as-of overrides are ignored. No provider or model is called.

CSV uses explicit metadata/current-record/recorded-event row types. Every row
retains generation/window/timezone/sample/coverage provenance. Spreadsheet formula
prefixes, including leading Unicode whitespace/control characters, are neutralized
with a leading apostrophe; standard CSV quoting preserves delimiters and newlines.
JSON retains inert original text. Downloaded files remain snapshots; downloading
again refreshes sources and permissions. A saved file cannot revoke itself later.

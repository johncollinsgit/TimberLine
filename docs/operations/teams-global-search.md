# Everbranch global search and command palette

Source feature, default off in native Teams. Deployment and runtime activation are separate.
The public campus demo embeds the same first-party palette and relevance implementation;
it remains a fictional browser simulation with `connect-src 'none'`.

## Experience and contract

The centered 560px search opens with click, typing or Ctrl/Cmd K. Eight initial
results expand through View all results. Arrow keys choose, Enter opens and Escape
closes without clearing the query or replacing drafts. Mobile uses a full-screen,
keyboard-contained search dialog. Type, project/area, person and active/closed
filters apply across the workspace independently of the current list or page.
Empty queries show current-access recent references and available commands.
Recents contain at most eight opaque references in per-user/workspace sessionStorage.

`GET /team/search` uses the existing current-tenant resolver, TeamWorkAccess,
viewer/workspace response binding and a separate 240/minute authenticated limiter.
Inputs are `q`, `type`, `project`, `person`, `state`, `limit`, opaque `cursor`,
optional bounded `recent[]`, or `ref` to revalidate a direct selection.
Results have a stable typed `id`, title, safe plain-text snippet, context, status,
source and typed navigation target. Cursor identity includes the viewer, workspace,
query and filters. Responses are private/no-store. No result counts include hidden work.

Native records include projects, tasks, current descriptions/custom fields/comments,
project update history, ready file names/metadata and current workspace members.
Connected Operations records use the existing safe display fields, current area,
financial, assignment, connection ownership, selected project and generation checks.
Raw account notes suppressed by the existing presentation stay suppressed in search.
Gmail plain-text copies require a generation-attested successful existing sync;
HTML and file bytes are never executed or extracted. Historical snapshots without
that attestation stay hidden until an ordinary authorized source refresh. Rebuilding
search never invents source authority. Typing never calls a provider. Opening a
connected operation keeps the existing record workflow's provider verification.

Commands open existing forms; they do not save or send anything. Supported native
commands are navigation, Create task, Create project and Create record in an
authorized area. The campus demo offers navigation and Submit request. The shared
command catalog is `resources/data/team-search-commands.json`. Existing unsaved-work
guards apply before navigation or creation. Old per-view filters remain available.

## Index and lifecycle

`SearchIndex` owns tokenization, prefix retrieval, bounded fuzzy candidate retrieval
and relevance ordering. Token lookups are tenant-keyed HMACs. Display payloads are
encrypted at rest, including native work. Only kind/reference, closed state and
update time accompany the lookup index. No plaintext copy of encrypted source text
or unkeyed dictionary is created. Exact IDs, exact titles and title prefixes use
an indexed ranking path; ambiguous/fuzzy/body matches use authoritative full ranking.
Every returned row is reconstructed from current permitted canonical data. Private
project fields are removed before matching, highlighting, pagination or facets.

Native graph changes index within the canonical transaction. Operations model saves
and deletes update their index transactionally; sync snapshots record generation
attestation separately from immutable raw evidence. Failed writes cannot publish
uncommitted results. Source disconnects and grant changes take effect on subsequent
reads without waiting for index cleanup. Personal directory names are read live.

Index schema version is `SearchMatcher::VERSION`; bump it when token semantics change.
Rebuilds use the same workspace lock order as canonical writers. Each committed
batch checkpoints source and last ID in `team_search_runs`. Repeating a completed
run is a no-op; `--restart` reconciles again, including physically removed records.
Rebuilds never contact providers or change canonical records.

MySQL stores normalized JSON. New native revisions hash that stored representation
so their checksums survive a round trip. Existing immutable revisions are never
rewritten: unverifiable historical updates stay excluded, increment the durable
rebuild warning count and make the command exit unsuccessfully for review.

## Rollout and rollback

1. Apply the reviewed additive migration through normal migration/release gates.
2. Keep `TEAM_WORK_SEARCH_ENABLED=false` and enable the independently default-off
   `TEAM_WORK_SEARCH_INDEX_ENABLED=true` before backfilling. This keeps new writes
   indexed while the UI stays hidden. Backfill each eligible workspace:
   `php artisan team:search-rebuild WORKSPACE_ID --restart --batch=100`.
   For a bounded maintenance window add `--max-batches=10`; rerun without `--restart`
   to resume. Completion is recorded in `team_search_runs.completed_at`; require
   `warnings=0` before activating the feature.
3. Verify native role/source isolation and browser behavior, then enable the feature
   through the existing reviewed configuration/release process. Keep background
   indexing enabled through cutover so there is no write gap.
4. Existing `team.search` structured logs report duration, state read, matching,
   presentation time, returned row count and continuation state. They contain no query,
   source body or result title. Watch existing request errors, pending rebuild checkpoints
   and source-sync health alongside latency. Native writes and their index commit together.

Rollback sets both search flags false and restores the existing view filters.
Keep index and source-attestation tables/columns; no customer-data deletion is needed.
Do not run the migration's down method as a production rollback.

## Verification and deterministic demo build

`npm run test:team-search` runs shared relevance fixtures, existing campus scope/race
tests and native global-search authorization/lifecycle cases. The ordinary Teams
suite covers all existing work and draft behavior. `TEAM_SEARCH_BENCHMARK=1` enables
the opt-in 10,000-document response benchmark. Use representative workspace content;
the budgets are local matcher p95 under 50ms and native search p95 under 200ms.

`npm run test:team-search-browser` checks the embedded desktop/phone demo with CSP and
network recording. `tests/e2e/team-native-search.browser.mjs` uses only the existing
disposable fictional fixture server and verifies native commands/draft retention.
The tests use `DEMO_BROWSER_EXECUTABLE` and `SEARCH_EVIDENCE` to select a browser and
external evidence directory. Browser assertions verify accessibility semantics and
keyboard behavior, not physical screen-reader certification.

After changing shared assets, run `php scripts/demos/embed-campus-search.php`.
The command is deterministic and idempotent, preserves the existing demo document
and embeds local CSS/JS/catalog assets. The pinned v3 `build-campus-hosted.php` also
uses it and includes a legacy shell adapter. No CDN, external script or provider
permission is added. Verify the reviewed diff and exact deployed release before
describing either the source changes or demo URL as live.


## Prepared matcher hot path (October 31; source candidate)

Repeated warm searches compare individual source strings before rebuilding a
combined signature. Normalized combined text and compact identifiers are cached
per document, and compact query text is prepared once per query. Source-field
changes, including mutable values with string conversion, still invalidate the
prepared document; caller-parsed query objects remain supported. Ranking,
filters, typo rules and the existing 50ms p95 budget are unchanged.

The unchanged synthetic 10,000-document benchmark blocked the Pay release on
Forge at 50.4ms and 55.0ms. This candidate passed all 14 matcher checks at 44.3ms
in a private synthetic test directory on that same host; no live application
source or business records were changed for the measurement. The shared campus
copy was regenerated through the existing embed command. Protected release and
served-artifact verification still precede any live-performance claim.

# Buildings workspace source update

UI source candidate against release `058d0bfeb46b0127733913e9e878fcbeded2b1e2`.
This does not establish deployment or hosted acceptance.

## Workflow

Buildings has a left master list with immediately filtering **Search buildings**.
A selected building has an adjacent ticket queue and separate detail panel.
iPad portrait and mobile use three steps with Back controls that retain search
and building. Desktop and wider iPad landscape show adjacent panels.

The official map is initially collapsed below the workflow. **View source map**
opens it with the selected building's name and recorded map number. Its image,
zoom controls and directory remain intact. No map position or pin is invented.
The official catalog and saved workspace locations remain distinct. A native
saved-location deep link selects the saved directory. Existing metadata, custody,
reservation actions and permissions remain. Ticket detail is read-only;
**Open request & available actions** returns to the existing request screen.

## Linkage prerequisite

Current `EnterpriseController::command` validation and `RequestWorkflow` support
free-text `data.location` and optional coordinates. Neither accepts or persists
a verified request `location_id` / `resource_id`. Creating a task does not create
that relationship. Verified building open/urgent counts therefore cannot be
populated by normal current intake.

Master-list counts use only explicit IDs already present in the current permitted
request projection. Without links it says **Ticket links not established**, never
a fabricated zero. Counts describe the loaded result, not all building activity.

Exact trimmed, case-insensitive location-name matches appear separately as
**Possible location matches**. They are labeled unverified on the card and detail
and excluded from master counts and linked-ticket priority summaries. Similar
strings and other workspaces are excluded; no fuzzy matching or alias inference.

A separately reviewed backend increment must resolve a saved location in the
current tenant/department, retain its stable ID through validation, permissions,
exact retries and audit, define human review of existing free text, and test
cross-tenant/department, revoked access and concurrent changes. This UI patch
does not authorize any backfill, new links or grants.

## Fields and limits

Cards show existing task priority, request status, projected assignee name and
recorded task/request due date. Open records sort before closed records, then
urgent, high, normal, low and unknown; due date breaks ties. Missing priority says
**Priority not set**. Missing names and dates remain explicit.

Private data still uses the current Enterprise projection: up to 100 readable
requests from a bounded 1,000-request scan, including more-results and incomplete
scan notices. No extra record/provider endpoint is added. Reload/reset clears
stale panels before another result arrives.
The detached map is hidden, collapsed and cleared on reset, feature loss, missing
scope, pending reads and denied reads; only a fresh authorized Buildings result
can show its current selection. Late responses from a former scope cannot reveal it.

## Tour and copy

**Find building work** opens Buildings and highlights its real search field.
It is appended to preserve saved priority indices. Private navigation requires
a current workspace and Resources. The sample COO journey remains read-only;
it establishes no COO login.

Seven existing scenes retain durations and navigation. Main copy gives a short
action and benefit; optional facts and limitations move under **What to know**.
Screen buttons use explicit names. Playback behavior is unchanged.

Before: “Search connects authorized work, locations and accountable departments.
The official location directory keeps uncertain placements visibly unverified.”

After, Buildings priority: “Search buildings, choose a building, then inspect a
ticket beside it.”

Private scene buttons are labeled from their actual first available destination;
existing navigation order remains unchanged. The opening says: “Open the campus
overview to find current work. Use Buildings when you want to start with a location.”

After, COO priority: “See sample workspaces and open a supporting record.”
Optional guidance identifies workspace stages and proposed costs as illustrative.

## Evidence and limits

The local actual-shared-renderer fixture blocks operational SQL and outbound
requests. Chromium/WebKit desktop, iPad portrait/landscape and mobile checks cover
instant filter, truthful counts, separate unverified matches, priority order,
status/assignee/due facts, cross-area exclusion, escaped hostile text, Back/focus,
map context, reload, tour navigation/highlight, concise copy, named controls and
no overflow or JavaScript errors. Pointer and Space-key Play/Pause stop elapsed
time in both engines. This does not reproduce native Safari Computer Use
accessibility clicks or establish that reported live automation issue is fixed.

Existing sample/private tour unit checks and updated browser expectations cover
the added priority step and optional details. No production browser/account/data,
credentials, Forge change or deployment is used. Physical devices, hosted
persistence and complete verified building counts require separate acceptance.
Independent review and normal protected final-commit release gates still apply.

# Asana to Google Calendar Sync

This guide walks you through connecting one Asana project to one Google Calendar.
After setup, dated Asana tasks appear on the calendar automatically. When a task
changes in Asana, the matching calendar event is updated.

## What This Sync Does

- Creates Google Calendar events from Asana tasks that have a due date or due time.
- Updates the same calendar event when the Asana task title, notes, date, or time changes.
- Skips completed tasks when "Skip completed Asana tasks" is turned on.
- Runs automatically on a schedule after it is enabled.

## What It Does Not Do

- It does not sync every Asana project. You choose one project for this workflow.
- It does not sync tasks without dates.
- It does not delete calendar events when an Asana task is completed or deleted.
- It does not update instantly. Most changes appear after the next scheduled check.

## Before You Start

Have these ready:

- The Asana account that can see the project you want to sync.
- The Google account that owns, or can edit, the destination calendar.
- The name of the Asana project.
- The name of the Google Calendar.

Use shared company accounts when possible. For example, use an events or team
calendar account instead of a personal employee calendar.

## Setup Steps

1. Open Everbranch and go to Marketing, then Providers and Integrations.
2. Find the Asana to Google Calendar section.
3. Click Save + Connect Asana.
4. Sign in with the Asana account that can see the source project.
5. Return to the setup page and choose the Asana project from the project list.
6. Click Save + Connect Google.
7. Sign in with the Google account that owns or can edit the destination calendar.
8. Return to the setup page and choose the Google Calendar from the calendar list.
9. Leave the default timing settings alone unless support tells you otherwise.
10. Click Save + Dry Run.

The dry run is a safe preview. It checks Asana and tells you how many events would
be created or updated, but it does not write anything to Google Calendar.

## Turning It On

After the dry run looks right:

1. Check Enable automation.
2. Click Save Setup.
3. Click Save + Run Live if you want the first sync to happen right away.

After that, the sync runs automatically.

## How Updates Work

The first time a dated Asana task syncs, Everbranch creates a Google Calendar
event and remembers the connection between that task and that event.

Later, if the Asana task changes, Everbranch updates the same calendar event. It
does not create a second event for normal edits.

## Good Habits

- Do not manually edit events on the synced Google Calendar unless you are okay
  with Asana replacing those details later.
- Keep the Asana task title clear. It becomes the calendar event title.
- Put useful event details in the Asana task notes. They become the calendar
  event description.
- Give every event task a due date. Tasks without dates are skipped.
- Keep one clear Asana project for the calendar workflow.

## If Something Looks Wrong

Check these first:

- The workflow is enabled.
- Both Asana and Google show as connected.
- The correct Asana project is selected.
- The correct Google Calendar is selected.
- The task has a due date or due time.
- The task is not completed, if completed tasks are skipped.

If a change does not appear immediately, wait for the next scheduled check. If it
still does not appear, run Save + Dry Run and send the result to support.


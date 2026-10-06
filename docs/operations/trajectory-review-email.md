# Trajectory review email

## Behavior

Accounts → Sharing and reminders → Email reminders controls the signed-in recipient's preference in the selected space. Off is the default; household partners and business members do not inherit another recipient's opt-in. Daily and weekly (Monday) reminders run around 9 AM in the selected IANA timezone, observing daylight saving changes. The scheduler checks every 15 minutes during that hour; a healthy database queue worker is required. Jobs delayed past the hour wait until the next scheduled opportunity.

Messages include the total outstanding review count and four newest visible allocated transactions, merchant/category/movement/date and signed USD amount, plus authenticated review/settings links. The queue spans imported history, excludes pending, removed, reviewed, and future-dated rows, and uses LedgerService redaction for shared allocations. The date bounds use calendar dates consistently on SQLite and MySQL. The dashboard may display only its first 250 review rows; the email count includes the entire queue.

No messages go to empty queues. Each opted-in recipient can receive one message per space per local day or week. The same outstanding queue can be reminded about in the next period. Changing timezone/frequency never clears an existing claim. Preferences, counts, claims and results use encrypted Trajectory events, without storing transaction bodies in queue payloads or delivery events.

## Authorized pilot setup

After normal protected CI/Forge deployment, configure only the recipient who explicitly requested reminders:

```sh
php artisan trajectory:review-emails --user=RECIPIENT_EMAIL --space=SPACE_ID --frequency=daily --timezone=America/New_York
```

Add `--send-now` for an explicitly authorized initial digest. It bypasses the scheduled hour, but preserves access checks, opt-in, empty-queue suppression, and period deduplication. The command prints only preference/provider/outcome labels. Space/user must already exist and have current finance access; the command does not grant access or provision accounts.

Production must use a configured Laravel SMTP, SES, Postmark, Resend or sendmail transport. Log, array and compound fallback transports are rejected to avoid logging private message content or falsely recording delivery. Existing `mail.from.address` is retained with the Trajectory sender name. No new credentials or paid provider subscription is needed when the configured transport is ready.

## Monitoring and retry policy

`php artisan trajectory:status` reports `review_email_provider_ready`, `review_email_uncertain`, and `review_email_unfinished` along with existing financial-source health. Events are `review_email_preference`, `review_email_claimed`, `review_email_accepted`, and `review_email_uncertain`. Result events reference the claim ID in `record_id`. Queue jobs serialize only user/space IDs, have one attempt, and re-read current data and permissions. A recipient-email change disables the old preference until opted in again.

`accepted` is provider handoff, not a claim that the email reached an inbox. Any transport exception is recorded as uncertain without persisting its potentially sensitive message. A crash after claiming but before recording the result appears unfinished after ten minutes. Never delete claims or automatically replay ambiguous sends; inspect the mail provider's delivery evidence first. The next scheduled period can send again. Separate spaces send separate digests; there is no cross-space combined email.

## Disable and verification

Recipients can choose Off in Accounts. Operators can use the same command with `--frequency=off` for an authorized recipient. Disabling Trajectory or the finance space also blocks queued jobs; records remain intact.

Run `php -d memory_limit=1G vendor/bin/pest tests/Feature/Trajectory`, `npm run build`, migration lint, and the normal release gates. ReviewEmailTest covers scope privacy, exact allocated cents, four newest rows/count, empty queues, opt-out and revocation, changed email, future/pending/removed data, provider ambiguity, period deduplication and DST. Render checks cover escaped imported content and both HTML/plain-text links.

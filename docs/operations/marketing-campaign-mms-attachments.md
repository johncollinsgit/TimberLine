# Approved campaign photo texts

The approval-based SMS campaign executor supports one reviewed photo attachment.
Campaign and delivery channel remain `sms`; a delivery with a photo is sent as
MMS and records its requested/actual transport mode in provider metadata.
Campaigns without an attachment retain their existing SMS behavior. The embedded
bulk messaging dispatcher is a separate path and does not consume this schema.

## Prepare and review

Upload a JPEG, PNG or GIF through the tenant's normal media library with channel
`sms` and the campaign's store key. Files use the public disk under
`messaging-media/tenant-{tenant}/{store}/sms/`. The asset must belong to the same
tenant and store as the campaign. An arbitrary external URL is not a selectable
campaign photo.

Set the following reviewed selection on `campaign.target_snapshot.mms_media`:

```json
{
  "asset_id": 123,
  "sha256": "<SHA-256 of the original uploaded bytes>",
  "required": true,
  "prepared_at": "2026-11-13T17:00:00+00:00"
}
```

The example ID and date are placeholders. Use the actual owned asset, checksum
and attachment revision time; never infer an original customer opt-in time from
this review timestamp. The campaign requires a resolved tenant and store.

The normal `MarketingCampaignAudienceBuilder` validates the photo and copies a
canonical selection, URL, MIME and size to each recipient's
`recommendation_snapshot.mms_media`. Other existing audit metadata is retained
for these photo recipients. The normal `MarketingApprovalService` records the
send approval and pins the exact media selection as `mms_media_approval`.
Preparing an unchanged photo preserves its approval. Changing/removing the
photo clears the current approval and requires another review; prior approval
rows remain in the audit history. Already attempted photo recipients are not
rewritten or requeued by preparation.

These are draft-authoring operations, not permission to send. The owner must
review the exact image, message and recipient set before launch. No consent or
opt-out flags are enabled by selecting media or approving a draft.

## Execute through the normal approval path

Use `MarketingSmsExecutionService::sendApprovedForCampaign` after normal owner
approval. The existing tenant, recipient status, SMS consent, suppression and
send-time guards run first. The executor freshly reads the campaign/recipient
media and approval, validates asset ownership and actual file bytes, and requires
the approval-bound selection to match the current image and review revision.
The public HTTPS URL is derived from the owned public storage path; the saved
asset URL must agree. The file's size, checksum and MIME must still match.

A photo request passes `media_url`, `send_as_mms=true` and
`fallback_to_sms_on_mms_failure=false` through the existing `TwilioSmsService`.
Photo delivery does not depend on the text-only cost planner choosing MMS. An
invalid/missing/changed/unapproved image is skipped with
`mms_media_invalid_or_unapproved` before a provider attempt. A provider MMS error
is recorded as a failure; the image is never silently dropped for a text-only
fallback. The delivery's `provider_payload.mms_media` retains the asset ID,
checksum, URL, MIME, size and review revision.

No media selection creates approvals, schedules jobs or sends anything. The
existing runtime enablement, sender readiness, billing and time-window rules
remain in force. Execution `dry_run` is not read-only: it writes simulated send
states, so do not use prepared production recipients for validation.

## Media and sender acceptance

The app's general upload limit can exceed the MMS limit. An MMS image must be at
most 5,000,000 bytes; image plus UTF-8 body must fit that total and the body must
not exceed 1,600 characters. JPEG/PNG/GIF MIME is validated from actual image
bytes. WebP/SVG assets are not accepted by this executor. Prefer a smaller image
with large readable text; carrier image resizing can affect small labels.

Before live acceptance, verify the sender's current MMS capability and that an
unauthenticated public request returns the image bytes with the correct MIME.
Do not use local files, login-protected URLs or sample signed recipient links.
Source tests use fictional records, a fake public disk and fake Twilio HTTP;
they do not establish a real carrier/handset delivery.

Twilio references: [MMS media URL](https://www.twilio.com/docs/messaging/tutorials/how-to-send-sms-messages),
[supported media](https://help.twilio.com/articles/360018832773),
[media limits/resizing](https://www.twilio.com/docs/messaging/api/media-resource).

Focused check:

```sh
php -d memory_limit=1G vendor/bin/pest tests/Feature/Marketing/MarketingCampaignMmsAttachmentsTest.php --compact
```

## Branded HTML in approval-based email campaigns

The matching approval-based email executor now passes `campaign.message_html`
through the existing embedded email composer in `legacy_html` mode and supplies
the result as `html_body` alongside the rendered plain-text alternative. The
existing tenant provider boundary still supplies recipient-specific unsubscribe
links and headers, checks suppressions, and owns delivery. Use inline styles and
absolute public HTTPS image URLs; the normal composer removes script/style tags.
The recipient's subject snapshot takes precedence, followed by the campaign's
configured subject and legacy campaign-name fallback.

When a reviewed recipient has `recommendation_snapshot.email_design.html_sha256`,
changing or removing the saved HTML fails closed with `email_design_changed`
before a provider attempt. Prepare and review the updated design before another
approval. Delivery metadata records `content_format` and the composed HTML hash
before provider-boundary unsubscribe preparation. Email campaigns without HTML
retain their plain-text behavior. No new embedded campaign is needed to deliver
the reviewed design through the existing approval-based email endpoint.

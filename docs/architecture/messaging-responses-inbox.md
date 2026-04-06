# Messaging Responses Inbox

## Purpose

The Shopify embedded Backstage app now exposes a first-class `Responses` inbox beside `Message Analytics`.

This inbox is the operator-facing source of truth for inbound SMS and email replies. It is not just a view of provider logs:
- inbound replies are persisted as tenant-scoped conversations and messages,
- STOP/unsubscribe state is recorded in Backstage channel-state storage,
- operator replies are sent from the inbox and appended back into the thread,
- provider callbacks still update delivery state, but provider dashboards are no longer the only place to inspect replies.

## Storage

New persistence:
- `messaging_conversations`
- `messaging_conversation_messages`
- `messaging_contact_channel_states`

These records are tenant-scoped and store-aware (`store_key`) so the embedded app stays aligned with existing Shopify tenant/store context.

## Inbound SMS setup

Configure Twilio to post inbound replies to:

`POST /webhooks/twilio/inbound`

Behavior:
- validates Twilio signature when `MARKETING_TWILIO_VERIFY_SIGNATURE=true`
- resolves tenant/profile from the most relevant outbound delivery when possible
- persists inbound SMS into the Responses inbox
- records STOP/STOPALL/UNSUBSCRIBE/CANCEL/END/QUIT as SMS opt-out state
- records HELP replies as inbox messages without unsubscribing the contact
- optionally records START/UNSTOP as resubscribe when `MARKETING_MESSAGING_RESPONSES_ALLOW_START_RESUBSCRIBE=true`

Recommended Twilio Messaging Service settings:
- status callback: `POST /webhooks/twilio/status`
- inbound callback: `POST /webhooks/twilio/inbound`
- Smart Encoding: enabled

## Inbound Email setup

Current concrete inbound adapter:
- SendGrid Inbound Parse

Configure SendGrid Inbound Parse to post to:

`POST /webhooks/sendgrid/inbound?token=YOUR_TOKEN`

Required env/config:
- `MARKETING_MESSAGING_RESPONSES_SENDGRID_INBOUND_TOKEN`
- `MARKETING_MESSAGING_RESPONSES_EMAIL_INBOUND_DOMAIN`

Reply threading works best when outbound email uses a reply alias generated from the inbound domain, for example:

`reply+t{tenantId}d{deliveryId}@reply.example.test`

That alias is now used by the embedded messaging send flows and by inbox replies.

Inbound email behavior:
- parses `from`, `to`, `subject`, `text`/`html`, and raw headers
- attempts threading by reply alias first
- falls back to `In-Reply-To` / `References` provider message ids
- falls back again to unique sender identity when possible
- records unsubscribe-like inbound text into email channel state
- classifies obvious out-of-office / auto-reply messages as `auto_reply`

## Embedded API endpoints

Page:
- `GET /shopify/app/messaging/responses`

Tenant-authenticated embedded API:
- `GET /shopify/app/api/messaging/responses`
- `GET /shopify/app/api/messaging/responses/{conversation}`
- `POST /shopify/app/api/messaging/responses/{conversation}/actions`
- `POST /shopify/app/api/messaging/responses/{conversation}/reply`

Supported conversation actions:
- `mark_read`
- `mark_unread`
- `close`
- `reopen`
- `archive`
- `assign_to_me`
- `unassign`

## Operator safety rules

SMS:
- STOP-like replies mark SMS state `unsubscribed`
- the conversation status becomes `opted_out`
- future inbox SMS replies are blocked in both UI and service layer
- a system note is added to the thread for audit visibility

Email:
- unsubscribed, bounced, or suppressed states block inbox replies
- SendGrid delivery events can move channel state to `bounced`, `suppressed`, or `unsubscribed`

## Local / test verification

Focused automated checks:

```bash
php -d memory_limit=512M ./vendor/bin/pest tests/Feature/MessagingResponsesInboxTest.php tests/Feature/ShopifyEmbeddedNavigationTest.php
npm run build
```

Manual checklist:
- send an outbound SMS from embedded messaging
- reply from a handset with a normal message
- reply with `STOP`
- open `Responses > Text` and verify opt-out state + blocked SMS reply composer
- reply with `HELP` and verify the thread stays open
- send an outbound email from embedded messaging
- reply through the configured inbound mailbox/provider
- open `Responses > Email` and verify the thread matches the same customer/conversation

## Current gaps

- SendGrid inbound parse still requires provider-side DNS/mailbox configuration outside this repo.
- Older outbound emails sent before reply aliases were introduced may only thread by `In-Reply-To` / `References` or sender identity fallback.
- Unmatched inbound messages without a safe tenant resolution are logged and ignored rather than being stored in a cross-tenant holding queue.

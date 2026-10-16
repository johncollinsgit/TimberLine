# Phone foundations and fictional practice

October 16, 2026. This increment builds reusable event/routing boundaries and a
local scripted practice surface. It is not a live phone service.

## Implemented boundaries

`CallEventAdapter` produces a versioned, provider-neutral call event from a
verified server-owned account/number identity. `TwilioCallEventAdapter` supports
strict scalar form callbacks signed for the exact canonical HTTPS callback URL,
including every received scalar field. It never falls back to a platform token
or disables validation in testing. The SDK's public signature test vector and
tampered URL/body/account/number tests are recorded in `VoiceProviderRailsTest`.
Repeated/nested form fields and JSON bodies are deliberately unsupported.

No HTTP provider ingress is exposed. A future ingress must resolve tenant/account
and number ownership from durable verified server records, not request tenant IDs,
caller ID or an array received from the browser. The internal identity array is a
service contract, not an authentication token. Persist signed delivery receipts
under a database lock before calling the reducer. Validate proxy/canonical URL
configuration and SDK port/encoding behavior before claiming provider compatibility.

`CallLifecycle` is a pure reducer. Its identity binds tenant, provider, account,
call and simulation mode. Exact repeats have no new transition; a conflicting
duplicate or contradictory terminal event requires review. Older events cannot
resurrect a completed call. Duration stays separate from money: provider charges
and currency remain unknown until authoritative usage reconciliation exists.
The bounded seen-event map is not a durable webhook ledger or a billing system.

`CallRoutingPolicy` validates versioned timezone, weekday/holiday hours, staff and
forwarding targets, and fallback policy. End times are exclusive, DST repeats use
local wall-clock rules, and overnight periods must be split across days. Only
server-supplied available/authorized staff can ring. Duplicate targets are removed;
forwarding to known owned numbers is rejected. No available staff falls back to
an approved receptionist or voicemail. This does not prove global forwarding-loop,
queue, emergency calling, real voicemail or live-transfer acceptance.

## Fictional practice

`EVERBRANCH_AI_ASSISTANT_PHONE_PRACTICE_ENABLED` defaults false. It additionally
requires every assistant gate and a `local` or `testing` environment. Production
cannot expose it even with that flag true. The paired web/native view explicitly
runs a selected scenario; opening the view sends no provider request.

Seven scripted scenarios cover open/closed hours, unavailable staff, declined
transfer, agreed callback, private-data refusal and provider outage. Every scenario
uses fixed Fictional Cedar Works data, fictional public knowledge version 1 and
`FakeCallEventAdapter`. No account token, tenant executive evidence, recording,
call, message, purchase, task assignment or usage charge is produced. A simulation
ID is not a delivery receipt. Call status is always labeled simulated; unknown
provider outcomes and declined transfers do not become successful connections.

The scripts are exercises for the routing/state rails, not LLM dialogue, a security
test of a live receptionist, publication of tenant knowledge or proof of real human
handoff. Callback output is a proposal for review, not a saved commitment.

## Next integration gates

Add durable owned-number/account mappings, port requests, call/session state,
delivery receipts and usage reconciliation with additive migration recovery and
MySQL concurrency evidence. Live adapters need exact action approvals, execution
reservations and uncertain-outcome reconciliation before any dispatch. Emergency
geography, consent/recording, retention, routing recovery and release owners remain
open. Store/native and real provider acceptance are deferred. No credential or
purchase is needed to review this local increment.

Mail connectors remain a separate provider-reliability increment, as required by
repository release separation. This phone increment does not alter SMS delivery,
legacy Twilio credentials, existing mail transport or billing.

Primary protocol references: [Twilio request security](https://www.twilio.com/docs/usage/security),
[Call resource](https://www.twilio.com/docs/voice/api/call-resource), and
[SDK signature test vector](https://github.com/twilio/twilio-php/blob/main/tests/Twilio/Unit/RequestValidatorTest.php).

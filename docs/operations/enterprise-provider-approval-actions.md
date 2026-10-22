# Enterprise provider approval actions — no secrets

October 22 source milestone. None of these configuration/access actions has
executed. The only financial authorization is the shared $5 lifetime synthetic
trial maximum, including any prior provider/acceptance spend. No recipient or
exact outgoing message is approved. All provider switches remain false.

## Service email trial (reviewer needs no mailbox)

Provider adapter: existing owned SendGrid HTTP payload/response construction,
with automatic paid retries disabled. Historical source identifies platform
sender no-reply@theeverbranch.com; this is not current provider verification.
Owner must identify the exact SendGrid account and verified Everbranch sender,
then approve a dedicated least-privilege Mail Send API credential configuration
into the independent Enterprise runtime secret store as
ENTERPRISE_TRIAL_SENDGRID_API_KEY. No shared retail SMTP password is inherited.
A named source account/key or issuance action is still unknown; no key has been
read/copied/created. Approve the exact receiving address and exact HTML/text
preview separately. Destination allowlist belongs to the approved trial reviewer.
Cost reservation uses an approved inclusive maximum per attempt in the shared
ledger; zero/unknown rates, unknown prior spend or expired rates block IO.

## SMS trial

Provider adapter: fixed Twilio Messages SMS-only HTTPS request, no MMS, media,
shortener or blind retry. Owner must identify account SID and an already
registered/consented verified sender number and approve its credential source
and independent destination ENTERPRISE_TRIAL_TWILIO_ACCOUNT_SID / AUTH_TOKEN /
SMS_FROM_NUMBER. No account/from-number was established from tracked source.
Approve the exact E.164 receiving number/reviewer and final GSM-7 body/link;
preview displays segment count and maximum inclusive carrier/provider cost.
No number, registration purchase, recurring plan or delivery is authorized.

## Selected fictional-evidence AI trial

Provider: owned ResponsesTransport to https://api.openai.com/v1/responses;
no provider storage or action tools, only selected fictional fields/source refs.
Historical Avery project is Everbranch AI Branch with shared Forge variable
EVERBRANCH_AI_BRANCH_API_KEY. Its runbook explicitly prohibits reuse for another
integration. No secret was inspected or copied. Owner must identify a permitted
OpenAI project/key source (or separately authorize a new restricted key), model,
current input/output rates and validity, then approve independent configuration
ENTERPRISE_TRIAL_AI_API_KEY / MODEL / rate variables. Maximum input12000/output800
and the single shared $5 campaign ledger bound each attempt; no recurring budget.
Actual account/model acceptance and spend are unverified. Uncertain usage retains
reservation and pauses the campaign; fake responses do not prove live access.

## Optional own-user Gmail (separate from reviewer service email)

Intended first user: john@evergrovesoftware.com. Code is tested; live consent is
pending. Owner must identify a Google OAuth client approved for the independent
Enterprise deployment, approve its client configuration destination
ENTERPRISE_GMAIL_CLIENT_ID / CLIENT_SECRET / exact HTTPS REDIRECT_URI, and approve
the specific account consent. Existing retail GOOGLE_* sign-in credentials and
grants are not inherited. Requested scopes are openid, email and
https://www.googleapis.com/auth/gmail.send; no inbox read permission. Refresh
material is encrypted per user/tenant/workspace. The callback requires the same
signed-in user/session and PKCE/state. Explicit account/client revocation can
invalidate the same Google's consent in other workspace connections; review
that impact before consent/revoke. Sending still needs exact recipient/subject/
body confirmation and separate send activation. No OAuth grant or real mail.

## Runtime, scanner and release

Independent reviewed app origin/database/private storage and an explicitly marked
synthetic trial anchor are prerequisites. Source never provisions real college
organizations/users/memberships. Scanner endpoint configuration requires owner
approval and actual clean/malicious/unavailable acceptance; until then files stay
quarantined. No upload is public. Signed provider delivery-event ingestion remains
outside this bounded slice; acceptance, open and explicit acknowledgment are
separate, and delivery remains unverified.

After source acceptance: dependency PR458 → PR459 → PR461 → reviewer milestone,
required checks/reviews, owner-authorized merge, protected GitHub-to-Forge deploy,
then exact readiness SHA/route/config acceptance. No direct deployment, copied
production records, DNS changes or grant bypass is authorized.

Tour publication has no real email/SMS/AI delivery dependency. Its email is an
in-app preview only. A reviewed source release may publish that isolated tour
while the separate controlled trial stays disconnected and disabled; normal
review, explicit merge/release approval and protected Forge gates still apply.

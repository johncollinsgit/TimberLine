# Twilio tenant account provisioning

Evergrove Software LLC is the company; Everbranch is its platform. Use one parent
account and a separate child account for each tenant, with tenant-specific
numbers, registrations, messaging services, credentials and usage records.
SMS and voice may share a capable number; route SMS to the messaging webhook and
voice to the selected phone service through the voice webhook/SIP.

## Endpoint correction

`POST /2010-04-01/Accounts.json` creates a subaccount.
`POST /2010-04-01/Accounts/{AccountSid}.json` updates that account, including its
friendly name. The former implementation used the update endpoint. A parent SID
stored as a tenant provider ID must be reconciled; it must never be considered a
new isolated tenant account. The new guard rejects that mapping, verifies the
returned child and parent IDs, reserves before the request and blocks automatic
retries after an uncertain result. Credentials remain encrypted.

## Owner-authorized preparation, October 17, 2026

The parent display name was corrected to **Evergrove Software LLC**. Dedicated
active child accounts were created and ownership-checked for:

- **Evergrove Software LLC - Everbranch**, linked to the existing Evergrove tenant.
- **Modern Forestry LLC**, linked as a pending migration to the existing tenant.

Both application records remain `awaiting_business_profile`; neither is marked
ready or used for live tenant sends. No active phone number, campaign, webhook,
Shopify setting or operational customer data was moved or changed. Modern
Forestry's legacy sender remains operational while a verified migration is planned.
Its approved toll-free verification does not follow an API number transfer.
Coordinate the verification plan before cutover; do not move the number first.

The owner separately authorized one demo test to their own mobile using the
existing Modern Forestry sender. The local presenter-only sender is restricted to
that recipient and is not a new tenant fallback. Provider status confirmed delivery.

References: [Twilio subaccounts](https://www.twilio.com/docs/iam/api/subaccounts),
[ISV onboarding](https://www.twilio.com/docs/messaging/compliance/a2p-10dlc/onboarding-isv),
[number/verification migration](https://www.twilio.com/en-us/blog/developers/best-practices/direct-customer-to-isv-rearchitecture-guide).

Validation: provisioning and messaging platform tests: 16 passed, 89 assertions;
Modern Forestry guard: 156 passed, 847 assertions. No live provider requests are
made by these tests.

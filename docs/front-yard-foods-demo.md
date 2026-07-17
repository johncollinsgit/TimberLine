# Front Yard Foods Demo Runbook

## Prepare

Run after migrations are live:

```bash
php artisan everbranch:prepare-front-yard-foods
```

The command reuses tenant slug `front-yard-foods`. For a new tenant it uses ID 4 only when available, otherwise the smallest unused ID greater than 4. It preserves all existing memberships and grants active tenant-admin access to `johncollinsemail@gmail.com`.

It also idempotently prepares the canonical Front Yard Foods agreement draft. By default the first customer Stripe checkout is only the $299 Everbranch onboarding plus the $59/month Everbranch Launch Partner service. Shopify/Square implementation is presented as a separate written quote/work order until an exact amount and payment schedule are intentionally supplied.

If an implementation quote is approved before sending, configure the negotiated implementation price without changing the reusable template:

```bash
php artisan everbranch:prepare-front-yard-foods \
  --implementation-fee=1200 \
  --implementation-due-on-acceptance=600 \
  --implementation-due-before-launch=600
```

After operator review, rotate a password-protected proposal link. The plaintext password is printed once and is never stored:

```bash
php artisan everbranch:prepare-front-yard-foods \
  --send-agreement
```

Do not send until the first-checkout amount, additional scope, exclusions, and implementation phases are approved. If implementation fees are not final, leave the implementation amount blank and collect implementation through a later signed milestone/work order. Electronic acceptance creates immutable evidence, a Stripe-direct subscription authorization, and an authorized billing order. It does not create a charge or activate modules; the customer must separately choose the Stripe Pay action after checkout readiness is enabled.

### Commercial separation

- Shopify Basic: $39/month month-to-month or $29/month effective when billed annually, paid directly to Shopify.
- Everbranch onboarding: $299 one time.
- Everbranch Launch Partner: $59/month for six consecutive cycles.
- Everbranch standard: $149/month beginning with cycle seven.
- Shopify/Square migration and implementation: separate written quote/work order unless an exact amount is intentionally added before sending.
- Out-of-scope software work: $50/hour only after written electronic approval.

### Data-use assurance

The signed agreement and customer proposal page must say plainly that Evergrove and Everbranch use Front Yard Foods data only for the approved migration, setup, support, reporting, security, legal compliance, and client-authorized integrations. Evergrove does not sell Front Yard Foods data, share it with unrelated third parties, or use Shopify, Square, Squarespace, Substack, booking, customer, product, inventory, or file access outside the approved service work.

The proposal must keep four cost buckets visibly separate: Shopify store expenses; third-party apps and services; Everbranch one-time setup and monthly service; and Evergrove implementation services. Stripe is the direct-client processor for Everbranch/Evergrove charges; Relay is the verified payout bank only. Shopify App Store billing remains a separate disabled lane.

It idempotently prepares Customers, Class Scheduling, Field Service, Messaging, and Reporting; six fictional customers with test phone `8646165468`; four classes covering sourdough, vegetable growing, preserving, and edible garden design; four consultation/installation jobs; enrollments; preview reminders; and durable job photos.

## Public and Mobile Surfaces

- Public signup: `/signup/classes/front-yard-foods`
- Internal calendar: `/classes`
- Mobile: Home class calendar, Classes Branch, class detail, attendees, customer detail, message composer, and reminder scheduling

Public registration requires an enabled and visible Branch, published/open class, remaining capacity, and a unique normalized email per class. Capacity is checked inside a transaction. Reminder scheduling requires class consent and an attendee phone or email. Preparation and registration never trigger automatic live delivery.

## Image Sources and Licenses

- Front Yard Foods branding/service imagery: <https://www.frontyardfoods.com/> and <https://www.frontyardfoods.com/services>, used for the authorized customer demonstration.
- USDA ARS apple orchard photograph by Scott Bauer: <https://commons.wikimedia.org/wiki/File:Apple_orchard.jpg>, public domain.
- Tree Planting by Fquasie: <https://commons.wikimedia.org/wiki/File:Tree_Planting.jpg>, CC BY-SA 4.0.

Each downloaded image is stored as a private tenant workspace asset and records its source, license, and demo-purpose metadata.

## Production Verification

1. Confirm the command reports the canonical tenant and can be run twice without changing counts.
2. Confirm John can switch to Front Yard Foods without reauthentication.
3. Confirm every customer uses the designated test number and each job photo is a tenant-owned workspace asset.
4. Confirm the public page lists only published/open classes and rejects full or duplicate registrations.
5. Confirm another tenant cannot read class, enrollment, customer, job, asset, or reminder records.
6. Confirm desktop and iPhone cards open the intended details and the calendar remains usable at narrow widths.
7. Only after provider/consent readiness and explicit action-time confirmation, deliver at most one test SMS to `8646165468` and one test email to `johncollinsemail@gmail.com`.
8. Confirm `/landlord/agreements` shows the draft/version hash and the proposal is only available on an Evergrove host after password entry.
9. Confirm tenant `/agreements` only exposes the accepted copy and provider-confirmed receipts, without internal notes or raw access evidence.
10. Confirm acceptance alone creates no charge; payment requires the locked Stripe action and access remains blocked until settlement, schedule configuration, provider verification, and audited fulfillment exist.

## Suggested Front Yard Foods Branches

The current launch set covers Customers, Class Scheduling, Field Service, Messaging, and Reporting. Natural next Branches are Consultations, Edible Landscape Design, Garden/Orchard Installations, Sourdough & Food Preservation, Photo Portfolio, and Seasonal Maintenance. Keep these as reusable capabilities rather than tenant-specific code.

# Front Yard Foods Demo Runbook

## Prepare

Run after migrations are live:

```bash
php artisan everbranch:prepare-front-yard-foods
```

The command reuses tenant slug `front-yard-foods`. For a new tenant it uses ID 4 only when available, otherwise the smallest unused ID greater than 4. It preserves all existing memberships and grants active tenant-admin access to `johncollinsemail@gmail.com`.

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

## Suggested Front Yard Foods Branches

The current launch set covers Customers, Class Scheduling, Field Service, Messaging, and Reporting. Natural next Branches are Consultations, Edible Landscape Design, Garden/Orchard Installations, Sourdough & Food Preservation, Photo Portfolio, and Seasonal Maintenance. Keep these as reusable capabilities rather than tenant-specific code.

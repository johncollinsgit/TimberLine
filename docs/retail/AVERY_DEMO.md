# Avery tablet presentation — October 17–18, 2026

The existing Retail install contains an independent Avery concept, not Avery's live
store or an activated merchant workspace. No app identity or saved customer/cart
records are reset. Cache v16 carries 142 public photographs and the public modules;
authenticated pages, APIs, real recipients and agreement/payment links are excluded.

## Presentation sequence

1. Open Avery. Eight real models and 77 offered handle combinations use Avery's
   public catalog photographs and dated prices. Find Your Avery asks category,
   intended use, budget and material, then returns two or three suitable models.
   Compare photographed handles, save an exact fictional preference, show its
   variant-specific shop QR, and demonstrate product-specific restock permission.
   Dated availability is a snapshot, never a stock reservation.
2. Open Workshop. Fourteen fictional customers and 33 orders connect products,
   materials, production stages, inventory allocation, shipping checklists and care.
   Ask for ten likely-to-order customers: each suggestion cites explicit sample
   signals, not an asserted probability. Bob Ross is an invented customer unrelated
   to the public figure; his favorite is a Woodsman with Pinecone/Pearl Resin.
3. Open Morgan's optional Lifetime Knife Passport. Request sharpening, then open
   the owner care queue and advance Received → Being serviced → Ready → Returned.
   The request stays attached to Morgan and the same individual knife. Registration
   does not restrict Avery's published guarantee. Pinecone purchase provenance and
   maker review remain relevant; the demo does not decide warranty coverage.
4. Use Talk with AI. The same conversation can open screens, read current fictional
   context, fill an allowed form, save a fictional customer, create a catalog-priced
   demo invoice, or prepare John Smith's preferred knife message. Typed questions
   share this context. Real recipient details never enter the AI context. AI tools
   cannot send messages, take payment, publish or operate a real merchant record.
5. Review a formatted email or text. The recipient enters their own address/mobile
   and checks one-message consent. A separate confirmation button sends the real
   message. SendGrid acceptance and Twilio delivery are reported distinctly. This
   does not enroll the recipient in Avery's or any other business's marketing list.
6. Show pricing: $299/month plus $499 one-time setup and one shared prepaid usage
   balance. New Standard has no text setup or separate email/text monthly access
   fee. OpenAI is 2× actual API cost; model examples include the cheaper Mini voice
   option. Email is $1.49/1,000, SMS $0.05/segment and MMS $0.15. The example five-person usage is $48.95 and a regular month $347.95. The first payment is $798, with no usage fees or preload. Future voice will offer owner-approved team model choice and caps;
   telephone transport, connection fees, implementation and launch scope still
   need a written quote. The screen does not activate a service or collect credit.
7. Present mode's final Avery footer opens the actual review agreement and live
   $798 first-month/setup invoice. Links require the online owner session. The
   agreement access code is in the forward-ready email sent to the owner, never
   public assets. Opening either link does not accept the agreement or pay it.

## Live services and boundaries

The local demo runs on the Mac at port 8797. Keep the Mac awake, its server running,
and the paired ADB reverse connection active. Each tablet needs internet for OpenAI
WebRTC. Internet alone cannot replace access to this local server. Offline shopping,
fictional records and pricing work after preparation; live AI, email/SMS and private
agreement/invoice handoff require reconnection. Do not clear browser data or restart
the server without securely restoring its process-only provider credential.

The owner explicitly authorized temporary Modern Forestry SMS testing while the new
Everbranch sender awaits verification. The private local presenter configuration can
allow a separately confirmed recipient for a single demonstration. The text identifies
Modern Forestry's test number and Everbranch, includes fictional-customer labeling
and STOP, and requires recipient ownership/permission. Default configuration restricts
SMS to its allowlist; production never reads this local private transport. No Modern
Forestry Shopify, orders, rewards, imports or operational sender routing changed.

Provider credentials and handoff tokens live only in ignored mode-0600 private files.
Delivery attempts reserve a locked private record before HTTP, retain only a keyed
recipient signature, and reuse the same attempt on retries. Ambiguous responses stay
unconfirmed and never automatically send again. Routes remain owner-only, verified,
CSRF-protected and throttled. The new Everbranch and Modern Forestry Twilio child
accounts are pending business onboarding; the existing approved Forestry number has
not moved because toll-free verification does not transfer with it.

## Sources and design references

`avery-catalog-sources.json` records each fetched public Shopify product and photo.
Model/handle offerings and prices were observed October 17. Avery's
[lifetime policy](https://www.averyknifeworks.com/lifetime-gaurantee) supplies the
service context; verify its current terms with the maker. No 3D or unoffered handle
combinations are invented. Workshop concepts use common lot/material/production
patterns described by [MRPeasy](https://www.mrpeasy.com/production-management/) and
[Katana](https://katanamrp.com/manufacturing-software/), adapted to fictional knife
records. No claim is made that Avery uses those systems.

## Verification

Run `vendor/bin/pest tests/Feature/Retail`, the Avery/workbench/commerce-link checks,
and `photo-offline-check.mjs` / `sales-upgrade-check.mjs`. Live scripts are explicit
opt-in and must not be rerun casually: they incur provider usage or send real mail.
The authorized live tests already verified SendGrid email acceptance and receipt,
Twilio delivered SMS, and a real Realtime conversation that opened Bob, showed ten
follow-ups, filled/saved a customer, made a $385 demo invoice and prepared a message.
No ambient microphone, payment or Avery email was used.

For a connected existing tablet:

```sh
adb -s DEVICE reverse tcp:8797 tcp:8797
adb -s DEVICE forward tcp:9224 localabstract:chrome_devtools_remote
RETAIL_QA_CDP_PORT=9224 RETAIL_QA_DEVICE_NAME=galaxy node scripts/retail/tablet-avery-check.mjs
```

The tablet check preserves existing local records, verifies all photos and private
cache exclusions, prices/fees, finder/QR, Bob, ten follow-ups, passport, handoff and
offline reopening. It checks voice readiness without opening the microphone and
never submits a real message or payment. Private screenshot/evidence output is
ignored under `output/retail`.

Latest policy verification: 13 backend checks (77 assertions), changed-file Pint,
and the browser sales/signup/typed-navigation/offline click-through passed with
cache v13. This is browser verification, not completion of the physical-tablet
refresh; both wireless-debugging transports need reconnection. The pricing and
Twilio provisioning PRs passed their GitHub checks. No new live send, invoice,
payment, subscription or production deployment occurred during this policy update.


## October 18 presentation polish

Avery's top navigation uses Shop & discover (catalog/finder/story), Knife care
(passport/Care & interests), Workshop (customers, follow-ups, orders, production,
inventory and shipping), and Demo tools (assistant/pricing/presentation/app kit).
Touch/keyboard menus close on selection, Escape or outside click. The supplied
transparent Avery logo appears in the store, workspace/passport and email preview.

Care & interests explains both workflows and includes three fictional service
requests (Received, Being serviced and Ready) and four product-specific interests.
Customer, knife, handle, purchase reference and service activity remain linked;
saved preferences never imply contact permission. Sample requests use catalog
photos labeled as fictional; no real service intake or warranty approval occurs.
An additive sample seed preserves tablet-created records and production progress.
Fourteen customers, 33 past orders, three production batches, six material lots
and shipping checklists make the workshop concrete. Voice/typed navigation can
open the finder, passport and Care & interests through the same allowlisted tools.

The centered email preview uses Avery's logo, exact selected handle photo, readable
copy and product link. The reviewed SendGrid HTML mirrors that design and embeds
logo/photo with content IDs. Real recipient confirmation and the existing durable
send reservation remain mandatory; no mailing list is populated. Existing demo
invoice draft presentation is a separate agent's work and is not changed here.

Present close has six service preference checkboxes. An owner-only endpoint stores
an audited, canonical, zero-access-fee quote on the existing prospect. Replay does
not duplicate the audit. Saving does not activate features, change the live invoice,
preload credit or accept terms. Future memory is an included Standard feature when
released; memory/model usage and future combined voice cost are 2× actual provider
cost. Planned features remain labeled planned.

QA: `navigation-care-check.mjs`, `avery-check.mjs`, `sales-upgrade-check.mjs`,
`photo-offline-check.mjs` and `workbench-check.mjs` exercise layouts, saved records,
consent and offline data. `email-brand-live-check.mjs` requires explicit opt-in and
an owner-authorized email; its stable request ID prevents duplicate test sends.
Never run it to a prospect or silently change that stable request ID.

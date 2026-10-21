# Alpha Printing browser POS demonstration

This is a fictional browser demonstration, separate from the local/testing POS
services and the real Alpha Printing workspace. It is prepared for review; a
source change or passing test does not establish a later deployment.

## Route and access boundary

The proposed public GET/HEAD URL is
`https://app.theeverbranch.com/platform/demos/alpha-printing-pos`.
It requires no sign-in because it exposes only source-controlled fictional
fixtures. It does not select tenant 16, impersonate a user, grant access, create
a financial merchant, connect a processor or invoke the real POS services.
Query parameters are rejected, so this URL cannot accept tenant, customer,
inventory, payment or callback identifiers. Non-GET methods have no handler.

The controller allows the configured canonical landlord host. Local/testing
also permits localhost and 127.0.0.1 for review. Customer domains are not an
alternative entry point. The route retains the existing host/security middleware
but excludes application session persistence and the scheduler-heartbeat writer.
The controller reads no operational records and renders only trusted resources.

All changes made by the demo remain in its dedicated browser sessionStorage
namespace `everbranch:fictional-pos-demo:v1`. Storage is local to that browser
session; the PC and iPhone do not share a merchant ledger. Blocked storage falls
back to memory with an explicit warning. Reset clears only this namespace.
Storage and all displayed outcomes remain unauthenticated fictional evidence.
Do not enter real customer or card information. The interface supplies fictional
customer choices rather than collecting names, emails, phone numbers or cards.

## Demonstration contract

The demonstration rehearses a fixture catalog and stock, exact-SKU entry,
guest or fictional customer selection, a frozen cart with integer-cent tax,
simulated card outcomes and recovery, cash received/change, a printable
fictional receipt, and a simulated manager refund with customer history.
Pending outcomes are distinct from confirmed sales; refund history is based on
confirmed demo outcomes and never enrolls a customer in marketing.

Prices, quantities, customers, tax rates and service labels are examples, not
Alpha Printing's actual catalog, inventory, registrations or business policies.
The original counter illustration is fictional hardware imagery, not evidence
of a supported reader. Existing shipping/postal software remains separate; no
postage, label purchase, carrier rating, surcharge or dual-pricing policy exists
in this demo. Printed browser receipts are fictional and establish no physical
printer acceptance.

The page uses nonced inline scripts/styles, no external fonts or scripts,
`connect-src 'none'`, `form-action 'none'`, a no-store response, no-referrer and
noindex headers, and no frame embedding. No card provider, credentials,
analytics, messages, customer import or operational write endpoint is included.

## Release and acceptance

Review the exact source and meaningful HTTP/JavaScript/browser checks before
release. The release owner must serialize with the campus demo release, use the
ordinary GitHub/Forge gates, and independently verify the active commit and the
exact demo URL. The prior code release's readiness poll timed out while Forge
queued its build; its later successful activation was independently verified.
Do not retrigger an uncertain release or weaken checks to make a status green.

Real `PosAccess`, Terminal/live-payment flags, tenant entitlement, onboarding and
real checkout remain unchanged. This route is not a way to relax their guards.
Native iPhone Tap to Pay and physical reader/scanner/printer acceptance remain
separate integrations. The fixture demonstration can be reviewed in a PC or
iPhone browser; it is not an installed native iOS application.

If the demo must be withdrawn, revert this standalone route/controller/view and
assets through the normal release path. Do not remove additive POS tables,
change merchant configuration or delete production records. Browser Reset
clears only the fictional browser journal and does not affect the real workspace.

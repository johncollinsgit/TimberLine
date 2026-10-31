# Provider terms and publishing flags for Everbranch AI

Owner instruction recorded October 24, 2026: retain these findings for other
Everbranch AI/development work to flag when relevant. Publishing review is a
separate milestone from engineering tests. This is an implementation review,
not a legal-compliance certification. Recheck the current terms and the actual
account/build before release; dated notes do not establish continuing approval.

## Before publishing an Apple app

- Verify acceptance of the current English Apple Developer Program License
  Agreement in the owning developer account. The public agreement reviewed here
  is dated October 13, 2026. Acceptance alone does not establish app compliance.
  Use an authorized App Store, Custom App, TestFlight or registered-device route;
  an Everbranch Enterprise runtime does not confer Apple enterprise distribution
  rights for external customers.
- Review physical-goods payments separately from selling digital app features
  or subscriptions. The current POS cohort sells merchandise and printing
  services used outside the app. Reassess a new product or billing model.
- Provide an accessible in-app privacy-policy link and matching App Store
  disclosures, reviewed retention/deletion instructions, accurate permission
  descriptions and the required SDK/privacy reports. Include actual Square,
  Shopify, device/location, optional customer-contact and diagnostic behavior.
  The POS candidate now includes the in-app policy link; the existing general
  notice still needs a POS-specific completeness review. Keep disclosure review
  open on the publishing checklist rather than claiming it is finished.

Sources: [Apple program agreement](https://developer.apple.com/support/terms/apple-developer-program-license-agreement/)
and [App Review Guidelines, 3.1.3(e) and 5.1](https://developer.apple.com/app-store/review/guidelines/).

## Before live Tap to Pay collection, including pilots

- Obtain the applicable Apple managed entitlement, provisioning/signing and
  distribution approval for the exact bundle. A source entitlement, accepted
  developer agreement or Square app registration is not that approval.
- Verify each merchant's PSP account and acceptance of Apple's Tap to Pay
  merchant terms through the supported SDK owner/admin linking flow. Inspect
  the real device, merchant, location and exact installed build.
- The present Square integration is online-only. Square's current iPhone Tap
  to Pay documentation says offline payments are unsupported. Offline holding,
  later submission and its provider-idempotency contract remain future work
  requiring a fresh supported-provider and terms assessment.

Sources: [Apple Tap to Pay setup](https://developer.apple.com/tap-to-pay/)
and [Square iPhone Tap to Pay requirements and merchant linking](https://developer.squareup.com/docs/mobile-payments-sdk/ios/tap-to-pay).

## Flag restricted provider data across Everbranch AI

- Apple Tap to Pay data has transaction/order-management purpose limits.
  Intermediaries must not repurpose it for advertising or customer profiling.
  Do not decrypt protected card payloads or copy card details into prompts,
  memory, logs or fixtures.
- Square's Developer Terms were rechecked October 31, 2026; the current page is
  dated October 27, 2026.
  They prohibit using Square-derived content, including anonymized content,
  to train or otherwise enhance an AI system. Flag proposed Square-data
  retrieval, embeddings, assistant memory, learning or model-input changes for
  documented provider/legal review before implementation; merchant consent or
  anonymization must not be treated as permission for prohibited training.
- Square requires seller authorization for content access/storage. Revocation
  requires stopping access and deleting Square content. Resolve the distinction
  between provider content and independently required transaction records with
  the provider/legal reviewer before promising indefinite retention or applying
  deletion to an evidence journal. Never silently purge payment evidence.
- Flag proposed SDK resale, competing provider-tool services or seller-migration
  features against Square's restrictions. Obtain clarification for the actual
  design; this note does not conclude that a custom POS using the supported SDK
  is prohibited.

Sources: [Apple agreement, 3.3.9(D)](https://developer.apple.com/support/terms/apple-developer-program-license-agreement/)
and [Square Developer Terms, sections 1–3 and 7–8](https://squareup.com/us/en/legal/general/developers).

## How to use this record

Flag a relevant proposal with the requirement, its source and the missing
evidence. Treat new source adapters and cross-product AI reuse as separate
reviews. Do not claim these notes enforce runtime data filtering, establish PCI
compliance, approve a merchant, or automatically accept future agreements.
Do not copy one tenant's private account evidence into another tenant's memory.

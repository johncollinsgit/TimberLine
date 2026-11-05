# Wholesale Email Messenger

## Branded customer reorder drafts (November 5; source candidate)

The Monday account list opens a private customer-specific preview in the
installed Shopify app. `WholesaleCustomerOutreachDraft` uses that account's
current eligible latest order; `WholesaleOutreachProductCatalog` matches public
photos and wholesale product links by provider product ID. The copy action
includes inline HTML and plain text for pasting into Gmail, with a selection
fallback if clipboard permission is denied. Subject and personal-message edits
stay local to the review page. No Gmail draft API permission or customer send is
introduced. See the reporting/outreach runbook for suppression and schedule rules.

## Saved wholesale introduction draft

The wholesale Shopify app contains a saved sixteen-block draft named
“Bring Modern Forestry to your store.” Edits use the current draft revision and
tenant/store context. Saving does not send the email; test sending is a separate
explicit action to entered test addresses. There is no campaign-send control.

## Photo selection and workspace home

Image blocks and each product-grid image use Shopify's native single-image File
picker (`pick:shopify/File`), with a thumbnail instead of a raw image URL field.
The verified current store resolves the chosen MediaImage ID server-side. Client
store identifiers and image URLs cannot select another store's credentials.
Cancel or failure retains the previous image; changing blocks while selection
resolves updates only the original block. Save/test wait for pending selections.
The shared birthday editor uses the same selector. Selecting does not save or send;
Save draft remains explicit. Click destinations remain editable as “Link when clicked.”

The embedded shell passes its home navigation target independently of its hidden
header navigation. The logo therefore returns wholesale staff to their wholesale
overview, preserving Shopify context, rather than the generic loyalty dashboard.
Standalone shell home behavior is unchanged.

## Retired image and wholesale URLs

On November 4, the seeded `SaleImage-darktheme_5f5f655b-77a9-49f5-b699-037f71dee79e.png`
file returned 404. The same artwork remained available in Shopify Files as
`SaleImage-darktheme_a92fcf55-cd43-4749-ba15-d6c3c041fed6.png`.
The source default uses that current asset. The hero links to
`https://modernforestrywholesale.com/`, and the application CTA links to
`https://modernforestrywholesale.com/pages/wholesale-application-1`.
The old retail `/collections/wholesale` and `/pages/wholesale` paths returned 404.

Existing saved drafts repair only the exact known old hero filenames and these
old destinations, by section ID. Existing placeholder-product repairs remain.
The repair updates the revision only when a value actually changes; subsequent
opens do not keep changing it. Merchant descriptions, titles, button labels,
custom artwork and custom URLs remain intact. No network request or marketing
send occurs during repair.

## Verification

- Verify replacement images against Shopify Files and check the public image
  response before changing a draft. A successfully saved URL can still name a
  missing file.
- Verify exact editor values after entering a URL and again after saving.
- Review the visible image in the native browser. Check a mobile preview when
  changing layout; replacing this image URL does not alter layout.
- Run the focused messenger/URL-repair cases in
  `tests/Feature/Wholesale/EmbeddedWholesaleOperationsTest.php`.
- Run `node tests/e2e/email-photo-selector.mjs` for the offline fictional-files
  browser checks. It mocks every request and sends no email.
- Release backend changes through the existing GitHub/Forge gates. A live draft
  edit does not establish that the new defaults have reached production.

# Shopify supplier drafts

This pilot sends one paid Website shipment to the supplier’s Shopify **Drafts** as an internal work ticket. Everbranch owns the sale and payment. The supplier receives the destination name and postal address, the mapped variants and quantities, and the Website order reference. Carrier labels and tracking remain a manual supplier workflow during the pilot.

## Set up one seller and supplier

1. Agree on the wholesale cost, shipping origin and rates, stock availability, returns, and what destination data the supplier needs. Set the Website shipping origin to the approved supplier location.
2. Create or confirm the supplier's separate catalog variants. For the Free Range Humanity pilot, map the 8 oz and 16 oz Bigfoots Delight variants to their matching Modern Forestry Shopify variant IDs. Use the target variants for the custom Thru Hike + Beard candle. Store numeric variant IDs in the seller site's `managed_website.dropshipping_routes` configuration.
3. Have the supplier authorize a dedicated Shopify Admin app connection with draft-order read and write access, draft-order staff permissions, and the necessary protected customer data access for shipping names and addresses. Confirm the permanent `myshopify.com` domain and the shop's `gid://shopify/Shop/…` ID. Keep these credentials separate from catalog, checkout, payment, and marketing integrations.
4. Apply the Everbranch `website_dropship_drafts` migration. Set `WEBSITE_DROPSHIP_SHOPIFY_SUPPLIERS` through the approved host secret mechanism. Its JSON structure is indexed by seller tenant ID, seller site ID, then the supplier key in the route. Enable `WEBSITE_DROPSHIP_SHOPIFY_DRAFTS_ENABLED=true` and refresh the app configuration only when the scoped supplier setup is ready.

```json
{
  "101": {
    "202": {
      "modern-forestry": {
        "shop_domain": "supplier-example.myshopify.com",
        "shop_id": "gid://shopify/Shop/12345",
        "admin_token": "<dedicated supplier app token>"
      }
    }
  }
}
```

## Hand off a paid order

Use a fully paid, unfulfilled native Website order with a US shipping address, verified live Stripe payment, no refund activity, and a single Shopify supplier for all physical items. Review the address transfer and variant mapping before running the operator command.

```sh
php artisan website:dropship-draft 101 202 303 --submit
```

In Shopify Drafts, confirm that the ticket is private, totals $0, and shows the correct variants, quantities, and destination. Use the draft to make and ship the products. Keep it in Drafts and record shipment and tracking in the seller's fulfillment workflow. Coordinate any subsequent cancellation or refund directly with the supplier before shipment.

## Enable automatic intake

1. Run both dropshipping migrations and confirm the operator path as part of supplier acceptance. Configure the seller to use Stripe for this pilot.
2. Set `"automatic": true` on the approved seller site and supplier entry in `WEBSITE_DROPSHIP_SHOPIFY_SUPPLIERS`. Refresh app configuration and restart the dedicated worker after configuration changes.
3. Run the standard Laravel scheduler and supervise the dedicated worker.

```sh
php artisan queue:work website_dropship_drafts --queue=website-dropship-drafts --sleep=3 --tries=1 --timeout=60
```

The paid Stripe transaction records the outbox entry. The scheduler queues it each minute through `website:dispatch-dropship-drafts`. The worker rechecks approval and looks for Stripe refund activity before creating a draft. The handoff uses the frozen supplier route and the Shopify destination approved at payment. Existing paid orders can use the operator path.

Review `website_dropship_outbox` rows in `review` using the stored seller tenant, site, and order IDs. An expired queue lease is recoverable, and a pending Shopify create is reconciled by its original tag. The worker moves a handoff to review when the supplier approval changes, the payment becomes ineligible, or recovery reaches five worker attempts. Restore the original connection and use the operator commands for any reviewed order that should still proceed.

## Recover an uncertain submission

The command records a claim before sending Shopify a single create request. If the response is lost, run the read-only lookup against the same supplier connection.

```sh
php artisan website:dropship-draft 101 202 303 --reconcile
```

If the command cannot verify the result, search the original Shopify shop using the correlation tag it reports. Shopify search can lag. Keep the existing claim and resolve the ticket in that shop before deciding on a recovery. Printful submission and supplier shipment status synchronization are future work.

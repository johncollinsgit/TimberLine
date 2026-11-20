# Dropshipping sales channel

A merchant sells the item through its Everbranch website and delegates shipment to a supplier. The selling site's Website order owns the customer payment, refunds, and customer communications. Sales Channels attributes confirmed supplier-routed sales to Dropshipping once.

## Set up a seller and supplier

1. Complete Website Commerce and shipping setup for the selling tenant. Add each reviewed supplier ship-from location under the selling site. Confirm the carrier services, packaging, supported destinations, shipping costs, and return address with the supplier.
2. Create the seller's catalog products and variants. Enter the actual packaged weights and dimensions. Use inventory tracking only where the seller can keep available stock current.
3. Map each seller variant to one supplier variant. `MANAGED_WEBSITE_DROPSHIPPING_ROUTES` is a deployment setting keyed by seller tenant ID, site ID, and Website variant ID. Each mapping has `provider`, `supplier`, `variant`, and `location_id`. The location belongs to the selling site. The supplier key and external variant identifier are opaque strings. Keep provider credentials out of this setting.

Example with fictional IDs:

```json
{
  "10": {
    "20": {
      "30": {"provider": "shopify", "supplier": "example-candle-shop", "variant": "111001", "location_id": 45},
      "31": {"provider": "shopify", "supplier": "example-candle-shop", "variant": "111002", "location_id": 45}
    }
  }
}
```

4. Test each size and quantity, a stale quote after a mapping change, a mixed-supplier cart, and the paid order's classification. Each mapped physical item must use shipping. A checkout can quote one supplier origin. Separate the purchase if physical items are stocked locally or ship from different suppliers.
5. Establish the supplier order handoff and tracking workflow before publishing mapped products. A mapping records the intended supplier and variant. The fulfillment team still needs a way to send, reconcile, cancel, and track the supplier order. Keep the customer payment on the selling site and share only the information the supplier needs to fulfill the order.

For the Free Range Humanity pilot, give the 8 oz and 16 oz Bigfoots Delight candles separate mappings to Modern Forestry's corresponding Shopify variants. The active variant IDs, supplier location, size-specific packaging, inventory, prices, and fulfillment arrangement are merchant setup data. Use a separate supplier mapping for swag.

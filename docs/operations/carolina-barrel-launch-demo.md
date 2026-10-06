# Carolina Barrel Co. launch-partner demo

The Carolina Barrel Co. workspace can be prepared with a short-lived, fictional dashboard demonstration. It is designed for a login walkthrough, not for operations.

## What it creates

- Six customer profiles using only the non-routable `demo.carolinabarrel.invalid` domain. They are explicitly opted out of email and SMS.
- Ten clearly labeled `CBC-DEMO-*` order records across retail, wholesale, affiliate, and custom-quote sources. They never create a payment, shipment, fulfillment, invoice, or customer notification.
- Seventy-two clearly tagged `session_started:carolina-barrel-demo-*` events spread across the last 30 days so the Home dashboard has sessions, orders, conversion, sales, trend labels, and chart data to demonstrate.

Every visible fake person and order is prefixed or described as `DEMO`. The live website stays a draft and is not published by this operation.

## Run or refresh

After the application release is deployed, use the **Maintenance Carolina Barrel Launch Demo** GitHub Action. Select `refresh` and type `CAROLINA-BARREL-DEMO` exactly. Refresh first removes only records with the markers above, then rebuilds the fixture.

For a read-only preview on an application host:

```bash
php artisan everbranch:prepare-carolina-barrel-launch-demo carolina-barrel-co --mode=refresh
```

## Remove before live use

Use the same GitHub Action, choose `remove`, and type `CAROLINA-BARREL-DEMO`. The operation is tenant-scoped and deletes only:

- `CBC-DEMO-*` orders;
- `session_started:carolina-barrel-demo-*` events; and
- matching non-routable demo profiles carrying the Carolina Barrel demo note.

It does not delete the website draft, website pages, live-domain settings, real customers, real orders, customer imports, payments, or assets.

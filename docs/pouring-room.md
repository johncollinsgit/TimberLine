# Pouring Room – Progressive Flow

## Overview
The Pouring Room UI now follows progressive disclosure:

1. **Stacks** – big picture (Retail, Wholesale, Events/Markets)
2. **Stack Orders** – calm card list sorted by due date
3. **Order Detail** – focused “what to make” + recipes, start/complete
4. **All Candles** – aggregate by scent/size for bulk planning
5. **Calendar / Timeline** – date‑based planning views

## Status Mapping
- **Start this order** → `status = pouring`
- **Complete / Submit** → `status = brought_down`

These statuses remain compatible with Shipping and existing workflows.

## Views / Routes
- `/pouring` → Stacks
- `/pouring/stack/{channel}` → Stack orders
- `/pouring/order/{order}` → Order detail
- `/pouring/all-candles` → Aggregated totals
- `/pouring/calendar` → Calendar
- `/pouring/timeline` → Timeline

## Dashboard Toggle
A compact dashboard bar can be shown/hidden per user. Preference is stored in `users.ui_preferences.pouring_dashboard_enabled`.

## Recipes
Recipes are read‑only in Pouring Room. Each scent uses:
- `scent.oilBlend` + components
- If missing: warning + link to Recipes Admin

## Notes
- Orders shown are **published** and in `submitted_to_pouring | pouring | brought_down | verified`.
- Pending publish counts are visible in stacks but not included in pouring lists.

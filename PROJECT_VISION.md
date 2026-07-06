# Project Vision — Everbranch / Modern Forestry

> The "what I want the system to be" companion to `EVERBRANCH_SYSTEM_INVENTORY.md`
> ("what the system is"). The `project-strategist` agent reads both and advises on the
> gap. Last updated: 2026-07-06.

## 1. North Star
Everbranch is a **multi-tenant operations platform** for product/maker businesses.
**Modern Forestry** (a candle company — the owner's own business) is **tenant #1**: the
flagship and proving ground. The intent is that **other companies onboard as their own
tenants** on Everbranch over time. Modern Forestry's tools are largely built but **not yet
streamlined** — the near-term job is to make the flagship tenant excellent, then generalize.

## 2. 6–12 Month Win Condition
_TODO (not yet stated). Working assumption: Modern Forestry runs its full order → pour
workflow smoothly with minimal manual effort, in a shape clean enough to onboard a 2nd tenant._

## 3. Priorities (ranked, current)
1. **Streamline the existing Modern Forestry tools** (built, not yet smooth).
2. **Make scent mapping "super smart"** so employees rarely map by hand.
3. **Clean the order data** (remove pre-2026 orders).

## 4. Hard Constraints
- _TODO (team size, budget, deadlines)._

## 5. Explicit Non-Goals
- _TODO._

## 6. Your Role
- Owner / founder of Modern Forestry and Everbranch. `johncollinsemail@gmail.com` holds
  both **tenant control** for Modern Forestry and **landlord** access. Mostly directing via agents.

## 7. Modern Forestry Tenant — Data Flow (the core story)
**Access model:** Modern Forestry = tenant #1, granted all modules, non-paying for now.
An **account button (lower-left)** switches between the **landlord** view and the **Modern
Forestry tenant** view.

**Order intake (3 raw sources → normalized candles to pour):**
1. **Retail Shopify** — `theforestrystudio.com` → pushes orders via API into the tenant.
2. **Wholesale Shopify** — `modernforestrywholesale.com` → pushes orders via API.
3. **Events module** — orders entered manually; the module holds **box plans** that, when
   published, send **boxes to the pouring room**.

So the raw inputs are **wholesale lists, retail lists, and event box lists**. These get
**normalized into individual candles** to be poured. The point of the whole pipeline is
**user-based teamwork** on fulfilling those candles.

**The scent-mapping complication:** raw order lines use inconsistent/unique names
("- Appalachian maple bourbon mason candle 4oz", "one AMB", "Beard Soy Candle") that must be
mapped to a **canonical scent**. Unmapped-scent intake is **intentional and valuable** —
employees who know the catalog do the mapping — but the suggestions must get much smarter so
mapping is rare. (An existing time-of-year proximity % match on events is the model to beat.)

## 8. Known Tensions (IS vs OUGHT)
- **Scent mapping is naive.** `app/Services/ScentGuessEngine.php` scores only by substring +
  PHP `similar_text` against the whole noisy title, and **ignores the `scent_aliases` table**
  entirely — so learned/human mappings and abbreviations (AMB → Appalachian Maple Bourbon)
  don't inform suggestions. This is the root cause of "we spend all our time mapping."
- **`orders:purge` is unsafe for a scoped delete.** It truncates *all* orders and clears only
  `order_lines` + `mapping_exceptions`, leaving orphaned rows in 8 other tables that reference
  `order_id` (candle_cash, birthday, marketing attribution/reviews, pour_batch_lines,
  retail_plan_items, import_normalizations). No year- or tenant-scoped purge exists yet.
- **Platform vs. flagship.** "Streamline Modern Forestry" and "multi-tenant platform" can pull
  apart — watch for tenant-1-specific shortcuts that won't generalize ("Forestry bias").

## 9. Decision Log
- **2026-07-06 · Scheduler heartbeat (dead-cron detection).** `scheduler:heartbeat` runs
  every minute and stamps a timestamp (`SchedulerHeartbeatService`, cached forever). A
  terminable web middleware (`EvaluateSchedulerHeartbeat`, throttled ~5m) checks freshness on
  organic traffic — which keeps flowing even if cron dies — and raises a `system /
  scheduler_stalled` health event when the heartbeat is >10m old, auto-resolving when it
  recovers. Tunable via `config/scheduler_heartbeat.php`. Tested in
  `tests/Feature/SchedulerHeartbeatTest.php`. Closes the circular gap where a dead scheduler
  would silently stop imports AND any scheduler-based check. For a fully external guarantee,
  add a dead-man's-switch ping (healthchecks.io / Forge) later.
- **2026-07-06 · Per-store import health check.** Added `ShopifyImportHealthService` +
  `shopify:import-health` command (scheduled hourly) that checks each storefront's last
  successful `shopify_import_runs` entry and raises a `shopify / order_import_stale`
  integration health event (visible in `integration-health:list-open` and the integrations
  surface) when a store goes stale/never-imports — auto-resolving once imports resume. Catches
  expired tokens, broken cron, revoked scopes. Threshold 90m (`--stale-after`). Tested in
  `tests/Feature/Shopify/ShopifyImportHealthTest.php`.
- **2026-07-06 · Automate order imports from BOTH storefronts.** The scheduled
  `shopify:import-orders` ran with no `--store`, so it only polled `active_store_keys`
  (retail by default) — wholesale was silently never imported; the daily webhook-drift
  audit was retail-only too. Fixed `routes/console.php` to schedule both `retail` and
  `wholesale` explicitly for the 30-min import and the daily webhook verify. Guarded by
  `tests/Feature/Shopify/ScheduledImportsCoverBothStoresTest.php`. **Two prod prerequisites
  remain (can't verify from here):** (1) the Laravel scheduler cron (`php artisan schedule:run`
  every minute) must be active on the Forge host or NOTHING scheduled runs; (2) the wholesale
  app must be OAuth-installed on prod (`/shopify/auth/wholesale`) or its import logs "not installed".
- **2026-07-06 · Delete all orders except those made in 2026.** Rationale: stale orders back to
  2018 clog the queue. Keyed on `ordered_at`, tenant #1, cutoff `2026-01-01`. Local DB was already
  clean (4 orders, all 2026); the old orders live in **production**. **Built** `orders:prune`
  (`app/Console/Commands/OrdersPrune.php`) — dry-run + sqlite backup, deletes order-owned rows,
  NULLs value-bearing references, tested. **Not yet executed against prod** — run `--dry-run` there
  first, review counts, then `--force`.
- **2026-07-06 · Upgrade scent-mapping intelligence (deterministic, suggest-only).** DONE. The
  live engine was `app/Services/ScentGovernance/ResolveScentMatchService.php` (not the dead
  `ScentGuessEngine`). Added candle-domain stopword stripping + initialism/acronym matching, so
  "…mason candle 4oz", "one AMB", and "Beard Soy Candle" now resolve correctly. Covered by
  `tests/Feature/ScentGovernance/ResolveScentMatchServiceTest.php`. LLM fallback deferred;
  alias self-learning (write resolved mappings back to `scent_aliases`) is the next lever.

# Integration Health Events Maintenance (Step 8)

## Purpose
- Keep `integration_health_events` bounded over time.
- Give operators a CLI workflow to inspect active issues without SQL.

## Retention Policy
- Config key: `marketing.integration_health.resolved_retention_days`
- Env var: `MARKETING_INTEGRATION_HEALTH_RESOLVED_RETENTION_DAYS`
- Default: `45` days
- Pruning deletes **resolved** events older than the retention cutoff.
- Open events are never pruned by default.

## Operator Commands
- `php artisan integration-health:list-open`
  - Lists open events with optional filters:
  - `--provider=shopify`
  - `--store=retail`
  - `--tenant-id=1`
  - `--severity=error`
  - `--event-type=customer_provisioning_failed`
  - `--limit=100`

- `php artisan integration-health:prune`
  - Prunes old resolved events.
  - Optional filters:
  - `--provider=shopify`
  - `--store=retail`
  - `--tenant-id=1`
  - `--days=60`
  - `--dry-run` (no delete, summary only)

## Scheduler
- Daily automatic prune is scheduled in `routes/console.php`:
  - `integration-health:prune` at `02:20`


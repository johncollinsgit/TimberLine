# Everbranch — start here

Build your mental model from these repo-root docs, in order:

1. `EVERBRANCH_SYSTEM_INVENTORY.md` — full system map (apps, backend, DB, Shopify, mobile, env/services, risks). Ground truth for structure; if it contradicts the code, trust the code and say so.
2. `PROJECT_VISION.md` — goals, priorities, constraints, and the decision log for recent work.
3. `AGENTS.md` + `README_FOR_AGENTS.md` — guardrails/doctrine (e.g. `config/module_catalog.php` is canonical; enforce server-side tenant scoping).

For strategy/architecture questions, use the `project-strategist` agent.

## Production infrastructure (verified 2026-07-06)

- **Real prod = ONE DigitalOcean droplet, IP `129.212.138.111`** (hostname `Backstage`, NYC3, Ubuntu 24.04, Forge-managed: org `modern-forestry`, server `backstage-pfw`). It serves ALL domains from one nginx: `theeverbranch.com` (canonical, incl. `app.` + tenant wildcards), `backstage.theforestrystudio.com` (legacy), `evergrovesoftware.com`, `forestrybackstage.com`. All domains are Cloudflare-proxied — DNS shows Cloudflare IPs, never the origin.
- **MySQL runs on that same box** (`DB_CONNECTION=mysql`) — app and database share the droplet.
- **Scheduler cron IS active** (`schedule:run` every minute via forge crontab). The Forge UI "Scheduler" toggle is misleading — the cron was installed directly.
- **Deploy path: GitHub Actions on `main` → SSH → `scripts/deploy_backstage.sh`.** The Forge site still tracks branch `agent/codex` — stale; do not use Forge push-to-deploy.
- Prod is a **Laravel-managed Forge server** (Forge server ID `1165565`, VPC Name "Laravel Managed", created Feb 20 2026). The underlying DO droplet is provisioned & billed by Laravel — it does NOT appear in John's personal DigitalOcean account. **Resize/scale prod through Forge (or Laravel billing), NOT the DigitalOcean console.**
- ⚠️ **Two-droplets trap:** the DO account `johncollinsemail@gmail.com` ("My Team", GitHub login) contains ONE *unrelated* droplet also named "Backstage" (`134.209.43.25`, id 553310860) — a **blank, never-provisioned box** (no nginx/MySQL/code) that has been billing ~$12/mo since Feb and was mistakenly resized to 8GB/$48-mo on 2026-07-06 thinking it was prod. It is NOT production — destroy or downsize it. **Before ANY prod infra action, confirm the target IP is `129.212.138.111` and act via Forge.**
- Prod is a **Laravel VPS** (`growth-s-1vcpu-2gb`, billed by Laravel, DO-parity pricing) — resized to **8 GB / 4 vCPU** on 2026-07-06. Resize via Forge → Settings → Size, not the DigitalOcean console. Daily Forge database backups are enabled.

## Operations & deploys

- Deploys are gated: `.github/workflows/deploy.yml` runs the Pest suite + asset build on every push to `main` and blocks on failure (emergency bypass only via manual dispatch with run_tests unchecked).
- **Developer Control Center** at `/landlord/developer` (landlord-operator only) shows live system health, a production-readiness checklist, the recent-changes log, and the vision board. Content lives in landlord-global models `AgenticChange` / `VisionIdea` / `ReadinessChecklistItem`, seeded idempotently by `DeveloperDashboardSeeder`. When you ship a vision-board idea, set its `VisionIdea` status to `done` (drops off the board), flip the matching checklist item to `done`, and add an `AgenticChange` — see `README_FOR_AGENTS.md`. `php artisan ops:record-backup` feeds the "last backup" widget.

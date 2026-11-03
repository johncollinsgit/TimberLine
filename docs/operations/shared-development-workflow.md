# Shared PC and Mac development workflow

One current checkout per machine, one branch/PR per task, one active writer per
branch. GitHub carries source and task handoffs. Local Codex chats, ignored files,
installed dependencies, databases and credentials do not travel with Git.

## Pick up a task

1. Read `AGENTS.md`, current orientation pointers and the relevant runbook. Check
   the [work log](shared-work-log.md) and **existing** GitHub PR/task, including
   review findings and its latest head, before starting a duplicate task or PR.
2. Inspect before updating:

   ```sh
   git status --short --branch
   git branch --show-current
   git log -1 --format='%H %cI %s'
   git fetch origin
   node scripts/dev/workspace.mjs status
   ```

   Fetch does not replace working files. Preserve dirty and Mac-only work. Do not
   reset, clean, overwrite or auto-stash it to make an update fit. The helper is
   read-only; its `origin/main` is a local ref, not an automatically refreshed
   remote state or live-server claim.
3. Search current `origin/main`, existing PRs and the work log for requested
   behavior. Compare functions/tests, not branch names or old green checks. A
   shallow-history comparison may be inconclusive. Separate obsolete copies from
   genuinely unshipped work.
4. Claim the existing task record with device/session, branch, exact head,
   acceptance and next action. Coordinate ownership transfer before another
   writer edits that branch. Parallel tasks use separate worktrees and explicit
   file ownership; do not rebuild the same feature on both machines.
5. Reuse a suitable checkout/worktree and installed runtime. On **clean** `main`,
   use `git pull --ff-only` before branching. On a task branch, inspect fetched
   changes and resolve deliberately; never force-push another writer's work.

## Keep the edit loop small

Keep a synthetic-data preview running. Edit, wait for the relevant rebuild,
refresh/use working HMR, and exercise the changed behavior. Match main CI with
PHP 8.4 and Node 22. Run `composer install` when PHP dependencies are missing or
`composer.lock` changed; use `npm ci` when Node dependencies are missing or
`package-lock.json` changed. Do not update dependencies incidentally.

| Change | Useful local check |
| --- | --- |
| PHP behavior | `php vendor/bin/pest --compact path/to/relevant/Test.php` |
| Workflow TypeScript | `npm run typecheck` |
| Search ranking | `npm run test:team-search-ranking` |
| Enterprise assets | `npm run build:enterprise` |
| Migration | Migration lint and its registered disposable MySQL recovery test |
| Rendered interaction | Existing focused browser scenario and actual preview |
| Instructions/docs | Review links, commands and resulting diff |

Run the full build for a release candidate or a shared-build change; repeat when
new changes/failures justify it. Do not reinstall, rebuild databases or repeat
unrelated suites for every small edit. Existing required CI still runs normally.
Keep environment/secrets, private evidence, databases and caches machine-local.
Local success does not establish provider activation, customer acceptance,
independent backups or physical-device readiness.

### PC preview

The prepared checkout is `C:\Users\father\Documents\GitHub\Everbranch`. Its
machine-local helper supplies project Node 22 and dedicated loopback MySQL:

```powershell
& .\.local\everbranch.ps1 Start
& .\.local\everbranch.ps1 Status
& .\.local\everbranch.ps1 Check
& .\.local\everbranch.ps1 Stop
```

The current PC preview uses incremental **built-asset watching** and manual
browser refresh. The upstream shared Blade head lacks the React refresh preamble
needed by `npm run dev:full` on React-backed pages. Keep that source fix separate
for review; the local helper does not rewrite application responses or modify the
tracked template. Do not copy PC runtimes, MySQL data, app key or `.env` to Mac.

### Mac preview

Locate/reuse its TimberLine checkout and inventory dirty files, unpublished
commits, current task and preview first. Inspect working tools rather than
reinstalling them. Keep a separate local database, app key and synthetic
credentials; ordinary work does not need a production environment/provider key.

With lockfile-compatible dependencies, reuse a working preview. If it hits the
same React preamble issue, use three terminals with Node 22 selected:

```sh
php artisan serve
./node_modules/.bin/vite build --watch
./node_modules/.bin/vite build --config vite.enterprise.config.js --watch
```

Stop a prior Vite dev server and remove its stale `public/hot` file before choosing
built assets. Inspect that exact file; do not use broad cleanup. Watch rebuilds
assets rather than rerunning the whole test suite. Refresh after a successful build.

## Share notes and switch machines

The [work log](shared-work-log.md) indexes **one existing task record/PR**. Keep
changing handoff details and ownership claims in that record, not in competing
status feeds or a machine's chat memory. General instructions travel with Git.
Private customer evidence belongs in an approved private store, referenced only
from private notes. This repository is public.

Generate a starting handoff without changing files:

```sh
node scripts/dev/workspace.mjs handoff --device PC --task "short task" --record "existing PR or task URL"
```

Review it before sharing. Complete goal/acceptance, what is already done, branch/
head and pushed SHA, changed files, exact validation at that SHA or working tree,
open findings, next action and active writer. Record **source**, **deployment**
and **runtime/provider activation** separately. Uncommitted/ignored bytes are not
available on another device just because HEAD matches. Transfer approved tracked
work on the same task branch and required private artifacts through a verified
private handoff. Never paste secrets, raw customer data, production dumps or
private evidence into the repo, public PRs or generated public handoffs.

Before leaving: preserve changes, update the task, verify any authorized push at
its exact SHA and release ownership. On the other machine: fetch, verify that SHA
and record, claim ownership and continue the stated next action. Preserve existing
work and resolve diverged histories explicitly. Test a real handoff/readback before
claiming both machines are synchronized; this process does not sync private files.

## Review and release once

1. Keep one focused PR with the behavior change, scope, important findings, exact
   validation and activation limits. Reuse an existing draft for the same work.
2. Review the final head and pass all existing required GitHub checks. Rebased or
   newly composed source needs new-head checks; an old green run is insufficient.
3. Merge/deploy only within user release authorization. Retain the existing
   GitHub-gated Forge atomic path. Its deploy workflow already reuses successful
   named PR checks for an identical merged tree; do not duplicate that mechanism,
   bypass CI or enable direct push-to-deploy to save time.
4. Observe deployment, GET `https://app.theeverbranch.com/ready` and compare its
   release with the approved merged SHA. Verify affected hosted behavior. Record
   failures/unknowns; do not silently retry deployment or change provider flags.
5. Record merged/deployed SHA, hosted results and activation gates, then release
   the writer and close the task. Enterprise hardening remains planning-only until
   the shared process is adopted and the owner authorizes the next slice.

`AGENTS.md` is Codex's durable repository entrypoint and must exist in each
checkout. See [official instruction discovery](https://learn.chatgpt.com/docs/agent-configuration/agents-md).

# Everbranch, GitHub and Shopify access

Current access map for continuing work and answering live questions. Read
`AGENTS.md` and `README_FOR_AGENTS.md` first. Check existing connections before
asking the owner to sign in, supply credentials, or repeat setup.

## Choose the source

| Need | Preferred route | Verify first |
| --- | --- | --- |
| Source, READMEs, implementation, PRs and checks | Local Everbranch checkout and authenticated GitHub connector | Repository `johncollinsgit/TimberLine`, branch, current diff and target commit |
| Live Everbranch settings and records | Existing host-specific SSH connection; Windows alias below | Server identity, `forge` account, active release and tenant |
| Live Shopify records and analytics | Authenticated Shopify connector / Admin API | `get_shop_info` identifies Modern Forestry at `theforestrystudio.com`; confirm scope for the requested operation |
| Browser-only Everbranch administration | Existing signed-in Everbranch tab | Correct business and user permissions |
| SSH recovery or an unconfigured computer | Existing authenticated Forge site Commands | Exact production server/site below; inspect the available browser profiles before asking for sign-in |

A repository copy answers source questions; deployed configuration and provider
records answer live-state questions. Identify which was checked and when.
Read only the relevant runbook and implementation, expanding when needed.

## Repository and persistent guidance

- GitHub: `https://github.com/johncollinsgit/TimberLine` (application name Everbranch).
- Known Windows checkout: `C:\Users\father\Documents\GitHub\Everbranch`.
  Existing task worktrees may be more appropriate; inspect `git worktree list`
  and `git status --short` before choosing one. Preserve other work and staging.
- Use the GitHub connector for repository files, PRs and Actions when available.
  Local Git also works. The `gh` executable is not required and was absent from
  the Windows PATH during the November 21, 2026 access check.
- New agents should read this tracked runbook; chat history is supplementary.
  A different computer, account or cloud runtime needs its own authorized
  connection. Do not assume it inherited another computer's keys or sessions.

## Everbranch production target

| Property | Value |
| --- | --- |
| Public application | `https://app.theeverbranch.com` |
| SSH address / account | `129.212.138.111:22` / `forge` |
| Server hostname | `Backstage` |
| Forge organization / server | Modern Forestry / Everbranch |
| Forge server ID / site ID | `1165565` / `3053351` |
| Active application symlink | `/home/forge/backstage.theforestrystudio.com/current` |
| Forge site Commands | `https://forge.laravel.com/modern-forestry/backstage-pfw/3053351/commands` |
| Modern Forestry tenant slug | `modern-forestry` |

Production is the Forge-managed Laravel VPS at that address. The separate
personal DigitalOcean box historically named Backstage at `134.209.43.25` is
not this production target. Do not provision a replacement or change servers
merely because one local authentication route is unavailable.

`/up` reports liveness. `/ready` reports readiness and the active release ID.
Read the current release rather than retaining a release-directory number
from an earlier chat. Deployment still follows the protected PR / CI / Forge
atomic release workflow in `forge-atomic-release-runbook.md`; SSH access does
not authorize hotpatching, bypassing checks or running test fixtures in production.

## Windows direct SSH

Computer account: `C:\Users\father`. The prepared client configuration uses:

| Local item | Location / value |
| --- | --- |
| OpenSSH alias | `everbranch-prod` |
| Client configuration | `%USERPROFILE%\.ssh\config` |
| Dedicated private identity | `%USERPROFILE%\.ssh\id_ed25519_everbranch_windows` |
| Corresponding public identity | Same path with `.pub` appended |
| Dedicated host trust file | `%USERPROFILE%\.ssh\known_hosts_everbranch_prod` |
| Public-key label | `everbranch-windows-father-2026-11-21` |
| Client public-key fingerprint | `SHA256:si5v2TZJC/1v6fnDEMwGlr3b8MuuKS221gCsiCSlZAc` |

The private key stays on this computer with file access limited to its owner
and Windows system/administrator principals. It is intended for unattended
owner-authorized agent commands; do not upload it, print it, add it to Git,
or copy the Mac's identity. This setup does not require `ssh-agent`.

The alias uses public-key-only batch authentication, `IdentitiesOnly yes`,
`StrictHostKeyChecking yes`, a dedicated known-host file, ED25519 host keys,
`UpdateHostKeys no`, and `ForwardAgent no`. It requests no PTY. The dedicated
server key entry is intended to use `restrict`, disabling PTY, agent/X11/port
forwarding and user rc files while allowing commands as `forge`.

Check effective configuration without exposing private-key contents:

```powershell
ssh -G everbranch-prod
ssh-keygen -lf "$env:USERPROFILE\.ssh\id_ed25519_everbranch_windows.pub" -E sha256
```

Test an actual connection and resolve the active application:

```powershell
ssh everbranch-prod 'id -un'
ssh everbranch-prod 'hostname'
ssh everbranch-prod 'readlink -f /home/forge/backstage.theforestrystudio.com/current'
ssh everbranch-prod 'git -C /home/forge/backstage.theforestrystudio.com/current rev-parse HEAD'
```

Host ED25519 fingerprint verified through authenticated production Forge
Commands on November 21, 2026:

```text
SHA256:A1J/cbrwIvJRYQLmoOj9gwc+B4BLu8ABEnLQN6JnHpA
```

The network-presented key matched this fingerprint before creating the dedicated
Windows trust file. Historical fingerprint changes are recorded separately;
this dated verification does not establish their cause. If the key changes,
stop and verify the replacement through the authenticated management channel.
Do not disable host checking, auto-accept the changed key, or delete trust files.

Windows OpenSSH 9.5's `ssh-keyscan` failed with an unsupported KEX message during
setup. Git for Windows' existing `C:\Program Files\Git\usr\bin\ssh-keyscan.exe`
retrieved the public host key, which was checked against Forge's fingerprint.
A scan alone is not a trusted identity source. Normal Windows `ssh.exe` reached
the pinned server successfully; client authentication is a separate check.

## Shopify access

1. Discover the currently available Shopify tools and call `get_shop_info`.
   Modern Forestry at `theforestrystudio.com` was successfully verified through
   the authenticated connector on November 21, 2026. Do not assume the selected
   shop is unchanged, and distinguish retail from wholesale/dev stores.
2. Use dedicated read tools when they cover the question. Use `graphql_schema`
   before unfamiliar Admin GraphQL operations, `graphql_query` for scoped reads,
   and `run_analytics_query` for supported aggregate reporting. Available tools
   and account scopes may differ between sessions.
3. For application-side integration work, inspect `ShopifyStores`,
   `ShopifyGraphqlClient`, and the domain service that owns the operation.
   Resolve the tenant's intended store and existing server-held credentials
   through those services. Never print resolved credential arrays or `.env`.
   A connector's permissions and Everbranch's app token permissions are separate.
4. Use browser sign-in only for a step that actually requires the Admin UI or
   renewed authorization. A signed-out browser does not invalidate a working
   API connection. If a scope is missing, report the specific failed operation.

Do not run mutations, syncs, import/reconciliation jobs, discount updates or
message sends to answer a read-only question. New stores, tokens, scopes,
permissions and provider activation need their applicable explicit authorization.

## Live Everbranch reads and messaging

- Scope application reads to the requested tenant and required fields. For raw
  database audits, use an explicit read-only transaction and roll it back.
  Laravel bootstrap itself is not proof that a service call is read-only;
  inspect the service before invoking it.
- Return the minimum necessary customer data. Keep environment files, private
  keys, provider secrets, session tokens and raw credential-bearing arrays out
  of terminal output, chat, GitHub, READMEs and Forge command history.
- Use the existing tenant services for an authorized configuration change and
  verify the persisted result. Preserve immutable sent-message snapshots.
- Candle Cash reminder composition is owned by
  `TenantRewardsPolicyService`, `TenantRewardsPolicyMessagePreviewService`,
  and `TenantRewardsReminderDispatchService`. Inspect active tenant overrides
  plus renderer defaults; an old campaign record is not necessarily a current
  reusable reminder template.
- November 21 live verification found SendGrid selected for Modern Forestry
  email and Twilio enabled for SMS. Tenant SES sending was disabled. Recheck
  tenant routing/readiness when answering current provider questions; do not
  substitute Mailchimp or assume the SES migration is active.

## Forge fallback and recovery

Use the existing authenticated Forge site Commands page listed above. It runs
commands as `forge` in the site's directory and retains command/output history.
Confirm the current application symlink before bootstrapping Laravel. The UI
can show Finished before output becomes available; refresh the saved command's
detail page and inspect its result instead of resubmitting a mutation.

If SSH authentication fails, inspect the alias, identity-file existence, public
fingerprint, host pin and exact error. Do not regenerate an existing identity,
replace another computer's keys, enable password/root login or alter the firewall
as routine troubleshooting. An authorized administrator can remove only the
dedicated public-key entry identified above to revoke this computer's access;
preserve every unrelated entry and retain a working management route.

References: [OpenSSH authorized-key restrictions](https://man.openbsd.org/sshd#restrict),
[Windows key management](https://learn.microsoft.com/en-us/windows-server/administration/openssh/openssh_keymanagement),
[Forge site Commands](https://laravel.com/blog/forge-site-commands).

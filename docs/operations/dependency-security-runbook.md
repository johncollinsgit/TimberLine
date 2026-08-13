# Dependency and Outbound-Request Security Runbook

## Scope and design

- **Classification:** core platform security capability.
- **Tenant scope:** none. It protects shared runtime egress; tenant data may
  supply a URL only to the guarded callers.
- **Entitlement/billing:** none.
- **Canonical contracts:** `OutboundRequestPolicy`, Composer lockfile, npm
  lockfile, and the GitHub Actions audit gate.
- **Reuse:** this policy is platform-neutral and has no Forestry-specific
  routing or behavior.

## Dependency gate

Every production candidate and pull-request test build runs:

```bash
composer audit --locked
npm audit --package-lock-only --audit-level=moderate
```

Do not suppress or ignore an advisory in CI. Update the smallest compatible
dependency set, regenerate the lockfile, run the affected tests, then run the
normal build and full test gate. Use `npm ci` locally when reproducing CI so
the tested dependency tree is the exact locked tree.

## Outbound URLs

Use `App\Services\Http\OutboundRequestPolicy` for a request whose host is
derived from tenant, prospect, imported, or other non-code data. It requires
HTTPS, rejects embedded credentials, IP literals, local/internal hostnames,
non-443 ports, and DNS results that include private or reserved addresses. It
also disables redirects so a validated public host cannot redirect the request
to an internal one.

The initial protected callers are tenant discovery audits, wholesale prospect
website enrichment, and remote workspace-asset imports. New callers must
reuse the policy rather than adding an unguarded `Http::get()` path.

The policy is not a replacement for provider-specific allowlists. Calls to
Stripe, Shopify, QuickBooks, and other known providers should continue to use
their existing configured HTTPS endpoints and least-privilege credentials.

## Privileged password reset

The production maintenance workflow requires a ticket/incident reason and
records the GitHub actor, target account, role, and reason in the application
log. Keep the GitHub `production` environment approval requirement enabled;
this workflow must never be usable as an unreviewed account-escalation path.

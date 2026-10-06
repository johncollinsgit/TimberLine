# Trajectory source coverage reconciliation

Live Plaid data is authoritative for a connected account from its first available
transaction onward. A Monarch transaction export fills only the earlier history
gap. This reconciliation pairs a Monarch account with one uniquely matching
Plaid account in the same household, ignoring a masked Monarch suffix such as
`(...3129)`.

Superseded Monarch rows are excluded through the existing ledger state, but are
not deleted. They keep encrypted source evidence and receive an encrypted audit
marker. Unmatched names, statements, transfers, and live Plaid rows are not
changed.

Preview before applying:

```sh
php artisan trajectory:reconcile-import-sources --owner=RECIPIENT_EMAIL --space=HOUSEHOLD_SPACE_ID --json
```

Use `--apply` to exclude overlap and `--restore` to restore only rows excluded
by this process. Both require current finance access. Monarch imports run the
same idempotent policy after import, so future files fill only uncovered time.

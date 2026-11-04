<?php

namespace App\Services\Trajectory;

use App\Models\Trajectory\Account;
use App\Models\Trajectory\Connection;
use App\Models\Trajectory\Event;
use App\Models\Trajectory\Space;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Explicit balance authority only. Never changes financial observations or ledger rows. */
class CashBalanceSourceService
{
    public function sourceType(Account $account): string
    {
        return match (true) {
            str_starts_with($account->source_key, 'manual:') && strlen($account->source_key) > strlen('manual:') => 'manual',
            str_starts_with($account->source_key, 'monarch:'.$account->space_id.':') => 'historical_import',
            $account->connection_id && str_starts_with($account->source_key, 'plaid:'.$account->connection_id.':') && strlen($account->source_key) > strlen('plaid:'.$account->connection_id.':') => 'connected',
            default => 'other',
        };
    }

    public function links(Space $space): array
    {
        return $space->settings['current_cash_sources'] ?? [];
    }

    public function version(Space $space): int
    {
        return (int) ($space->settings['cash_source_version'] ?? 0);
    }

    private function validPair(Space $space, ?Account $historical, ?Account $replacement): bool
    {
        return $space->kind === 'household' && $historical && $replacement
            && $historical->id !== $replacement->id
            && $historical->space_id === $space->id && $replacement->space_id === $space->id
            && $historical->kind === 'cash' && $replacement->kind === 'cash'
            && $historical->currency === $replacement->currency
            && ! $historical->connection_id
            && in_array($this->sourceType($historical), ['manual', 'historical_import'], true)
            && $this->sourceType($replacement) === 'connected'
            && $replacement->balance_cents !== null;
    }

    public function excluded(Space $space, Collection $accounts): array
    {
        $excluded = [];
        $links = $this->links($space);
        foreach ($links as $historicalId => $replacementId) {
            $historical = $accounts->firstWhere('id', (int) $historicalId);
            $replacement = $accounts->firstWhere('id', (int) $replacementId);
            // Never follow chains or derive identity from a nickname/mask.
            if (! array_key_exists((string) $replacementId, $links) && $this->validPair($space, $historical, $replacement)) {
                $excluded[(int) $historicalId] = (int) $replacementId;
            }
        }

        return $excluded;
    }

    public function currentCash(Space $space, Collection $accounts): int
    {
        $excluded = $this->excluded($space, $accounts);

        return (int) $accounts->where('kind', 'cash')->reject(fn (Account $a): bool => isset($excluded[$a->id]))->sum('balance_cents');
    }

    public function accountPayload(Space $space, Collection $accounts): array
    {
        $excluded = $this->excluded($space, $accounts);

        return $accounts->map(fn (Account $a): array => [
            ...$a->only(['id', 'name', 'kind', 'connection_id', 'balance_cents', 'history_start', 'observed_at', 'currency']),
            'source_type' => $this->sourceType($a),
            'current_balance_excluded' => isset($excluded[$a->id]),
            'current_balance_replaced_by_account_id' => $excluded[$a->id] ?? null,
        ])->all();
    }

    public function change(User $user, Space $space, Account $account, ?int $replacementId, int $expectedVersion): int
    {
        app(WorkspaceService::class)->manage($user, $space);
        abort_unless($space->kind === 'household', 422, 'Balance source links are available in Personal only.');

        return DB::transaction(function () use ($user, $space, $account, $replacementId, $expectedVersion): int {
            $space = Space::whereKey($space->id)->lockForUpdate()->firstOrFail();
            app(WorkspaceService::class)->manage($user, $space);
            abort_unless($space->kind === 'household', 422);
            abort_unless($this->version($space) === $expectedVersion, 409, 'Balance sources changed. Refresh and review again.');
            $account = Account::where('space_id', $space->id)->lockForUpdate()->findOrFail($account->id);
            $before = $this->links($space);
            $after = $before;
            if ($replacementId === null) {
                unset($after[(string) $account->id]);
            } else {
                $replacement = Account::where('space_id', $space->id)->lockForUpdate()->findOrFail($replacementId);
                abort_unless($this->validPair($space, $account, $replacement), 422, 'Choose a historical cash snapshot and its connected cash account in this household and currency.');
                abort_if(isset($before[(string) $replacementId]) || in_array($account->id, array_map('intval', array_values($before)), true), 422, 'Unlink existing balance sources before making a chain.');
                $connection = Connection::findOrFail($replacement->connection_id);
                abort_unless($connection->status === 'connected', 422, 'Sync or reconnect the replacement bank feed first.');
                $after[(string) $account->id] = $replacementId;
            }
            if ($before === $after) {
                return $this->version($space);
            }
            $version = $expectedVersion + 1;
            $space->update(['settings' => [...($space->settings ?? []), 'current_cash_sources' => $after, 'cash_source_version' => $version]]);
            Event::create([
                'space_id' => $space->id, 'actor_id' => $user->id, 'record_id' => $account->id,
                'action' => 'cash_balance_source_changed',
                'before' => ['links' => $before, 'version' => $expectedVersion],
                'after' => ['links' => $after, 'version' => $version],
            ]);

            return $version;
        });
    }

    public function assertUnlinked(Space $space, Account $account): void
    {
        $links = $this->links($space);
        abort_if(isset($links[(string) $account->id]) || in_array($account->id, array_map('intval', array_values($links)), true), 422, 'Unlink this account’s balance source before changing ownership.');
    }
}

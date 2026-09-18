<?php

namespace App\Services\Trajectory;

use App\Models\Trajectory\Account;
use App\Models\Trajectory\Event;
use App\Models\Trajectory\Space;
use App\Models\Trajectory\Transaction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SourceCoverageReconciliationService
{
    /**
     * Prefer a live Plaid account over the same account imported through Monarch
     * from the first available Plaid transaction onward. The historical export
     * remains authoritative for its earlier gap.
     */
    public function preview(User $user, Space $space): array
    {
        app(FinanceAccess::class)->authorize($user, $space);

        $accounts = Account::where('space_id', $space->id)->get();
        $plaid = $accounts->filter(fn (Account $account): bool => str_starts_with($account->source_key, 'plaid:') && $account->connection_id);
        $monarch = $accounts->filter(fn (Account $account): bool => str_starts_with($account->source_key, 'monarch:'));
        $byName = $plaid->groupBy(fn (Account $account): string => $this->accountName($account->name));

        return $monarch->map(function (Account $historical) use ($byName): ?array {
            $candidates = $byName->get($this->accountName($historical->name), collect());
            if ($candidates->count() !== 1) {
                return null;
            }
            $live = $candidates->first();
            $cutover = Transaction::where('account_id', $live->id)->where('removed', false)->min('posted_on');
            if (! $cutover) {
                return null;
            }
            $cutoverOn = CarbonImmutable::parse($cutover)->toDateString();
            $historicalRows = Transaction::where('account_id', $historical->id)->where('removed', false);

            return [
                'historical_account_id' => $historical->id,
                'live_account_id' => $live->id,
                'account_name' => $live->name,
                'cutover_on' => $cutoverOn,
                'historical_rows' => (clone $historicalRows)->count(),
                'keep_before_cutover' => (clone $historicalRows)->whereDate('posted_on', '<', $cutoverOn)->count(),
                'supersede_count' => (clone $historicalRows)->whereDate('posted_on', '>=', $cutoverOn)->count(),
            ];
        })->filter()->values()->all();
    }

    public function apply(User $user, Space $space): array
    {
        $preview = $this->preview($user, $space);

        return DB::transaction(function () use ($user, $space, $preview): array {
            Space::whereKey($space->id)->lockForUpdate()->firstOrFail();
            app(FinanceAccess::class)->authorize($user->fresh(), $space);
            $applied = [];
            foreach ($preview as $source) {
                $rows = Transaction::where('account_id', $source['historical_account_id'])
                    ->where('removed', false)
                    ->whereDate('posted_on', '>=', $source['cutover_on'])
                    ->lockForUpdate()
                    ->get();
                if ($rows->isEmpty()) {
                    continue;
                }
                $event = Event::create([
                    'space_id' => $space->id,
                    'actor_id' => $user->id,
                    'action' => 'source_coverage_superseded',
                    'after' => [
                        'historical_account_id' => $source['historical_account_id'],
                        'live_account_id' => $source['live_account_id'],
                        'cutover_on' => $source['cutover_on'],
                        'transaction_ids' => $rows->pluck('id')->all(),
                    ],
                ]);
                foreach ($rows as $row) {
                    $row->update([
                        'removed' => true,
                        'source' => [...($row->source ?? []), 'source_superseded_by' => $event->id, 'source_superseded_at' => now()->toIso8601String()],
                    ]);
                }
                $applied[] = [...$source, 'superseded_count' => $rows->count(), 'event_id' => $event->id];
            }

            return $applied;
        });
    }

    public function restore(User $user, Space $space): int
    {
        app(FinanceAccess::class)->authorize($user, $space);

        return DB::transaction(function () use ($user, $space): int {
            Space::whereKey($space->id)->lockForUpdate()->firstOrFail();
            app(FinanceAccess::class)->authorize($user->fresh(), $space);
            $restored = 0;
            foreach (Event::where('space_id', $space->id)->where('action', 'source_coverage_superseded')->get() as $event) {
                $ids = $event->after['transaction_ids'] ?? [];
                foreach (Transaction::whereIn('id', $ids)->lockForUpdate()->get() as $row) {
                    if (($row->source['source_superseded_by'] ?? null) !== $event->id) {
                        continue;
                    }
                    $source = $row->source;
                    unset($source['source_superseded_by'], $source['source_superseded_at']);
                    $row->update(['removed' => false, 'source' => $source]);
                    $restored++;
                }
            }
            if ($restored) {
                Event::create(['space_id' => $space->id, 'actor_id' => $user->id, 'action' => 'source_coverage_restored', 'after' => ['transaction_count' => $restored]]);
            }

            return $restored;
        });
    }

    private function accountName(string $name): string
    {
        return (string) Str::of($name)
            ->lower()
            ->replaceMatches('/\s*\(\.\.\.\d{4}\)\s*$/', '')
            ->replaceMatches('/[^a-z0-9]+/', ' ')
            ->squish();
    }
}

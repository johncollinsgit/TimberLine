<?php

namespace App\Livewire\Retail;

use App\Jobs\SyncMarketEventsJob;
use App\Services\MarketEventSyncCoordinator;
use Illuminate\Support\Facades\Log;
use Livewire\Component;

class MarketsSyncStatus extends Component
{
    public int $planId = 0;
    public string $queue = 'markets';
    public ?string $lastSyncedAt = null;
    public ?string $lastSyncedHuman = null;
    public string $syncStatus = 'idle';
    public ?string $syncMessage = null;
    public ?string $error = null;

    public function mount(int $planId = 0, string $queue = 'markets'): void
    {
        $this->planId = max(0, $planId);
        $this->queue = strtolower(trim($queue)) ?: 'markets';
    }

    public function refresh(): void
    {
        if ($this->queue !== 'markets') {
            $this->syncStatus = 'idle';
            $this->syncMessage = null;
            $this->lastSyncedAt = null;
            $this->lastSyncedHuman = null;
            return;
        }

        try {
            $state = app(MarketEventSyncCoordinator::class)->queueStatus();
            $this->syncStatus = (string) ($state['status'] ?? 'idle');
            $this->lastSyncedAt = (string) ($state['last_sync_at'] ?? '') ?: null;
            $this->lastSyncedHuman = null;
            if ($this->lastSyncedAt !== null) {
                try {
                    $this->lastSyncedHuman = \Illuminate\Support\Carbon::parse($this->lastSyncedAt)->diffForHumans();
                } catch (\Throwable $e) {
                    $this->lastSyncedHuman = null;
                }
            }
            $this->syncMessage = $this->statusMessageFor($state);
            $this->error = null;
        } catch (\Throwable $e) {
            $this->syncStatus = 'unavailable';
            $this->syncMessage = null;
            $this->lastSyncedAt = null;
            $this->lastSyncedHuman = null;
            $this->error = 'Unable to load sync status.';
        }
    }

    public function syncEvents(): void
    {
        $this->error = null;

        if ($this->queue !== 'markets') {
            return;
        }

        $coordinator = app(MarketEventSyncCoordinator::class);
        $gate = $coordinator->canQueue(4, false);

        if (! ($gate['allowed'] ?? false)) {
            $reason = (string) ($gate['reason'] ?? 'unknown');
            $message = match ($reason) {
                'running' => 'Market event sync is already running.',
                'cooldown' => 'Market event sync was run recently. Please wait a few minutes.',
                'missing_table' => 'Event sync status table is missing. Run migrations.',
                default => 'Market event sync is temporarily unavailable.',
            };
            $this->dispatch('toast', ['type' => 'warning', 'message' => $message]);
            $this->refresh();
            return;
        }

        try {
            $coordinator->markQueued(4, auth()->id());
            if ((string) config('queue.default') === 'sync') {
                SyncMarketEventsJob::dispatchAfterResponse(4, false, auth()->id());
            } else {
                SyncMarketEventsJob::dispatch(4, false, auth()->id());
            }
            $this->dispatch('toast', ['type' => 'success', 'message' => 'Market event sync queued.']);
            $this->refresh();
        } catch (\Throwable $e) {
            Log::error('Failed to queue market event sync job from MarketsSyncStatus', [
                'exception' => get_class($e),
                'message' => $e->getMessage(),
            ]);
            $this->error = 'Failed to queue calendar sync.';
            $this->dispatch('toast', ['type' => 'error', 'message' => 'Failed to queue market event sync.']);
            $this->refresh();
        }
    }

    /**
     * @param  array<string,mixed>  $state
     */
    protected function statusMessageFor(array $state): ?string
    {
        $status = (string) ($state['status'] ?? 'idle');
        $last = (string) ($state['last_sync_status'] ?? '');

        return match ($status) {
            'running' => 'Sync in progress.',
            'queued' => 'Sync queued.',
            'failed' => 'Last sync failed.',
            'success' => 'Last sync succeeded.',
            'unavailable' => 'Sync status unavailable.',
            default => match ($last) {
                'failed' => 'Last sync failed.',
                'success' => 'Last sync succeeded.',
                default => null,
            },
        };
    }

    public function render()
    {
        return view('livewire.retail.markets-sync-status');
    }
}

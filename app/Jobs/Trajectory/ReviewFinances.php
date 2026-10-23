<?php

namespace App\Jobs\Trajectory;

use App\Models\Trajectory\Record;
use App\Services\Trajectory\FinanceAuditService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ReviewFinances implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 160;

    public function __construct(public int $auditId) {}

    public function failed(?Throwable $exception): void
    {
        $audit = Record::where('kind', 'finance_audit')->find($this->auditId);
        if ($audit && in_array($audit->data['status'], ['queued', 'running'], true)) {
            $audit->update(['data' => [...$audit->data, 'status' => 'paused', 'reason' => 'The review worker stopped. Resume preserves completed checks and existing usage reservations.']]);
        }
    }

    public function handle(FinanceAuditService $reviews): void
    {
        $audit = Record::where('kind', 'finance_audit')->find($this->auditId);
        if (! $audit) {
            return;
        }
        try {
            if ($reviews->work($audit)) {
                self::dispatch($audit->id);
            }
        } catch (Throwable $e) {
            $audit->refresh()->update(['data' => [...$audit->data, 'status' => 'paused', 'reason' => $e instanceof \Symfony\Component\HttpKernel\Exception\HttpException ? $e->getMessage() : 'Review paused. Check AI setup, access, queue health or usage before resuming.']]);
        }
    }
}

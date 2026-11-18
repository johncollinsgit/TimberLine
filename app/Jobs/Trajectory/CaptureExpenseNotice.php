<?php

namespace App\Jobs\Trajectory;

use App\Models\Trajectory\Transaction;
use App\Services\Trajectory\ExpenseNotificationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class CaptureExpenseNotice implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public int $transactionId) {}

    public function handle(ExpenseNotificationService $notices): void
    {
        if ($transaction = Transaction::find($this->transactionId)) {
            $notices->capture($transaction);
        }
    }
}

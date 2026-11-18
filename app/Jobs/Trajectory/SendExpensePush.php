<?php

namespace App\Jobs\Trajectory;

use App\Models\Trajectory\ExpenseNotice;
use App\Services\Trajectory\WebPushService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SendExpensePush implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 90;

    public function __construct(public int $noticeId) {}

    public function handle(WebPushService $push): void
    {
        if ($notice = ExpenseNotice::find($this->noticeId)) {
            $push->send($notice);
        }
    }
}

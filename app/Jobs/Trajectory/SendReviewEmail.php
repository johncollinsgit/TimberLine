<?php

namespace App\Jobs\Trajectory;

use App\Services\Trajectory\ReviewEmailService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SendReviewEmail implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(public int $userId, public int $spaceId) {}

    public function handle(ReviewEmailService $emails): void
    {
        $emails->send($this->userId, $this->spaceId);
    }
}

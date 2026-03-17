<?php

namespace App\Observers;

use App\Models\MarketingReviewHistory;
use App\Services\Marketing\ProductReviewNotificationService;

class MarketingReviewHistoryObserver
{
    public function __construct(
        protected ProductReviewNotificationService $notificationService
    ) {
    }

    public function created(MarketingReviewHistory $review): void
    {
        $this->notificationService->send($review);
    }
}

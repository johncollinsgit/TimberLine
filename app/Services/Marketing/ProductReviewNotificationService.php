<?php

namespace App\Services\Marketing;

use App\Mail\ProductReviewSubmittedMail;
use App\Models\MarketingReviewHistory;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class ProductReviewNotificationService
{
    public function __construct(
        protected CandleCashTaskService $taskService
    ) {
    }

    public function send(MarketingReviewHistory $review): void
    {
        if (! $this->shouldNotify($review) || $review->notification_sent_at) {
            return;
        }

        $email = trim((string) data_get(
            $this->taskService->integrationConfig(),
            'product_review_notification_email',
            'info@theforestrystudio.com'
        ));

        if ($email === '') {
            return;
        }

        try {
            Mail::to($email)->send(new ProductReviewSubmittedMail($review));
            $review->forceFill([
                'notification_sent_at' => now(),
            ])->save();
        } catch (\Throwable $exception) {
            Log::warning('product review notification failed', [
                'review_id' => $review->id,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    protected function shouldNotify(MarketingReviewHistory $review): bool
    {
        return (string) $review->provider === 'backstage'
            && (string) $review->integration === 'native';
    }
}

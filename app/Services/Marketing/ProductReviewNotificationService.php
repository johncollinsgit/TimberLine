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

    public function send(MarketingReviewHistory $review, bool $force = false): void
    {
        if (! $this->shouldNotify($review) || (! $force && $review->notification_sent_at)) {
            return;
        }

        $email = trim((string) data_get(
            $this->taskService->integrationConfig((int) ($review->tenant_id ?? 0) ?: null),
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

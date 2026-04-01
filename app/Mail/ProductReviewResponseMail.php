<?php

namespace App\Mail;

use App\Models\MarketingReviewHistory;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class ProductReviewResponseMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public MarketingReviewHistory $review
    ) {
    }

    public function build(): self
    {
        $productTitle = $this->review->product_title ?: 'your recent purchase';
        $customerName = $this->review->displayReviewerName();
        $merchantName = trim((string) ($this->review->tenant?->name ?: config('app.name', 'Forestry Backstage')));
        $subjectMerchant = $merchantName !== '' ? $merchantName : 'Modern Forestry';

        $reviewUrl = $this->review->product_url ?: ($this->review->product_handle
            ? rtrim((string) config('marketing.candle_cash.storefront_base_url', 'https://theforestrystudio.com'), '/') . '/products/' . ltrim($this->review->product_handle, '/')
            : null);

        return $this->subject('We replied to your review on ' . $subjectMerchant)
            ->markdown('emails.product-review-response')
            ->with([
                'review' => $this->review,
                'productTitle' => $productTitle,
                'customerName' => $customerName,
                'merchantName' => $subjectMerchant,
                'reviewUrl' => $reviewUrl,
            ]);
    }
}

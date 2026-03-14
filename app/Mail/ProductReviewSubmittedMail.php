<?php

namespace App\Mail;

use App\Models\MarketingReviewHistory;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class ProductReviewSubmittedMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public MarketingReviewHistory $review
    ) {
    }

    public function build(): self
    {
        $productTitle = $this->review->product_title ?: 'Product';
        $statusLabel = $this->review->status === 'approved' ? 'Live now' : 'Pending review';

        return $this->subject('New product review: ' . $productTitle)
            ->view('emails.product-review-submitted')
            ->with([
                'review' => $this->review,
                'productTitle' => $productTitle,
                'statusLabel' => $statusLabel,
                'productUrl' => $this->review->product_url,
                'adminUrl' => route('marketing.candle-cash.reviews', [
                    'review' => $this->review->id,
                ]),
            ]);
    }
}

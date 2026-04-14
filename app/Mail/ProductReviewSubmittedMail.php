<?php

namespace App\Mail;

use App\Models\MarketingReviewHistory;
use App\Support\Tenancy\TenantHostBuilder;
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
        $merchantName = trim((string) ($this->review->tenant?->name ?: config('app.name', 'Forestry Backstage')));
        $subjectMerchant = $merchantName !== '' ? $merchantName : 'Forestry Backstage';
        $adminPath = route('marketing.candle-cash.reviews', [
            'review' => $this->review->id,
        ], false);
        $adminUrl = app(TenantHostBuilder::class)->canonicalLandlordUrlForPath($adminPath)
            ?? route('marketing.candle-cash.reviews', [
                'review' => $this->review->id,
            ]);

        return $this->subject('New review for ' . $subjectMerchant . ': ' . $productTitle)
            ->view('emails.product-review-submitted')
            ->with([
                'review' => $this->review,
                'merchantName' => $subjectMerchant,
                'productTitle' => $productTitle,
                'productUrl' => $this->review->product_url,
                'adminUrl' => $adminUrl,
            ]);
    }
}

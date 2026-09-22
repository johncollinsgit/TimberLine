<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class TenantRewardsWishlistWeeklySummaryMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    /**
     * @param  array<string,mixed>  $report
     */
    public function __construct(
        public array $report
    ) {}

    public function build(): self
    {
        return $this->subject(sprintf(
            '%s rewards + wishlist: %s',
            (string) data_get($this->report, 'tenant.name', 'Storefront'),
            (string) data_get($this->report, 'health.label', 'Weekly summary')
        ))
            ->view('emails.marketing.tenant-rewards-wishlist-weekly-summary')
            ->with(['report' => $this->report]);
    }
}

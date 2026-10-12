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
        return $this->from((string) config('mail.from.address'), 'Everbranch')
            ->subject('Everbranch weekly recap · '.(string) data_get($this->report, 'tenant.name', 'Storefront').' · '.(string) data_get($this->report, 'window.label', 'Past week'))
            ->view('emails.marketing.tenant-rewards-wishlist-weekly-summary')
            ->text('emails.marketing.tenant-rewards-wishlist-weekly-summary-text')
            ->with(['report' => $this->report]);
    }
}

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
        public array $report,
        public ?int $deliveryId = null
    ) {}

    public function build(): self
    {
        if ($this->deliveryId !== null) {
            $this->withSymfonyMessage(function (\Symfony\Component\Mime\Email $message): void {
                $message->getHeaders()->addTextHeader('X-Everbranch-Logical-Message-ID', 'operator-report:'.$this->deliveryId);
                $message->getHeaders()->addTextHeader('X-SMTPAPI', json_encode(['unique_args' => [
                    'marketing_email_delivery_id' => (string) $this->deliveryId,
                    'tenant_id' => (string) data_get($this->report, 'tenant.id'),
                ]], JSON_THROW_ON_ERROR));
            });
        }

        return $this->from((string) config('mail.from.address'), 'Everbranch')
            ->subject('Everbranch weekly recap · '.(string) data_get($this->report, 'tenant.name', 'Storefront').' · '.(string) data_get($this->report, 'window.label', 'Past week'))
            ->view('emails.marketing.tenant-rewards-wishlist-weekly-summary')
            ->text('emails.marketing.tenant-rewards-wishlist-weekly-summary-text')
            ->with(['report' => $this->report]);
    }
}

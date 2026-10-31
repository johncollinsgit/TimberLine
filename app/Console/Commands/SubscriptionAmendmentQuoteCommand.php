<?php

namespace App\Console\Commands;

use App\Models\SubscriptionBillingAgreement;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Subscriptions\SubscriptionAmendmentProposal;
use App\Services\Subscriptions\SubscriptionAmendmentQuote;
use App\Services\Subscriptions\SubscriptionReplacementAccess;
use Illuminate\Console\Command;

class SubscriptionAmendmentQuoteCommand extends Command
{
    protected $signature = 'subscriptions:amendment-quote {--tenant=} {--actor=} {--agreement=} {--file=} {--fetch : Request the original store calculation; never save new billing terms}';

    protected $description = 'Preview a delivery/scents proposal and explicitly calculate its tax without creating an order or changing payment authorization.';

    public function handle(SubscriptionAmendmentProposal $proposals, SubscriptionAmendmentQuote $quotes): int
    {
        try {
            $tenant = Tenant::query()->where('slug', (string) $this->option('tenant'))->firstOrFail();
            $actor = User::findOrFail((int) $this->option('actor'));
            $row = SubscriptionBillingAgreement::query()->forTenantId($tenant->id)->findOrFail((int) $this->option('agreement'));
            app(SubscriptionReplacementAccess::class)->owner($tenant->id, $actor, $row->member_id, 'servicing');
            $path = (string) $this->option('file');
            abort_unless(str_starts_with($path, '/') && ! is_link($path) && is_file($path) && (fileperms($path) & 0777) === 0600
                && filesize($path) <= 65536, 422, 'Use a private regular 0600 JSON file at most 64 KiB.');
            $input = json_decode(file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
            abort_unless(is_array($input), 422);
            $proposal = $proposals->review($row, $input);
            $result = ['subject' => $proposal['subject'], 'status' => 'offline_proposal', 'quote_required' => true, 'provider_requests' => false];
            if ($this->option('fetch')) {
                $result = $quotes->calculate($row, $input, $actor) + ['status' => 'calculated_only', 'provider_requests' => true];
            }
            $this->line(json_encode($result + ['billing_terms_saved' => false, 'creates_order' => false, 'charges_customer' => false], JSON_PRETTY_PRINT));

            return self::SUCCESS;
        } catch (\Throwable $error) {
            $this->error($error instanceof \Symfony\Component\HttpKernel\Exception\HttpException ? $error->getMessage()
                : 'The future delivery quote needs review. No payment or order was created.');

            return self::FAILURE;
        }
    }
}

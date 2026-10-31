<?php

namespace App\Console\Commands;

use App\Models\SubscriptionBillingAgreement;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Subscriptions\SubscriptionReplacementAccess;
use App\Services\Subscriptions\SubscriptionShopifyMethodService;
use Illuminate\Console\Command;

class SubscriptionShopifyCommand extends Command
{
    protected $signature = 'subscriptions:shopify {action=summary} {--tenant=} {--actor=} {--agreement=} {--source-order=} {--apply : Persist verified method mapping only}';

    protected $description = 'Review exact original Shopify/Shop Pay method reuse without adopting a contract or submitting a billing attempt.';

    public function handle(SubscriptionShopifyMethodService $service): int
    {
        try {
            $tenant = Tenant::query()->where('slug', (string) $this->option('tenant'))->firstOrFail();
            $actor = User::findOrFail((int) $this->option('actor'));
            $row = SubscriptionBillingAgreement::query()->forTenantId($tenant->id)->where('processor', 'shopify')->findOrFail((int) $this->option('agreement'));
            app(SubscriptionReplacementAccess::class)->owner($tenant->id, $actor, $row->member_id, 'setup');
            abort_unless(in_array($this->argument('action'), ['summary', 'verify'], true), 422, 'Choose summary or verify.');
            $result = ['status' => $row->status, 'method_verified' => $row->method_verified_at !== null,
                'reauthorization_required' => null, 'contract_adopted' => false, 'billing_enabled' => false, 'provider_mutations' => false];
            if ($this->argument('action') === 'verify') {
                $proof = $service->verify($tenant->id, $actor, $row->id, (string) $this->option('source-order'), (bool) $this->option('apply'));
                $result = array_replace($result, ['status' => $this->option('apply') ? 'verified_mapping_saved' : 'verified_mapping_preview',
                    'method_verified' => true, 'method_type' => $proof['method_type'], 'last_four' => $proof['last_four'],
                    'reauthorization_required' => $proof['reauthorization_required'], 'checked_at' => $proof['checked_at']]);
            } else {
                $result['provider_requests'] = false;
            }
            $result['note'] = 'Exact technical mapping only. Supported contract adoption, source stop and actual merchant acceptance remain separate.';
            $this->line(json_encode($result, JSON_PRETTY_PRINT));

            return self::SUCCESS;
        } catch (\Throwable $error) {
            $this->error($error instanceof \Symfony\Component\HttpKernel\Exception\HttpException ? $error->getMessage() : 'The original Shopify/source payment mapping needs review. No contract or payment was created.');

            return self::FAILURE;
        }
    }
}

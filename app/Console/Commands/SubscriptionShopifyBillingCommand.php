<?php

namespace App\Console\Commands;

use App\Models\SubscriptionBillingAgreement;
use App\Models\SubscriptionBillingCycle;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Subscriptions\SubscriptionBillingAgreementService;
use App\Services\Subscriptions\SubscriptionReplacementAccess;
use App\Services\Subscriptions\SubscriptionShopifyExecutionService;
use Illuminate\Console\Command;

class SubscriptionShopifyBillingCommand extends Command
{
    protected $signature = 'subscriptions:shopify-billing {action=summary} {--tenant=} {--actor=} {--agreement=} {--cycle=} {--terms-hash=} {--revision=} {--apply : Execute only the explicitly named original-account step}';

    protected $description = 'Preview or explicitly execute gated original-method Shopify activation, collection and original-attempt reconciliation.';

    public function handle(SubscriptionShopifyExecutionService $service): int
    {
        try {
            $tenant = Tenant::query()->where('slug', (string) $this->option('tenant'))->firstOrFail();
            $actor = User::findOrFail((int) $this->option('actor'));
            $row = SubscriptionBillingAgreement::query()->forTenantId($tenant->id)->where('processor', 'shopify')->findOrFail((int) $this->option('agreement'));
            app(SubscriptionReplacementAccess::class)->owner($tenant->id, $actor, $row->member_id, 'setup');
            app(SubscriptionReplacementAccess::class)->member($row);
            $action = $this->argument('action');
            abort_unless(in_array($action, ['summary', 'accept', 'activate', 'collect', 'reconcile', 'sync'], true), 422, 'Choose summary, accept, activate, collect, reconcile or sync.');
            $result = app(SubscriptionBillingAgreementService::class)->state($row) + ['provider_requests' => false, 'charges_customer' => false];
            if ($action !== 'summary' && $this->option('apply')) {
                $result = match ($action) {
                    'accept' => $service->acceptMerchant($tenant->id, $actor, $row->id, (string) $this->option('terms-hash')),
                    'activate' => app(SubscriptionBillingAgreementService::class)->state($service->activate($tenant->id, $actor, $row->id,
                        (string) $this->option('terms-hash'), (int) $this->option('revision'))),
                    'sync' => app(SubscriptionBillingAgreementService::class)->state($service->syncSchedule($row, null, true)),
                    'collect', 'reconcile' => $this->cycleState($action === 'collect' ? $service->collect($row) : $service->reconcile(
                        SubscriptionBillingCycle::query()->forTenantId($tenant->id)->where('agreement_id', $row->id)->where('processor', 'shopify')->findOrFail((int) $this->option('cycle')))),
                };
                $result['provider_requests'] = true;
                $result['charges_customer'] = $action === 'collect';
            }
            $this->line(json_encode($result, JSON_PRETTY_PRINT));

            return self::SUCCESS;
        } catch (\Throwable $error) {
            $this->error($error instanceof \Symfony\Component\HttpKernel\Exception\HttpException ? $error->getMessage()
                : 'The original Shopify billing step needs review. Reconcile the original attempt before any further collection.');

            return self::FAILURE;
        }
    }

    private function cycleState(SubscriptionBillingCycle $cycle): array
    {
        return ['cycle_id' => $cycle->id, 'payment_status' => $cycle->payment_status, 'order_confirmed' => $cycle->handoff_status === 'confirmed',
            'review_required' => $cycle->last_error_code !== null];
    }
}

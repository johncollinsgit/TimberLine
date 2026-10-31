<?php

namespace App\Console\Commands;

use App\Models\SubscriptionBillingAgreement;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Subscriptions\SubscriptionReplacementAccess;
use App\Services\Subscriptions\SubscriptionShopifyContractService;
use App\Services\Subscriptions\SubscriptionShopifyMethodService;
use Illuminate\Console\Command;

class SubscriptionShopifyCommand extends Command
{
    protected $signature = 'subscriptions:shopify {action=summary} {--tenant=} {--actor=} {--agreement=} {--source-order=} {--terms-hash=} {--revision=} {--review-hash=} {--apply : Apply the named mapping/calculation/adoption step only; never collect}';

    protected $description = 'Preview exact saved-method reuse and gated paused-contract adoption; never activate or collect.';

    public function handle(SubscriptionShopifyMethodService $service, SubscriptionShopifyContractService $contracts): int
    {
        try {
            $tenant = Tenant::query()->where('slug', (string) $this->option('tenant'))->firstOrFail();
            $actor = User::findOrFail((int) $this->option('actor'));
            $row = SubscriptionBillingAgreement::query()->forTenantId($tenant->id)->where('processor', 'shopify')->findOrFail((int) $this->option('agreement'));
            app(SubscriptionReplacementAccess::class)->owner($tenant->id, $actor, $row->member_id, 'setup');
            $action = $this->argument('action');
            abort_unless(in_array($action, ['summary', 'verify', 'plan', 'calculate', 'refresh', 'adopt', 'recover'], true), 422, 'Choose summary, verify, plan, calculate, refresh, adopt or recover.');
            $result = ['status' => $row->status, 'method_verified' => $row->method_verified_at !== null,
                'reauthorization_required' => null, 'contract_adopted' => false, 'billing_enabled' => false, 'provider_mutations' => false];
            if ($action === 'verify') {
                $proof = $service->verify($tenant->id, $actor, $row->id, (string) $this->option('source-order'), (bool) $this->option('apply'));
                $result = array_replace($result, ['status' => $this->option('apply') ? 'verified_mapping_saved' : 'verified_mapping_preview',
                    'method_verified' => true, 'method_type' => $proof['method_type'], 'last_four' => $proof['last_four'],
                    'reauthorization_required' => $proof['reauthorization_required'], 'checked_at' => $proof['checked_at']]);
            } elseif (in_array($action, ['plan', 'calculate', 'refresh', 'adopt', 'recover'], true)) {
                $plan = $contracts->plan($tenant->id, $actor, $row->id);
                $result = array_replace($result, $plan);
                if ($this->option('apply') && $action !== 'plan') {
                    $result = array_replace($result, match ($action) {
                        'calculate' => $contracts->calculate($tenant->id, $actor, $row->id, (string) $this->option('terms-hash'), (int) $this->option('revision')),
                        'refresh' => $contracts->refresh($tenant->id, $actor, $row->id),
                        'adopt' => $contracts->adopt($tenant->id, $actor, $row->id, (string) $this->option('review-hash'), (int) $this->option('revision')),
                        'recover' => $contracts->recover($tenant->id, $actor, $row->id),
                    }, ['provider_requests' => true, 'provider_mutations' => $action !== 'refresh']);
                }
            } else {
                $result['provider_requests'] = false;
                $result['adoption_status'] = data_get($row->authorization_evidence, 'shopify_adoption.status', 'not_started');
                $result['contract_adopted'] = $result['adoption_status'] === 'adopted_paused';
            }
            $result['note'] = 'Method access and paused adoption do not activate billing. Original source stop, supported API capabilities and merchant acceptance remain required; no billing attempt is submitted.';
            $this->line(json_encode($result, JSON_PRETTY_PRINT));

            return self::SUCCESS;
        } catch (\Throwable $error) {
            $this->error($error instanceof \Symfony\Component\HttpKernel\Exception\HttpException ? $error->getMessage() : 'The original Shopify/source operation needs review. Inspect the retained original attempt; no billing attempt is submitted.');

            return self::FAILURE;
        }
    }
}

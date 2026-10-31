<?php

namespace App\Console\Commands;

use App\Models\SubscriptionBillingAgreement;
use App\Models\SubscriptionBillingCycle;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Subscriptions\SubscriptionBraintreeExecutionService;
use App\Services\Subscriptions\SubscriptionBraintreeMethodService;
use App\Services\Subscriptions\SubscriptionReplacementAccess;
use Illuminate\Console\Command;

class SubscriptionBraintreeCommand extends Command
{
    protected $signature = 'subscriptions:braintree {action=summary} {--tenant=} {--actor=} {--agreement=} {--cycle=} {--source-order=} {--source-connection=} {--reviewed-hash=} {--revision=} {--apply : Verify/persist or perform the explicitly gated operation}';

    protected $description = 'Verify original Braintree subscription methods and process only individually activated replacement agreements.';

    public function handle(SubscriptionBraintreeExecutionService $execution): int
    {
        try {
            $tenant = Tenant::query()->where('slug', (string) $this->option('tenant'))->firstOrFail();
            $actor = User::findOrFail((int) $this->option('actor'));
            $row = SubscriptionBillingAgreement::query()->forTenantId($tenant->id)->findOrFail((int) $this->option('agreement'));
            app(SubscriptionReplacementAccess::class)->owner($tenant->id, $actor, $row->member_id, 'setup');
            $action = $this->argument('action');
            abort_unless(in_array($action, ['summary', 'verify', 'accept', 'activate', 'collect', 'reconcile'], true), 422, 'Choose summary, verify, accept, activate, collect or reconcile.');
            if ($action === 'verify') {
                $proof = app(SubscriptionBraintreeMethodService::class)->verify($tenant->id, $actor, $row->id, (string) $this->option('source-order'), (bool) $this->option('apply'));
                $this->line(json_encode(['status' => $this->option('apply') ? 'verified_mapping_saved' : 'verified_mapping_preview',
                    'method_type' => $proof['method_type'], 'last_four' => $proof['last_four'], 'reauthorization_required' => false,
                    'checked_at' => $proof['checked_at'], 'provider_mutations' => false], JSON_PRETTY_PRINT));

                return self::SUCCESS;
            }
            if ($action === 'summary' || ! $this->option('apply')) {
                $this->line(json_encode(['status' => $row->status, 'processor' => $row->processor, 'revision' => $row->revision,
                    'terms_hash' => $row->terms_hash, 'method_verified' => $row->method_verified_at !== null,
                    'next_billing_date' => $row->next_billing_date?->toDateString(), 'provider_requests' => false,
                    'note' => 'Preview only. Activation and collection repeat current provider and source-stop checks.'], JSON_PRETTY_PRINT));

                return self::SUCCESS;
            }
            if ($action === 'accept') {
                $result = $execution->acceptMerchant($tenant->id, $actor, $row->id, (string) $this->option('reviewed-hash'));
            } elseif ($action === 'activate') {
                $row = $execution->activate($tenant->id, $actor, $row->id, (int) $this->option('source-connection'),
                    (string) $this->option('reviewed-hash'), (int) $this->option('revision'));
                $result = ['status' => $row->status, 'billing_owner' => 'everbranch', 'processor' => 'braintree', 'customer_charge_sent' => false];
            } else {
                if ($action === 'collect') {
                    $cycle = $execution->collect($row);
                } else {
                    $cycle = SubscriptionBillingCycle::query()->forTenantId($tenant->id)->where('agreement_id', $row->id)->findOrFail((int) $this->option('cycle'));
                    $cycle = $execution->reconcile($cycle);
                }
                $result = ['cycle_id' => $cycle->id, 'payment_status' => $cycle->payment_status, 'handoff_status' => $cycle->handoff_status,
                    'last_error_code' => $cycle->last_error_code, 'next_billing_date' => $row->fresh()->next_billing_date?->toDateString()];
            }
            $this->line(json_encode($result, JSON_PRETTY_PRINT));

            return self::SUCCESS;
        } catch (\Throwable $error) {
            $this->error($error instanceof \Symfony\Component\HttpKernel\Exception\HttpException ? $error->getMessage() : 'The original Braintree operation requires review. An uncertain payment must be reconciled before any further attempt.');

            return self::FAILURE;
        }
    }
}

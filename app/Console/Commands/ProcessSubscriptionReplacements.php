<?php

namespace App\Console\Commands;

use App\Models\SubscriptionBillingAgreement;
use App\Models\SubscriptionBillingCycle;
use App\Services\Subscriptions\SubscriptionBraintreeExecutionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class ProcessSubscriptionReplacements extends Command
{
    protected $signature = 'subscriptions:replacement-process {--collect} {--reconcile} {--limit=20}';

    protected $description = 'Process exact allowed replacement members and recover original Braintree transactions without resubmitting sales.';

    public function handle(SubscriptionBraintreeExecutionService $execution): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);
        if ($limit === false || $limit < 1 || $limit > 100) {
            $this->error('Use a limit from 1 to 100.');

            return self::FAILURE;
        }
        if (! Schema::hasTable('subscription_billing_agreements') || ! Schema::hasTable('subscription_billing_cycles')) {
            $this->line(json_encode(['available' => false, 'provider_requests' => false]));

            return self::SUCCESS;
        }
        $allowed = SubscriptionBillingAgreement::query()->where('processor', 'braintree')
            ->whereIn('tenant_id', config('subscriptions.replacement_tenant_ids', []))->whereIn('member_id', config('subscriptions.replacement_member_ids', []));
        if (! $this->option('collect') && ! $this->option('reconcile')) {
            $this->line(json_encode(['active' => (clone $allowed)->where('status', 'active')->count(), 'provider_requests' => false]));

            return self::SUCCESS;
        }
        $counts = ['processed' => 0, 'reconciled' => 0, 'review_required' => 0];
        if ($this->option('reconcile')) {
            $cycles = SubscriptionBillingCycle::query()->where('processor', 'braintree')->whereNotNull('payment_started_at')
                ->whereIn('agreement_id', (clone $allowed)->select('id'))->where(function ($query) {
                    $query->whereIn('payment_status', ['sending', 'unknown', 'processing', 'review'])
                        ->orWhere('handoff_status', '!=', 'confirmed')
                        ->orWhere('cycle_date', '>=', now()->subDays(7)->toDateString())
                        ->orWhere(fn ($query) => $query->where('cycle_date', '>=', now()->subDays(540)->toDateString())->where('updated_at', '<=', now()->subDay()));
                })->orderBy('updated_at')->orderBy('id')->limit($limit)->get();
            foreach ($cycles as $cycle) {
                try {
                    $execution->reconcile($cycle);
                    $counts['reconciled']++;
                } catch (\Throwable) {
                    $cycle->fresh()->update(['last_error_code' => 'original_braintree_or_shopify_review']);
                    $counts['review_required']++;
                } finally {
                    $cycle->touch();
                }
            }
        }
        if ($this->option('collect') && config('subscriptions.replacement_collection_enabled')) {
            foreach ((clone $allowed)->where(fn ($query) => $query->where('status', 'active')
                ->orWhere(fn ($query) => $query->where('status', 'paused')->whereNotNull('resume_on')))
                ->whereBetween('next_billing_date', [now()->subDay()->toDateString(), now()->addDay()->toDateString()])
                ->orderBy('updated_at')->orderBy('id')->limit($limit)->get() as $agreement) {
                $clock = now($agreement->terms['timezone']);
                if ($agreement->next_billing_date->toDateString() !== $clock->toDateString() || $clock->hour < $agreement->terms['billing_hour']) {
                    continue;
                }
                try {
                    $cycle = $execution->collect($agreement);
                    $counts[$cycle->payment_status === 'succeeded' ? 'processed' : 'review_required']++;
                } catch (\Throwable) {
                    $counts['review_required']++;
                } finally {
                    $agreement->touch();
                }
            }
        }
        $this->line(json_encode($counts));

        return self::SUCCESS;
    }
}

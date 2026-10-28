<?php

namespace App\Services\Subscriptions;

use App\Models\MarketingProfile;
use App\Models\ShopifyStore;
use App\Models\SubscriptionPilotEvent;
use App\Models\SubscriptionPilotMember;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Billing\TenantPaymentsReadinessService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/** Offline reporting and attributed preparation. No provider mutation or message transport. */
class SubscriptionPilotService
{
    public function __construct(private readonly SubscriptionAccessService $access) {}

    public function available(): bool
    {
        return Schema::hasTable('subscription_pilot_members') && Schema::hasTable('subscription_pilot_events');
    }

    public function prepare(int $tenantId, User $actor, array $input): SubscriptionPilotMember
    {
        $this->access->assertManager($tenantId, $actor);
        $data = Validator::make($input, [
            'shopify_store_id' => ['required', 'integer', 'min:1'],
            'marketing_profile_id' => ['nullable', 'integer', 'min:1'],
            'recharge_customer_id' => ['required', 'string', 'regex:/^[1-9][0-9]{0,18}$/D'],
            'shopify_customer_id' => ['required', 'string', 'regex:/^[1-9][0-9]{0,18}$/D'],
            'name' => ['required', 'string', 'max:190'], 'email' => ['required', 'email', 'max:254'],
            'target_date' => ['required', 'date_format:Y-m-d'], 'timezone' => ['required', 'timezone'],
            'currency' => ['required', 'string', 'regex:/^[A-Z]{3}$/D'],
            'expected_amount_cents' => ['nullable', 'integer', 'min:0', 'max:10000000'],
            'source_url' => ['required', 'url:https', 'max:500'], 'observed_at' => ['required', 'date'],
            'lines' => ['required', 'array', 'min:1', 'max:20'],
            'lines.*' => ['array:subscription_id,variant_id,label,quantity,unit_price_cents,billing_months,delivery_months,prepaid,finite_deliveries'],
            'lines.*.subscription_id' => ['nullable', 'string', 'regex:/^[1-9][0-9]{0,18}$/D'],
            'lines.*.variant_id' => ['required', 'string', 'regex:/^[1-9][0-9]{0,18}$/D'],
            'lines.*.label' => ['required', 'string', 'max:190'], 'lines.*.quantity' => ['required', 'integer', 'min:1', 'max:100'],
            'lines.*.unit_price_cents' => ['required', 'integer', 'min:0', 'max:10000000'],
            'lines.*.billing_months' => ['required', 'integer', 'min:1', 'max:24'],
            'lines.*.delivery_months' => ['required', 'integer', 'min:1', 'max:24'],
            'lines.*.prepaid' => ['required', 'boolean'], 'lines.*.finite_deliveries' => ['nullable', 'integer', 'min:1', 'max:120'],
        ])->validate();
        $store = ShopifyStore::query()->where('tenant_id', $tenantId)->findOrFail($data['shopify_store_id']);
        $origin = parse_url($data['source_url']);
        abort_unless(($origin['scheme'] ?? '') === 'https' && preg_match('/^[a-z0-9-]+\.admin\.rechargeapps\.com$/D', $origin['host'] ?? '')
            && ($origin['path'] ?? '') === '/merchant/customers/'.$data['recharge_customer_id']
            && empty($origin['user']) && empty($origin['pass']) && empty($origin['query']) && empty($origin['fragment']), 422, 'An exact Recharge customer source URL is required.');
        $observed = CarbonImmutable::parse($data['observed_at']);
        abort_if($observed->gt(now()->addMinutes(5)), 422, 'The source observation cannot be in the future.');
        if (! empty($data['marketing_profile_id'])) {
            $profile = MarketingProfile::query()->where('tenant_id', $tenantId)->findOrFail($data['marketing_profile_id']);
            $linked = $profile->links()->where('source_type', 'shopify_customer')
                ->where('source_id', $store->store_key.':'.$data['shopify_customer_id'])->exists();
            abort_unless($linked, 422, 'The selected existing customer must have this exact Shopify identity.');
        }
        foreach ($data['lines'] as $line) {
            abort_unless($line['billing_months'] % $line['delivery_months'] === 0, 422, 'Billing must contain a whole number of delivery intervals.');
            abort_unless((bool) $line['prepaid'] === ($line['billing_months'] > $line['delivery_months']), 422, 'Prepaid and delivery intervals must agree.');
        }
        $details = ['name' => $data['name'], 'email' => strtolower($data['email']), 'source_url' => $data['source_url'],
            'source_observed_at' => $observed->toIso8601String(), 'source_verification' => 'merchant_portal',
            'lines' => $data['lines'], 'candle_cash_allowed' => false, 'shop_domain' => $store->shop_domain,
            'source_billing_stopped' => false, 'customer_authorization_verified' => false];
        SubscriptionEvidence::assertContent($details);

        return DB::transaction(function () use ($tenantId, $actor, $data, $details): SubscriptionPilotMember {
            // The retained module row serializes first preparation as well as retries.
            DB::table('subscription_module_settings')->where('tenant_id', $tenantId)->lockForUpdate()->get();
            $existing = SubscriptionPilotMember::query()->forTenantId($tenantId)->where('shopify_store_id', $data['shopify_store_id'])
                ->where('recharge_customer_id', $data['recharge_customer_id'])->lockForUpdate()->first();
            if ($existing) {
                abort_unless($existing->shopify_customer_id === $data['shopify_customer_id'] && $existing->details === $details
                    && $existing->target_date->toDateString() === $data['target_date'] && $existing->expected_amount_cents === ($data['expected_amount_cents'] ?? null)
                    && $existing->currency === $data['currency'] && $existing->timezone === $data['timezone']
                    && $existing->marketing_profile_id === ($data['marketing_profile_id'] ?? null), 409, 'The retained pilot preparation differs. Review it before replacing source terms.');

                return $existing;
            }

            return SubscriptionPilotMember::query()->create([
                'tenant_id' => $tenantId, 'shopify_store_id' => $data['shopify_store_id'], 'marketing_profile_id' => $data['marketing_profile_id'] ?? null,
                'recharge_customer_id' => $data['recharge_customer_id'], 'shopify_customer_id' => $data['shopify_customer_id'],
                'target_date' => $data['target_date'], 'timezone' => $data['timezone'], 'currency' => $data['currency'],
                'expected_amount_cents' => $data['expected_amount_cents'] ?? null, 'details' => $details, 'prepared_by' => $actor->id,
                'billing_owner' => 'recharge', 'status' => 'prepared',
            ]);
        });
    }

    /** Manual attribution cannot assert verified payments or complete provider coverage. */
    public function observe(int $tenantId, User $actor, int $memberId, array $input): SubscriptionPilotEvent
    {
        $this->access->assertManager($tenantId, $actor);
        $member = SubscriptionPilotMember::query()->forTenantId($tenantId)->findOrFail($memberId);
        $data = Validator::make($input, [
            'provider' => ['required', Rule::in(['recharge', 'shopify', 'stripe'])],
            'kind' => ['required', Rule::in(['payment', 'order', 'fulfillment', 'coverage'])],
            'provider_reference' => ['required', 'string', 'max:190', 'regex:/^[A-Za-z0-9_:#.\/-]+$/D'],
            'status' => ['required', Rule::in(['success', 'failed', 'pending', 'refunded', 'fulfilled', 'unfulfilled', 'unavailable'])],
            'currency' => ['nullable', 'string', 'regex:/^[A-Z]{3}$/D'],
            'amount_cents' => ['nullable', 'integer', 'min:0', 'max:10000000'],
            'cycle_date' => ['nullable', 'date_format:Y-m-d'], 'occurred_at' => ['required', 'date'], 'observed_at' => ['required', 'date'],
            'source_url' => ['required', 'url:https', 'max:500'], 'note' => ['nullable', 'string', 'max:500'],
            'payment_reference' => ['nullable', 'string', 'max:190'],
        ])->validate();
        abort_unless(parse_url($data['source_url'], PHP_URL_HOST) === parse_url($member->details['source_url'], PHP_URL_HOST)
            && ($data['provider'] === 'recharge' || str_contains($data['source_url'], '/merchant/')), 422, 'A source observation must retain the reviewed merchant origin.');
        abort_if(CarbonImmutable::parse($data['observed_at'])->gt(now()->addMinutes(5)) || CarbonImmutable::parse($data['occurred_at'])->gt(CarbonImmutable::parse($data['observed_at'])), 422, 'The observation times require review.');
        abort_if($data['kind'] === 'payment' && (! isset($data['currency'], $data['amount_cents'], $data['cycle_date']) || $data['currency'] !== $member->currency), 422, 'Payment amount, currency and billing cycle must be explicit.');
        $key = SubscriptionEvidence::digest(implode('|', [$data['provider'], $data['kind'], $data['provider_reference'], $data['status']]));
        $evidence = array_intersect_key($data, array_flip(['source_url', 'note', 'payment_reference']));

        return DB::transaction(function () use ($member, $actor, $data, $key, $evidence): SubscriptionPilotEvent {
            SubscriptionPilotMember::query()->forTenantId($member->tenant_id)->whereKey($member->id)->lockForUpdate()->firstOrFail();
            $attributes = ['provider' => $data['provider'], 'kind' => $data['kind'], 'provider_reference' => $data['provider_reference'],
                'status' => $data['status'], 'verification' => 'merchant_portal', 'currency' => $data['currency'] ?? null,
                'amount_cents' => $data['amount_cents'] ?? null, 'cycle_date' => $data['cycle_date'] ?? null,
                'occurred_at' => CarbonImmutable::parse($data['occurred_at'])->utc(), 'observed_at' => CarbonImmutable::parse($data['observed_at'])->utc(), 'evidence' => $evidence];
            $existing = SubscriptionPilotEvent::query()->forTenantId($member->tenant_id)->where('member_id', $member->id)->where('event_key', $key)->first();
            if ($existing) {
                abort_unless($existing->amount_cents === $attributes['amount_cents'] && $existing->currency === $attributes['currency']
                    && $existing->cycle_date?->toDateString() === ($data['cycle_date'] ?? null) && $existing->evidence === $evidence
                    && $existing->occurred_at->equalTo($attributes['occurred_at']), 409, 'Conflicting payment evidence requires review.');

                return $existing;
            }

            return SubscriptionPilotEvent::query()->create($attributes + ['tenant_id' => $member->tenant_id,
                'member_id' => $member->id, 'event_key' => $key, 'actor_user_id' => $actor->id]);
        });
    }

    public function dashboard(int $tenantId, User $actor, ?int $memberId = null): array
    {
        $this->access->assertManager($tenantId, $actor);
        if (! $this->available()) {
            return ['available' => false, 'members' => [], 'selected' => null, 'events' => [], 'chart' => ['labels' => [], 'datasets' => []]];
        }
        $members = SubscriptionPilotMember::query()->forTenantId($tenantId)->orderBy('id')->get();
        $selected = $memberId ? $members->firstWhere('id', $memberId) : null;
        abort_if($memberId && ! $selected, 404);
        $events = SubscriptionPilotEvent::query()->forTenantId($tenantId)->whereIn('member_id', $selected ? [$selected->id] : $members->pluck('id'))
            ->orderBy('occurred_at')->orderBy('id')->get();
        $cards = $members->map(fn ($member) => $this->memberReadout($member, $events->where('member_id', $member->id)))->all();
        $labels = $events->where('kind', 'payment')->pluck('cycle_date')->filter()->map(fn ($date) => $date->format('Y-m'))
            ->merge(($selected ? collect([$selected]) : $members)->map(fn ($m) => $m->target_date->format('Y-m')))->unique()->sort()->values()->all();
        $datasets = [];
        $singleCurrency = $members->pluck('currency')->unique()->count() === 1;
        $latest = $this->latest($events);
        foreach (['recharge' => 'Recharge portal payments', 'stripe' => 'Stripe payments', 'shopify' => 'Shopify Payments collections'] as $provider => $label) {
            $datasets[] = ['label' => $label, 'data' => array_map(function ($month) use ($latest, $provider, $singleCurrency) {
                $rows = $latest->where('kind', 'payment')->where('provider', $provider)->where('status', 'success')
                    ->filter(fn ($e) => $e->cycle_date?->format('Y-m') === $month);

                return $singleCurrency && $rows->isNotEmpty() ? round($rows->sum('amount_cents') / 100, 2) : null;
            }, $labels)];
        }
        $tenant = Tenant::query()->findOrFail($tenantId);
        $accountReady = app(TenantPaymentsReadinessService::class)->readinessFor($tenant);

        return ['available' => true, 'members' => $cards, 'selected' => $selected?->id,
            'events' => $events->sortByDesc('occurred_at')->take(100)->values(),
            'chart' => ['labels' => $labels, 'datasets' => $datasets],
            'payment_ready' => $accountReady['ready'], 'payment_blockers' => $accountReady['blockers'],
            'recharge_connected' => \App\Models\SubscriptionRechargeConnection::query()->forTenantId($tenantId)->where('status', 'verified')->exists(),
            'total_prepared' => count($cards), 'currency' => $members->pluck('currency')->unique()->count() === 1 ? $members->first()?->currency : null,
        ];
    }

    private function memberReadout(SubscriptionPilotMember $member, $events): array
    {
        $current = $this->latest($events)->filter(fn ($event) => $event->cycle_date?->toDateString() === $member->target_date->toDateString());
        $payments = $current->where('kind', 'payment')->where('status', 'success');
        // A mirrored Shopify order is never a second payment. Distinct processor
        // payment references are counted, and one payment cannot be counted twice.
        $unique = $payments->unique(fn ($event) => $event->provider.'|'.$event->provider_reference);
        $providers = $unique->pluck('provider')->unique();
        $duplicate = $providers->contains('stripe') && ($providers->contains('recharge') || $providers->contains('shopify'));
        $excess = $member->expected_amount_cents !== null && $unique->sum('amount_cents') > $member->expected_amount_cents;
        $orders = $current->where('kind', 'order')->where('provider', 'shopify')->where('status', 'success');
        $observed = $events->max('observed_at');
        $enrollment = \Illuminate\Support\Facades\Schema::hasTable('subscription_pilot_enrollments')
            ? \App\Models\SubscriptionPilotEnrollment::query()->forTenantId($member->tenant_id)->where('member_id', $member->id)->first() : null;
        $executionCycle = $enrollment ? \App\Models\SubscriptionPilotCycle::query()->forTenantId($member->tenant_id)->where('enrollment_id', $enrollment->id)
            ->whereDate('cycle_date', $member->target_date->toDateString())->first() : null;
        $possible = $duplicate || $excess || data_get($executionCycle?->reconciliation, 'possible_duplicate') === true;
        $coverage = data_get($executionCycle?->reconciliation, 'coverage');
        $clear = $coverage && ($coverage['status'] ?? null) === 'observed_clear'
            && CarbonImmutable::parse($coverage['checked_at'])->gt(now()->subMinutes(15));
        $blockers = [];
        if ($member->billing_owner === 'recharge') {
            $blockers[] = 'Recharge billing remains primary';
        }
        if (! $enrollment?->authorized_at) {
            $blockers[] = 'Customer Stripe authorization required';
        }
        if (! $enrollment?->activated_at) {
            $blockers[] = 'Fresh Recharge billing-stop and charge checks required before activation';
        }
        if ($executionCycle?->last_error_code) {
            $blockers[] = 'Operator review: '.str_replace('_', ' ', $executionCycle->last_error_code);
        }
        if (! $enrollment?->activated_at) {
            $blockers[] = 'Stripe merchant, signed callback and Shopify bridge readiness required';
        }

        return ['id' => $member->id, 'name' => $member->details['name'], 'email' => $member->details['email'],
            'status' => $member->status, 'billing_owner' => $member->billing_owner,
            'target_date' => $member->target_date->toDateString(), 'currency' => $member->currency,
            'expected_amount_cents' => $member->expected_amount_cents, 'lines' => $member->details['lines'],
            'source_url' => $member->details['source_url'], 'source_observed_at' => $member->details['source_observed_at'],
            'last_observed_at' => $observed, 'paid_cents' => $unique->sum('amount_cents'),
            'payment_status' => $unique->isNotEmpty() ? 'Observed payment' : (now($member->timezone)->toDateString() < $member->target_date->toDateString() ? 'Awaiting cycle' : 'Not verified'),
            'shopify_status' => $orders->isNotEmpty() ? 'Observed order' : 'Not verified',
            'fulfillment_status' => $current->where('kind', 'fulfillment')->where('status', 'fulfilled')->isNotEmpty() ? 'Observed fulfillment' : 'Not verified',
            'duplicate_status' => $possible ? 'Possible duplicate' : ($clear ? 'No additional payment observed at '.CarbonImmutable::parse($coverage['checked_at'])->setTimezone($member->timezone)->format('g:i a T') : 'Not verified'),
            'duplicate_alert' => $possible, 'execution_status' => $enrollment?->status,
            'can_review_execution' => config('subscriptions.pilot_setup_enabled') && in_array($member->tenant_id, config('subscriptions.pilot_tenant_ids', []), true),
            'coverage_checked_at' => $coverage['checked_at'] ?? null, 'candle_cash_allowed' => false,
            'activation_blockers' => $blockers,
        ];
    }

    private function latest($events)
    {
        return $events->groupBy(fn ($e) => $e->provider.'|'.$e->kind.'|'.$e->provider_reference)->map(function ($group) {
            $provider = $group->filter(fn ($event) => in_array($event->verification, ['shopify_read', 'shopify_ingest', 'stripe_read'], true));
            if ($provider->isNotEmpty()) {
                return $provider->sortByDesc('id')->sortByDesc(fn ($event) => $event->provider === 'stripe' ? $event->observed_at->toIso8601String() : (string) data_get($event->evidence, 'shopify_updated_at'))->first();
            }

            return $group->sortByDesc('id')->sortByDesc('observed_at')->first();
        })->values();
    }

    /** Called only with an owned Shopify read or the existing verified ingestion lane. */
    public function projectShopifyOrder(int $tenantId, string $storeKey, array $order, string $verification = 'shopify_ingest'): int
    {
        if (! $this->available() || ! in_array($verification, ['shopify_read', 'shopify_ingest'], true) || empty($order['id']) || ($order['test'] ?? false)) {
            return 0;
        }
        $store = ShopifyStore::query()->forTenantId($tenantId)->where('store_key', $storeKey)->first();
        if (! $store || ! preg_match('/^[1-9][0-9]*$/D', (string) data_get($order, 'customer.id', ''))) {
            return 0;
        }
        $members = SubscriptionPilotMember::query()->forTenantId($tenantId)->where('shopify_store_id', $store->id)
            ->where('shopify_customer_id', (string) data_get($order, 'customer.id'))->get();
        $attributes = collect($order['note_attributes'] ?? [])->mapWithKeys(fn ($a) => [(string) ($a['name'] ?? '') => $a['value'] ?? null]);
        $subscriptionIds = array_filter(array_map('trim', explode(',', (string) $attributes->get('rc_subscription_ids', ''))));

        $count = 0;
        foreach ($members as $member) {
            $expected = array_filter(array_column($member->details['lines'], 'subscription_id'));
            $pilotCycle = null;
            if (! $subscriptionIds && \Illuminate\Support\Facades\Schema::hasTable('subscription_pilot_cycles')) {
                $pilotCycle = \App\Models\SubscriptionPilotCycle::query()->forTenantId($tenantId)->where('shopify_order_id', (string) $order['id'])
                    ->where('public_id', $attributes->get('everbranch_pilot_cycle'))->first();
                if ($pilotCycle) {
                    $enrollment = \App\Models\SubscriptionPilotEnrollment::query()->forTenantId($tenantId)->find($pilotCycle->enrollment_id);
                    if (! $enrollment || ! $pilotCycle->paid_at || ! $pilotCycle->terms['merchant']['livemode'] || $enrollment->member_id !== $member->id || $pilotCycle->terms['shopify_store_id'] !== $store->id
                        || $pilotCycle->terms['shop_domain'] !== $store->shop_domain
                        || $pilotCycle->payment_intent_id !== $attributes->get('everbranch_stripe_payment_intent')
                        || $pilotCycle->terms['merchant']['account_id'] !== $attributes->get('everbranch_stripe_account')
                        || $pilotCycle->terms['terms_hash'] !== $attributes->get('everbranch_terms_hash')) {
                        $pilotCycle = null;
                    }
                }
            }
            if ((! $pilotCycle && (! $expected || ! $subscriptionIds || array_diff($subscriptionIds, $expected))) || $member->details['shop_domain'] !== $store->shop_domain) {
                continue;
            }
            $at = CarbonImmutable::parse($order['processed_at'] ?? $order['created_at']);
            $updated = CarbonImmutable::parse($order['updated_at'] ?? $order['processed_at'] ?? $order['created_at']);
            $cycle = $pilotCycle ? $pilotCycle->cycle_date->toDateString() : $at->setTimezone($member->timezone)->toDateString();
            $financial = strtolower((string) ($order['financial_status'] ?? ''));
            $status = $financial === 'paid' && empty($order['cancelled_at']) ? 'success' : ($financial === 'refunded' ? 'refunded' : 'pending');
            $currency = (string) ($order['currency'] ?? '');
            if ($currency !== $member->currency) {
                continue;
            }
            $amount = $this->cents((string) ($order['total_price'] ?? ''));
            $source = ['source_url' => 'https://'.$store->shop_domain.'/admin/orders/'.$order['id'],
                'shopify_order_id' => (string) $order['id'], 'shopify_updated_at' => $updated->toIso8601String(),
                'subscription_ids' => array_values($subscriptionIds), 'source_charge_id' => $attributes->get('rc_charge_id')];
            $base = ['currency' => $currency, 'cycle_date' => $cycle, 'occurred_at' => $at, 'observed_at' => now(), 'verification' => $verification, 'evidence' => $source];
            $this->appendProjection($member, $base + ['provider' => 'shopify', 'kind' => 'order', 'provider_reference' => (string) $order['id'], 'status' => $status, 'amount_cents' => $amount]);
            if ($status === 'success' && strtolower((string) ($order['fulfillment_status'] ?? '')) === 'fulfilled') {
                $this->appendProjection($member, array_replace($base, ['provider' => 'shopify', 'kind' => 'fulfillment', 'provider_reference' => (string) $order['id'], 'status' => 'fulfilled', 'amount_cents' => null, 'occurred_at' => $updated]));
            }
            // Imported/manual external transactions are not another payment.
            // Only an actual successful Shopify Payments sale/capture is projected.
            foreach (($order['transactions'] ?? []) as $transaction) {
                if (strtolower((string) ($transaction['gateway'] ?? '')) !== 'shopify_payments'
                    || strtolower((string) ($transaction['status'] ?? '')) !== 'success'
                    || ! in_array(strtolower((string) ($transaction['kind'] ?? '')), ['sale', 'capture'], true)
                    || empty($transaction['id'])) {
                    continue;
                }
                $this->appendProjection($member, array_replace($base, ['provider' => 'shopify', 'kind' => 'payment',
                    'provider_reference' => (string) $transaction['id'], 'status' => $status,
                    'amount_cents' => $this->cents((string) ($transaction['amount'] ?? ''))]));
            }
            $count++;
        }

        return $count;
    }

    private function cents(string $amount): int
    {
        abort_unless(preg_match('/^([0-9]{1,8})(?:\.([0-9]{1,2}))?$/D', $amount, $matches), 422, 'Exact payment amount requires review.');

        return ((int) $matches[1] * 100) + (int) str_pad($matches[2] ?? '', 2, '0');
    }

    private function appendProjection(SubscriptionPilotMember $member, array $attributes): void
    {
        $key = SubscriptionEvidence::digest(SubscriptionEvidence::canonical([
            'provider' => $attributes['provider'], 'kind' => $attributes['kind'], 'reference' => $attributes['provider_reference'],
            'status' => $attributes['status'], 'amount_cents' => $attributes['amount_cents'], 'source_revision' => $attributes['evidence']['shopify_updated_at'],
        ]));
        DB::transaction(function () use ($member, $attributes, $key): void {
            SubscriptionPilotMember::query()->forTenantId($member->tenant_id)->whereKey($member->id)->lockForUpdate()->firstOrFail();
            SubscriptionPilotEvent::query()->firstOrCreate(['tenant_id' => $member->tenant_id, 'member_id' => $member->id, 'event_key' => $key], $attributes);
        });
    }
}

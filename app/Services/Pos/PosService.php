<?php

declare(strict_types=1);

namespace App\Services\Pos;

use App\Services\PaymentsCore\Contract;
use App\Services\PaymentsCore\MerchantBinding;
use App\Services\PaymentsCore\PaymentCore;
use App\Services\PaymentsCore\PaymentJournal;
use App\Services\PaymentsCore\SaleSnapshot;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

final class PosService
{
    public function __construct(private PosStore $store, private PosAccess $access, private PosReadiness $readiness,
        private PosCatalog $catalog, private NativePosCommerce $native, private ShopifyPosCommerce $shopify,
        private SquareClient $square, private PaymentJournal $journal, private SquareNativeProvider $provider, private PosCustomerDirectory $customers, private PosCustomerRewards $rewards) {}

    private function core(): PaymentCore
    {
        return new PaymentCore($this->journal, ['square_native' => $this->provider], $this->access);
    }

    public function registers(): array
    {
        $actor = $this->access->actor();
        $rows = [];
        foreach ($this->store->rows('registers', $actor['tenant_id'])->orderBy('name')->limit(100)->get() as $register) {
            try {
                $scope = $this->access->register($register->id);
            } catch (PosException $error) {
                if ($error->status === 404) {
                    continue;
                }
                throw $error;
            }
            $rows[] = ['id' => $register->id, 'name' => $register->name, 'location' => $register->location_name,
                'catalog_source' => $register->catalog_source, 'environment' => $scope['connection']->environment,
                'can_create_customers' => in_array($scope['role'], ['owner', 'tenant_owner', 'admin'], true),
                'setup_blockers' => config('pos_live.onboarding_enabled') === true ? $this->readiness->onboardingBlockers($register, $scope['connection'], $scope) : $this->readiness->blockers($register, $scope['connection']),
                'blockers' => $this->readiness->blockers($register, $scope['connection'])];
        }

        return ['registers' => $rows, 'runtime_id' => $this->store->runtime(), 'square_application_id' => config('pos_live.square_application_id')];
    }

    public function catalog(string $register): array
    {
        return ['items' => $this->catalog->listing($this->access->register($register, 'catalog'))];
    }

    public function approvedQuotes(string $register): array
    {
        return ['quotes' => $this->catalog->approvedQuotes($this->access->register($register, 'catalog'))];
    }

    public function authorizeDevice(string $register): array
    {
        $scope = $this->access->register($register, 'setup');
        if (config('pos_live.onboarding_enabled') === true && in_array($scope['role'], ['owner', 'tenant_owner', 'admin'], true)) {
            if ($this->readiness->onboardingBlockers($scope['register'], $scope['connection'], $scope)) {
                throw new PosException('onboarding_not_ready', 503);
            }
            $this->store->event($scope['tenant_id'], $register, 'native_onboarding_authorized',
                ['actor_id' => $scope['actor_id'], 'device_id' => $scope['device_id'], 'build' => $scope['build']]);
        } else {
            $scope = $this->refreshStaleMerchant($scope);
            $this->access->assertAllowed($this->access->binding($scope), 'collect');
        }
        $this->square->verify($scope['connection']);
        $credentials = $this->store->decrypt($scope['connection']->credentials);

        return ['application_id' => config('pos_live.square_application_id'), 'access_token' => $credentials['access_token'],
            'location_id' => $scope['connection']->provider_location_id, 'merchant_id' => $scope['connection']->merchant_id,
            'environment' => $scope['connection']->environment,
            'can_link_apple_account' => in_array($scope['role'], ['owner', 'tenant_owner', 'admin'], true)];
    }

    public function requestStatus(string $register, string $requestId): array
    {
        $scope = $this->access->register($register);
        $sale = $this->store->rows('sales', $scope['tenant_id'])->where('register_id', $register)->where('request_id', $requestId)->first();
        if (! $sale) {
            throw new PosException('quote_not_found', 404);
        }

        return $this->inspect($register, $sale->id);
    }

    public function quote(string $register, array $input): array
    {
        $scope = $this->access->register($register, 'quote');
        $binding = $this->access->binding($scope);
        $hash = Contract::hash([$binding->snapshot(), $scope['actor_id'], $input]);
        $old = $this->store->rows('sales', $scope['tenant_id'])->where('request_id', $input['request_id'])->first();
        if ($old) {
            if (! hash_equals($this->snapshot($old)['input_hash'], $hash)) {
                throw new PosException('request_conflict');
            }

            return $this->projection($old);
        }
        $terms = $this->catalog->terms($scope, $input);
        $id = bin2hex(random_bytes(16));
        $sale = new SaleSnapshot($id, 'usd', $terms['lines']);
        $settings = $this->store->decrypt($scope['register']->configuration);
        if (empty($settings['tax_valid_until']) || strtotime($settings['tax_valid_until']) <= time()) {
            throw new PosException('tax_review_expired', 503);
        }
        if (($settings['customer_required'] ?? $scope['register']->catalog_source === 'shopify') && empty($input['customer_profile_id'])) {
            throw new PosException('customer_selection_required', 422);
        }
        $customer = isset($input['customer_profile_id']) ? $this->customers->freeze($scope, $input['customer_profile_id']) : null;
        $rewards = $customer ? $this->rewards->freeze($scope, $customer) : ['enabled' => false];
        $snapshot = ['binding' => $binding->snapshot(), 'connection_id' => $scope['connection']->id,
            'build' => $scope['build'], 'device_id' => $scope['device_id'], 'input_hash' => $hash, 'terms' => $terms, 'customer' => $customer ?? ($input['customer'] ?? null), 'customer_rewards' => $rewards,
            'register_name' => $scope['register']->name, 'location_name' => $scope['register']->location_name,
            'sale' => $sale->snapshot(), 'configuration' => $settings];
        $claim = $terms['source'] === 'website_invoice' ? Contract::hash(['invoice', $terms['source_order_id'], $terms['invoice_hash']]) : null;
        try {
            $this->store->db->transaction(function () use ($scope, $input, $register, $id, $sale, $snapshot, $claim, $terms, $customer): void {
                $this->store->rows('sales', $scope['tenant_id'])->insert($this->store->values($scope['tenant_id'], [
                    'id' => $id, 'register_id' => $register, 'location_id' => $scope['register']->location_id,
                    'actor_id' => $scope['actor_id'], 'request_id' => $input['request_id'], 'payment_request_id' => (string) Str::uuid(),
                    'sdk_attempt_id' => (string) Str::uuid(), 'active_source_claim' => $claim,
                    'snapshot' => $this->store->encrypt($snapshot), 'snapshot_hash' => Contract::hash($snapshot),
                    'amount_minor' => $sale->amountMinor, 'currency' => 'usd', 'commerce_source' => $terms['source'], 'state' => 'quoted', 'stock_state' => 'unprepared',
                    'created_at' => now(), 'expires_at' => now()->addMinutes(10),
                ]));
                if ($customer) {
                    $this->customers->linkSale($scope, $id, $customer['id']);
                }
            }, 3);
        } catch (QueryException $error) {
            $old = $this->store->rows('sales', $scope['tenant_id'])->where('request_id', $input['request_id'])->first();
            if ($old && hash_equals($this->snapshot($old)['input_hash'], $hash)) {
                return $this->projection($old);
            }
            throw new PosException('quote_already_in_progress');
        }
        $this->access->register($register, 'quote');

        return $this->projection($this->sale($scope, $id));
    }

    public function start(string $register, string $id): array
    {
        $scope = $this->access->register($register, 'collect');
        $key = 'pos-start-'.hash('sha256', $this->store->runtime().':'.$scope['tenant_id'].':'.$register);

        return Cache::lock($key, 180)->block(5, function () use ($scope, $register, $id): array {
            $scope = $this->access->register($register, 'collect');
            $sale = $this->sale($scope, $id);
            $snapshot = $this->snapshot($sale);
            $binding = MerchantBinding::restore($snapshot['binding']);
            if ($sale->attempt_id || $this->journal->findByRequest($scope['tenant_id'], $sale->payment_request_id)) {
                // Never issue another native collection descriptor on an HTTP retry.
                return $this->inspect($register, $id);
            }
            $scope = $this->refreshStaleMerchant($scope, (int) $sale->amount_minor);
            $blockers = $this->readiness->blockers($scope['register'], $scope['connection'], (int) $sale->amount_minor);
            if ($blockers) {
                throw new PosException('collection_not_ready', 503);
            }
            if (strtotime($sale->expires_at) <= time() || $sale->state !== 'quoted') {
                throw new PosException('quote_expired');
            }
            if (! hash_equals($binding->fingerprint(), $this->access->binding($scope)->fingerprint())) {
                throw new PosException('merchant_binding_changed');
            }
            if (isset($snapshot['customer']['id'])) {
                $currentCustomer = $this->customers->freeze($scope, (int) $snapshot['customer']['id']);
                if ($currentCustomer['id'] !== $snapshot['customer']['id'] || ($currentCustomer['shopify_customer_id'] ?? null) !== ($snapshot['customer']['shopify_customer_id'] ?? null)) {
                    throw new PosException('customer_selection_changed');
                }
                if (($snapshot['customer_rewards']['enabled'] ?? false) && ($this->rewards->policy($scope['tenant_id'])['version'] ?? null) !== $snapshot['customer_rewards']['version']) {
                    throw new PosException('customer_rewards_policy_changed');
                }
            }
            $this->square->verify($scope['connection']);
            if ($snapshot['terms']['source'] === 'shopify') {
                $this->shopify->prepare($scope, $sale, $snapshot);
            } else {
                $this->native->prepare($sale, $snapshot);
            }
            $this->access->assertAllowed($binding, 'collect');
            $row = $this->core()->start($binding, new SaleSnapshot($id, 'usd', $snapshot['sale']['lines']), $sale->payment_request_id);
            $this->store->rows('sales', $scope['tenant_id'])->where('id', $id)->update(['attempt_id' => $row['id'], 'state' => $row['state']]);
            $this->access->assertAllowed($binding, 'collect');
            $claimed = $this->store->rows('sales', $scope['tenant_id'])->where('id', $id)->whereNull('descriptor_issued_at')->update(['descriptor_issued_at' => now()]);
            if ($claimed !== 1) {
                throw new PosException('recovery_required');
            }
            $credentials = $this->store->decrypt($scope['connection']->credentials);
            $this->store->event($scope['tenant_id'], $id, 'native_descriptor_issued');

            return [...$this->projection($this->sale($scope, $id)), 'collection' => [
                'application_id' => config('pos_live.square_application_id'), 'access_token' => $credentials['access_token'],
                'location_id' => $binding->locationId, 'merchant_id' => $binding->merchantAccount,
                'environment' => $scope['connection']->environment, 'amount_minor' => $row['amount_minor'], 'currency' => 'USD',
                'payment_attempt_id' => $sale->sdk_attempt_id, 'reference_id' => $row['request_id'],
                'sale_id' => $id, 'expires_at' => now()->addSeconds(90)->toIso8601String(),
                'online_only' => true, 'application_fee_minor' => 0, 'tips_enabled' => false,
            ]];
        });
    }

    public function inspect(string $register, string $id): array
    {
        $scope = $this->access->register($register);
        $sale = $this->sale($scope, $id);
        $this->snapshot($sale);
        $operation = $this->journal->findByRequest($scope['tenant_id'], $sale->payment_request_id);
        if ($operation) {
            $this->recordOutcome($sale, $operation);
            $sale = $this->sale($scope, $id);
        }

        return $this->projection($sale);
    }

    public function discard(string $register, string $requestId): array
    {
        $scope = $this->access->register($register, 'recover');
        $key = 'pos-start-'.hash('sha256', $this->store->runtime().':'.$scope['tenant_id'].':'.$register);

        return Cache::lock($key, 180)->block(5, function () use ($register, $requestId): array {
            $scope = $this->access->register($register, 'recover');
            $sale = $this->store->rows('sales', $scope['tenant_id'])->where('register_id', $register)->where('request_id', $requestId)->first();
            if (! $sale) {
                // A delayed quote request remains harmless: it cannot collect
                // without a separate descriptor request for its sale ID.
                return ['discarded' => true];
            }
            $sale = $this->sale($scope, $sale->id);
            if ($sale->descriptor_issued_at || $sale->attempt_id || $sale->source_reference
                || $this->journal->findByRequest($scope['tenant_id'], $sale->payment_request_id)) {
                throw new PosException('payment_recovery_required');
            }
            $this->store->rows('sales', $scope['tenant_id'])->where('id', $sale->id)->update(['state' => 'cancelled']);
            $this->native->release($sale, $this->snapshot($sale));
            $this->store->event($scope['tenant_id'], $sale->id, 'unstarted_quote_discarded');

            return ['discarded' => true];
        });
    }

    public function reconcile(string $register, string $id, ?string $hint = null): array
    {
        $scope = $this->access->register($register, 'recover');
        $sale = $this->sale($scope, $id);
        if ($hint !== null) {
            $this->store->rows('sales', $scope['tenant_id'])->where('id', $id)->update(['payment_hint' => $this->store->encrypt(['payment_id' => $hint])]);
        }
        $snapshot = $this->snapshot($sale);
        $operation = $this->journal->findByRequest($scope['tenant_id'], $sale->payment_request_id);
        if (! $operation) {
            throw new PosException('payment_not_started');
        }
        $row = $this->core()->recover(MerchantBinding::restore($snapshot['binding']), $operation['id']);
        $this->recordOutcome($sale, $row);
        app(PosOutbox::class)->drainSale($scope, $id);

        return $this->projection($this->sale($scope, $id));
    }

    public function expireUnstarted(object $sale): void
    {
        $key = 'pos-start-'.hash('sha256', $this->store->runtime().':'.$sale->tenant_id.':'.$sale->register_id);
        Cache::lock($key, 180)->get(function () use ($sale): void {
            $current = $this->store->rows('sales', (int) $sale->tenant_id)->where('id', $sale->id)->firstOrFail();
            if ($current->state !== 'quoted' || strtotime($current->expires_at) > time() || $current->descriptor_issued_at
                || $current->source_reference || $current->attempt_id || $this->journal->findByRequest((int) $sale->tenant_id, $sale->payment_request_id)) {
                return;
            }
            $this->store->rows('sales', (int) $sale->tenant_id)->where('id', $sale->id)->update(['state' => 'cancelled']);
            $this->native->release($current, $this->snapshot($current));
        });
    }

    public function recordOutcome(object $sale, array $operation): void
    {
        $tenant = (int) $sale->tenant_id;
        if ($operation['sale_id'] !== $sale->id || $operation['tenant_id'] !== $tenant || $operation['kind'] !== 'payment') {
            throw new PosException('payment_sale_mismatch');
        }
        $this->store->db->transaction(function () use ($tenant, $sale, $operation): void {
            $this->store->rows('sales', $tenant)->where('id', $sale->id)->lockForUpdate()->firstOrFail();
            $this->store->rows('sales', $tenant)->where('id', $sale->id)->update(['attempt_id' => $operation['id'], 'state' => $operation['state']]);
            if (in_array($operation['state'], ['succeeded', 'failed', 'cancelled'], true)) {
                $this->enqueue($tenant, $sale->id, $operation['state'] === 'succeeded' ? 'finalize' : 'release', ['operation_id' => $operation['id']]);
                if ($operation['state'] === 'succeeded') {
                    $this->enqueue($tenant, $sale->id, 'customer_purchase', ['operation_id' => $operation['id']]);
                }
            }
        }, 3);
    }

    public function refund(string $register, string $id, array $input): array
    {
        $scope = $this->access->register($register, 'refund');
        $sale = $this->sale($scope, $id);
        $snapshot = $this->snapshot($sale);
        if (! $sale->attempt_id || $sale->stock_state !== 'committed') {
            throw new PosException('sale_finalization_required');
        }
        $this->provider->audit($this->journal->get($scope['tenant_id'], $sale->attempt_id));
        if ($input['amount_minor'] > (int) $sale->amount_minor - app(PosCustomerPurchases::class)->confirmedRefunds($sale)) {
            throw new PosException('refund_exceeds_remaining_payment');
        }
        $row = $this->core()->refund(MerchantBinding::restore($snapshot['binding']), $sale->attempt_id,
            $input['request_id'], $input['amount_minor'], $input['reason']);
        if (! in_array($row['state'], ['succeeded', 'failed', 'cancelled'], true)) {
            $row = $this->core()->recover(MerchantBinding::restore($snapshot['binding']), $row['id']);
        }
        if ($row['state'] === 'succeeded') {
            $this->enqueue($scope['tenant_id'], $id, 'refund_'.$row['id'], ['operation_id' => $row['id']]);
            $this->enqueue($scope['tenant_id'], $id, 'customer_refund_'.$row['id'], ['operation_id' => $row['id']]);
            app(PosOutbox::class)->drainSale($scope, $id);
        }

        return ['refund_id' => $row['id'], 'state' => $row['state'], 'sale' => $this->projection($this->sale($scope, $id))];
    }

    public function receipt(string $register, string $id): array
    {
        $scope = $this->access->register($register);
        $sale = $this->sale($scope, $id);
        if ($sale->state !== 'succeeded' || $sale->stock_state !== 'committed') {
            throw new PosException('receipt_not_confirmed');
        }
        $snapshot = $this->snapshot($sale);
        $operation = $this->journal->get($scope['tenant_id'], $sale->attempt_id);
        $refunds = $this->store->db->table('payment_core_operations')->where('tenant_id', $scope['tenant_id'])
            ->where('original_id', $sale->attempt_id)->where('kind', 'refund')->get(['id', 'state', 'amount_minor']);

        return ['receipt_number' => 'EB-'.strtoupper(substr($id, 0, 12)), 'created_at' => $sale->created_at,
            'merchant_location' => $snapshot['location_name'], 'register_name' => $snapshot['register_name'],
            'lines' => $snapshot['terms']['display'], 'amount_minor' => (int) $sale->amount_minor, 'currency' => 'USD',
            'payment_reference' => $operation['provider_id'], 'customer' => $snapshot['customer'],
            'customer_rewards_status' => ! ($snapshot['customer_rewards']['enabled'] ?? false) ? 'not_configured'
                : ($this->store->rows('outbox', $scope['tenant_id'])->where('sale_id', $id)->where('effect_key', 'like', 'customer_%')->where('state', 'review')->exists() ? 'review_required'
                    : ($this->store->rows('outbox', $scope['tenant_id'])->where('sale_id', $id)->where('effect_key', 'like', 'customer_%')->whereNotIn('state', ['completed', 'review'])->exists() ? 'pending' : 'processed')),
            'refunds' => $refunds->map(fn ($r) => ['id' => $r->id, 'state' => $r->state, 'amount_minor' => (int) $r->amount_minor])->all(),
            'refunded_minor' => max((int) $refunds->where('state', 'succeeded')->sum('amount_minor'), app(PosCustomerPurchases::class)->confirmedRefunds($sale)),
            'settlement_status' => 'provider_reconciliation_required'];
    }

    public function sale(array $scope, string $id): object
    {
        $sale = $this->store->rows('sales', $scope['tenant_id'])->where('register_id', $scope['register']->id)->where('id', $id)->first();
        if (! $sale) {
            throw new PosException('sale_unavailable', 404);
        }
        if ($sale->actor_id != $scope['actor_id'] && $scope['role'] === 'member') {
            throw new PosException('sale_unavailable', 404);
        }

        return $sale;
    }

    public function snapshot(object $sale): array
    {
        $snapshot = $this->store->decrypt($sale->snapshot);
        $binding = MerchantBinding::restore($snapshot['binding']);
        if (! hash_equals($sale->snapshot_hash, Contract::hash($snapshot)) || $binding->runtimeId !== $this->store->runtime()
            || $binding->tenantId !== (int) $sale->tenant_id || $binding->registerId !== $sale->register_id
            || (new SaleSnapshot($sale->id, $sale->currency, $snapshot['sale']['lines']))->amountMinor !== (int) $sale->amount_minor) {
            throw new PosException('stored_sale_integrity_error', 503);
        }

        return $snapshot;
    }

    public function enqueue(int $tenant, string $sale, string $effect, array $payload): void
    {
        // Stable effect key, immutable payload, and no provider calls in this transaction.
        $this->store->rows('outbox', $tenant)->insertOrIgnore($this->store->values($tenant, [
            'id' => bin2hex(random_bytes(16)), 'sale_id' => $sale, 'effect_key' => $effect,
            'payload' => $this->store->encrypt($payload), 'state' => 'pending', 'available_at' => now(),
        ]));
    }

    private function projection(object $sale): array
    {
        $snapshot = $this->snapshot($sale);

        return ['customer' => isset($snapshot['customer']['id']) ? $snapshot['customer'] : null,
            'sale_id' => $sale->id, 'request_id' => $sale->request_id, 'state' => $sale->state,
            'stock_state' => $sale->stock_state, 'amount_minor' => (int) $sale->amount_minor, 'currency' => 'USD',
            'lines' => $snapshot['terms']['display'], 'expires_at' => $sale->expires_at,
            'canonical_confirmation' => $sale->state === 'succeeded',
            'collection_ready' => $this->readiness->blockers($this->access->register($sale->register_id)['register'], $this->access->register($sale->register_id)['connection'], (int) $sale->amount_minor) === [],
            'manager_refunds_allowed' => $this->access->register($sale->register_id)['can_refund'],
            'receipt_available' => $sale->state === 'succeeded' && $sale->stock_state === 'committed',
            'recovery_required' => $sale->descriptor_issued_at !== null && ! in_array($sale->state, ['succeeded', 'failed', 'cancelled'], true)];
    }

    private function refreshStaleMerchant(array $scope, ?int $amount = null): array
    {
        // Current provider retrieval can renew saved readiness, but cannot
        // bypass any unrelated collection, signing or acceptance gate.
        if ($this->readiness->blockers($scope['register'], $scope['connection'], $amount) === ['merchant_evidence_stale']) {
            $this->square->verify($scope['connection']);

            return $this->access->register($scope['register']->id, 'collect');
        }

        return $scope;
    }
}

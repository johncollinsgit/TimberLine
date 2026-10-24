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
        private SquareClient $square, private PaymentJournal $journal, private SquareNativeProvider $provider) {}

    private function core(): PaymentCore
    {
        return new PaymentCore($this->journal, app(PosProviders::class)->all(), $this->access);
    }

    public function registers(): array
    {
        $actor = $this->access->actor();
        $rows = [];
        foreach ($this->store->rows('registers', $actor['tenant_id'])->orderBy('name')->limit(100)->get() as $register) {
            if (! $this->access->isWeb() && ($register->provider ?? 'square_native') !== 'square_native') {
                continue;
            }
            try {
                $scope = $this->access->register($register->id);
            } catch (PosException $error) {
                if ($error->status === 404) {
                    continue;
                }
                throw $error;
            }
            $rows[] = ['provider' => $register->provider ?? 'square_native', 'collection_mode' => ($register->provider ?? 'square_native') === 'stripe_terminal' ? 'reader' : 'native_handoff',
                'reader_available' => ($register->provider ?? 'square_native') === 'stripe_terminal' && $this->store->rows('readers', $actor['tenant_id'])->where('register_id', $register->id)->exists(), 'id' => $register->id, 'name' => $register->name, 'location' => $register->location_name,
                'catalog_source' => $register->catalog_source, 'environment' => $scope['connection']->environment,
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
        $scope = $this->access->register($register, 'collect');
        if (($scope['register']->provider ?? 'square_native') !== 'square_native' || $this->access->isWeb()) {
            throw new PosException('native_payment_app_required', 403);
        }
        $scope = $this->refreshStaleMerchant($scope);
        $this->access->assertAllowed($this->access->binding($scope), 'collect');
        app(PosProviders::class)->verify($scope['connection']);
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

    public function quote(string $register, array $input, ?string $expectedTermsHash = null): array
    {
        $scope = $this->access->register($register, 'quote');
        $binding = $this->access->binding($scope);
        if (isset($input['basket_id'])) {
            app(PosPilotOperations::class)->claimBasketQuote($scope, $input);
        }
        $pilot = isset($scope['trade_event']) ? ['operator_id' => $scope['operator_id'], 'operator_name' => $scope['operator']->name, 'event_id' => $scope['trade_event']->id, 'event_name' => $scope['trade_event']->name, 'event_version' => (int) $scope['trade_event']->version, 'timezone' => $scope['trade_event']->timezone, 'shift_id' => $scope['shift']->id, 'device_record_id' => $scope['device']->id] : null;
        $hash = Contract::hash($pilot ? [$binding->snapshot(), $scope['actor_id'], $input, $pilot] : [$binding->snapshot(), $scope['actor_id'], $input]);
        $old = $this->store->rows('sales', $scope['tenant_id'])->where('request_id', $input['request_id'])->first();
        if ($old) {
            if (! hash_equals($this->snapshot($old)['input_hash'], $hash)) {
                throw new PosException('request_conflict');
            }
            if ($expectedTermsHash !== null && ! hash_equals($expectedTermsHash, Contract::hash($this->snapshot($old)['terms']))) {
                throw new PosException('handoff_terms_changed');
            }

            return $this->projection($old);
        }
        $terms = $this->catalog->terms($scope, $input);
        if ($expectedTermsHash !== null && ! hash_equals($expectedTermsHash, Contract::hash($terms))) {
            throw new PosException('handoff_terms_changed');
        }
        $id = bin2hex(random_bytes(16));
        $sale = new SaleSnapshot($id, 'usd', $terms['lines']);
        $settings = $this->store->decrypt($scope['register']->configuration);
        if (empty($settings['tax_valid_until']) || strtotime($settings['tax_valid_until']) <= time()) {
            throw new PosException('tax_review_expired', 503);
        }
        $snapshot = ['binding' => $binding->snapshot(), 'connection_id' => $scope['connection']->id,
            'build' => $scope['build'], 'device_id' => $scope['device_id'], 'input_hash' => $hash, 'terms' => $terms, 'customer' => $input['customer'] ?? null,
            'register_name' => $scope['register']->name, 'location_name' => $scope['register']->location_name,
            'sale' => $sale->snapshot(), 'configuration' => $settings];
        if ($pilot) {
            $snapshot['pilot'] = $pilot;
        }
        $claim = $terms['source'] === 'website_invoice' ? Contract::hash(['invoice', $terms['source_order_id'], $terms['invoice_hash']]) : null;
        try {
            $this->store->rows('sales', $scope['tenant_id'])->insert($this->store->values($scope['tenant_id'], [
                'id' => $id, 'register_id' => $register, 'location_id' => $scope['register']->location_id,
                'actor_id' => $scope['actor_id'], 'request_id' => $input['request_id'], 'payment_request_id' => (string) Str::uuid(),
                'sdk_attempt_id' => (string) Str::uuid(), 'active_source_claim' => $claim,
                'snapshot' => $this->store->encrypt($snapshot), 'snapshot_hash' => Contract::hash($snapshot),
                'amount_minor' => $sale->amountMinor, 'currency' => 'usd', 'commerce_source' => $terms['source'], 'state' => 'quoted', 'stock_state' => 'unprepared',
                ...($pilot ? array_intersect_key($pilot, array_flip(['operator_id', 'event_id', 'shift_id', 'device_record_id'])) : []),
                'created_at' => now(), 'expires_at' => now()->addMinutes(10),
            ]));
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
            if (isset($snapshot['pilot']) && (($scope['trade_event']->id ?? null) !== $snapshot['pilot']['event_id'] || ($scope['operator_id'] ?? null) !== $snapshot['pilot']['operator_id'] || (int) ($scope['trade_event']->version ?? 0) !== $snapshot['pilot']['event_version'] || ($scope['shift']->id ?? null) !== $snapshot['pilot']['shift_id'])) {
                throw new PosException('sale_event_context_changed');
            }
            $handoff = $this->store->rows('handoffs', $scope['tenant_id'])->where('register_id', $register)->where('request_id', $sale->request_id)->first();
            if ($handoff && ((int) $handoff->claimed_by !== $scope['actor_id'] || $handoff->claimed_device !== $scope['device_id'])) {
                throw new PosException('handoff_claimed_by_another_device', 403);
            }
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
            app(PosProviders::class)->verify($scope['connection']);
            if ($binding->provider === 'stripe_terminal') {
                $reader = $this->store->rows('readers', $scope['tenant_id'])->where('register_id', $register)->firstOrFail();
                app(StripeTerminalClient::class)->reader($scope['connection'], $reader->reader_id, true);
                app(StripeTerminalProvider::class)->reserve($scope, $sale);
            }
            try {
                if ($snapshot['terms']['source'] === 'shopify') {
                    $this->shopify->prepare($scope, $sale, $snapshot);
                } else {
                    $this->native->prepare($sale, $snapshot);
                }
            } catch (\Throwable $error) {
                if ($binding->provider === 'stripe_terminal') {
                    app(StripeTerminalProvider::class)->releaseUnstarted($sale);
                }
                throw $error;
            }
            $this->access->assertAllowed($binding, 'collect');
            $row = $this->core()->start($binding, new SaleSnapshot($id, 'usd', $snapshot['sale']['lines']), $sale->payment_request_id);
            $this->store->rows('sales', $scope['tenant_id'])->where('id', $id)->update(['attempt_id' => $row['id'], 'state' => $row['state']]);
            if ($binding->provider === 'stripe_terminal') {
                $this->recordOutcome($sale, $row);
                app(PosOutbox::class)->drainSale($scope, $id);

                return $this->projection($this->sale($scope, $id));
            }
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
            if ($sale->descriptor_issued_at || $sale->attempt_id
                || $this->journal->findByRequest($scope['tenant_id'], $sale->payment_request_id)) {
                throw new PosException('payment_recovery_required');
            }
            $snapshot = $this->snapshot($sale);
            if ($snapshot['terms']['source'] === 'shopify' && $sale->source_reference) {
                $this->store->rows('sales', $scope['tenant_id'])->where('id', $sale->id)->update(['state' => 'cancelled']);
                $this->enqueue($scope['tenant_id'], $sale->id, 'release_unstarted', []);
                app(PosOutbox::class)->drainSale($scope, $sale->id);
            } else {
                $this->store->rows('sales', $scope['tenant_id'])->where('id', $sale->id)->update(['state' => 'cancelled']);
                $this->native->release($sale, $snapshot);
                if ($snapshot['binding']['provider'] === 'stripe_terminal') {
                    app(StripeTerminalProvider::class)->releaseUnstarted($sale);
                }
            }
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

    public function readerControl(string $register, string $id, string $action): array
    {
        $scope = $this->access->register($register, $action === 'cancel' ? 'recover' : 'collect');
        $sale = $this->sale($scope, $id);
        $snapshot = $this->snapshot($sale);
        if ($snapshot['binding']['provider'] !== 'stripe_terminal' || ! $sale->attempt_id) {
            throw new PosException('reader_payment_required');
        }
        $binding = MerchantBinding::restore($snapshot['binding']);
        if ($action !== 'cancel') {
            $this->access->assertAllowed($binding, 'collect');
            app(PosProviders::class)->verify($scope['connection']);
        }
        $operation = $this->journal->get($scope['tenant_id'], $sale->attempt_id);
        if (in_array($operation['state'], ['succeeded', 'failed', 'cancelled'], true)) {
            return $this->reconcile($register, $id);
        }
        try {
            app(StripeTerminalProvider::class)->control($operation, $action);
        } catch (PosException $error) {
            $this->store->event($scope['tenant_id'], $id, 'reader_control_uncertain', ['code' => $error->blocker]);
        }

        return $this->reconcile($register, $id);
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
            if ($operation['state'] === 'succeeded' && isset($this->snapshot($sale)['pilot'])) {
                $this->store->rows('sales', $tenant)->where('id', $sale->id)->whereNull('confirmed_at')->update(['confirmed_at' => now()]);
            }
            if (in_array($operation['state'], ['succeeded', 'failed', 'cancelled'], true)) {
                $this->enqueue($tenant, $sale->id, $operation['state'] === 'succeeded' ? 'finalize' : 'release', ['operation_id' => $operation['id']]);
            }
        }, 3);
    }

    public function refund(string $register, string $id, array $input): array
    {
        $scope = $this->access->register($register, 'refund');

        return Cache::lock($this->refundLock($scope['tenant_id'], $input['request_id']), 180)->block(5, fn () => $this->performRefund($register, $id, $input));
    }

    private function refundLock(int $tenant, string $request): string
    {
        return 'pos-refund-request-'.hash('sha256', $this->store->runtime().':'.$tenant.':'.$request);
    }

    private function performRefund(string $register, string $id, array $input): array
    {
        $scope = $this->access->register($register, 'refund');
        $sale = $this->sale($scope, $id);
        $snapshot = $this->snapshot($sale);
        if (! $sale->attempt_id || $sale->stock_state !== 'committed') {
            throw new PosException('sale_finalization_required');
        }
        if (isset($scope['operator_id'])) {
            app(PosPilotRefunds::class)->reserve($scope, $sale, $input);
        }
        try {
            $row = $this->core()->refund(MerchantBinding::restore($snapshot['binding']), $sale->attempt_id, $input['request_id'], $input['amount_minor'], $input['reason']);
        } catch (\Throwable $error) {
            if (! $this->journal->findByRequest($scope['tenant_id'], $input['request_id'])) {
                $this->store->rows('refund_details', $scope['tenant_id'])->where('request_id', $input['request_id'])->whereNull('operation_id')->update(['state' => 'not_dispatched']);
            }
            throw $error;
        }
        if (! in_array($row['state'], ['succeeded', 'failed', 'cancelled'], true)) {
            $row = $this->core()->recover(MerchantBinding::restore($snapshot['binding']), $row['id']);
        }
        app(PosPilotRefunds::class)->record($sale, $row);
        if ($row['state'] === 'succeeded') {
            $this->enqueue($scope['tenant_id'], $id, 'refund_'.$row['id'], ['operation_id' => $row['id']]);
            app(PosOutbox::class)->drainSale($scope, $id);
        }

        return ['refund_id' => $row['id'], 'state' => $row['state'], 'sale' => $this->projection($this->sale($scope, $id))];
    }

    public function refundStatus(string $register, string $id, string $requestId, bool $resume = false): array
    {
        $scope = $this->access->register($register, 'refund');

        return Cache::lock($this->refundLock($scope['tenant_id'], $requestId), 180)->block(5, fn () => $this->inspectRefund($register, $id, $requestId, $resume));
    }

    private function inspectRefund(string $register, string $id, string $requestId, bool $resume): array
    {
        $scope = $this->access->register($register, 'refund');
        $sale = $this->sale($scope, $id);
        $detail = $this->store->rows('refund_details', $scope['tenant_id'])->where('sale_id', $id)->where('request_id', $requestId)->first();
        $operation = $this->journal->findByRequest($scope['tenant_id'], $requestId);
        if (! $detail) {
            if ($operation) {
                throw new PosException('refund_decision_review_required');
            }
            // The request lock also guards delayed submitters. Retire an absent
            // request durably before telling the browser it can use a fresh key.
            $this->store->rows('refund_details', $scope['tenant_id'])->insert($this->store->values($scope['tenant_id'], ['id' => bin2hex(random_bytes(16)), 'sale_id' => $id, 'request_id' => $requestId, 'operator_id' => $scope['operator_id'], 'amount_minor' => 0, 'state' => 'not_dispatched', 'decision' => $this->store->encrypt(['reason' => 'other', 'restock' => false, 'lines' => []]), 'created_at' => now()]));

            return ['state' => 'not_dispatched', 'resume_original_available' => false];
        }
        if (! $operation) {
            if ($detail->state === 'not_dispatched') {
                return ['state' => 'not_dispatched', 'resume_original_available' => false];
            }
            if (! $resume) {
                return ['state' => 'unconfirmed', 'resume_original_available' => true];
            }
            $decision = $this->store->decrypt($detail->decision);

            return $this->performRefund($register, $id, ['request_id' => $requestId, 'amount_minor' => (int) $detail->amount_minor, 'reason' => $decision['reason'], 'restock' => $decision['restock'], 'restock_lines' => $decision['lines']]);
        }
        if ($operation['kind'] !== 'refund' || $operation['sale_id'] !== $id) {
            throw new PosException('refund_sale_mismatch');
        }
        $row = $this->core()->recover(MerchantBinding::restore($this->snapshot($sale)['binding']), $operation['id']);
        app(PosPilotRefunds::class)->record($sale, $row);
        if ($row['state'] === 'succeeded') {
            $this->enqueue($scope['tenant_id'], $id, 'refund_'.$row['id'], ['operation_id' => $row['id']]);
            app(PosOutbox::class)->drainSale($scope, $id);
        }

        return ['state' => $row['state'], 'refund_id' => $row['id']];
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
        $refundDetails = $this->store->rows('refund_details', $scope['tenant_id'])->where('sale_id', $id)->get()->keyBy('operation_id');

        $providerReceipt = $this->store->rows('stripe_operations', $scope['tenant_id'])->where('id', $id)->first();
        $details = $providerReceipt?->receipt ? $this->store->decrypt($providerReceipt->receipt) : [];

        return ['provider_receipt' => ($details['payment_id'] ?? null) === $operation['provider_id'] ? $details : null, 'receipt_number' => 'EB-'.strtoupper(substr($id, 0, 12)), 'created_at' => $sale->created_at, 'confirmed_at' => $sale->confirmed_at,
            'attribution' => $snapshot['pilot'] ?? null, 'tax_minor' => (int) ($snapshot['terms']['tax_minor'] ?? array_sum(array_column($snapshot['sale']['lines'], 'tax_amount_minor'))), 'restock_items' => app(PosPilotRefunds::class)->eligible($snapshot),
            'merchant_location' => $snapshot['location_name'], 'register_name' => $snapshot['register_name'],
            'lines' => $snapshot['terms']['display'], 'amount_minor' => (int) $sale->amount_minor, 'currency' => 'USD',
            'payment_reference' => $operation['provider_id'], 'customer' => $snapshot['customer'],
            'refunds' => $refunds->map(fn ($r) => ['id' => $r->id, 'state' => $r->state, 'amount_minor' => (int) $r->amount_minor,
                'confirmed_at' => $refundDetails->get($r->id)?->confirmed_at, 'synchronization_pending' => $r->state === 'succeeded' && ! $refundDetails->get($r->id)?->restocked_at])->all(),
            'refunded_minor' => (int) $refunds->where('state', 'succeeded')->sum('amount_minor'),
            'settlement_status' => 'provider_reconciliation_required'];
    }

    public function sale(array $scope, string $id): object
    {
        $sale = $this->store->rows('sales', $scope['tenant_id'])->where('register_id', $scope['register']->id)->where('id', $id)->first();
        if (! $sale) {
            throw new PosException('sale_unavailable', 404);
        }
        if (isset($scope['operator_id'])) {
            $pilot = $this->snapshot($sale)['pilot'] ?? [];
            $assigned = ! empty($pilot['event_id']) && $this->store->rows('event_staff', $scope['tenant_id'])->where('event_id', $pilot['event_id'])->where('operator_id', $scope['operator_id'])->where('active', true)->exists();
            if (! $assigned || ($scope['role'] === 'member' && ($pilot['operator_id'] ?? '') !== $scope['operator_id'] && ($pilot['device_record_id'] ?? '') !== $scope['device']->id)) {
                throw new PosException('sale_unavailable', 404);
            }

            return $sale;
        }
        $webTerminal = $this->access->isWeb() && ($this->snapshot($sale)['binding']['provider'] ?? '') === 'stripe_terminal'
            && ((int) $sale->actor_id === $scope['actor_id'] && ($this->snapshot($sale)['device_id'] ?? null) === $scope['device_id'] || $scope['can_refund']);
        $webCreator = $webTerminal || $this->access->isWeb() && $this->store->rows('handoffs', $scope['tenant_id'])
            ->where('register_id', $scope['register']->id)->where('request_id', $sale->request_id)
            ->where('claimed_by', $sale->actor_id)->where('actor_id', $scope['actor_id'])->where('device_id', $scope['device_id'])->exists();
        if (($this->access->isWeb() && ! $webCreator) || (! $webCreator && $sale->actor_id != $scope['actor_id'] && $scope['role'] === 'member')) {
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

        if (isset($snapshot['pilot'])) {
            foreach (['operator_id', 'event_id', 'shift_id', 'device_record_id'] as $key) {
                if (($sale->{$key} ?? null) !== ($snapshot['pilot'][$key] ?? null)) {
                    throw new PosException('sale_attribution_integrity_error', 503);
                }
            }
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

        $intent = ($snapshot['binding']['provider'] ?? '') === 'stripe_terminal' ? $this->store->rows('stripe_operations', (int) $sale->tenant_id)->where('id', $sale->id)->first() : null;

        return ['provider' => $snapshot['binding']['provider'], 'reader_state' => $intent?->reader_state,
            'retry_available' => $intent?->reader_state === 'declined', 'resume_available' => $intent && (int) $intent->dispatch_generation === 0 && strtotime($intent->created_at) > time() - 23 * 3600, 'review_required' => $intent && ! $intent->payment_id && strtotime($intent->created_at) <= time() - 23 * 3600,
            'can_leave' => $sale->state === 'succeeded' && $sale->stock_state === 'committed' || in_array($sale->state, ['failed', 'cancelled'], true) && $sale->stock_state === 'released',
            'sale_id' => $sale->id, 'request_id' => $sale->request_id, 'state' => $sale->state,
            'stock_state' => $sale->stock_state, 'amount_minor' => (int) $sale->amount_minor, 'currency' => 'USD',
            'lines' => $snapshot['terms']['display'], 'expires_at' => $sale->expires_at,
            'canonical_confirmation' => $sale->state === 'succeeded',
            'manager_refunds_allowed' => $this->access->register($sale->register_id)['can_refund'],
            'receipt_available' => $sale->state === 'succeeded' && $sale->stock_state === 'committed',
            'recovery_required' => ($sale->descriptor_issued_at !== null || $sale->attempt_id !== null) && ! in_array($sale->state, ['succeeded', 'failed', 'cancelled'], true)];
    }

    private function refreshStaleMerchant(array $scope, ?int $amount = null): array
    {
        // Current provider retrieval can renew saved readiness, but cannot
        // bypass any unrelated collection, signing or acceptance gate.
        if ($this->readiness->blockers($scope['register'], $scope['connection'], $amount) === ['merchant_evidence_stale']) {
            app(PosProviders::class)->verify($scope['connection']);

            return $this->access->register($scope['register']->id, 'collect');
        }

        return $scope;
    }
}

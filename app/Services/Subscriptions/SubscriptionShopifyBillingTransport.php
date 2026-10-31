<?php

namespace App\Services\Subscriptions;

use App\Models\ShopifyStore;
use App\Models\SubscriptionBillingAgreement;
use App\Models\SubscriptionBillingCycle;
use App\Models\SubscriptionCredentialRevision;
use App\Models\SubscriptionInstallation;
use App\Models\SubscriptionPilotMember;
use App\Models\SubscriptionRechargeConnection;
use App\Models\SubscriptionRechargeCredentialRevision;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/** Fixed subscription protocol on the original installation; no arbitrary GraphQL or global token fallback. */
class SubscriptionShopifyBillingTransport
{
    public const IDENTITY_FIELDS = 'shop { id myshopifyDomain currencyCode ianaTimezone plan { partnerDevelopment } } currentAppInstallation { id app { id apiKey } accessScopes { handle } }';

    public const METHOD_FIELDS = 'id customer { id } revokedAt instrument { __typename ... on CustomerCreditCard { lastDigits expiryMonth expiryYear } ... on CustomerShopPayAgreement { lastDigits expiryMonth expiryYear inactive } }';

    public const ORDER_FIELDS = <<<'GRAPHQL'
id updatedAt processedAt test cancelledAt displayFinancialStatus customer { id }
customAttributes { key value }
totalPriceSet { shopMoney { amount currencyCode } }
totalShippingPriceSet { shopMoney { amount currencyCode } }
totalTaxSet { shopMoney { amount currencyCode } }
shippingAddress { firstName lastName company address1 address2 city province provinceCode countryCodeV2 zip phone }
lineItems(first: 25) { nodes { variant { id } quantity originalUnitPriceSet { shopMoney { amount currencyCode } } } pageInfo { hasNextPage } }
GRAPHQL;

    public function context(SubscriptionBillingAgreement $row, bool $currentRead = false): array
    {
        [$installation, $revision] = $this->resolve($row, $currentRead);

        return ['installation_id' => $installation->id, 'credential_revision_id' => $revision->id,
            'credential_fingerprint' => SubscriptionEvidence::digest($revision->credentials['access_token']),
            'shop_gid' => $installation->shop_gid, 'shop_domain' => $installation->shop_domain, 'app_gid' => $installation->app_gid,
            'app_installation_gid' => $installation->app_installation_gid, 'public_client_id' => $installation->public_client_id,
            'environment' => $row->method_binding['environment'], 'api_version' => SubscriptionAccessService::API_VERSION];
    }

    public function method(SubscriptionBillingAgreement $row): array
    {
        return $this->read($row, 'method', ['id' => $row->method_binding['payment_method_gid']]);
    }

    public function updateRead(SubscriptionBillingAgreement $row, string $operation, ?string $calculationId = null, ?array $terms = null): array
    {
        $variables = match ($operation) {
            'capabilities' => [],
            'contract' => ['id' => data_get($row->authorization_evidence, 'shopify_adoption.contract_id')],
            'calculation' => ['id' => $calculationId],
            'catalog' => ['customer' => 'gid://shopify/Customer/'.$row->terms['shopify_customer_id'],
                'variants' => array_map(fn ($line) => 'gid://shopify/ProductVariant/'.$line['variant_id'], ($terms ?? $row->terms)['lines'])],
            default => throw new \InvalidArgumentException('Unsupported original native update read.'),
        };
        abort_unless(! in_array($operation, ['contract', 'calculation'], true) || filled($variables['id']), 409);
        if ($operation === 'calculation') {
            abort_unless(SubscriptionShopifyUpdateProtocol::calculationId($calculationId), 422);
        }

        return $this->request($row, SubscriptionShopifyUpdateProtocol::document($operation), $variables, true);
    }

    /** Only the immutable original calculation can be sent. */
    public function updateCalculate(SubscriptionBillingAgreement $row, string $uuid): array
    {
        return app(SubscriptionShopifyUpdateService::class)->dispatchCalculate($row, $uuid,
            fn ($current, $intent) => $this->request($current, SubscriptionShopifyUpdateProtocol::document('calculate'), $intent['variables'], false, true));
    }

    public function updateCommit(SubscriptionBillingAgreement $row, string $uuid, array $authority): array
    {
        return app(SubscriptionShopifyUpdateService::class)->dispatchCommit($row, $uuid, $authority,
            fn ($current, $quote) => $this->request($current, SubscriptionShopifyUpdateProtocol::document('commit'), ['id' => $quote['calculation_id']], false, true));
    }

    /** Tax-only calculations have no commit entry point and cannot alter the live contract. */
    public function taxCalculate(SubscriptionBillingAgreement $row, string $uuid): array
    {
        return app(SubscriptionShopifyTaxService::class)->dispatch($row, $uuid,
            fn ($current, $variables) => $this->request($current, SubscriptionShopifyUpdateProtocol::document('calculate'), $variables, false, true));
    }

    public function originalOrder(SubscriptionBillingAgreement $row, string $id): array
    {
        abort_unless(preg_match('/\A[1-9][0-9]{0,19}\z/', $id), 422);

        return $this->read($row, 'order', ['id' => 'gid://shopify/Order/'.$id]);
    }

    public function billingCapabilities(SubscriptionBillingAgreement $row): array
    {
        return $this->request($row, SubscriptionShopifyBillingProtocol::document('billing_capabilities'), [], true);
    }

    public function billingReadiness(SubscriptionBillingAgreement $row): void
    {
        $this->assertBillingCapabilities($row, 'subscriptionBillingAttemptCreate');
    }

    public function billingCycle(SubscriptionBillingAgreement $row, string $date): array
    {
        $input = ['contractId' => data_get($row->authorization_evidence, 'shopify_adoption.contract_id'),
            'selector' => ['date' => SubscriptionShopifyBillingProtocol::instant($row->terms, $date)]];
        abort_unless(is_string($input['contractId']), 409);

        return $this->request($row, SubscriptionShopifyBillingProtocol::document('billing_cycle'), ['input' => $input], true);
    }

    public function billingAttempt(SubscriptionBillingCycle $cycle): array
    {
        abort_unless(is_string($cycle->provider_reference), 409);

        return $this->request($this->cycleAgreement($cycle), SubscriptionShopifyBillingProtocol::document('attempt'), ['id' => $cycle->provider_reference], true);
    }

    public function billingAttempts(SubscriptionBillingCycle $cycle, ?string $after = null): array
    {
        abort_unless($after === null || (strlen($after) <= 2048 && $after !== ''), 409);

        return $this->request($this->cycleAgreement($cycle), SubscriptionShopifyBillingProtocol::document('attempts'),
            ['id' => $cycle->terms['shopify_contract_id'], 'after' => $after], true);
    }

    private function cycleAgreement(SubscriptionBillingCycle $cycle): SubscriptionBillingAgreement
    {
        $row = SubscriptionBillingAgreement::query()->forTenantId($cycle->tenant_id)->findOrFail($cycle->agreement_id);
        abort_unless($cycle->processor === 'shopify' && $cycle->method_binding === $row->method_binding
            && $cycle->terms['shopify_contract_id'] === data_get($row->authorization_evidence, 'shopify_adoption.contract_id')
            && data_get($cycle->source_evidence, 'processor_context') === $this->context($row)
            && $cycle->merchant_fingerprint === SubscriptionEvidence::digest(SubscriptionEvidence::canonical($this->context($row))), 409, 'Retain the original Shopify cycle account, contract and credential.');

        return $row;
    }

    /** The single payment dispatch is claimed under the same locks as pause/cancel and never replayed. */
    public function bill(SubscriptionBillingCycle $cycle, array $sourceProof): array
    {
        $row = $this->cycleAgreement($cycle);
        app(SubscriptionShopifyTaxService::class)->assertCollectEntry($row);
        $this->assertBillingCapabilities($row, 'subscriptionBillingAttemptCreate');
        $providerCycle = $this->billingCycle($row, $cycle->cycle_date->toDateString());
        SubscriptionShopifyBillingProtocol::unbilled($row, $providerCycle['subscriptionBillingCycle'] ?? [], $cycle->cycle_date->toDateString());
        $tax = app(SubscriptionShopifyTaxService::class);
        $contract = ($tax->required($row) ? $this->updateRead($row, 'contract') : $this->contractRead($row, 'contract'))['subscriptionContract'] ?? [];
        SubscriptionShopifyBillingProtocol::owned($row, $contract);
        SubscriptionShopifyContractProtocol::assertTerms($row, $contract, data_get($row->authorization_evidence, 'shopify_adoption.original.request_uuid', ''), SubscriptionShopifyUpdateHistory::attributes($row));
        $tax->assertFresh($row, data_get($cycle->reconciliation, 'native_tax_preflight'), $cycle->cycle_date->toDateString(), false, $contract);
        abort_unless(($contract['status'] ?? null) === 'ACTIVE' && SubscriptionShopifyBillingProtocol::sameInstant($contract['nextBillingDate'] ?? null,
            SubscriptionShopifyBillingProtocol::instant($cycle->terms, $cycle->cycle_date->toDateString())), 409, 'The original Shopify contract is not active on this reviewed billing instant.');
        DB::transaction(function () use ($row, $cycle, $sourceProof) {
            SubscriptionPilotMember::query()->forTenantId($row->tenant_id)->whereKey($row->member_id)->lockForUpdate()->firstOrFail();
            $current = SubscriptionBillingAgreement::query()->forTenantId($row->tenant_id)->whereKey($row->id)->lockForUpdate()->firstOrFail();
            $attempt = SubscriptionBillingCycle::query()->forTenantId($row->tenant_id)->whereKey($cycle->id)->lockForUpdate()->firstOrFail();
            abort_if($attempt->payment_started_at || data_get($attempt->reconciliation, 'dispatch_started_at'), 409, 'Recover the original Shopify attempt; never submit another payment request.');
            app(SubscriptionShopifyExecutionService::class)->assertDispatch($current, $attempt, $sourceProof);
            $attempt->update(['payment_status' => 'sending', 'payment_started_at' => now(),
                'reconciliation' => array_replace($attempt->reconciliation ?? [], ['dispatch_started_at' => now()->toISOString(), 'dispatch_source_proof' => $sourceProof],
                    app(SubscriptionShopifyTaxService::class)->required($current) ? ['dispatch_native_tax_preflight' => $attempt->reconciliation['native_tax_preflight']] : [])]);
        });
        $attempt = $cycle->fresh();
        $row = $this->cycleAgreement($attempt);
        app(SubscriptionShopifyExecutionService::class)->assertDispatch($row, $attempt, $sourceProof);

        return $this->request($row, SubscriptionShopifyBillingProtocol::document('bill'), [
            'id' => $attempt->provider_request['subscriptionContractId'], 'input' => $attempt->provider_request['subscriptionBillingAttemptInput'],
        ], false);
    }

    /** Serialize owned schedule patches with local customer changes. These operations never charge. */
    public function schedule(SubscriptionBillingAgreement $row, string $operation, int $expectedRevision): array
    {
        abort_unless(in_array($operation, ['activate', 'pause', 'cancel', 'set_date'], true), 422);
        $mutation = ['activate' => 'subscriptionContractActivate', 'pause' => 'subscriptionContractPause',
            'cancel' => 'subscriptionContractCancel', 'set_date' => 'subscriptionContractSetNextBillingDate'][$operation];
        $this->assertBillingCapabilities($row, $mutation);

        return DB::transaction(function () use ($row, $operation, $expectedRevision) {
            SubscriptionPilotMember::query()->forTenantId($row->tenant_id)->whereKey($row->member_id)->lockForUpdate()->firstOrFail();
            $current = SubscriptionBillingAgreement::query()->forTenantId($row->tenant_id)->whereKey($row->id)->lockForUpdate()->firstOrFail();
            abort_unless($current->revision === $expectedRevision, 409, 'The local subscription schedule changed before the provider patch.');
            app(SubscriptionShopifyExecutionService::class)->assertSchedule($current, $operation);
            $variables = ['id' => data_get($current->authorization_evidence, 'shopify_adoption.contract_id')];
            if ($operation === 'set_date') {
                $variables['date'] = SubscriptionShopifyBillingProtocol::instant($current->terms,
                    $current->next_billing_date?->toDateString() ?? $current->terms['first_billing_date']);
            }
            $document = match ($operation) {
                'pause' => SubscriptionShopifyContractProtocol::document('pause'),
                'set_date' => SubscriptionShopifyContractProtocol::document('first_date'),
                default => SubscriptionShopifyBillingProtocol::document($operation),
            };

            return $this->request($current, $document, $variables, false);
        });
    }

    private function assertBillingCapabilities(SubscriptionBillingAgreement $row, string $mutation): void
    {
        $data = $this->billingCapabilities($row);
        abort_unless(collect(data_get($data, 'currentAppInstallation.accessScopes', []))->pluck('handle')->contains('write_own_subscription_contracts')
            && collect(data_get($data, 'currentAppInstallation.accessScopes', []))->pluck('handle')->contains('read_all_orders')
            && collect(data_get($data, 'mutationType.fields', []))->pluck('name')->contains($mutation)
            && collect(data_get($data, 'attemptType.fields', []))->pluck('name')->contains('state')
            && ! array_diff(['actor', 'billingCycleSelector', 'idempotencyKey', 'inventoryPolicy', 'originTime', 'paymentProcessingPolicy'], collect(data_get($data, 'inputType.inputFields', []))->pluck('name')->all())
            && ! array_diff(['SubscriptionBillingAttemptPendingState', 'SubscriptionBillingAttemptActionRequiredState', 'SubscriptionBillingAttemptFailedState', 'SubscriptionBillingAttemptSuccessState'], collect(data_get($data, 'stateType.possibleTypes', []))->pluck('name')->all()),
            423, 'The original app needs the pinned billing protocol, own-contract write permission and historical order access for continuing reconciliation.');
    }

    public function contractRead(SubscriptionBillingAgreement $row, string $operation): array
    {
        $state = data_get($row->authorization_evidence, 'shopify_adoption', []);
        $variables = match ($operation) {
            'capabilities' => [],
            'calculation' => ['id' => $state['calculation_id'] ?? null],
            'reviewed_calculation' => ['id' => $state['terminal_calculation_id'] ?? $state['commit_original']['calculation_id'] ?? $state['calculation_id'] ?? null],
            'contract' => ['id' => $state['contract_id'] ?? null],
            'customer_contracts' => ['id' => 'gid://shopify/Customer/'.$row->terms['shopify_customer_id']],
            default => throw new \InvalidArgumentException('Unsupported replacement contract read.'),
        };
        abort_unless($operation === 'capabilities' || filled($variables['id']), 409, 'The original contract/calculation reference is not available.');

        return $this->read($row, $operation === 'reviewed_calculation' ? 'calculation' : $operation, $variables);
    }

    /** Fixed original intent only. Create/commit dispatches are claimed durably before HTTP and never resent. */
    public function contractMutation(SubscriptionBillingAgreement $row, string $operation): array
    {
        abort_unless(in_array($operation, ['calculate', 'commit', 'pause', 'first_date'], true), 422);
        $state = data_get($row->authorization_evidence, 'shopify_adoption', []);
        $this->adoptionAuthority($row, $state);
        abort_unless(! in_array($operation, ['pause', 'first_date'], true) || isset($state['contract_id'], $state['commit_dispatched_at']), 409, 'Recover the original created contract before changing its paused schedule.');
        $capabilities = $this->contractRead($row, 'capabilities');
        $scopes = collect(data_get($capabilities, 'currentAppInstallation.accessScopes', []))->pluck('handle');
        abort_unless($scopes->contains('write_own_subscription_contracts'), 423, 'The original subscription app needs its own-contract write permission.');
        foreach (['mutationType.fields' => ['subscriptionContractCreateCalculate', 'subscriptionContractCalculationCommit', 'subscriptionContractPause', 'subscriptionContractSetNextBillingDate'],
            'queryType.fields' => ['subscriptionContractCalculation'], 'deliveryPolicyType.fields' => ['multiFulfillment'],
            'createInputType.inputFields' => array_keys($state['original']['input'])] as $path => $required) {
            abort_unless(! array_diff($required, collect(data_get($capabilities, $path, []))->pluck('name')->all()), 423, 'The original pinned Shopify API does not expose the reviewed contract calculation protocol.');
        }
        if (in_array($operation, ['calculate', 'commit'], true)) {
            DB::transaction(function () use ($row, $operation) {
                $current = SubscriptionBillingAgreement::query()->forTenantId($row->tenant_id)->whereKey($row->id)->lockForUpdate()->firstOrFail();
                $state = data_get($current->authorization_evidence, 'shopify_adoption', []);
                $this->adoptionAuthority($current, $state);
                abort_if(isset($state[$operation.'_dispatched_at']), 409, 'Recover the original contract operation; it cannot be submitted again.');
                abort_unless($operation === 'calculate' || isset($state['commit_original']), 409);
                if ($operation === 'commit') {
                    $this->commitAuthority($current, $state);
                    $state['commit_dispatch_proof'] = $state['commit_send_proof'];
                }
                $state[$operation.'_dispatched_at'] = now()->toISOString();
                $evidence = $current->authorization_evidence;
                $evidence['shopify_adoption'] = $state;
                $current->update(['authorization_evidence' => $evidence]);
            });
        }
        $row = $row->fresh();
        $state = data_get($row->authorization_evidence, 'shopify_adoption');
        $this->adoptionAuthority($row, $state);
        if ($operation === 'commit') {
            $this->commitAuthority($row, $state);
        }
        $variables = match ($operation) {
            'calculate' => ['input' => $state['original']['input']],
            'commit' => ['id' => $state['commit_original']['calculation_id']],
            'pause' => ['id' => $state['contract_id']],
            'first_date' => ['id' => $state['contract_id'], 'date' => SubscriptionShopifyContractProtocol::firstBillingInstant($row)],
        };

        return $this->request($row, SubscriptionShopifyContractProtocol::document($operation), $variables, false);
    }

    private function adoptionAuthority(SubscriptionBillingAgreement $row, array $state): void
    {
        SubscriptionBillingAuthorization::assertCurrent($row);
        $original = $state['original'] ?? [];
        abort_unless(config('subscriptions.replacement_shopify_adoption_enabled') === true && $row->status === 'prepared' && ! $row->activated_at
            && ($original['terms_hash'] ?? null) === $row->terms_hash && ($original['processor_context'] ?? null) === $this->context($row)
            && ($original['input'] ?? null) === SubscriptionShopifyContractProtocol::input($row, $original['request_uuid'] ?? ''), 423, 'Review the original disabled or changed contract adoption intent.');
        app(SubscriptionReplacementAccess::class)->owner($row->tenant_id, User::findOrFail($original['actor_id'] ?? null), $row->member_id, 'setup');
        abort_unless($row->method_binding['environment'] === 'sandbox' || config('subscriptions.replacement_shopify_live_acceptance_verified') === true, 423, 'Original-account Shopify live acceptance is required.');
    }

    private function commitAuthority(SubscriptionBillingAgreement $row, array $state): void
    {
        $proof = $state['commit_dispatch_proof'] ?? $state['commit_send_proof'] ?? [];
        $binding = $row->method_binding;
        $revision = SubscriptionRechargeCredentialRevision::query()->forTenantId($row->tenant_id)->where('connection_id', $binding['source_connection_id'])
            ->where('status', 'active')->findOrFail($binding['source_credential_revision_id']);
        abort_unless(SubscriptionShopifyContractProtocol::calculationId($state['terminal_calculation_id'] ?? null)
            && ($state['commit_original']['calculation_id'] ?? null) === $state['terminal_calculation_id']
            && data_get($state, 'quote.calculation_id') === $state['terminal_calculation_id']
            && ($state['commit_original']['review_hash'] ?? null) === data_get($state, 'quote.review_hash')
            && ($proof['connection_id'] ?? null) === $binding['source_connection_id']
            && ($proof['credential_revision_id'] ?? null) === $binding['source_credential_revision_id']
            && ($proof['source_customer_id'] ?? null) === $row->terms['recharge_customer_id']
            && SubscriptionRechargeOperationService::enabled($row->tenant_id) && $revision->verified_at && ! $revision->revoked_at && ! $revision->expires_at?->isPast()
            && data_get($state, 'original.source_context.credential_fingerprint') === SubscriptionEvidence::digest((string) ($revision->credentials['access_token'] ?? ''))
            && is_string($proof['checked_at'] ?? null) && \Carbon\CarbonImmutable::parse($proof['checked_at'])->betweenIncluded(now()->subMinute(), now()->addSeconds(5))
            && SubscriptionRechargeConnection::query()->forTenantId($row->tenant_id)->whereKey($binding['source_connection_id'])->where('status', 'verified')
                ->where('active_credential_revision_id', $binding['source_credential_revision_id'])->whereNull('read_pause_reason')->exists(), 409, 'Fresh original source-stop proof is required before the single contract commit.');
        app(SubscriptionReplacementAccess::class)->owner($row->tenant_id, User::findOrFail($state['commit_original']['actor_id']), $row->member_id, 'setup');
    }

    public static function document(string $operation): string
    {
        return match ($operation) {
            'method' => 'query ReplacementSavedMethod($id: ID!) { '.self::IDENTITY_FIELDS.' customerPaymentMethod(id: $id, showRevoked: true) { '.self::METHOD_FIELDS.' } }',
            'order' => 'query ReplacementSourceOrder($id: ID!) { '.self::IDENTITY_FIELDS.' order(id: $id) { '.self::ORDER_FIELDS.' } }',
            default => throw new \InvalidArgumentException('Unsupported subscription protocol operation.'),
        };
    }

    private function read(SubscriptionBillingAgreement $row, string $operation, array $variables): array
    {
        $document = in_array($operation, ['method', 'order'], true) ? self::document($operation) : SubscriptionShopifyContractProtocol::document($operation);

        return $this->request($row, $document, $variables, true);
    }

    private function request(SubscriptionBillingAgreement $row, string $document, array $variables, bool $checkIdentity, bool $currentRead = false): array
    {
        [$installation, $revision] = $this->resolve($row, $currentRead);
        $context = $this->context($row, $currentRead);
        try {
            $response = Http::withHeaders(['X-Shopify-Access-Token' => $revision->credentials['access_token'], 'Accept' => 'application/json'])
                ->withoutRedirecting()->connectTimeout(3)->timeout(12)->post('https://'.$installation->shop_domain.'/admin/api/'.SubscriptionAccessService::API_VERSION.'/graphql.json',
                    ['query' => $document, 'variables' => $variables]);
            abort_unless($response->successful() && $response->header('X-Shopify-API-Version') === SubscriptionAccessService::API_VERSION
                && strlen($response->body()) <= 1048576, 502);
            $body = json_decode($response->body(), true, 64, JSON_BIGINT_AS_STRING | JSON_THROW_ON_ERROR);
            abort_unless(is_array($body) && empty($body['errors']) && is_array($body['data'] ?? null), 502);
        } catch (\Throwable) {
            abort(502, 'The original Shopify subscription evidence could not be verified.');
        }
        abort_unless($this->context($row->fresh(), $currentRead) === $context, 409, 'The original subscription installation or credential changed.');
        if ($checkIdentity) {
            $this->identity($row, $installation, $body['data']);
        }

        return $body['data'];
    }

    private function identity(SubscriptionBillingAgreement $row, SubscriptionInstallation $installation, array $data): void
    {
        foreach (['shop.id' => $installation->shop_gid, 'shop.myshopifyDomain' => $installation->shop_domain,
            'currentAppInstallation.id' => $installation->app_installation_gid, 'currentAppInstallation.app.id' => $installation->app_gid,
            'currentAppInstallation.app.apiKey' => $installation->public_client_id, 'shop.currencyCode' => $row->terms['currency'],
            'shop.ianaTimezone' => $row->terms['timezone']] as $path => $expected) {
            abort_unless(data_get($data, $path) === $expected, 409, 'The original Shopify store or subscription app identity differs.');
        }
        $scopes = collect(data_get($data, 'currentAppInstallation.accessScopes', []))->pluck('handle');
        foreach ([['read_customer_payment_methods'], ['read_own_subscription_contracts', 'write_own_subscription_contracts'],
            ['read_customers', 'write_customers'], ['read_orders', 'write_orders'], ['read_products', 'write_products']] as $alternatives) {
            abort_unless($scopes->intersect($alternatives)->isNotEmpty(), 423, 'The original app needs its subscription and customer payment-method permissions.');
        }
        $development = data_get($data, 'shop.plan.partnerDevelopment');
        abort_unless(is_bool($development) && $development === ($row->method_binding['environment'] === 'sandbox'), 423, 'Sandbox subscription setup requires an isolated Shopify development store.');
    }

    private function resolve(SubscriptionBillingAgreement $row, bool $currentRead = false): array
    {
        abort_unless(SubscriptionEvidence::readsEnabled($row->tenant_id), 423, 'Subscription provider reads are paused.');
        $binding = $row->method_binding;
        abort_unless($row->processor === 'shopify' && ($binding['processor'] ?? null) === 'shopify'
            && in_array($binding['environment'] ?? null, ['sandbox', 'production'], true)
            && is_int($binding['installation_id'] ?? null) && is_int($binding['credential_revision_id'] ?? null), 409);
        app(SubscriptionReplacementAccess::class)->member($row);
        $installation = SubscriptionInstallation::query()->forTenantId($row->tenant_id)->where('shopify_store_id', $row->terms['shopify_store_id'])
            ->where('status', 'verified')->when($currentRead, fn ($q) => $q->lockForUpdate())->findOrFail($binding['installation_id']);
        abort_unless($installation->verified_at && ! $installation->read_pause_reason
            && $installation->shop_domain === $binding['shop_domain'] && $installation->app_gid === $binding['app_gid']
            && $installation->active_credential_revision_id === $binding['credential_revision_id']
            && $installation->api_version === SubscriptionAccessService::API_VERSION, 409, 'The original verified Shopify subscription installation is unavailable.');
        $revision = SubscriptionCredentialRevision::query()->forTenantId($row->tenant_id)->where('installation_id', $installation->id)
            ->where('status', 'active')->when($currentRead, fn ($q) => $q->lockForUpdate())->findOrFail($binding['credential_revision_id']);
        abort_unless($revision->verified_at && ! $revision->revoked_at && ! $revision->expires_at?->isPast(), 409, 'The original Shopify subscription credential is unavailable.');
        $token = $revision->credentials['access_token'] ?? null;
        abort_unless(is_string($token) && $token !== '' && strlen($token) <= 4096 && ! preg_match('/[\r\n]/', $token), 409);
        abort_unless(ShopifyStore::query()->forTenantId($row->tenant_id)->whereKey($installation->shopify_store_id)
            ->where('shop_domain', $installation->shop_domain)->when($currentRead, fn ($q) => $q->lockForUpdate())->first(), 409);

        return [$installation, $revision];
    }
}

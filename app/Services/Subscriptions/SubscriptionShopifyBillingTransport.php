<?php

namespace App\Services\Subscriptions;

use App\Models\ShopifyStore;
use App\Models\SubscriptionBillingAgreement;
use App\Models\SubscriptionCredentialRevision;
use App\Models\SubscriptionInstallation;
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

    public function context(SubscriptionBillingAgreement $row): array
    {
        [$installation, $revision] = $this->resolve($row);

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

    public function originalOrder(SubscriptionBillingAgreement $row, string $id): array
    {
        abort_unless(preg_match('/\A[1-9][0-9]{0,19}\z/', $id), 422);

        return $this->read($row, 'order', ['id' => 'gid://shopify/Order/'.$id]);
    }

    public function contractRead(SubscriptionBillingAgreement $row, string $operation): array
    {
        $state = data_get($row->authorization_evidence, 'shopify_adoption', []);
        $variables = match ($operation) {
            'capabilities' => [],
            'calculation' => ['id' => $state['calculation_id'] ?? null],
            'contract' => ['id' => $state['contract_id'] ?? null],
            'customer_contracts' => ['id' => 'gid://shopify/Customer/'.$row->terms['shopify_customer_id']],
            default => throw new \InvalidArgumentException('Unsupported replacement contract read.'),
        };
        abort_unless($operation === 'capabilities' || filled($variables['id']), 409, 'The original contract/calculation reference is not available.');

        return $this->read($row, $operation, $variables);
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
        abort_unless(($state['commit_original']['calculation_id'] ?? null) === ($state['calculation_id'] ?? null)
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

    private function request(SubscriptionBillingAgreement $row, string $document, array $variables, bool $checkIdentity): array
    {
        [$installation, $revision] = $this->resolve($row);
        $context = $this->context($row);
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
        abort_unless($this->context($row->fresh()) === $context, 409, 'The original subscription installation or credential changed.');
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

    private function resolve(SubscriptionBillingAgreement $row): array
    {
        abort_unless(SubscriptionEvidence::readsEnabled($row->tenant_id), 423, 'Subscription provider reads are paused.');
        $binding = $row->method_binding;
        abort_unless($row->processor === 'shopify' && ($binding['processor'] ?? null) === 'shopify'
            && in_array($binding['environment'] ?? null, ['sandbox', 'production'], true)
            && is_int($binding['installation_id'] ?? null) && is_int($binding['credential_revision_id'] ?? null), 409);
        app(SubscriptionReplacementAccess::class)->member($row);
        $installation = SubscriptionInstallation::query()->forTenantId($row->tenant_id)->where('shopify_store_id', $row->terms['shopify_store_id'])
            ->where('status', 'verified')->findOrFail($binding['installation_id']);
        abort_unless($installation->verified_at && ! $installation->read_pause_reason
            && $installation->shop_domain === $binding['shop_domain'] && $installation->app_gid === $binding['app_gid']
            && $installation->active_credential_revision_id === $binding['credential_revision_id']
            && $installation->api_version === SubscriptionAccessService::API_VERSION, 409, 'The original verified Shopify subscription installation is unavailable.');
        $revision = SubscriptionCredentialRevision::query()->forTenantId($row->tenant_id)->where('installation_id', $installation->id)
            ->where('status', 'active')->findOrFail($binding['credential_revision_id']);
        abort_unless($revision->verified_at && ! $revision->revoked_at && ! $revision->expires_at?->isPast(), 409, 'The original Shopify subscription credential is unavailable.');
        $token = $revision->credentials['access_token'] ?? null;
        abort_unless(is_string($token) && $token !== '' && strlen($token) <= 4096 && ! preg_match('/[\r\n]/', $token), 409);
        abort_unless(ShopifyStore::query()->forTenantId($row->tenant_id)->whereKey($installation->shopify_store_id)
            ->where('shop_domain', $installation->shop_domain)->exists(), 409);

        return [$installation, $revision];
    }
}

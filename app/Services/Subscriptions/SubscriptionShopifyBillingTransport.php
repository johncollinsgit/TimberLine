<?php

namespace App\Services\Subscriptions;

use App\Models\ShopifyStore;
use App\Models\SubscriptionBillingAgreement;
use App\Models\SubscriptionCredentialRevision;
use App\Models\SubscriptionInstallation;
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
        [$installation, $revision] = $this->resolve($row);
        $context = $this->context($row);
        try {
            $response = Http::withHeaders(['X-Shopify-Access-Token' => $revision->credentials['access_token'], 'Accept' => 'application/json'])
                ->withoutRedirecting()->connectTimeout(3)->timeout(12)->post('https://'.$installation->shop_domain.'/admin/api/'.SubscriptionAccessService::API_VERSION.'/graphql.json',
                    ['query' => self::document($operation), 'variables' => $variables]);
            abort_unless($response->successful() && $response->header('X-Shopify-API-Version') === SubscriptionAccessService::API_VERSION
                && strlen($response->body()) <= 1048576, 502);
            $body = json_decode($response->body(), true, 64, JSON_BIGINT_AS_STRING | JSON_THROW_ON_ERROR);
            abort_unless(is_array($body) && empty($body['errors']) && is_array($body['data'] ?? null), 502);
        } catch (\Throwable) {
            abort(502, 'The original Shopify subscription evidence could not be verified.');
        }
        abort_unless($this->context($row->fresh()) === $context, 409, 'The original subscription installation or credential changed.');
        $this->identity($row, $installation, $body['data']);

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

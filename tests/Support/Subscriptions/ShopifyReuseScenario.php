<?php

namespace Tests\Support\Subscriptions;

use App\Models\ShopifyStore;
use App\Models\SubscriptionBillingAgreement;
use App\Models\SubscriptionCredentialRevision;
use App\Models\SubscriptionInstallation;
use App\Models\SubscriptionRechargeConnection;
use App\Models\SubscriptionRechargeCredentialRevision;
use App\Models\Tenant;
use App\Models\TenantModuleEntitlement;
use App\Models\User;
use App\Services\Subscriptions\SubscriptionBillingAgreementService;
use App\Services\Subscriptions\SubscriptionEvidence;
use App\Services\Subscriptions\SubscriptionPilotService;
use Illuminate\Support\Facades\Http;

/** Fictional original merchant and source; all provider responses are local, no vault/contract/payment writes. */
class ShopifyReuseScenario
{
    public Tenant $tenant;

    public User $actor;

    public ShopifyStore $store;

    public SubscriptionInstallation $installation;

    public SubscriptionCredentialRevision $revision;

    public SubscriptionRechargeConnection $source;

    public SubscriptionRechargeCredentialRevision $sourceRevision;

    public SubscriptionBillingAgreement $agreement;

    public ?string $defect = null;

    public bool $card = false;

    public function __construct()
    {
        Http::preventStrayRequests();
        $this->tenant = Tenant::create(['name' => 'Fictional Shopify reuse', 'slug' => 'shopify-reuse']);
        TenantModuleEntitlement::create(['tenant_id' => $this->tenant->id, 'module_key' => 'subscriptions', 'availability_status' => 'available',
            'enabled_status' => 'enabled', 'billing_status' => 'add_on_comped', 'entitlement_source' => 'test_explicit', 'price_source' => 'test']);
        $this->actor = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
        $this->actor->tenants()->attach($this->tenant, ['role' => 'owner', 'membership_active' => true]);
        $this->store = ShopifyStore::create(['tenant_id' => $this->tenant->id, 'store_key' => 'retail',
            'shop_domain' => 'fictional-reuse.myshopify.com', 'access_token' => 'UNRELATED_STORE_TOKEN']);
        $this->installation = SubscriptionInstallation::create(['tenant_id' => $this->tenant->id, 'shopify_store_id' => $this->store->id,
            'shop_domain' => $this->store->shop_domain, 'shop_gid' => 'gid://shopify/Shop/1001', 'app_gid' => 'gid://shopify/App/2001',
            'app_installation_gid' => 'gid://shopify/AppInstallation/3001', 'public_client_id' => 'fictional-subscription-client',
            'api_version' => '2026-10', 'shop_timezone' => 'America/New_York', 'status' => 'verified', 'verified_at' => now()]);
        $this->revision = SubscriptionCredentialRevision::create(['tenant_id' => $this->tenant->id, 'installation_id' => $this->installation->id,
            'revision' => 1, 'credentials' => ['access_token' => 'ORIGINAL_SUBSCRIPTION_TOKEN'], 'credential_fingerprint' => SubscriptionEvidence::digest('ORIGINAL_SUBSCRIPTION_TOKEN'),
            'digest_key_id' => SubscriptionEvidence::keyId(), 'grant_type' => 'existing_shopify_store', 'status' => 'active', 'verified_at' => now()]);
        $this->installation->update(['active_credential_revision_id' => $this->revision->id]);
        $this->source = SubscriptionRechargeConnection::create(['tenant_id' => $this->tenant->id, 'platform' => 'shopify',
            'identifier' => $this->store->shop_domain, 'provider_store_id' => '9001', 'api_version' => '2021-11', 'status' => 'verified', 'verified_at' => now()]);
        $this->sourceRevision = SubscriptionRechargeCredentialRevision::create(['tenant_id' => $this->tenant->id, 'connection_id' => $this->source->id,
            'revision' => 1, 'credentials' => ['access_token' => 'ORIGINAL_RECHARGE_TOKEN'], 'credential_fingerprint' => SubscriptionEvidence::digest('ORIGINAL_RECHARGE_TOKEN'),
            'digest_key_id' => SubscriptionEvidence::keyId(), 'status' => 'active', 'verified_at' => now(),
            'scopes' => ['read_store', 'read_customers', 'read_subscriptions', 'read_payment_methods']]);
        $this->source->update(['active_credential_revision_id' => $this->sourceRevision->id]);
        $member = app(SubscriptionPilotService::class)->prepare($this->tenant->id, $this->actor, ['shopify_store_id' => $this->store->id,
            'recharge_customer_id' => '123', 'shopify_customer_id' => '456', 'name' => 'Fictional member', 'email' => 'fictional@example.test',
            'target_date' => '2027-01-31', 'timezone' => 'America/New_York', 'currency' => 'USD', 'expected_amount_cents' => 2500,
            'source_url' => 'https://fictional.admin.rechargeapps.com/merchant/customers/123', 'observed_at' => now()->toISOString(),
            'lines' => [['subscription_id' => '789', 'variant_id' => '999', 'label' => 'Monthly candle', 'quantity' => 1, 'unit_price_cents' => 2500,
                'billing_months' => 1, 'delivery_months' => 1, 'prepaid' => false]]]);
        config()->set('subscriptions.replacement_setup_enabled', true);
        config()->set('subscriptions.replacement_tenant_ids', [$this->tenant->id]);
        config()->set('subscriptions.replacement_member_ids', [$member->id]);
        config()->set('subscriptions.provider_reads_enabled', true);
        config()->set('subscriptions.provider_read_tenant_ids', [$this->tenant->id]);
        config()->set('subscriptions.recharge_reads_enabled', true);
        config()->set('subscriptions.recharge_read_tenant_ids', [$this->tenant->id]);
        $input = ['processor' => 'shopify', 'shipping_cents' => 0, 'tax_cents' => 0, 'shipping_address' => $this->address(),
            'method_binding' => ['installation_id' => $this->installation->id, 'payment_method_gid' => 'gid://shopify/CustomerPaymentMethod/original_method',
                'source_connection_id' => $this->source->id, 'source_payment_method_id' => '321', 'environment' => 'sandbox'],
            'authorization_reference' => 'original-source:123', 'source_terms_reviewed' => true, 'continuing_authorization_reviewed' => true];
        $service = app(SubscriptionBillingAgreementService::class);
        $review = $service->review($this->tenant->id, $this->actor, $member->id, $input);
        $this->agreement = $service->prepare($this->tenant->id, $this->actor, $member->id, $input, $review['terms_hash']);
        Http::fake(fn ($request) => str_contains($request->url(), 'api.rechargeapps.com') ? $this->recharge($request) : $this->shopify($request));
    }

    public function address(): array
    {
        return ['firstName' => 'Fictional', 'lastName' => 'Member', 'address1' => '123 Example Street', 'city' => 'Greenville',
            'provinceCode' => 'SC', 'countryCode' => 'US', 'zip' => '29601'];
    }

    protected function shopify($request)
    {
        if ($request->url() !== 'https://'.$this->store->shop_domain.'/admin/api/2026-10/graphql.json' || $request->method() !== 'POST'
            || $request->header('X-Shopify-Access-Token') !== ['ORIGINAL_SUBSCRIPTION_TOKEN'] || ! str_starts_with($request['query'], 'query Replacement')) {
            throw new \RuntimeException('Unexpected fictional Shopify request.');
        }
        $scopes = ['read_customer_payment_methods', 'read_own_subscription_contracts', 'read_customers', 'read_orders', 'read_products'];
        if ($this->defect === 'scope') {
            $scopes = array_diff($scopes, ['read_customer_payment_methods']);
        }
        $data = ['shop' => ['id' => 'gid://shopify/Shop/1001', 'myshopifyDomain' => $this->store->shop_domain, 'currencyCode' => 'USD',
            'ianaTimezone' => 'America/New_York', 'plan' => ['partnerDevelopment' => $this->defect !== 'production_store']],
            'currentAppInstallation' => ['id' => 'gid://shopify/AppInstallation/3001', 'app' => ['id' => $this->defect === 'app' ? 'gid://shopify/App/9999' : 'gid://shopify/App/2001',
                'apiKey' => 'fictional-subscription-client'], 'accessScopes' => array_map(fn ($scope) => ['handle' => $scope], array_values($scopes))]];
        if (str_contains($request['query'], 'ReplacementSavedMethod')) {
            $data['customerPaymentMethod'] = ['id' => 'gid://shopify/CustomerPaymentMethod/original_method', 'customer' => ['id' => 'gid://shopify/Customer/'.($this->defect === 'customer' ? '999' : '456')],
                'revokedAt' => $this->defect === 'revoked' ? '2027-01-01T00:00:00Z' : null,
                'instrument' => ['__typename' => $this->card ? 'CustomerCreditCard' : 'CustomerShopPayAgreement', 'lastDigits' => '4242', 'expiryMonth' => 12,
                    'expiryYear' => $this->defect === 'expired' ? 2026 : 2030, 'inactive' => $this->defect === 'inactive']];
        } elseif (str_contains($request['query'], 'ReplacementSourceOrder')) {
            $address = $this->address();
            $address['countryCodeV2'] = $address['countryCode'];
            $address['province'] = 'South Carolina';
            unset($address['countryCode']);
            $money = fn ($amount) => ['shopMoney' => ['amount' => $amount, 'currencyCode' => 'USD']];
            $data['order'] = ['id' => 'gid://shopify/Order/111', 'customer' => ['id' => 'gid://shopify/Customer/456'], 'test' => true,
                'updatedAt' => '2027-01-02T15:00:00Z', 'processedAt' => '2027-01-02T15:00:00Z', 'cancelledAt' => null,
                'displayFinancialStatus' => $this->defect === 'refunded_order' ? 'REFUNDED' : 'PAID', 'customAttributes' => [['key' => 'rc_subscription_ids', 'value' => '789']],
                'totalPriceSet' => $money('25.00'), 'totalShippingPriceSet' => $money('0.00'), 'totalTaxSet' => $money('0.00'), 'shippingAddress' => $address,
                'lineItems' => ['nodes' => [['variant' => ['id' => 'gid://shopify/ProductVariant/999'], 'quantity' => 1, 'originalUnitPriceSet' => $money('25.00')]], 'pageInfo' => ['hasNextPage' => false]]];
        }
        if ($this->defect === 'rotated') {
            $this->revision->update(['status' => 'retired']);
        }

        return Http::response(['data' => $data], 200, ['X-Shopify-API-Version' => '2026-10']);
    }

    protected function recharge($request)
    {
        if ($request->method() !== 'GET' || $request->header('X-Recharge-Access-Token') !== ['ORIGINAL_RECHARGE_TOKEN']) {
            throw new \RuntimeException('Unexpected fictional source request.');
        }
        $response = match (parse_url($request->url(), PHP_URL_PATH)) {
            '/store' => ['store' => ['id' => 9001, 'identifier' => $this->store->shop_domain, 'external_platform' => 'shopify', 'checkout_platform' => 'shopify']],
            '/customers/123' => ['customer' => ['id' => 123, 'external_customer_id' => ['ecommerce' => '456'], 'has_valid_payment_method' => true]],
            '/subscriptions/789' => ['subscription' => ['id' => 789, 'customer_id' => 123, 'address_id' => 222, 'status' => 'active',
                'external_variant_id' => ['ecommerce' => '999'], 'quantity' => $this->defect === 'quantity' ? 1.9 : 1,
                'price' => $this->defect === 'price' ? '30.00' : '25.00', 'presentment_currency' => 'USD',
                'order_interval_unit' => 'month', 'order_interval_frequency' => 1, 'charge_interval_frequency' => 1, 'is_prepaid' => false,
                'expire_after_specific_number_of_charges' => $this->defect === 'finite_term' ? 6 : null,
                'order_day_of_month' => $this->defect === 'anchor' ? 15 : null,
                'next_charge_scheduled_at' => $this->defect === 'date' ? '2027-02-01T09:00:00-05:00' : '2027-01-31T09:00:00-05:00']],
            '/addresses/222' => ['address' => ['id' => 222, 'customer_id' => 123, 'payment_method_id' => $this->defect === 'address_method' ? 999 : 321,
                'first_name' => 'Fictional', 'last_name' => 'Member', 'address1' => '123 Example Street', 'city' => 'Greenville', 'province' => 'South Carolina', 'zip' => '29601', 'country_code' => 'US']],
            '/payment_methods/321' => ['payment_method' => ['id' => 321, 'customer_id' => 123, 'processor_name' => 'shopify_payments', 'status' => 'valid',
                'processor_payment_method_token' => $this->defect === 'token' ? 'gid://shopify/CustomerPaymentMethod/another_method' : 'gid://shopify/CustomerPaymentMethod/original_method',
                'payment_details' => ['last4' => '4242']]],
            default => throw new \RuntimeException('Unexpected fictional source path.'),
        };

        return Http::response($response, 200, ['X-Recharge-Version' => '2021-11']);
    }
}

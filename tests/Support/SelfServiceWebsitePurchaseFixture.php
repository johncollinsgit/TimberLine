<?php

namespace Tests\Support;

use App\Models\Tenant;
use App\Models\TenantBillingReceipt;
use App\Models\TenantCommercialOverride;
use App\Models\User;
use App\Services\Billing\SelfServicePurchaseEligibility;
use App\Services\Billing\SelfServiceStandardPurchaseService;
use App\Services\Billing\StripeWebhookIngestService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/** Fictional provider responses exercised through real Standard fulfillment; never live HTTP. */
class SelfServiceWebsitePurchaseFixture
{
    public static function confirm(Tenant $tenant, User $owner): void
    {
        config()->set(['self_service_purchase.enabled' => true, 'self_service_purchase.webhook_verified' => true,
            'services.stripe.secret' => 'sk_test_membership_fixture', 'services.stripe.account_id' => 'acct_membership_fixture',
            'services.stripe.webhook_secret' => 'whsec_membership_fixture', 'services.stripe.api_base' => 'https://api.stripe.com']);
        $intent = fn (): array => (array) data_get(TenantCommercialOverride::withoutGlobalScopes()->where('tenant_id', $tenant->id)->first()?->billing_mapping, 'self_service_purchase');
        $session = function (bool $paid) use ($intent, $tenant): array {
            $saved = $intent();

            return ['id' => 'cs_membership_fixture', 'object' => 'checkout.session', 'url' => 'https://checkout.stripe.com/c/pay/membership-fixture',
                'mode' => 'subscription', 'status' => $paid ? 'complete' : 'open', 'payment_status' => $paid ? 'paid' : 'unpaid',
                'client_reference_id' => $saved['id'], 'currency' => 'usd', 'amount_subtotal' => 79800, 'livemode' => false,
                'customer' => $paid ? 'cus_membership_fixture' : null, 'invoice' => $paid ? 'in_membership_fixture' : null, 'subscription' => $paid ? 'sub_membership_fixture' : null,
                'metadata' => ['purpose' => 'self_service_standard', 'purchase_id' => $saved['id'], 'quote_hash' => $saved['quote']['quote_hash'], 'tenant_id' => (string) $tenant->id]];
        };
        Http::fake(function (Request $request) use ($intent, $session) {
            $body = match (parse_url($request->url(), PHP_URL_PATH)) {
                '/v1/account' => ['id' => 'acct_membership_fixture', 'charges_enabled' => true],
                '/v1/tax/settings' => ['status' => 'active'],
                '/v1/checkout/sessions', '/v1/checkout/sessions/cs_membership_fixture' => $session($request->method() !== 'POST'),
                '/v1/subscriptions/sub_membership_fixture' => ['id' => 'sub_membership_fixture', 'object' => 'subscription', 'status' => 'active', 'livemode' => false,
                    'customer' => 'cus_membership_fixture', 'latest_invoice' => 'in_membership_fixture', 'current_period_end' => now()->addMonth()->timestamp,
                    'metadata' => ['purchase_id' => $intent()['id']], 'items' => ['data' => [['quantity' => 1, 'price' => ['currency' => 'usd', 'unit_amount' => 29900, 'recurring' => ['interval' => 'month', 'interval_count' => 1]]]]]],
                '/v1/invoices/in_membership_fixture' => ['id' => 'in_membership_fixture', 'object' => 'invoice', 'status' => 'paid', 'paid' => true, 'livemode' => false,
                    'currency' => 'usd', 'customer' => 'cus_membership_fixture', 'subscription' => 'sub_membership_fixture', 'payment_intent' => 'pi_membership_fixture',
                    'subtotal' => 79800, 'total' => 79800, 'amount_paid' => 79800, 'amount_remaining' => 0, 'status_transitions' => ['paid_at' => now()->timestamp]],
                '/v1/payment_intents/pi_membership_fixture' => ['id' => 'pi_membership_fixture', 'status' => 'succeeded', 'amount_received' => 79800, 'currency' => 'usd',
                    'customer' => 'cus_membership_fixture', 'livemode' => false, 'latest_charge' => ['id' => 'ch_membership_fixture', 'amount_refunded' => 0, 'disputed' => false]],
                default => throw new RuntimeException('Unexpected provider call in the website membership fixture.'),
            };

            return Http::response($body);
        });
        $purchase = app(SelfServiceStandardPurchaseService::class);
        $quote = $purchase->quote($tenant, $owner, ['managed_website']);
        expect($purchase->checkout($tenant, $owner, ['managed_website'], ['terms_accepted' => true, 'quote_hash' => $quote['quote_hash']])['ok'])->toBeTrue();
        $payload = json_encode(['id' => 'evt_membership_fixture', 'type' => 'checkout.session.completed', 'livemode' => false,
            'created' => now()->timestamp, 'data' => ['object' => $session(true)]], JSON_THROW_ON_ERROR);
        $timestamp = now()->timestamp;
        $signature = 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.'.'.$payload, 'whsec_membership_fixture');
        app(StripeWebhookIngestService::class)->ingest($payload, $signature);
        expect(app(SelfServicePurchaseEligibility::class)->paidForTenant($tenant))->toBeTrue()
            ->and(TenantBillingReceipt::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count())->toBe(1);
    }
}

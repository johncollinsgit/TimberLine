<?php

namespace App\Services\AiBranch;

use App\Models\Tenant;
use App\Models\TenantAccessAddon;
use App\Models\TenantAccessProfile;
use App\Models\TenantBillingSubscription;
use App\Models\TenantCommercialOverride;
use App\Models\User;
use App\Services\Tenancy\LandlordOperatorActionAuditService;
use App\Support\Auth\HomeRedirect;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/** Private operator preparation only; ordinary catalog purchases remain disabled. */
class AiBranchPurchase
{
    public const ADDON = 'ai_branch_growth';

    public function preview(Tenant $tenant, User $operator): array
    {
        $operator->refresh();
        abort_unless(HomeRedirect::isPlatformOperator($operator) && $operator->is_active !== false, 403);
        abort_unless(config('ai_branch.purchase_enabled') && in_array((int) $tenant->id, config('ai_branch.purchase_tenant_ids', []), true), 403, 'This workspace is not approved for an AI purchase.');
        $tier = (array) config('module_catalog.addons.'.self::ADDON);
        abort_unless(data_get($tier, 'pricing.recurring_price_cents') === 9900 && data_get($tier, 'pricing.currency') === 'USD', 409, 'Review the approved AI offer after a catalog price change.');
        $profile = TenantAccessProfile::query()->where('tenant_id', $tenant->id)->firstOrFail();
        abort_unless(in_array(self::ADDON, config('module_catalog.plans.'.$profile->plan_key.'.eligible_addons', []), true), 409);
        abort_if(TenantAccessAddon::query()->where('tenant_id', $tenant->id)->where('enabled', true)->whereIn('addon_key', ['ai_branch_starter', self::ADDON, 'ai_branch_pro'])->exists(), 409, 'An AI tier is already enabled; review its billing before another purchase.');
        abort_if(TenantBillingSubscription::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('provider', 'stripe')->whereIn('purchase_key', ['addon.ai_branch_starter', 'addon.'.self::ADDON, 'addon.ai_branch_pro'])->whereNotIn('status', ['canceled', 'incomplete_expired'])->exists(), 409, 'Existing AI billing requires review before another purchase.');
        app(AiBranchProvider::class)->settings();
        abort_unless(config('commercial.billing_readiness.lifecycle_mutations_enabled'), 503, 'Verified billing fulfillment must be available before checkout.');

        return ['tenant_id' => (int) $tenant->id, 'tenant_slug' => $tenant->slug, 'addon_key' => self::ADDON, 'currency' => 'USD', 'monthly_cents' => 9900, 'setup_cents' => 0, 'base_service_included' => false];
    }

    public function prepare(Tenant $tenant, User $operator, string $authorization): array
    {
        $offer = $this->preview($tenant, $operator);
        abort_unless(strlen($authorization) >= 5 && strlen($authorization) <= 160 && ! preg_match('/sk-|whsec_|https?:\/\//i', $authorization), 422, 'Supply a short authorization reference without secrets or private URLs.');
        $secret = (string) config('services.stripe.secret');
        $live = str_starts_with($secret, 'sk_live_');
        abort_unless($live || str_starts_with($secret, 'sk_test_'), 503);
        abort_unless(rtrim((string) config('services.stripe.api_base', 'https://api.stripe.com'), '/') === 'https://api.stripe.com', 503);
        abort_if(app()->environment('production') && ! $live, 503, 'Production AI purchases require live Stripe.');
        if ($live) {
            abort_unless(filled(config('services.stripe.webhook_secret')) && config('commercial.billing_readiness.agreement_checkout.live_webhook_verified') && config('commercial.billing_readiness.agreement_checkout.tax_decision_confirmed') && config('commercial.billing_readiness.agreement_checkout.relay_payout_verified'), 503, 'Live webhook, tax and payout verification are required.');
        }
        $fingerprint = hash('sha256', $secret);
        $saved = data_get(TenantAccessProfile::query()->where('tenant_id', $tenant->id)->firstOrFail()->metadata, 'ai_branch_purchase');
        if (! is_array($saved)) {
            $lookup = (string) config('module_catalog.addons.'.self::ADDON.'.stripe.recurring_price_lookup_key');
            $prices = $this->call('get', '/v1/prices', ['active' => 'true', 'lookup_keys[0]' => $lookup, 'limit' => 2]);
            $rows = (array) ($prices['data'] ?? []);
            abort_unless(count($rows) === 1 && ($prices['has_more'] ?? false) === false, 409, 'Exactly one approved Stripe price is required.');
            $price = $rows[0];
            abort_unless(($price['active'] ?? false) === true && ($price['livemode'] ?? null) === $live && ($price['currency'] ?? '') === 'usd' && ($price['unit_amount'] ?? null) === $offer['monthly_cents'] && ($price['billing_scheme'] ?? '') === 'per_unit' && data_get($price, 'recurring.interval') === 'month' && data_get($price, 'recurring.interval_count') === 1 && ($price['lookup_key'] ?? '') === $lookup && data_get($price, 'metadata.purchase_key') === 'addon.'.self::ADDON && preg_match('/^price_[A-Za-z0-9]+$/', $price['id'] ?? ''), 409, 'Stripe price does not match the exact approved monthly AI offer.');
            $customer = trim((string) data_get(TenantCommercialOverride::query()->where('tenant_id', $tenant->id)->first()?->billing_mapping, 'stripe.customer_reference'));
            $invalidCustomer = false;
            if ($customer !== '') {
                $response = $this->request()->get('https://api.stripe.com/v1/customers/'.urlencode($customer));
                if ($response->status() === 404 && $response->json('error.code') === 'resource_missing') {
                    $invalidCustomer = true;
                    $customer = '';
                } else {
                    abort_unless($response->successful() && ! $response->json('deleted', false) && $response->json('livemode') === $live && (string) $response->json('metadata.tenant_id') === (string) $tenant->id, 409, 'Existing Stripe customer ownership must be verified.');
                }
            }
            if ($customer === '') {
                $matches = $this->call('get', '/v1/customers/search', ['query' => "metadata['tenant_id']:'".(int) $tenant->id."' OR metadata['tenant_slug']:'".$tenant->slug."' OR name:'".str_replace(['\\', "'"], ['\\\\', "\\'"], $tenant->name)."'", 'limit' => 100]);
                abort_unless(($matches['data'] ?? null) === [] && ($matches['has_more'] ?? true) === false, 409, 'Review existing matching Stripe customers before creating checkout.');
            }
            $saved = DB::transaction(function () use ($tenant, $operator, $offer, $authorization, $fingerprint, $price, $customer, $invalidCustomer): array {
                $profile = TenantAccessProfile::query()->where('tenant_id', $tenant->id)->lockForUpdate()->firstOrFail();
                if (is_array($existing = data_get($profile->metadata, 'ai_branch_purchase'))) {
                    return $existing;
                }
                $snapshot = [...$offer, 'request_id' => (string) Str::uuid(), 'state' => 'reserved', 'operator_id' => $operator->id, 'authorization_reference' => $authorization, 'stripe_key_fingerprint' => $fingerprint, 'price_id' => $price['id'], 'customer_id' => $customer, 'invalid_customer_reference_skipped' => $invalidCustomer, 'created_at' => now()->timestamp, 'expires_at' => now()->addDay()->timestamp, 'automatic_tax' => (bool) config('commercial.billing_readiness.agreement_checkout.automatic_tax_enabled'), 'display_name' => $tenant->name, 'return_url' => route('app.start', ['tenant' => $tenant->slug, 'billing' => 'ai'])];
                $profile->forceFill(['metadata' => [...(array) $profile->metadata, 'ai_branch_purchase' => $snapshot]])->save();
                app(LandlordOperatorActionAuditService::class)->record($tenant->id, $operator->id, 'ai_branch.purchase.reserve', targetType: 'tenant', targetId: $tenant->id, confirmation: ['authorization_reference' => $authorization], afterState: $offer, context: ['request_id' => $snapshot['request_id'], 'invalid_customer_reference_skipped' => $invalidCustomer]);

                return $snapshot;
            });
        }
        abort_unless(hash_equals($fingerprint, (string) ($saved['stripe_key_fingerprint'] ?? '')) && ($saved['monthly_cents'] ?? null) === $offer['monthly_cents'] && ($saved['tenant_id'] ?? null) === (int) $tenant->id && ($saved['created_at'] ?? 0) > now()->subHours(23)->timestamp, 409, 'The existing checkout attempt requires operator reconciliation; another will not be created.');
        $metadata = ['purpose' => 'addon_checkout', 'tenant_id' => (string) $tenant->id, 'tenant_slug' => $tenant->slug, 'checkout_addons_interest' => self::ADDON, 'ai_branch_purchase' => '1', 'ai_purchase_request_id' => $saved['request_id']];
        $return = $saved['return_url'];
        $payload = ['mode' => 'subscription', 'success_url' => $return.'&payment=return', 'cancel_url' => $return.'&payment=cancel', 'client_reference_id' => 'tenant-'.$tenant->id, 'line_items[0][price]' => $saved['price_id'], 'line_items[0][quantity]' => 1, 'payment_method_types[0]' => 'card', 'billing_address_collection' => 'required', 'automatic_tax[enabled]' => $saved['automatic_tax'] ? 'true' : 'false', 'expires_at' => $saved['expires_at'], 'custom_text[submit][message]' => 'AI Branch Growth for '.$saved['display_name'].': USD 99 per month before applicable tax. Separate from your Everbranch base service. No setup fee.'];
        if ($saved['customer_id'] !== '') {
            $payload['customer'] = $saved['customer_id'];
        }
        foreach ($metadata as $key => $value) {
            $payload['metadata['.$key.']'] = $value;
            $payload['subscription_data[metadata]['.$key.']'] = $value;
        }
        $this->preview($tenant, $operator);
        $session = filled($saved['session_id'] ?? null)
            ? $this->call('get', '/v1/checkout/sessions/'.urlencode($saved['session_id']))
            : $this->call('post', '/v1/checkout/sessions', $payload, 'ai-branch-purchase-'.$saved['request_id']);
        abort_unless(($session['livemode'] ?? null) === $live && ($session['mode'] ?? '') === 'subscription' && ($session['currency'] ?? '') === 'usd' && ($session['amount_subtotal'] ?? null) === 9900 && ($session['status'] ?? '') === 'open' && data_get($session, 'metadata.ai_purchase_request_id') === $saved['request_id'] && preg_match('/^cs_[A-Za-z0-9_]+$/', $session['id'] ?? '') && parse_url($session['url'] ?? '', PHP_URL_SCHEME) === 'https' && parse_url($session['url'] ?? '', PHP_URL_HOST) === 'checkout.stripe.com', 409, 'Stripe checkout requires review; no payment has been confirmed locally.');
        DB::transaction(function () use ($tenant, $operator, $saved, $session): void {
            $profile = TenantAccessProfile::query()->where('tenant_id', $tenant->id)->lockForUpdate()->firstOrFail();
            abort_unless(data_get($profile->metadata, 'ai_branch_purchase.request_id') === $saved['request_id'], 409);
            $profile->forceFill(['metadata' => [...(array) $profile->metadata, 'ai_branch_purchase' => [...$saved, 'session_id' => $session['id'], 'state' => 'checkout_open']]])->save();
            app(LandlordOperatorActionAuditService::class)->record($tenant->id, $operator->id, 'ai_branch.purchase.prepare', targetType: 'tenant', targetId: $tenant->id, context: ['request_id' => $saved['request_id'], 'session_id' => $session['id'], 'monthly_cents' => 9900, 'status' => 'checkout_open']);
        });

        return [...$offer, 'request_id' => $saved['request_id'], 'session_id' => $session['id'], 'url' => $session['url'], 'expires_at' => $session['expires_at'] ?? $saved['expires_at'], 'paid' => false];
    }

    private function request(): \Illuminate\Http\Client\PendingRequest
    {
        return Http::asForm()->acceptJson()->withBasicAuth((string) config('services.stripe.secret'), '')->connectTimeout(5)->timeout(20);
    }

    private function call(string $method, string $path, array $payload = [], ?string $idempotency = null): array
    {
        $request = $this->request();
        if ($idempotency) {
            $request = $request->withHeaders(['Idempotency-Key' => $idempotency]);
        }
        $response = $request->{$method}('https://api.stripe.com'.$path, $payload);
        if ($response->failed() || ! is_array($response->json())) {
            throw new RuntimeException('Stripe preparation could not be verified. Retain this attempt for reconciliation.');
        }

        return $response->json();
    }
}

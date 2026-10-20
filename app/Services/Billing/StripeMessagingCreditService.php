<?php

namespace App\Services\Billing;

use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\TenantHostBuilder;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

class StripeMessagingCreditService
{
    public function __construct(protected TenantHostBuilder $hostBuilder) {}

    /** @return array{ok:bool,url:?string,message:?string} */
    public function createCheckout(Tenant $tenant, User $actor, int $packCents): array
    {
        if (! (bool) config('features.tenant_messaging_credit_checkout')) {
            return ['ok' => false, 'url' => null, 'message' => 'Messaging credit checkout is not enabled.'];
        }

        return $this->checkout($tenant, $actor, $packCents);
    }

    public function createAiCheckout(Tenant $tenant, User $actor, int $packCents): array
    {
        abort_unless(app(\App\Services\AiBranch\AiBranchOffer::class)->metered($tenant), 403);
        app(\App\Services\AiBranch\AiBranchAccess::class)->authorize($tenant, $actor);
        abort_unless(app(\App\Services\AiBranch\AiBranchAccess::class)->canApprove($tenant, $actor), 403);
        app(\App\Services\AiBranch\AiBranchProvider::class)->settings($tenant);
        abort_unless(filled(config('services.stripe.webhook_secret')) && config('commercial.billing_readiness.lifecycle_mutations_enabled')
            && rtrim((string) config('services.stripe.api_base'), '/') === 'https://api.stripe.com'
            && (! app()->isProduction() || str_starts_with((string) config('services.stripe.secret'), 'sk_live_')), 503, 'Service credit billing is not ready.');

        return $this->checkout($tenant, $actor, $packCents, true);
    }

    protected function checkout(Tenant $tenant, User $actor, int $packCents, bool $ai = false): array
    {
        $packs = array_map('intval', (array) config('marketing.messaging.platform.credit_packs_cents', []));
        if (! in_array($packCents, $packs, true)) {
            return ['ok' => false, 'url' => null, 'message' => 'Choose one of the available credit packs.'];
        }
        if (trim((string) config('services.stripe.secret')) === '') {
            return ['ok' => false, 'url' => null, 'message' => 'Stripe is not configured.'];
        }

        $returnUrl = $ai ? route('ai-branch.index', ['tenant' => $tenant->slug]) : $this->returnUrl($tenant);
        $separator = str_contains($returnUrl, '?') ? '&' : '?';
        $payload = [
            'mode' => 'payment',
            'success_url' => $returnUrl.$separator.'messaging_credit=success&session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => $returnUrl.$separator.'messaging_credit=cancel',
            'client_reference_id' => 'tenant-'.$tenant->id,
            'customer_email' => (string) $actor->email,
            'line_items[0][price_data][currency]' => 'usd',
            'line_items[0][price_data][unit_amount]' => $packCents,
            'line_items[0][price_data][product_data][name]' => $ai ? 'Everbranch shared service credit' : 'Everbranch messaging credit',
            'line_items[0][quantity]' => 1,
            'metadata[purpose]' => 'messaging_credit',
            'metadata[tenant_id]' => (string) $tenant->id,
            'metadata[actor_user_id]' => (string) $actor->id,
            'metadata[pack_cents]' => (string) $packCents,
        ];
        if ($ai) {
            $payload['payment_method_types[0]'] = 'card';
            $payload['metadata[credit_scope]'] = 'ai_shared';
        }
        $response = $this->request()
            ->withHeaders(['Idempotency-Key' => ($ai ? 'ai-service-credit-' : 'messaging-credit-').$tenant->id.'-'.$packCents.'-'.now()->format('YmdHi')])
            ->post(rtrim((string) config('services.stripe.api_base'), '/').'/v1/checkout/sessions', $payload);
        $json = (array) $response->json();
        $url = trim((string) ($json['url'] ?? ''));

        if ($ai) {
            abort_unless($response->successful() && ($json['livemode'] ?? null) === str_starts_with((string) config('services.stripe.secret'), 'sk_live_')
                && preg_match('/^cs_[A-Za-z0-9_]+$/', $json['id'] ?? '') && ($json['status'] ?? '') === 'open'
                && ($json['mode'] ?? '') === 'payment' && ($json['currency'] ?? '') === 'usd' && ($json['amount_subtotal'] ?? null) === $packCents
                && data_get($json, 'metadata.tenant_id') === (string) $tenant->id && data_get($json, 'metadata.pack_cents') === (string) $packCents
                && data_get($json, 'metadata.credit_scope') === 'ai_shared'
                && parse_url($url, PHP_URL_SCHEME) === 'https' && parse_url($url, PHP_URL_HOST) === 'checkout.stripe.com', 503, 'Credit checkout could not be verified. No local credit was added.');
            app(\App\Services\Tenancy\LandlordOperatorActionAuditService::class)->record($tenant->id, $actor->id, 'ai_branch.credit.checkout',
                confirmation: ['explicit' => true, 'pack_cents' => $packCents], result: ['session_id' => $json['id'], 'credit_added' => false]);
        }

        return $response->successful() && $url !== ''
            ? ['ok' => true, 'url' => $url, 'message' => null]
            : ['ok' => false, 'url' => null, 'message' => (string) data_get($json, 'error.message', 'Stripe checkout could not be started.')];
    }

    protected function request(): PendingRequest
    {
        return Http::asForm()->acceptJson()->timeout((int) config('services.stripe.timeout', 20))
            ->withBasicAuth((string) config('services.stripe.secret'), '');
    }

    protected function returnUrl(Tenant $tenant): string
    {
        $host = $this->hostBuilder->hostForSlug((string) $tenant->slug)
            ?: $this->hostBuilder->canonicalLandlordHost();

        return $this->hostBuilder->urlForHostPath($host, route('app.start', [], false))
            ?: url(route('app.start', [], false));
    }
}

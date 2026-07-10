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
        $packs = array_map('intval', (array) config('marketing.messaging.platform.credit_packs_cents', []));
        if (! in_array($packCents, $packs, true)) {
            return ['ok' => false, 'url' => null, 'message' => 'Choose one of the available credit packs.'];
        }
        if (trim((string) config('services.stripe.secret')) === '') {
            return ['ok' => false, 'url' => null, 'message' => 'Stripe is not configured.'];
        }

        $returnUrl = $this->returnUrl($tenant);
        $payload = [
            'mode' => 'payment',
            'success_url' => $returnUrl.'?messaging_credit=success&session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => $returnUrl.'?messaging_credit=cancel',
            'client_reference_id' => 'tenant-'.$tenant->id,
            'customer_email' => (string) $actor->email,
            'line_items[0][price_data][currency]' => 'usd',
            'line_items[0][price_data][unit_amount]' => $packCents,
            'line_items[0][price_data][product_data][name]' => 'Everbranch messaging credit',
            'line_items[0][quantity]' => 1,
            'metadata[purpose]' => 'messaging_credit',
            'metadata[tenant_id]' => (string) $tenant->id,
            'metadata[actor_user_id]' => (string) $actor->id,
            'metadata[pack_cents]' => (string) $packCents,
        ];
        $response = $this->request()
            ->withHeaders(['Idempotency-Key' => 'messaging-credit-'.$tenant->id.'-'.$packCents.'-'.now()->format('YmdHi')])
            ->post(rtrim((string) config('services.stripe.api_base'), '/').'/v1/checkout/sessions', $payload);
        $json = (array) $response->json();
        $url = trim((string) ($json['url'] ?? ''));

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

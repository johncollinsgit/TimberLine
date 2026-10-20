<?php

namespace App\Services\Billing;

use App\Models\IntegrationConnection;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/** Seller connection only. A connection never authorizes checkout or billing. */
class TenantPayPalConnectionService
{
    public function configured(): bool
    {
        $mode = config('tenant_payments.paypal.environment');

        return (bool) config('tenant_payments.paypal.enabled')
            && in_array($mode, ['sandbox', 'live'], true)
            && ($mode === 'sandbox' || config('tenant_payments.paypal.partner_approved'))
            && collect(['client_id', 'client_secret', 'partner_id', 'bn_code'])->every(fn ($key) => filled(config('tenant_payments.paypal.'.$key)));
    }

    public function connection(Tenant $tenant): ?IntegrationConnection
    {
        return IntegrationConnection::query()->forTenant($tenant)->where('provider', 'tenant_paypal')
            ->where('external_account_id', $this->fingerprint())->first();
    }

    public function start(Tenant $tenant, User $actor, string $returnUrl): string
    {
        abort_unless($this->configured(), 423, 'PayPal connections are waiting for platform setup.');
        $connection = DB::transaction(function () use ($tenant, $actor) {
            Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            $connection = $this->connection($tenant);
            if ($connection?->status === 'disconnected') {
                $connection->update(['status' => 'pending', 'external_account_secret' => json_encode(['tracking_id' => (string) Str::uuid()], JSON_THROW_ON_ERROR),
                    'connected_by_user_id' => $actor->id, 'connected_at' => null, 'last_synced_at' => null]);
            }
            if (! $connection) {
                $connection = IntegrationConnection::create(['tenant_id' => $tenant->id, 'provider' => 'tenant_paypal',
                    'external_account_id' => $this->fingerprint(), 'status' => 'pending', 'connected_by_user_id' => $actor->id,
                    'external_account_secret' => json_encode(['tracking_id' => (string) Str::uuid()], JSON_THROW_ON_ERROR),
                    'metadata' => ['environment' => config('tenant_payments.paypal.environment'), 'purpose' => 'payment_processing']]);
            }

            return $connection;
        });
        $secret = json_decode($connection->external_account_secret, true, flags: JSON_THROW_ON_ERROR);
        // This consent comes from the explicit checkbox in our authenticated form.
        $response = $this->client()->post('/v2/customer/partner-referrals', [
            'tracking_id' => $secret['tracking_id'], 'email' => $actor->email,
            'partner_config_override' => ['return_url' => $returnUrl, 'return_url_description' => 'Return to Everbranch'],
            'operations' => [['operation' => 'API_INTEGRATION', 'api_integration_preference' => ['rest_api_integration' => [
                'integration_method' => 'PAYPAL', 'integration_type' => 'THIRD_PARTY', 'third_party_details' => ['features' => ['PAYMENT', 'REFUND']],
            ]]]],
            'products' => ['EXPRESS_CHECKOUT'], 'legal_consents' => [['type' => 'SHARE_DATA_CONSENT', 'granted' => true]],
        ]);
        abort_unless($response->successful(), 502, 'PayPal could not start the connection. Please try again or contact Everbranch support.');
        $url = (string) data_get(collect((array) $response->json('links'))->firstWhere('rel', 'action_url'), 'href', '');
        $host = config('tenant_payments.paypal.environment') === 'live' ? 'www.paypal.com' : 'www.sandbox.paypal.com';
        abort_unless(parse_url($url, PHP_URL_SCHEME) === 'https' && parse_url($url, PHP_URL_HOST) === $host
            && ! parse_url($url, PHP_URL_USER) && ! parse_url($url, PHP_URL_PORT), 502, 'PayPal returned an invalid connection link.');
        app(\App\Services\Tenancy\LandlordOperatorActionAuditService::class)->record($tenant->id, $actor->id, 'tenant_payments.paypal.started',
            targetType: 'integration_connection', targetId: $connection->id, context: ['environment' => config('tenant_payments.paypal.environment')],
            confirmation: ['share_data_consent' => true]);

        return $url;
    }

    public function refresh(Tenant $tenant, User $actor): IntegrationConnection
    {
        abort_unless($this->configured(), 423);
        $connection = $this->connection($tenant);
        abort_unless($connection, 404);
        $secret = DB::transaction(function () use ($connection) {
            $locked = IntegrationConnection::query()->forTenantId($connection->tenant_id)->lockForUpdate()->findOrFail($connection->id);
            abort_if($locked->status === 'disconnected', 409, 'PayPal was disconnected. Connect it again before checking.');
            $secret = json_decode($locked->external_account_secret, true, flags: JSON_THROW_ON_ERROR);
            $secret['check_id'] = (string) Str::uuid();
            $locked->update(['external_account_secret' => json_encode($secret, JSON_THROW_ON_ERROR)]);

            return $secret;
        });
        // A return URL's merchantId/permissionsGranted fields are never evidence.
        $response = $this->client()->get('/v1/customer/partners/'.rawurlencode(config('tenant_payments.paypal.partner_id')).'/merchant-integrations', ['tracking_id' => $secret['tracking_id']]);
        if (! $response->successful()) {
            DB::transaction(function () use ($connection, $secret) {
                $locked = IntegrationConnection::query()->forTenantId($connection->tenant_id)->lockForUpdate()->findOrFail($connection->id);
                $current = json_decode($locked->external_account_secret, true, flags: JSON_THROW_ON_ERROR);
                if ($locked->status !== 'disconnected' && ($current['check_id'] ?? null) === $secret['check_id']) {
                    $locked->update(['status' => 'pending', 'last_synced_at' => null]);
                }
            });
            abort(502, 'PayPal verification is unavailable. The connection has not been verified.');
        }
        $data = $response->json();
        $owned = is_array($data) && ($data['tracking_id'] ?? null) === $secret['tracking_id']
            && is_string($data['merchant_id'] ?? null) && preg_match('/^[A-Z0-9]{8,32}$/D', $data['merchant_id']);
        $scopes = collect($data['oauth_integrations'] ?? [])->flatMap(fn ($integration) => $integration['oauth_third_party'] ?? [])
            ->filter(fn ($integration) => ($integration['partner_client_id'] ?? null) === config('tenant_payments.paypal.client_id'))
            ->flatMap(fn ($integration) => $integration['scopes'] ?? [])->all();
        $permissions = in_array('https://uri.paypal.com/services/payments/payment/authcapture', $scopes, true)
            && in_array('https://uri.paypal.com/services/payments/refund', $scopes, true);
        $ready = $owned && $permissions && ($data['payments_receivable'] ?? false) === true && ($data['primary_email_confirmed'] ?? false) === true;
        DB::transaction(function () use ($connection, $secret, $data, $owned, $ready, $permissions, $actor) {
            $locked = IntegrationConnection::query()->forTenantId($connection->tenant_id)->lockForUpdate()->findOrFail($connection->id);
            // A local disconnect wins over an in-flight provider response.
            abort_if($locked->status === 'disconnected', 409, 'PayPal was disconnected. Connect it again before checking.');
            $current = json_decode($locked->external_account_secret, true, flags: JSON_THROW_ON_ERROR);
            abort_unless(($current['tracking_id'] ?? null) === $secret['tracking_id'], 409, 'PayPal setup changed. Check verification again.');
            abort_unless(($current['check_id'] ?? null) === $secret['check_id'], 409, 'A newer PayPal verification has started. Refresh to see its result.');
            if ($owned) {
                abort_if(isset($secret['merchant_id']) && $secret['merchant_id'] !== $data['merchant_id'], 409, 'PayPal account changed. Contact Everbranch support.');
                $secret['merchant_id'] = $data['merchant_id'];
            }
            $locked->update(['status' => $ready ? 'connected' : 'pending', 'external_account_secret' => json_encode($secret, JSON_THROW_ON_ERROR),
                'last_synced_at' => now(), 'connected_at' => $ready ? ($locked->connected_at ?? now()) : null,
                'metadata' => ['environment' => config('tenant_payments.paypal.environment'), 'payments_receivable' => $owned && ($data['payments_receivable'] ?? false) === true,
                    'email_confirmed' => $owned && ($data['primary_email_confirmed'] ?? false) === true, 'permissions_verified' => $owned && $permissions]]);
            app(\App\Services\Tenancy\LandlordOperatorActionAuditService::class)->record($connection->tenant_id, $actor->id, 'tenant_payments.paypal.checked',
                targetType: 'integration_connection', targetId: $connection->id, result: ['connected' => $ready]);
        });

        return $connection->fresh();
    }

    public function disconnect(Tenant $tenant, User $actor): void
    {
        $connection = $this->connection($tenant);
        abort_unless($connection, 404);
        $connection->update(['status' => 'disconnected', 'last_synced_at' => null]);
        app(\App\Services\Tenancy\LandlordOperatorActionAuditService::class)->record($tenant->id, $actor->id, 'tenant_payments.paypal.disconnected', targetType: 'integration_connection', targetId: $connection->id);
    }

    private function client(): PendingRequest
    {
        $base = config('tenant_payments.paypal.environment') === 'live' ? 'https://api-m.paypal.com' : 'https://api-m.sandbox.paypal.com';
        $response = Http::asForm()->withBasicAuth(config('tenant_payments.paypal.client_id'), config('tenant_payments.paypal.client_secret'))
            ->withoutRedirecting()->connectTimeout(5)->timeout(20)->post($base.'/v1/oauth2/token', ['grant_type' => 'client_credentials']);
        abort_unless($response->successful() && is_string($response->json('access_token')) && filled($response->json('access_token')), 502, 'PayPal authentication is temporarily unavailable.');

        return Http::baseUrl($base)->acceptJson()->withToken($response->json('access_token'))
            ->withHeaders(['PayPal-Partner-Attribution-Id' => config('tenant_payments.paypal.bn_code')])
            ->withoutRedirecting()->connectTimeout(5)->timeout(20);
    }

    private function fingerprint(): string
    {
        return hash('sha256', implode('|', [config('tenant_payments.paypal.environment'), config('tenant_payments.paypal.client_id'), config('tenant_payments.paypal.partner_id')]));
    }
}

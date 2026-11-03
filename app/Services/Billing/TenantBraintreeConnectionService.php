<?php

namespace App\Services\Billing;

use App\Models\IntegrationConnection;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/** Tenant-owned processor setup. Connecting cannot select a billing owner or charge. */
class TenantBraintreeConnectionService
{
    public function enabledFor(Tenant $tenant): bool
    {
        return (bool) config('tenant_payments.braintree.setup_enabled')
            && in_array($tenant->id, (array) config('tenant_payments.braintree.tenant_ids', []), true)
            && app(TenantPaymentSetupAccess::class)->enabledFor($tenant);
    }

    public function connections(Tenant $tenant)
    {
        return IntegrationConnection::query()->forTenant($tenant)->where('provider', 'braintree')
            ->where('metadata->purpose', 'merchant_payment_verification')->orderBy('id')->get();
    }

    public function connect(Tenant $tenant, User $actor, array $input): IntegrationConnection
    {
        $this->authorize($tenant, $actor);
        $data = Validator::make($input, [
            'environment' => ['required', 'in:sandbox,production'],
            'merchant_id' => ['required', 'regex:/\A[A-Za-z0-9_-]{3,100}\z/'],
            'merchant_account_id' => ['required', 'regex:/\A[A-Za-z0-9_-]{1,100}\z/'],
            'braintree_public_key' => ['required', 'regex:/\A[A-Za-z0-9_-]{8,200}\z/'],
            'password' => ['required', 'regex:/\A[A-Za-z0-9_-]{8,200}\z/'],
            'label' => ['required', 'string', 'max:120'], 'reviewed' => ['required', 'accepted'],
        ])->validate();
        $credentials = ['environment' => $data['environment'], 'merchant_id' => $data['merchant_id'],
            'merchant_account_id' => $data['merchant_account_id'], 'public_key' => $data['braintree_public_key'], 'private_key' => $data['password']];
        $identity = $data['environment'].'|'.$data['merchant_id'].'|'.$data['merchant_account_id'];
        $account = app(TenantBraintreeGateway::class)->account($credentials, $data['merchant_account_id']);
        abort_unless(($account['id'] ?? null) === $data['merchant_account_id'] && ($account['status'] ?? null) === 'active'
            && preg_match('/\A[A-Z]{3}\z/', $account['currency'] ?? ''), 423, 'The selected Braintree merchant account must be active with a known currency.');

        return DB::transaction(function () use ($tenant, $actor, $data, $credentials, $identity, $account) {
            Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            $this->authorize($tenant, $actor->fresh());
            $existing = IntegrationConnection::query()->forTenant($tenant)->where('provider', 'braintree')->where('external_account_id', $identity)->lockForUpdate()->first();
            if ($existing) {
                abort_unless($existing->status === 'connected' && data_get($existing->metadata, 'purpose') === 'merchant_payment_verification'
                    && data_get($existing->metadata, 'currency') === $account['currency']
                    && hash_equals((string) $existing->access_token, json_encode($credentials, JSON_THROW_ON_ERROR)), 409, 'This account already has a retained connection. Credential changes need a separate reviewed reconnection.');

                return $existing;
            }

            return IntegrationConnection::create(['tenant_id' => $tenant->id, 'provider' => 'braintree', 'external_account_id' => $identity,
                'external_account_label' => trim($data['label']), 'status' => 'connected',
                'access_token' => json_encode($credentials, JSON_THROW_ON_ERROR), 'connected_by_user_id' => $actor->id,
                'connected_at' => now(), 'last_synced_at' => now(), 'metadata' => [
                    'purpose' => 'merchant_payment_verification', 'environment' => $data['environment'],
                    'merchant_id' => $data['merchant_id'], 'merchant_account_id' => $data['merchant_account_id'],
                    'currency' => $account['currency'], 'credential_revision' => (string) Str::uuid(),
                    'account_status' => 'active', 'account_read_verified' => true,
                    'collection_enabled' => false, 'vault_migration_enabled' => false,
                ]]);
        });
    }

    public function refresh(Tenant $tenant, User $actor, int $connectionId): IntegrationConnection
    {
        $this->authorize($tenant, $actor);
        $connection = $this->owned($tenant, $connectionId);
        $revision = data_get($connection->metadata, 'credential_revision');
        $credentials = $this->credentials($connection);
        $account = app(TenantBraintreeGateway::class)->account($credentials, $credentials['merchant_account_id']);
        abort_unless(($account['id'] ?? null) === $credentials['merchant_account_id'] && ($account['status'] ?? null) === 'active'
            && ($account['currency'] ?? null) === data_get($connection->metadata, 'currency'), 423, 'Braintree account status or currency needs review.');

        return DB::transaction(function () use ($tenant, $actor, $connection, $revision) {
            $this->authorize($tenant, $actor->fresh());
            $current = $this->owned($tenant, $connection->id, true);
            abort_unless(data_get($current->metadata, 'credential_revision') === $revision, 409, 'The original Braintree credential changed during verification.');
            $current->update(['last_synced_at' => now()]);

            return $current->fresh();
        });
    }

    /** Exact IDs must come from reviewed source records. This returns masked proof, not billing permission. */
    public function inspectVault(Tenant $tenant, User $actor, int $connectionId, string $customerId, string $paymentToken): array
    {
        $this->authorize($tenant, $actor);
        abort_unless(preg_match('/\A[A-Za-z0-9_-]{1,100}\z/', $customerId) && preg_match('/\A[A-Za-z0-9_-]{1,100}\z/', $paymentToken), 422);
        $connection = $this->owned($tenant, $connectionId);
        $credentials = $this->credentials($connection);
        $result = app(TenantBraintreeGateway::class)->vaultedMethod($credentials, $customerId, $paymentToken);
        $this->authorize($tenant, $actor->fresh());
        $current = $this->owned($tenant, $connectionId);
        abort_unless(hash_equals((string) $connection->access_token, (string) $current->access_token)
            && data_get($connection->metadata, 'credential_revision') === data_get($current->metadata, 'credential_revision'), 409);

        return $result + ['collection_enabled' => false, 'source_billing_stopped' => false];
    }

    private function authorize(Tenant $tenant, User $actor): void
    {
        abort_unless(app(TenantPaymentSetupAccess::class)->allows($tenant, $actor), 403);
        abort_unless($this->enabledFor($tenant), 404);
    }

    private function owned(Tenant $tenant, int $id, bool $lock = false): IntegrationConnection
    {
        $query = IntegrationConnection::query()->forTenant($tenant)->where('provider', 'braintree')
            ->where('status', 'connected')->where('metadata->purpose', 'merchant_payment_verification');
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->findOrFail($id);
    }

    private function credentials(IntegrationConnection $connection): array
    {
        try {
            $credentials = json_decode($connection->access_token, true, flags: JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            abort(409, 'The original Braintree credentials need review.');
        }
        abort_unless(is_array($credentials) && count($credentials) === 5
            && $connection->external_account_id === ($credentials['environment'] ?? '').'|'.($credentials['merchant_id'] ?? '').'|'.($credentials['merchant_account_id'] ?? ''), 409, 'The original Braintree account identity needs review.');

        return $credentials;
    }
}

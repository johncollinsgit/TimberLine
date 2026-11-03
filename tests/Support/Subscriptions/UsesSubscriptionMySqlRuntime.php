<?php

namespace Tests\Support\Subscriptions;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** Real commits for provider workflows; cleanup is limited to this test's created identities. */
trait UsesSubscriptionMySqlRuntime
{
    private array $subscriptionRuntimeTenants = [];

    private array $subscriptionRuntimeUsers = [];

    public function setUpUsesSubscriptionMySqlRuntime(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Provider workflow durability requires disposable local MySQL without outer test transactions.');
        }
        if (! app()->environment('testing') || ! preg_match('/(^|_)(ci|test|testing)(_|$)/i', DB::connection()->getDatabaseName())
            || ! in_array(config('database.connections.mysql.host'), ['localhost', '127.0.0.1'], true) || DB::transactionLevel() !== 0) {
            throw new \RuntimeException('Refusing provider workflow tests outside disposable local MySQL without enclosing transactions.');
        }
        Tenant::created(function (Tenant $tenant) {
            $this->subscriptionRuntimeTenants[] = $tenant->id;
        });
        User::created(function (User $user) {
            $this->subscriptionRuntimeUsers[] = $user->id;
        });
    }

    public function tearDownUsesSubscriptionMySqlRuntime(): void
    {
        if ($this->subscriptionRuntimeTenants === [] && $this->subscriptionRuntimeUsers === []) {
            return;
        }
        DB::purge('mysql');
        $ids = $this->subscriptionRuntimeTenants;
        DB::table('subscription_recharge_connections')->whereIn('tenant_id', $ids)->update(['active_credential_revision_id' => null]);
        DB::table('subscription_installations')->whereIn('tenant_id', $ids)->update(['active_credential_revision_id' => null]);
        foreach (['subscription_billing_actions', 'subscription_billing_cycles', 'subscription_billing_agreements', 'subscription_pilot_events',
            'subscription_pilot_members', 'subscription_recharge_credential_revisions', 'subscription_recharge_connections', 'subscription_credential_revisions',
            'subscription_installations', 'integration_connections', 'shopify_stores', 'marketing_profiles', 'tenant_module_entitlements', 'tenant_user'] as $table) {
            DB::table($table)->whereIn('tenant_id', $ids)->delete();
        }
        DB::table('users')->whereIn('id', $this->subscriptionRuntimeUsers)->delete();
        DB::table('tenants')->whereIn('id', $ids)->delete();
    }
}

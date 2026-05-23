<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Models\TenantAccessProfile;
use App\Models\TenantSetupStatus;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class EverbranchSeedAccessSurfaces extends Command
{
    protected $signature = 'everbranch:seed-access-surfaces
        {--dry-run : Show the records that would be created or updated without writing}
        {--password= : Optional password to set for seeded users; never printed}
        {--force-production : Allow execution in production}';

    protected $description = 'Create or update safe Everbranch landlord, Modern Forestry, demo, and sandbox access records.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $forceProduction = (bool) $this->option('force-production');

        if (app()->environment('production') && ! $forceProduction) {
            $this->error('Refusing to seed access surfaces in production. Use --force-production only after explicit operator approval.');

            return self::FAILURE;
        }

        $password = $this->option('password');
        $password = is_string($password) && trim($password) !== '' ? $password : null;

        $plan = $this->seedPlan();
        $this->info($dryRun ? 'Dry run: no records will be changed.' : 'Seeding Everbranch access surfaces.');

        foreach ($plan['tenants'] as $tenant) {
            $exists = Tenant::query()->where('slug', $tenant['slug'])->exists();
            $this->line(sprintf(
                '%s tenant: %s (%s, lane: %s)',
                $exists ? 'Update' : 'Create',
                $tenant['name'],
                $tenant['slug'],
                $tenant['account_mode']
            ));
        }

        foreach ($plan['users'] as $user) {
            $exists = User::query()->where('email', $user['email'])->exists();
            $tenantSlug = $user['tenant_slug'] ?: 'none';
            $passwordAction = $password !== null
                ? 'password will be set from --password'
                : ($exists ? 'password unchanged' : 'random password generated; use reset flow');

            $this->line(sprintf(
                '%s user: %s <%s> (role: %s, tenant: %s, %s)',
                $exists ? 'Update' : 'Create',
                $user['name'],
                $user['email'],
                $user['role'],
                $tenantSlug,
                $passwordAction
            ));
        }

        if ($dryRun) {
            return self::SUCCESS;
        }

        DB::transaction(function () use ($plan, $password): void {
            $tenants = [];

            foreach ($plan['tenants'] as $tenantDefinition) {
                $tenant = Tenant::query()->firstOrCreate(
                    ['slug' => $tenantDefinition['slug']],
                    ['name' => $tenantDefinition['name']]
                );

                if ((string) $tenant->name !== $tenantDefinition['name']) {
                    $tenant->forceFill(['name' => $tenantDefinition['name']])->save();
                }

                $tenants[$tenantDefinition['slug']] = $tenant;

                $this->seedAccessProfile($tenant, $tenantDefinition);
                $this->seedSetupStatus($tenant, $tenantDefinition);
            }

            foreach ($plan['users'] as $userDefinition) {
                $user = $this->seedUser($userDefinition, $password);
                $tenantSlug = (string) ($userDefinition['tenant_slug'] ?? '');

                if ($tenantSlug !== '' && isset($tenants[$tenantSlug])) {
                    $tenants[$tenantSlug]->users()->syncWithoutDetaching([
                        (int) $user->id => [
                            'role' => (string) $userDefinition['tenant_role'],
                            'created_at' => now(),
                            'updated_at' => now(),
                        ],
                    ]);
                }
            }
        });

        $this->info('Everbranch access surfaces seeded. No billing, checkout, module entitlements, Shopify deploy, or impersonation was activated.');

        if ($password === null) {
            $this->warn('New users received random passwords. Use the normal password reset/admin path before manual login testing.');
        }

        return self::SUCCESS;
    }

    /**
     * @return array{tenants:array<int,array<string,mixed>>,users:array<int,array<string,mixed>>}
     */
    protected function seedPlan(): array
    {
        $modernForestrySlug = strtolower(trim((string) config('tenancy.auth.flagship_tenant_slug', 'modern-forestry'))) ?: 'modern-forestry';

        return [
            'tenants' => [
                [
                    'slug' => $modernForestrySlug,
                    'name' => 'Modern Forestry',
                    'account_mode' => 'production',
                    'operating_mode' => 'shopify',
                    'source' => 'everbranch_seed_access_surfaces',
                    'plan_key' => 'custom',
                    'billing_lane_interest' => 'free_internal_demo',
                    'landlord_review_status' => 'reviewed',
                    'next_recommended_action' => 'Continue alpha production use from the tenant dashboard.',
                    'internal_notes' => 'Seeded as the flagship Modern Forestry alpha tenant for access-lane testing.',
                ],
                [
                    'slug' => 'everbranch-demo',
                    'name' => 'Everbranch Demo',
                    'account_mode' => 'demo',
                    'operating_mode' => 'direct',
                    'source' => 'everbranch_seed_access_surfaces',
                    'plan_key' => 'starter',
                    'billing_lane_interest' => 'free_internal_demo',
                    'landlord_review_status' => 'reviewed',
                    'next_recommended_action' => 'Use this tenant for sales/demo walkthroughs only.',
                    'internal_notes' => 'Seeded as a read-mostly demo tenant. Do not treat as Modern Forestry production data.',
                ],
                [
                    'slug' => 'sandbox-test-client',
                    'name' => 'Sandbox Test Client',
                    'account_mode' => 'sandbox',
                    'operating_mode' => 'direct',
                    'source' => 'everbranch_seed_access_surfaces',
                    'plan_key' => 'starter',
                    'billing_lane_interest' => 'free_internal_demo',
                    'landlord_review_status' => 'reviewed',
                    'next_recommended_action' => 'Use this tenant for destructive workflow testing.',
                    'internal_notes' => 'Seeded as disposable sandbox data for operator testing.',
                ],
            ],
            'users' => [
                [
                    'name' => 'Everbranch Platform Admin',
                    'email' => 'everbranch.operator@example.invalid',
                    'role' => 'platform_admin',
                    'requested_via' => 'everbranch_seed_access_surfaces',
                    'tenant_slug' => null,
                    'tenant_role' => null,
                ],
                [
                    'name' => 'Modern Forestry Admin',
                    'email' => 'modern.forestry.admin@example.invalid',
                    'role' => 'admin',
                    'requested_via' => 'everbranch_seed_access_surfaces',
                    'tenant_slug' => $modernForestrySlug,
                    'tenant_role' => 'admin',
                ],
                [
                    'name' => 'Everbranch Demo User',
                    'email' => 'everbranch.demo@example.invalid',
                    'role' => 'admin',
                    'requested_via' => 'customer_demo',
                    'tenant_slug' => 'everbranch-demo',
                    'tenant_role' => 'admin',
                ],
                [
                    'name' => 'Sandbox Test User',
                    'email' => 'sandbox.test@example.invalid',
                    'role' => 'admin',
                    'requested_via' => 'customer_sandbox',
                    'tenant_slug' => 'sandbox-test-client',
                    'tenant_role' => 'admin',
                ],
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $definition
     */
    protected function seedAccessProfile(Tenant $tenant, array $definition): void
    {
        $existing = TenantAccessProfile::query()->where('tenant_id', (int) $tenant->id)->first();
        $metadata = is_array($existing?->metadata) ? $existing->metadata : [];

        $metadata['account_mode'] = (string) $definition['account_mode'];
        $metadata['seeded_access_surface'] = true;

        TenantAccessProfile::query()->updateOrCreate(
            ['tenant_id' => (int) $tenant->id],
            [
                'plan_key' => (string) $definition['plan_key'],
                'operating_mode' => (string) $definition['operating_mode'],
                'source' => (string) $definition['source'],
                'metadata' => $metadata,
            ]
        );
    }

    /**
     * @param  array<string,mixed>  $definition
     */
    protected function seedSetupStatus(Tenant $tenant, array $definition): void
    {
        TenantSetupStatus::query()->updateOrCreate(
            ['tenant_id' => (int) $tenant->id],
            [
                'business_profile_status' => 'ready',
                'import_path' => (string) $definition['operating_mode'] === 'shopify' ? 'shopify' : 'manual',
                'shopify_connection_status' => 'not_connected',
                'square_status' => 'not_requested',
                'csv_manual_status' => 'not_started',
                'mobile_interest' => 'undecided',
                'plan_interest' => (string) $definition['plan_key'],
                'billing_lane_interest' => (string) $definition['billing_lane_interest'],
                'implementation_help_interest' => false,
                'commercial_review_status' => 'reviewed',
                'landlord_review_status' => (string) $definition['landlord_review_status'],
                'next_recommended_action' => (string) $definition['next_recommended_action'],
                'internal_notes' => (string) $definition['internal_notes'],
                'reviewed_at' => now(),
            ]
        );
    }

    /**
     * @param  array<string,mixed>  $definition
     */
    protected function seedUser(array $definition, ?string $password): User
    {
        $user = User::query()->firstOrNew(['email' => (string) $definition['email']]);
        $isNew = ! $user->exists;

        $user->forceFill([
            'name' => (string) $definition['name'],
            'role' => (string) $definition['role'],
            'is_active' => true,
            'email_verified_at' => $user->email_verified_at ?: now(),
        ]);

        if ($password !== null || $isNew) {
            $user->password = Hash::make($password ?? Str::password(32));
        }

        $optional = [];
        if (Schema::hasColumn('users', 'requested_via')) {
            $optional['requested_via'] = (string) $definition['requested_via'];
        }
        if (Schema::hasColumn('users', 'approval_requested_at')) {
            $optional['approval_requested_at'] = $user->approval_requested_at;
        }
        if (Schema::hasColumn('users', 'approved_at')) {
            $optional['approved_at'] = $user->approved_at ?: now();
        }
        if (Schema::hasColumn('users', 'approved_by')) {
            $optional['approved_by'] = $user->approved_by;
        }

        if ($optional !== []) {
            $user->forceFill($optional);
        }

        $user->save();

        return $user;
    }
}

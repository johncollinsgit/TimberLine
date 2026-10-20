<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Models\TenantModuleEntitlement;
use App\Services\Team\Operations\OperationsWorkspace;
use App\Services\Tenancy\LandlordCommercialConfigService;
use App\Services\Tenancy\LandlordOperatorActionAuditService;
use App\Services\Tenancy\TenantModuleAccessResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class LaunchTeamWorkspace extends Command
{
    protected $signature = 'team:launch {tenant=modern-forestry} {--apply} {--force-production} {--authorization=} {--activate-config} {--verify}';

    protected $description = 'Preview or apply an audited Teams rollout for an existing tenant; no provider writes or subscription changes.';

    public function handle(): int
    {
        $tenant = Tenant::where('slug', $this->argument('tenant'))->firstOrFail();
        $owner = $tenant->users()->wherePivot('membership_active', true)->wherePivotIn('role', ['owner', 'tenant_owner'])->where('users.is_active', true)->whereNotNull('users.email_verified_at')->orderBy('users.id')->first();
        if (! $owner || ! Schema::hasTable('team_areas')) {
            $this->error('An existing verified tenant owner and deployed Teams schema are required.');

            return self::FAILURE;
        }
        $this->line(json_encode(['tenant_id' => $tenant->id, 'tenant' => $tenant->slug, 'mode' => $this->option('apply') ? 'apply' : 'preview', 'provider_writes' => false, 'subscription_changes' => false]));
        if ($this->option('verify')) {
            app(\App\Services\Team\TeamWorkAccess::class)->context($owner, $tenant);
            abort_unless(config('team_work.premium_enabled') && config('team_operations.enabled') && \App\Models\TeamArea::where('tenant_id', $tenant->id)->whereIn('profile', ['events', 'wholesale'])->count() >= 2, 409, 'Teams rollout is not active.');
            $this->line(json_encode(['status' => 'active', 'tenant_id' => $tenant->id, 'provider_writes' => false]));

            return self::SUCCESS;
        }
        if (! $this->option('apply')) {
            return self::SUCCESS;
        }
        $authorization = (string) $this->option('authorization');
        if (! preg_match('/^[a-zA-Z0-9_-]{8,160}$/D', $authorization) || (app()->environment('production') && ! $this->option('force-production'))) {
            $this->error('Explicit rollout authorization and the production option are required.');

            return self::FAILURE;
        }
        if ($this->option('activate-config') && ! app()->environment('production')) {
            $this->error('Configuration activation requires production maintenance.');

            return self::FAILURE;
        }
        $areas = DB::transaction(function () use ($tenant, $owner, $authorization) {
            Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            $commercial = app(LandlordCommercialConfigService::class);
            foreach (['team_work', 'teams_wholesale_workspace'] as $module) {
                $existing = TenantModuleEntitlement::where(['tenant_id' => $tenant->id, 'module_key' => $module])->first();
                abort_if($existing?->ends_at?->isPast(), 409, 'An expired entitlement needs a separate commercial review.');
                $commercial->setTenantModuleEntitlement($tenant->id, $module, [...array_intersect_key($existing?->getAttributes() ?? [], array_flip(['billing_status', 'price_override_cents', 'currency', 'price_source', 'starts_at', 'ends_at', 'notes'])), 'availability_status' => 'available', 'enabled_status' => 'enabled', 'entitlement_source' => 'approved_teams_rollout', 'metadata' => [...($existing?->metadata ?? []), 'authorization' => $authorization, 'subscription_changes' => false]]);
                $commercial->setTenantModuleState($tenant->id, $module, true, 'configured');
            }
            app()->forgetInstance(TenantModuleAccessResolver::class);
            config(['team_work.enabled' => true, 'team_work.premium_enabled' => true, 'team_operations.enabled' => true, 'team_work.tenant_allowlist' => array_values(array_unique([...config('team_work.tenant_allowlist', []), (string) $tenant->id]))]);
            $workspace = app(OperationsWorkspace::class);
            $areas = [];
            foreach (['events' => 'Events', 'wholesale' => 'Wholesale'] as $profile => $name) {
                $areas[$profile] = $workspace->createArea($tenant, $owner, ['name' => $name, 'profile' => $profile])->id;
            }
            app(LandlordOperatorActionAuditService::class)->record(tenantId: $tenant->id, actorUserId: null, actionType: 'teams_workspace_launch', targetType: 'tenant', targetId: $tenant->id, confirmation: ['authorization' => $authorization], result: ['areas' => $areas, 'provider_writes' => false, 'subscription_changes' => false]);

            return $areas;
        });
        if ($this->option('activate-config')) {
            $this->activateConfiguration((string) $tenant->id, $authorization);
        }
        $this->line(json_encode(['status' => 'prepared', 'tenant_id' => $tenant->id, 'areas' => $areas, 'configuration_activated' => (bool) $this->option('activate-config'), 'provider_writes' => false]));

        return self::SUCCESS;
    }

    private function activateConfiguration(string $tenantId, string $authorization): void
    {
        abort_unless(app()->environment('production') && $this->option('force-production'), 409, 'Runtime configuration activation is production maintenance only.');
        $path = realpath(base_path('.env'));
        abort_unless($path && is_writable($path), 409, 'The shared environment is unavailable.');
        $original = file_get_contents($path);
        abort_unless(is_string($original) && str_contains($original, 'APP_KEY='), 409);
        $ids = array_values(array_unique([...array_map('strval', config('team_work.tenant_allowlist', [])), $tenantId]));
        $values = ['TEAM_WORK_ENABLED' => 'true', 'TEAM_WORK_PREMIUM_ENABLED' => 'true', 'TEAM_WORK_ASANA_ENABLED' => 'true', 'TEAM_WORK_TENANT_ALLOWLIST' => implode(',', $ids), 'TEAM_OPERATIONS_ENABLED' => 'true'];
        $updated = $original;
        foreach ($values as $key => $value) {
            $pattern = '/^'.preg_quote($key, '/').'=[^\r\n]*/m';
            $updated = preg_match($pattern, $updated) ? preg_replace($pattern, $key.'='.$value, $updated) : rtrim($updated)."\n".$key.'='.$value."\n";
        }
        $backup = $path.'.teams-backup-'.$authorization;
        if (! is_file($backup)) {
            $handle = fopen($backup, 'x');
            abort_unless($handle, 409);
            fwrite($handle, $original);
            fclose($handle);
            chmod($backup, 0600);
        }
        $temporary = $path.'.teams-pending-'.$authorization;
        abort_if(file_exists($temporary), 409, 'A pending configuration needs review.');
        $handle = fopen($temporary, 'x');
        abort_unless($handle, 409);
        fwrite($handle, $updated);
        fclose($handle);
        chmod($temporary, 0600);
        abort_unless(file_get_contents($path) === $original, 409, 'Shared environment changed during preparation. Review the retained pending configuration.');
        abort_unless(rename($temporary, $path), 409);
        try {
            abort_unless(Artisan::call('config:cache') === 0, 503, 'Configuration caching failed.');
        } catch (\Throwable $e) {
            file_put_contents($path, $original, LOCK_EX);
            chmod($path, 0600);
            Artisan::call('config:cache');
            throw $e;
        }
    }
}

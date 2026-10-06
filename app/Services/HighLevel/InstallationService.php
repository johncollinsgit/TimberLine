<?php

namespace App\Services\HighLevel;

use App\Models\HighLevel\Authorization;
use App\Models\HighLevel\EmbeddedSession;
use App\Models\HighLevel\Installation;
use App\Models\IntegrationConnection;
use App\Models\Tenant;
use App\Models\User;
use App\Services\FleetTracking\FleetTrackingAccessService;
use App\Services\Onboarding\FirstLoginWorkspaceProvisioner;
use App\Services\Tenancy\LandlordCommercialConfigService;
use App\Services\Tenancy\LandlordOperatorActionAuditService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class InstallationService
{
    public function __construct(private readonly HighLevelApi $api, private readonly LandlordCommercialConfigService $commercial,
        private readonly LandlordOperatorActionAuditService $audit) {}

    public function authorize(array $tokens): array
    {
        abort_unless(($tokens['appId'] ?? config('highlevel.app_id')) === config('highlevel.app_id')
            && filled($tokens['companyId'] ?? null) && filled($tokens['userId'] ?? null), 403);
        if (($tokens['userType'] ?? null) === 'Company') {
            $agency = Authorization::firstOrCreate(['app_id' => config('highlevel.app_id'), 'company_id' => $tokens['companyId']], ['status' => 'pending']);
            $this->api->saveTokens($agency, $tokens);
            $this->api->verifiedAdmin($agency, $tokens['userId']);
            $agency->update(['installer_user_id' => $tokens['userId'], 'status' => 'authorized']);

            return $this->synchronize($agency);

        }
        // This app is agency-only with mandatory bulk installation. Fail closed
        // on an unexpected location-only grant rather than accepting a client
        // administrator as an agency installer.
        abort(403, 'Install Everbranch Fleet through your agency administrator.');
    }

    public function synchronize(Authorization $agency, bool $recoverOnly = false): array
    {
        abort_unless($agency->status === 'authorized' && $agency->app_id === config('highlevel.app_id'), 403);
        $installed = [];
        foreach ($this->api->installedLocations($agency) as $location) {
            if (! is_array($location) || ! ($location['isInstalled'] ?? false)) {
                continue;
            }
            $id = $location['_id'] ?? $location['id'] ?? null;
            if (! is_string($id) || $id === '') {
                continue;
            }
            $existing = Installation::where('app_id', $agency->app_id)->where('location_id', $id)->first();
            if ($recoverOnly && $existing?->status === 'installed' && $existing->tenant_id && filled($existing->access_token)) {
                continue;
            }
            $installed[] = $this->install($agency->company_id, $id, $this->api->locationToken($agency, $id));
        }

        return $installed;
    }

    public function install(string $companyId, string $locationId, array $tokens): Installation
    {
        abort_unless(preg_match('/^[A-Za-z0-9_-]{1,80}$/', $companyId) && preg_match('/^[A-Za-z0-9_-]{1,80}$/', $locationId), 422);

        return Cache::lock('hl:install:'.$locationId, 90)->block(5, function () use ($companyId, $locationId, $tokens): Installation {
            return DB::transaction(function () use ($companyId, $locationId, $tokens): Installation {
                $install = Installation::firstOrCreate(['app_id' => config('highlevel.app_id'), 'location_id' => $locationId], ['company_id' => $companyId]);
                abort_unless($install->company_id === $companyId, 403);
                $this->api->saveTokens($install, $tokens);
                $location = $this->api->get($install, '/locations/'.$locationId)['location'] ?? [];
                abort_unless(($location['id'] ?? null) === $locationId && ($location['companyId'] ?? null) === $companyId, 403);
                if (! $install->tenant_id) {
                    $actor = $this->shadowUser('Everbranch Fleet installation');
                    $provisioned = app(FirstLoginWorkspaceProvisioner::class)->provision($actor,
                        substr((string) ($location['name'] ?? 'Client').' Fleet', 0, 120), 'general', ['fleet_tracking'], []);
                    $actor->forceFill(['is_active' => false])->save();
                    $install->update(['tenant_id' => $provisioned['tenant_id'], 'actor_user_id' => $actor->id]);
                    $dependencies = $this->dependencies('fleet_tracking');
                    foreach ((array) config('module_catalog.modules') as $key => $definition) {
                        if (($definition['status'] ?? 'disabled') === 'disabled') {
                            continue;
                        }
                        $enabled = in_array($key, $dependencies, true);
                        $this->commercial->setTenantModuleEntitlement((int) $install->tenant_id, $key,
                            ['availability_status' => $enabled ? 'available' : 'unavailable', 'enabled_status' => $enabled ? 'enabled' : 'disabled',
                                'billing_status' => $enabled ? 'included' : 'unavailable', 'entitlement_source' => 'highlevel_fleet',
                                'metadata' => ['installation_id' => $install->id, 'billing_authority' => 'highlevel', 'no_stripe_subscription' => true]], $actor->id);
                    }
                    app(FleetTrackingAccessService::class)->settings($install->tenant)->update(['phone_tracking_enabled' => false]);
                }
                // Authorization never proves a paid subscription. Signed billing
                // events activate it. Reinstall keeps the existing tenant and
                // requires new billing confirmation and Bouncie authorization.
                if ($install->status !== 'installed') {
                    $install->update(['status' => 'installed', 'installed_at' => now(), 'uninstalled_at' => null,
                        'plan_id' => $tokens['planId'] ?? $install->plan_id,
                        'payment_status' => $install->uninstalled_at ? 'PENDING' : $install->payment_status,
                        'payment_at' => $install->uninstalled_at ? null : $install->payment_at, 'grace_ends_at' => null]);
                    $this->audit->record($install->tenant_id, $install->actor_user_id, 'highlevel.install',
                        targetType: 'highlevel_installation', targetId: $install->id,
                        result: ['billing_authority' => 'highlevel', 'collection_enabled' => false]);
                }

                return $install->refresh();
            });
        });
    }

    public function uninstall(Installation $install): void
    {
        DB::transaction(function () use ($install): void {
            $install->update(['status' => 'uninstalled', 'uninstalled_at' => now(), 'access_token' => null,
                'refresh_token' => null, 'expires_at' => null, 'payment_status' => 'PENDING', 'grace_ends_at' => null]);
            EmbeddedSession::where('installation_id', $install->id)->whereNull('revoked_at')->update(['revoked_at' => now()]);
            if ($install->tenant_id) {
                app(FleetTrackingAccessService::class)->settings($install->tenant)->update(['bouncie_tracking_enabled' => false, 'phone_tracking_enabled' => false]);
                $connection = IntegrationConnection::forTenantId($install->tenant_id)->where('provider', 'bouncie')->first();
                if ($connection) {
                    app(FleetService::class)->disconnect($install);
                }
            }
            $this->audit->record($install->tenant_id, $install->actor_user_id, 'highlevel.uninstall',
                targetType: 'highlevel_installation', targetId: $install->id, result: ['bouncie_subscription_cancelled' => false]);
        });
    }

    public function shadowUser(string $name): User
    {
        return User::create(['name' => substr($name, 0, 120), 'email' => 'hl-'.Str::uuid().'@identity.invalid',
            'password' => Str::random(96), 'role' => 'admin', 'is_active' => false]);
    }

    private function dependencies(string $module, array $seen = []): array
    {
        if (in_array($module, $seen, true)) {
            return $seen;
        }
        $seen[] = $module;
        foreach ((array) config('module_catalog.modules.'.$module.'.dependencies', []) as $key) {
            $seen = $this->dependencies($key, $seen);
        }

        return $seen;
    }
}

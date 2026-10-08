<?php

namespace App\Services\HighLevel;

use App\Models\FieldServiceVehicle;
use App\Models\FleetLocationPoint;
use App\Models\FleetTrackingDevice;
use App\Models\HighLevel\Installation;
use App\Models\IntegrationConnection;
use App\Services\FleetTracking\BouncieLocationRefreshService;
use App\Services\FleetTracking\FleetTrackingAccessService;
use App\Services\Integrations\Bouncie\BouncieConnector;
use App\Services\Tenancy\LandlordOperatorActionAuditService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FleetService
{
    public function __construct(private readonly BouncieConnector $bouncie, private readonly FleetTrackingAccessService $access,
        private readonly LandlordOperatorActionAuditService $audit) {}

    public function connection(Installation $install): ?IntegrationConnection
    {
        return IntegrationConnection::forTenantId((int) $install->tenant_id)->where('provider', 'bouncie')->first();
    }

    public function available(Installation $install): array
    {
        $connection = $this->connection($install);
        abort_unless($connection && $connection->status === IntegrationConnection::STATUS_CONNECTED, 422, 'Connect Bouncie first.');
        try {
            return $this->bouncie->client($connection)->vehicles();
        } catch (\Throwable) {
            $connection->update(['last_error_code' => 'provider_unavailable', 'last_error_at' => now()]);
            abort(503, 'Bouncie is temporarily unavailable. Existing selections and saved locations are preserved.');
        }
    }

    public function select(Installation $install, array $ids, int $actorId): void
    {
        abort_unless($install->setupAllowed(), 403, 'Fleet setup is not available for this client account.');
        $ids = array_values(array_unique($ids));
        $limit = (int) config('highlevel.vehicle_limit');
        if (count($ids) > $limit) {
            throw ValidationException::withMessages(['devices' => "You can select up to {$limit} vehicles. Remove a selection before adding another."]);
        }
        $accountId = $this->connection($install)?->external_account_id;
        $vehicles = collect($this->available($install))->filter(fn ($v) => is_string($v['imei'] ?? null))->keyBy('imei');
        foreach ($ids as $id) {
            if (! $vehicles->has($id)) {
                throw ValidationException::withMessages(['devices' => 'Every selection must belong to this connected Bouncie account.']);
            }
        }
        DB::transaction(function () use ($install, $ids, $vehicles, $actorId, $accountId): void {
            // Serialize allowance checks and connection changes within a workspace.
            Installation::whereKey($install->id)->lockForUpdate()->firstOrFail();
            $install->refresh();
            abort_unless($install->setupAllowed(), 403);
            $connection = $this->connection($install);
            abort_unless($connection && $connection->status === IntegrationConnection::STATUS_CONNECTED
                && $connection->external_account_id === $accountId, 409, 'The Bouncie account changed. Refresh the device list.');
            $current = FleetTrackingDevice::forTenantId($install->tenant_id)->where('provider', 'bouncie')->where('status', 'active')->get();
            $all = array_values(array_unique([...$ids, ...$current->pluck('external_device_id')->all()]));
            sort($all, SORT_STRING); // Consistent lock order across workspaces.
            foreach ($all as $id) {
                DB::table('fleet_provider_device_claims')->insertOrIgnore(['provider' => 'bouncie', 'external_device_id' => $id,
                    'created_at' => now(), 'updated_at' => now()]);
                $claim = DB::table('fleet_provider_device_claims')->where('provider', 'bouncie')->where('external_device_id', $id)->lockForUpdate()->first();
                if (in_array($id, $ids, true)) {
                    $conflict = FleetTrackingDevice::where('provider', 'bouncie')->where('external_device_id', $id)
                        ->where('status', 'active')->where('tenant_id', '!=', $install->tenant_id)->exists();
                    if ($conflict || ($claim->integration_connection_id && (int) $claim->integration_connection_id !== (int) $connection->id)) {
                        throw ValidationException::withMessages(['devices' => 'A selected device is already active in another workspace. Disconnect it there first.']);
                    }
                    DB::table('fleet_provider_device_claims')->where('id', $claim->id)->update(['integration_connection_id' => $connection->id, 'updated_at' => now()]);
                } elseif ((int) $claim->integration_connection_id === (int) $connection->id) {
                    DB::table('fleet_provider_device_claims')->where('id', $claim->id)->update(['integration_connection_id' => null, 'updated_at' => now()]);
                }
            }
            FleetTrackingDevice::forTenantId($install->tenant_id)->where('provider', 'bouncie')->whereNotIn('external_device_id', $ids)
                ->update(['status' => 'inactive', 'uninstalled_at' => now()]);
            foreach ($ids as $id) {
                $device = FleetTrackingDevice::forTenantId($install->tenant_id)->where('provider', 'bouncie')->where('external_device_id', $id)->first();
                $provider = $vehicles[$id];
                $name = substr((string) ($provider['nickName'] ?? $provider['name'] ?? data_get($provider, 'vehicle.name') ?? 'Vehicle '.$id), 0, 120);
                $vehicle = $device?->vehicle()->where('tenant_id', $install->tenant_id)->first()
                    ?? FieldServiceVehicle::create(['tenant_id' => $install->tenant_id, 'name' => $name, 'identifier' => $id, 'status' => 'active']);
                $vehicle->update(['name' => $name, 'status' => 'active']);
                FleetTrackingDevice::updateOrCreate(['tenant_id' => $install->tenant_id, 'provider' => 'bouncie', 'external_device_id' => $id],
                    ['field_service_vehicle_id' => $vehicle->id, 'integration_connection_id' => $connection->id,
                        'label' => $name, 'status' => 'active', 'installed_at' => now(), 'uninstalled_at' => null]);
            }
            $this->audit->record($install->tenant_id, $actorId, 'highlevel.fleet.selection', targetType: 'highlevel_installation',
                targetId: $install->id, beforeState: ['selected_count' => $current->count()], afterState: ['selected_count' => count($ids)]);
        }, 3);
    }

    public function bootstrap(Installation $install): array
    {
        $tenant = $install->tenant;
        $settings = $this->access->settings($tenant);
        $connection = $this->connection($install);
        $health = ['status' => $connection?->status ?? 'disconnected'];
        if ($install->collectionAllowed()) {
            $health = app(BouncieLocationRefreshService::class)->refresh($tenant);
        }
        $showLocations = $install->hasSubscriptionAccess() && $this->access->enabledFor($tenant) && $this->access->isPolicyApproved($settings);
        $vehicles = FleetTrackingDevice::forTenantId($tenant->id)->where('provider', 'bouncie')->where('status', 'active')
            ->where('integration_connection_id', $connection?->id ?? 0)->with('vehicle')->limit((int) config('highlevel.vehicle_limit'))->get()->map(function ($device) use ($tenant, $settings, $showLocations): array {
                // Always select by provider recorded time, never by insertion order.
                $point = $showLocations ? FleetLocationPoint::forTenantId($tenant->id)->where('fleet_tracking_device_id', $device->id)->where('source', 'bouncie')
                    ->where('recorded_at', '>=', now()->subDays(max(1, min(30, $settings->retention_days))))->orderByDesc('recorded_at')->orderByDesc('id')->first() : null;

                return ['id' => $device->id, 'device_id' => $device->external_device_id, 'name' => $device->vehicle?->name ?? $device->label,
                    'location' => $point ? ['latitude' => (float) $point->latitude, 'longitude' => (float) $point->longitude,
                        'reported_at' => $point->recorded_at->toIso8601String(), 'older_reading' => $point->recorded_at->lt(now()->subMinutes(15))] : null];
            })->all();

        return ['workspace' => ['name' => $tenant->name], 'vehicles' => $vehicles,
            'connection' => ['status' => $connection?->status ?? 'disconnected', 'label' => $connection?->external_account_label, 'health' => $health],
            'settings' => ['retention_days' => min(30, $settings->retention_days), 'policy_approved' => $this->access->isPolicyApproved($settings),
                'policy_version' => $settings->policy_version, 'policy_sha256' => $settings->policy_sha256,
                'tracking_enabled' => $settings->bouncie_tracking_enabled, 'vehicle_limit' => (int) config('highlevel.vehicle_limit')],
            'subscription' => ['status' => $install->payment_status, 'grace_ends_at' => $install->grace_ends_at?->toIso8601String(),
                'required' => (bool) config('highlevel.subscription_required'), 'setup_allowed' => $install->setupAllowed(),
                'has_access' => $install->hasSubscriptionAccess(), 'collection_active' => $install->collectionAllowed(), 'billing_authority' => 'highlevel'],
            'map_key' => config('services.google_maps.fleet_api_key'), 'support_email' => config('everbranch.support_email')];
    }

    public function disconnect(Installation $install): void
    {
        DB::transaction(function () use ($install): void {
            Installation::whereKey($install->id)->lockForUpdate()->firstOrFail();
            $connection = $this->connection($install);
            if ($connection) {
                DB::table('fleet_provider_device_claims')->where('integration_connection_id', $connection->id)
                    ->update(['integration_connection_id' => null, 'updated_at' => now()]);
                $connection->update(['status' => IntegrationConnection::STATUS_DISCONNECTED, 'access_token' => null,
                    'refresh_token' => null, 'expires_at' => null, 'external_account_secret' => null]);
            }
            FleetTrackingDevice::forTenantId($install->tenant_id)->where('provider', 'bouncie')->update(['status' => 'inactive', 'uninstalled_at' => now()]);
            $this->access->settings($install->tenant)->update(['bouncie_tracking_enabled' => false, 'phone_tracking_enabled' => false]);
            $this->audit->record($install->tenant_id, $install->actor_user_id, 'highlevel.bouncie.disconnect',
                result: ['bouncie_subscription_cancelled' => false]);
        });
    }
}

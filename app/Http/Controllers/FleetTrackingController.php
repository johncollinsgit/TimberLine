<?php

namespace App\Http\Controllers;

use App\Models\FieldServiceVehicle;
use App\Models\FleetLocationPoint;
use App\Models\FleetTrackingDevice;
use App\Models\IntegrationConnection;
use App\Models\Tenant;
use App\Services\FleetTracking\FleetTrackingAccessService;
use App\Services\Integrations\Bouncie\BouncieConnector;
use App\Services\Tenancy\LandlordOperatorActionAuditService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class FleetTrackingController extends Controller
{
    public function __construct(private readonly FleetTrackingAccessService $access, private readonly LandlordOperatorActionAuditService $audit, private readonly BouncieConnector $bouncie) {}

    public function index(Request $request): View
    {
        $tenant = $this->tenant($request);
        $this->authorizeViewer($request, $tenant);
        $settings = $this->access->settings($tenant);
        $devices = FleetTrackingDevice::query()->forTenantId((int) $tenant->id)->with('vehicle:id,name,identifier')->orderBy('label')->get();
        $points = FleetLocationPoint::query()->forTenantId((int) $tenant->id)->orderByDesc('recorded_at')->limit(100)->get();
        $connection = IntegrationConnection::query()->forTenantId((int) $tenant->id)->where('provider', 'bouncie')->first();
        $membership = $request->user()?->tenants()->whereKey((int) $tenant->id)->first();
        $canManageBouncie = in_array(strtolower(trim((string) ($membership?->pivot->role ?? ''))), ['admin', 'owner', 'tenant_owner'], true);
        $bouncieVehicles = [];
        $connectionError = null;
        if ($connection?->isConnected()) {
            try {
                $bouncieVehicles = Cache::remember('bouncie_vehicle_inventory:'.$connection->id.':'.$connection->updated_at?->timestamp, now()->addMinute(), fn (): array => $this->bouncie->client($connection)->vehicles());
            } catch (\Throwable) {
                $connectionError = 'Bouncie could not be reached right now. Reconnect if this continues.';
            }
        }

        return view('field-service.fleet-tracking', ['tenant' => $tenant, 'settings' => $settings, 'devices' => $devices, 'points' => $points, 'vehicles' => FieldServiceVehicle::query()->forTenantId((int) $tenant->id)->where('status', 'active')->orderBy('name')->get(), 'globalEnabled' => (bool) config('services.fleet_tracking.enabled', false), 'mapApiKey' => (string) config('services.google_maps.fleet_api_key', ''), 'bouncieConnection' => $connection, 'bouncieVehicles' => $bouncieVehicles, 'bouncieConnectionError' => $connectionError, 'canManageBouncie' => $canManageBouncie]);
    }

    public function updateSettings(Request $request): RedirectResponse
    {
        $tenant = $this->tenant($request);
        $this->authorizeViewer($request, $tenant);
        $data = $request->validate(['phone_tracking_enabled' => ['nullable', 'boolean'], 'bouncie_tracking_enabled' => ['nullable', 'boolean'], 'policy_version' => ['required', 'string', 'max:80'], 'policy_text' => ['required', 'string', 'max:12000'], 'counsel_review_reference' => ['required', 'string', 'max:500'], 'retention_days' => ['required', 'integer', 'min:1', 'max:30'], 'counsel_reviewed' => ['accepted']]);
        $settings = $this->access->settings($tenant);
        $before = $settings->only(['phone_tracking_enabled', 'bouncie_tracking_enabled', 'policy_version', 'policy_sha256', 'counsel_review_reference', 'legal_reviewed_at', 'retention_days']);
        $settings->forceFill(['phone_tracking_enabled' => (bool) ($data['phone_tracking_enabled'] ?? false), 'bouncie_tracking_enabled' => (bool) ($data['bouncie_tracking_enabled'] ?? false), 'policy_version' => $data['policy_version'], 'policy_sha256' => hash('sha256', $data['policy_text']), 'counsel_review_reference' => $data['counsel_review_reference'], 'legal_reviewed_at' => now(), 'legal_reviewed_by_user_id' => (int) $request->user()->id, 'retention_days' => (int) $data['retention_days']])->save();
        $this->audit->record((int) $tenant->id, (int) $request->user()->id, 'fleet_tracking.settings.updated', targetType: 'tenant_fleet_tracking_setting', targetId: $settings->id, beforeState: $before, afterState: $settings->only(['phone_tracking_enabled', 'bouncie_tracking_enabled', 'policy_version', 'policy_sha256', 'counsel_review_reference', 'legal_reviewed_at', 'retention_days']));

        return back()->with('status', 'Tracking policy and retention settings saved. The global feature switch and tenant module must also be enabled before collection begins.');
    }

    public function storeDevice(Request $request): RedirectResponse
    {
        $tenant = $this->tenant($request);
        $this->authorizeViewer($request, $tenant);
        $data = $request->validate(['field_service_vehicle_id' => ['required', 'integer'], 'external_device_id' => ['required', 'string', 'max:160'], 'label' => ['nullable', 'string', 'max:160']]);
        abort_unless(FieldServiceVehicle::query()->forTenantId((int) $tenant->id)->whereKey((int) $data['field_service_vehicle_id'])->exists(), 422);
        $connection = IntegrationConnection::query()->forTenantId((int) $tenant->id)->where('provider', 'bouncie')->where('status', IntegrationConnection::STATUS_CONNECTED)->firstOrFail();
        $providerVehicle = collect($this->bouncie->client($connection)->vehicles())->first(fn (array $vehicle): bool => hash_equals(trim((string) ($vehicle['imei'] ?? '')), trim((string) $data['external_device_id'])));
        abort_unless(is_array($providerVehicle), 422, 'Select a tracker from this workspace’s connected Bouncie account.');
        $collision = FleetTrackingDevice::withoutGlobalScopes()->where('provider', 'bouncie')->where('external_device_id', trim((string) $data['external_device_id']))->where('tenant_id', '!=', (int) $tenant->id)->exists();
        abort_if($collision, 409, 'That Bouncie tracker is already assigned to another workspace.');
        $data['label'] = trim((string) ($data['label'] ?: ($providerVehicle['nickName'] ?? data_get($providerVehicle, 'model.name') ?? 'Company vehicle')));
        $device = FleetTrackingDevice::query()->updateOrCreate(['tenant_id' => (int) $tenant->id, 'field_service_vehicle_id' => (int) $data['field_service_vehicle_id']], ['provider' => 'bouncie', 'external_device_id' => trim($data['external_device_id']), 'label' => $data['label'] ?? null, 'status' => 'active', 'installed_at' => now(), 'uninstalled_at' => null]);
        $this->audit->record((int) $tenant->id, (int) $request->user()->id, 'fleet_tracking.device.mapped', targetType: 'fleet_tracking_device', targetId: $device->id, afterState: ['vehicle_id' => (int) $device->field_service_vehicle_id, 'provider' => $device->provider, 'external_device_id' => $device->external_device_id]);

        return back()->with('status', 'Bouncie device mapped to the company vehicle.');
    }

    public function syncBouncieVehicles(Request $request): RedirectResponse
    {
        $tenant = $this->tenant($request);
        $this->authorizeBouncieAdmin($request, $tenant);
        $connection = IntegrationConnection::query()->forTenantId((int) $tenant->id)
            ->where('provider', 'bouncie')->where('status', IntegrationConnection::STATUS_CONNECTED)->firstOrFail();

        try {
            $providerVehicles = collect($this->bouncie->client($connection)->vehicles())
                ->filter(fn (array $vehicle): bool => filled($vehicle['imei'] ?? null))
                ->unique(fn (array $vehicle): string => trim((string) $vehicle['imei']))
                ->values();
        } catch (\Throwable) {
            return back()->withErrors(['bouncie' => 'Bouncie could not be reached right now. Try the import again in a moment.']);
        }

        if ($providerVehicles->isEmpty()) {
            return back()->withErrors(['bouncie' => 'No vehicles were available in the connected Bouncie account.']);
        }

        $deviceIds = $providerVehicles->pluck('imei')->map(fn ($imei): string => trim((string) $imei))->all();
        $collision = FleetTrackingDevice::withoutGlobalScopes()->where('provider', 'bouncie')
            ->whereIn('external_device_id', $deviceIds)->where('tenant_id', '!=', (int) $tenant->id)->exists();
        abort_if($collision, 409, 'A Bouncie tracker is already assigned to another workspace. Contact Everbranch support before importing.');

        [$created, $updated] = DB::transaction(function () use ($providerVehicles, $tenant): array {
            $created = 0;
            $updated = 0;

            foreach ($providerVehicles as $providerVehicle) {
                $externalId = trim((string) $providerVehicle['imei']);
                $name = $this->providerVehicleName($providerVehicle);
                $device = FleetTrackingDevice::query()->forTenantId((int) $tenant->id)
                    ->where('provider', 'bouncie')->where('external_device_id', $externalId)->first();

                if ($device) {
                    $device->forceFill(['label' => $device->label ?: $name, 'status' => 'active', 'uninstalled_at' => null])->save();
                    $updated++;

                    continue;
                }

                $identifier = 'BOUNCIE-'.strtoupper(substr(hash('sha256', $externalId), 0, 8));
                $vehicle = FieldServiceVehicle::query()->firstOrCreate(
                    ['tenant_id' => (int) $tenant->id, 'identifier' => $identifier],
                    ['name' => $name, 'status' => 'active', 'notes' => 'Imported from the workspace Bouncie connection.'],
                );
                $vehicle->forceFill(['status' => 'active'])->save();
                FleetTrackingDevice::query()->create([
                    'tenant_id' => (int) $tenant->id,
                    'field_service_vehicle_id' => (int) $vehicle->id,
                    'provider' => 'bouncie',
                    'external_device_id' => $externalId,
                    'label' => $name,
                    'status' => 'active',
                    'installed_at' => now(),
                ]);
                $created++;
            }

            return [$created, $updated];
        });

        Cache::forget('bouncie_vehicle_inventory:'.$connection->id.':'.$connection->updated_at?->timestamp);
        $this->audit->record((int) $tenant->id, (int) $request->user()->id, 'fleet_tracking.bouncie.vehicles_synced', targetType: 'integration_connection', targetId: $connection->id, afterState: ['created' => $created, 'updated' => $updated, 'available' => $providerVehicles->count()]);

        return back()->with('status', $created.' Bouncie vehicle'.($created === 1 ? '' : 's').' imported; '.$updated.' existing mapping'.($updated === 1 ? '' : 's').' refreshed.');
    }

    private function authorizeViewer(Request $request, Tenant $tenant): void
    {
        abort_unless($this->access->enabledFor($tenant) && $this->access->canView($request->user(), $tenant), 403);
    }

    private function authorizeBouncieAdmin(Request $request, Tenant $tenant): void
    {
        $membership = $request->user()?->tenants()->whereKey((int) $tenant->id)->first();
        $role = strtolower(trim((string) ($membership?->pivot->role ?? '')));
        abort_unless($request->user()?->role === 'platform_admin' || in_array($role, ['admin', 'owner', 'tenant_owner'], true), 403);
    }

    /** @param array<string, mixed> $vehicle */
    private function providerVehicleName(array $vehicle): string
    {
        $nickname = trim((string) ($vehicle['nickName'] ?? ''));
        if ($nickname !== '') {
            return $nickname;
        }

        $model = trim(implode(' ', array_filter([
            data_get($vehicle, 'model.year'),
            data_get($vehicle, 'model.make'),
            data_get($vehicle, 'model.name'),
        ], fn ($part): bool => filled($part))));

        return $model !== '' ? $model : 'Bouncie vehicle';
    }

    private function tenant(Request $request): Tenant
    {
        $tenant = $request->attributes->get('current_tenant');
        abort_unless($tenant instanceof Tenant, 403);

        return $tenant;
    }
}

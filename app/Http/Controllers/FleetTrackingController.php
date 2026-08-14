<?php

namespace App\Http\Controllers;

use App\Models\FieldServiceVehicle;
use App\Models\FleetLocationPoint;
use App\Models\FleetTrackingDevice;
use App\Models\Tenant;
use App\Services\FleetTracking\FleetTrackingAccessService;
use App\Services\Tenancy\LandlordOperatorActionAuditService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class FleetTrackingController extends Controller
{
    public function __construct(private readonly FleetTrackingAccessService $access, private readonly LandlordOperatorActionAuditService $audit) {}

    public function index(Request $request): View
    {
        $tenant = $this->tenant($request);
        $this->authorizeViewer($request, $tenant);
        $settings = $this->access->settings($tenant);
        $devices = FleetTrackingDevice::query()->forTenantId((int) $tenant->id)->with('vehicle:id,name,identifier')->orderBy('label')->get();
        $points = FleetLocationPoint::query()->forTenantId((int) $tenant->id)->orderByDesc('recorded_at')->limit(100)->get();

        return view('field-service.fleet-tracking', ['tenant' => $tenant, 'settings' => $settings, 'devices' => $devices, 'points' => $points, 'vehicles' => FieldServiceVehicle::query()->forTenantId((int) $tenant->id)->where('status', 'active')->orderBy('name')->get(), 'globalEnabled' => (bool) config('services.fleet_tracking.enabled', false), 'mapApiKey' => (string) config('services.google_maps.fleet_api_key', '')]);
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
        $device = FleetTrackingDevice::query()->updateOrCreate(['tenant_id' => (int) $tenant->id, 'field_service_vehicle_id' => (int) $data['field_service_vehicle_id']], ['provider' => 'bouncie', 'external_device_id' => trim($data['external_device_id']), 'label' => $data['label'] ?? null, 'status' => 'active', 'installed_at' => now(), 'uninstalled_at' => null]);
        $this->audit->record((int) $tenant->id, (int) $request->user()->id, 'fleet_tracking.device.mapped', targetType: 'fleet_tracking_device', targetId: $device->id, afterState: ['vehicle_id' => (int) $device->field_service_vehicle_id, 'provider' => $device->provider, 'external_device_id' => $device->external_device_id]);

        return back()->with('status', 'Bouncie device mapped to the company vehicle.');
    }

    private function authorizeViewer(Request $request, Tenant $tenant): void
    {
        abort_unless($this->access->enabledFor($tenant) && $this->access->canView($request->user(), $tenant), 403);
    }

    private function tenant(Request $request): Tenant
    {
        $tenant = $request->attributes->get('current_tenant');
        abort_unless($tenant instanceof Tenant, 403);

        return $tenant;
    }
}

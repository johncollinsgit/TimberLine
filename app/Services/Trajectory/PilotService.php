<?php

namespace App\Services\Trajectory;

use App\Models\Tenant;
use App\Models\TenantAccessProfile;
use App\Models\TenantModuleEntitlement;
use App\Models\TenantModuleState;
use App\Models\Trajectory\Event;
use App\Models\Trajectory\Space;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class PilotService
{
    public function prepare(User $user, ?Tenant $business = null, bool $enable = false, ?string $plan = null): array
    {
        $plan ??= $business ? 'both' : 'personal';
        abort_unless(in_array($plan, config('trajectory.plans'), true), 422);
        abort_if(in_array($plan, ['business', 'both'], true) && ! $business, 422, 'Select a business workspace for this plan.');

        return DB::transaction(function () use ($user, $business, $enable, $plan): array {
            $spaces = [];
            $house = null;
            if ($plan !== 'business') {
                $houseTenant = Tenant::firstOrCreate(['slug' => 'trajectory-household-'.$user->id], ['name' => 'Private household']);
                TenantAccessProfile::firstOrCreate(['tenant_id' => $houseTenant->id], ['plan_key' => 'base', 'operating_mode' => 'direct', 'source' => 'trajectory_pilot']);
                // Household authorization uses finance membership only; no generic tenant membership is created.
                $house = Space::firstOrCreate(['tenant_id' => $houseTenant->id, 'kind' => 'household'], ['owner_user_id' => $user->id, 'name' => 'My household', 'settings' => ['discretionary_categories' => config('trajectory.discretionary_defaults')]]);
                $spaces = [$house];
            }
            if ($business && $plan !== 'personal') {
                abort_unless(app(\App\Services\Tenancy\TenantFinancialAccess::class)->allows($user, $business), 403, 'Pilot owner requires business financial access.');
                $company = Space::firstOrCreate(['tenant_id' => $business->id, 'kind' => 'business'], ['owner_user_id' => $user->id, 'name' => $business->name]);
                $spaces[] = $company;
                if ($house) {
                    DB::table('trajectory_links')->updateOrInsert(['household_id' => $house->id, 'business_id' => $company->id], ['created_by' => $user->id, 'created_at' => now(), 'updated_at' => now()]);
                }
            }
            foreach ($spaces as $space) {
                $space->update(['settings' => [...($space->settings ?? []), 'plan' => $plan, 'billing_mode' => 'unbilled_pilot']]);
                if ($enable) {
                    $before = TenantModuleEntitlement::where('tenant_id', $space->tenant_id)->where('module_key', 'trajectory')->first()?->toArray();
                    TenantModuleEntitlement::updateOrCreate(['tenant_id' => $space->tenant_id, 'module_key' => 'trajectory'], ['availability_status' => 'available', 'enabled_status' => 'enabled', 'billing_status' => 'included_in_plan', 'entitlement_source' => 'operator_setup', 'price_source' => 'catalog', 'notes' => 'Unbilled Trajectory pilot. No subscription activation.']);
                    TenantModuleState::updateOrCreate(['tenant_id' => $space->tenant_id, 'module_key' => 'trajectory'], ['enabled_override' => true, 'setup_status' => 'in_progress']);
                    $space->update(['enabled' => true]);
                    Event::create(['space_id' => $space->id, 'actor_id' => $user->id, 'action' => 'pilot_enabled', 'before' => $before, 'after' => ['billing_impact_cents' => 0, 'plan' => $space->kind === 'household' ? 'personal' : 'business']]);
                }
            }

            return $spaces;
        });
    }
}

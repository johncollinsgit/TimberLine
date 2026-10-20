<?php

declare(strict_types=1);

// Creates a NEW, marked, disposable SQLite database; inherited refusal protects other files.
require __DIR__.'/prepare-premium-fixture.php';

use App\Models\TeamArea;
use App\Models\TeamOperationRecord;
use App\Models\TenantAccessProfile;
use App\Models\TenantModuleEntitlement;
use App\Models\TenantModuleState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

$tenant = $tenants[0];
TenantAccessProfile::where('tenant_id', $tenant->id)->update(['plan_key' => 'starter', 'operating_mode' => 'shopify']);
TenantModuleEntitlement::create(['tenant_id' => $tenant->id, 'module_key' => 'wholesale_operations', 'enabled_status' => 'enabled', 'availability_status' => 'available', 'entitlement_source' => 'fictional-preview']);
TenantModuleState::create(['tenant_id' => $tenant->id, 'module_key' => 'wholesale_operations', 'enabled_override' => true, 'setup_status' => 'configured']);
$areas = [];
foreach (['events', 'wholesale'] as $profile) {
    $a = TeamArea::create(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'slug' => $profile, 'name' => ucfirst($profile), 'profile' => $profile, 'settings' => ['stages' => config('team_operations.profiles.'.$profile.'.stages'), 'source_authority' => 'asana', 'sending_enabled' => false, 'preset' => []]]);
    $areas[$profile] = $a;
    DB::table('team_area_members')->insert(['id' => Str::uuid(), 'tenant_id' => $tenant->id, 'area_id' => $a->id, 'user_id' => $users['member']->id, 'role' => 'coordinator', 'created_at' => now(), 'updated_at' => now()]);
}
$record = function ($area, $kind, $title, $status, $data = [], $start = null, $end = null) use ($tenant) {
    return TeamOperationRecord::create(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'area_id' => $area->id, 'source_key' => hash('sha256', (string) Str::uuid()), 'kind' => $kind, 'title' => $title, 'status' => $status, 'data' => $data, 'starts_on' => $start, 'ends_on' => $end, 'visibility' => in_array($kind, ['financial', 'staff']) ? 'admin' : 'area']);
};
foreach (['Cedar Grove Harvest Market', 'North Ridge Makers Fair', 'Evergreen Winter Showcase', 'Riverfront Holiday Market', 'Woodland Candle Workshop', 'Mountain Arts Weekend'] as $i => $title) {
    $start = now()->addDays(2 + $i * 6)->toDateString();
    $record($areas['events'], 'event', $title, ['accepted', 'staffing', 'research', 'applied', 'ready', 'accepted'][$i], ['venue' => ['Cedar Hall', 'North Ridge Pavilion', 'Evergreen Centre'][$i % 3], 'city' => 'Fictional Falls', 'state' => 'NC', 'timezone' => 'America/New_York', 'environment' => 'indoor', 'organizer' => 'Fictional organizer', 'email' => 'organizer@example.invalid', 'setup_time' => '08:00', 'event_hours' => '10:00–17:00', 'booth' => '10 × 10', 'instructions' => 'Fictional fixture. Confirm current organizer instructions before the event.', 'notes' => 'Synthetic acceptance data. No real source records.'], $start, now()->addDays(3 + $i * 6)->toDateString());
}
foreach (['2023', '2024', '2025', '2026'] as $i => $year) {
    $record($areas['events'], 'financial', 'Cedar Grove Harvest Market', 'accepted', ['event_id' => 'fictional-history-'.$year, 'needs_allocation_review' => false, 'net_sales_cents' => 420000 + $i * 40000, 'total_expense_cents' => 300000 + $i * 20000, 'selling_days' => 2, 'costs' => ['staffing' => 90000, 'lodging' => 35000, 'mileage' => 18000, 'per_diem' => 12000, 'event_fees' => 40000, 'cogs' => 100000, 'other' => 5000, 'rental_cars' => null, 'parking' => null], 'source' => 'Fictional financial fixture, not a connected workbook'], $year.'-09-10', $year.'-09-11');
}
foreach (['Alex Fictional', 'Jordan Fictional', 'Casey Fictional'] as $name) {
    $record($areas['events'], 'staff', $name, 'needs_review', ['name' => $name, 'identity_review' => true, 'source' => 'Fictional directory fixture; no accounts or messaging recipients created.']);
}
foreach (['Pine & Paper Goods', 'Meadow Lane Boutique', 'Juniper Home', 'Riverstone Mercantile', 'Wild Fern Market'] as $i => $title) {
    $account = $record($areas['wholesale'], 'account', $title, ['retention', 'negotiation', 'outreach', 'qualified', 'reactivation'][$i], ['contact' => 'Fictional buyer', 'email' => 'buyer'.$i.'@example.invalid', 'city' => 'Fictional Falls', 'custom_scents' => 'Cedar, amber', 'shipping' => 'Confirm seasonal delivery window', 'next_action' => 'Review sample selection', 'notes' => 'Synthetic wholesale relationship. No real sales or tax documents.']);
    $record($areas['wholesale'], 'opportunity', $title.' · seasonal collection', ['qualified', 'negotiation', 'outreach', 'discovery', 'reactivation'][$i], ['account_id' => $account->id, 'amount_cents' => 80000 + $i * 25000, 'probability' => 20 + $i * 15]);
}
$marker = json_decode(file_get_contents($database.'.fixture.json'), true, flags: JSON_THROW_ON_ERROR);
$marker['purpose'] = 'operations-acceptance';
$marker['areas'] = array_map(fn ($a) => $a->id, $areas);
file_put_contents($database.'.fixture.json', json_encode($marker, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
echo "Prepared fictional Events and Wholesale operations. Live sources and sending are disabled.\n";

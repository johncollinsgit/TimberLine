<?php

chdir(dirname(__DIR__, 2));
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (! app()->environment('testing') || Illuminate\Support\Facades\DB::connection()->getDriverName() !== 'mysql' || ! preg_match('/(^|_)(ci|test|testing)(_|$)/', Illuminate\Support\Facades\DB::connection()->getDatabaseName())) {
    throw new RuntimeException('Requires a disposable MySQL testing database.');
}
if (App\Models\Tenant::whereIn('slug', ['workspace-preview-field', 'workspace-preview-followup'])->exists()) {
    throw new RuntimeException('Use a fresh disposable fixture database.');
}
Illuminate\Support\Facades\Http::preventStrayRequests();
$user = App\Models\User::factory()->tenantAdmin()->create(['email' => 'workspace-preview@example.test', 'name' => 'Fictional Owner', 'password' => Illuminate\Support\Facades\Hash::make('Local-preview-only-482741')]);
$result = [];
foreach (['field' => ['Fictional Field Services', 'America/New_York'], 'followup' => ['Fictional Client Studio', 'America/Los_Angeles']] as $kind => [$name,$zone]) {
    $tenant = App\Models\Tenant::create(['name' => $name, 'slug' => 'workspace-preview-'.$kind]);
    $user->tenants()->attach($tenant->id, ['role' => 'owner', 'membership_active' => true]);
    App\Models\TenantAccessProfile::create(['tenant_id' => $tenant->id, 'plan_key' => 'base', 'operating_mode' => 'direct', 'source' => 'test', 'metadata' => ['tenant_blueprint' => ['business_template' => $kind === 'field' ? 'field_service' : 'generic'], 'ai_branch' => ['timezone' => $zone]]]);
    App\Models\TenantAccessAddon::create(['tenant_id' => $tenant->id, 'addon_key' => 'ai_branch_growth', 'enabled' => true, 'source' => 'stripe_fulfillment']);
    App\Models\TenantBillingSubscription::create(['tenant_id' => $tenant->id, 'provider' => 'stripe', 'provider_subscription_reference' => 'fictional_sub_'.$kind, 'purchase_key' => 'addon.ai_branch_growth', 'status' => 'active', 'current_period_ends_at' => now()->addMonth()]);
    App\Models\AiBranchAccount::create(['tenant_id' => $tenant->id, 'enabled' => true, 'budget_cents' => 1500]);
    if ($kind === 'field') {
        $job = App\Models\FieldServiceJob::create(['tenant_id' => $tenant->id, 'title' => 'Install entrance lighting', 'status' => 'blocked', 'operational_status' => 'blocked', 'scheduled_for' => now()->addDay()]);
        App\Models\FieldServiceTask::create(['tenant_id' => $tenant->id, 'field_service_job_id' => $job->id, 'title' => 'Confirm fixture delivery', 'status' => 'open', 'due_at' => now()->subDay()]);
    } else {
        App\Models\TenantModuleEntitlement::create(['tenant_id' => $tenant->id, 'module_key' => 'field_service', 'enabled_status' => 'disabled', 'billing_status' => 'included']);
        $customer = App\Models\MarketingProfile::create(['tenant_id' => $tenant->id, 'first_name' => 'Alex', 'last_name' => 'Sample']);
        App\Models\CustomerLoopAction::create(['tenant_id' => $tenant->id, 'marketing_profile_id' => $customer->id, 'title' => 'Review client follow-up', 'reason' => 'Fictional scenario', 'action_type' => 'follow_up', 'status' => 'suggested', 'due_at' => now()->subDay()]);
    }
    App\Models\AiBranchMemory::create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'body' => $kind === 'field' ? 'Keep field work summaries brief.' : 'Use a warm tone in client drafts.', 'expires_at' => now()->addDay()]);
    $result[$kind] = ['tenant' => $tenant->id, 'slug' => $tenant->slug];
}
echo json_encode($result);

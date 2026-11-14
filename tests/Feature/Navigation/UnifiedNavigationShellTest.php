<?php

use App\Models\MarketingProfile;
use App\Models\Tenant;
use App\Models\TenantAccessProfile;
use App\Models\TenantBudSetting;
use App\Models\User;

test('unified shell surfaces Branches and customer hub for tenant-aware marketing users', function () {
    $tenant = Tenant::query()->create([
        'name' => 'Navigation Tenant',
        'slug' => 'navigation-tenant',
    ]);

    TenantAccessProfile::query()->create([
        'tenant_id' => $tenant->id,
        'plan_key' => 'starter',
        'operating_mode' => 'direct',
        'source' => 'test',
    ]);

    MarketingProfile::query()->create([
        'tenant_id' => $tenant->id,
        'first_name' => 'Pat',
        'last_name' => 'Lee',
        'email' => 'pat@example.test',
    ]);

    $user = User::factory()->create(['role' => 'marketing_manager']);
    $user->tenants()->attach($tenant->id, ['role' => 'owner']);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSeeText('Marketing')
        ->assertSeeText('Branches')
        ->assertSee('data-sidebar-key="branches"', false)
        ->assertDontSee('data-sidebar-key="modules"', false)
        ->assertDontSee('data-sidebar-sortable', false)
        ->assertDontSeeText('Shortcuts')
        ->assertDontSeeText('Shopify workspace');
});

test('unified shell keeps modules hidden when there is no tenant context', function () {
    $user = User::factory()->create(['role' => 'admin']);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertDontSee('data-sidebar-key="modules"', false)
        ->assertSeeText('Workspace Guide')
        ->assertSee('data-sidebar-key="wiki-sections"', false)
        ->assertDontSee('data-sidebar-key="backstage-wiki"', false)
        ->assertDontSeeText('Wiki Sections');
});

test('every active tenant member can open Branches from the primary sidebar', function (): void {
    $tenant = Tenant::query()->create(['name' => 'Branch Access Tenant', 'slug' => 'branch-access-tenant']);
    TenantAccessProfile::query()->create([
        'tenant_id' => $tenant->id,
        'plan_key' => 'starter',
        'operating_mode' => 'direct',
        'source' => 'test',
    ]);
    $user = User::factory()->create(['role' => 'member']);
    $user->tenants()->attach($tenant->id, ['role' => 'member']);

    $this->actingAs($user)
        ->get('http://branch-access-tenant.theeverbranch.com/marketing/modules')
        ->assertOk()
        ->assertSee('data-sidebar-key="branches"', false);
});

test('account help offers included guidance and accessible human support while AI stays gated', function () {
    $tenant = Tenant::query()->create(['name' => 'Support Tenant', 'slug' => 'support-tenant']);
    TenantAccessProfile::query()->create([
        'tenant_id' => $tenant->id,
        'plan_key' => 'starter',
        'operating_mode' => 'direct',
        'source' => 'test',
    ]);
    $user = User::factory()->create(['role' => 'admin']);
    $user->tenants()->attach($tenant->id, ['role' => 'owner']);
    TenantBudSetting::query()->create(['tenant_id' => $tenant->id, 'status' => 'disabled']);

    $this->actingAs($user)
        ->get(route('account-help.index'))
        ->assertOk()
        ->assertSeeText('Included setup guidance.')
        ->assertSeeText('Ask Everbranch')
        ->assertSeeText('Talk to a person')
        ->assertSee('for="help-question"', false)
        ->assertSee('id="help-question"', false)
        ->assertSee('for="help-workflow"', false)
        ->assertSee('id="help-workflow"', false)
        ->assertSee('name="escalate" value="1"', false)
        ->assertSee(route('account-help.bud.ask'), false)
        ->assertSeeText('Model-assisted drafts use an approved monthly cap.')
        ->assertSeeText('Sending and publishing always need your confirmation.')
        ->assertSeeText('Request Bud AI')
        ->assertDontSeeText('Request Bud activation')
        ->assertSee('border-zinc-200 bg-white', false)
        ->assertDontSeeText('Approved cap:');

    $this->actingAs($user)
        ->post(route('account-help.bud.ask'), ['question' => 'wat needs my attention'])
        ->assertRedirect()
        ->assertSessionHas('bud_answer', fn (array $answer): bool => str_contains($answer['reply'], 'Customer Loop'));

    config()->set('bud.core_enabled', false);
    TenantBudSetting::query()->where('tenant_id', $tenant->id)->update(['ai_status' => 'approved', 'ai_monthly_budget_cents' => 1250]);

    $this->actingAs($user)
        ->get(route('account-help.index'))
        ->assertOk()
        ->assertSeeText('The guide is unavailable. Your question will go to the support team.')
        ->assertSeeText('Talk to a person')
        ->assertSeeText('Send to Everbranch')
        ->assertSeeText('Approved cap: $12.50/month. Provider readiness is required.')
        ->assertDontSeeText('Request Bud AI');
});

test('tenant settings stays immediately above workspace guide', function () {
    $tenant = Tenant::query()->create(['name' => 'Ordered Tenant', 'slug' => 'ordered-tenant']);
    TenantAccessProfile::query()->create([
        'tenant_id' => $tenant->id,
        'plan_key' => 'starter',
        'operating_mode' => 'direct',
        'source' => 'test',
    ]);
    $user = User::factory()->create([
        'role' => 'admin',
        'ui_preferences' => ['sidebar_order' => ['administration', 'account-help', 'home']],
    ]);
    $user->tenants()->attach($tenant->id, ['role' => 'owner']);

    $html = $this->actingAs($user)
        ->get(route('dashboard', ['tenant' => $tenant->slug]))
        ->assertOk()
        ->getContent();

    $agreements = strpos($html, 'data-sidebar-key="user-agreements"');
    $help = strpos($html, 'data-sidebar-key="account-help"');
    $settings = strpos($html, 'data-sidebar-key="workspace-settings"');
    $guide = strpos($html, 'data-sidebar-key="wiki-sections"');

    expect($agreements)->not->toBeFalse()
        ->and($help)->not->toBeFalse()
        ->and($settings)->not->toBeFalse()
        ->and($guide)->not->toBeFalse()
        ->and($agreements)->toBeLessThan($settings)
        ->and($help)->toBeLessThan($settings)
        ->and($settings)->toBeLessThan($guide);
});

test('marketing modules route keeps Marketing available and marks the compact Branches group active', function () {
    $tenant = Tenant::query()->create([
        'name' => 'Navigation Tenant',
        'slug' => 'navigation-tenant',
    ]);

    TenantAccessProfile::query()->create([
        'tenant_id' => $tenant->id,
        'plan_key' => 'starter',
        'operating_mode' => 'direct',
        'source' => 'test',
    ]);

    $user = User::factory()->create(['role' => 'marketing_manager']);
    $user->tenants()->attach($tenant->id, ['role' => 'owner']);

    $response = $this->actingAs($user)
        ->get('http://navigation-tenant.theeverbranch.com/marketing/modules')
        ->assertOk()
        ->assertSeeText('Marketing')
        ->assertSee('data-sidebar-key="marketing"', false)
        ->assertSee('data-sidebar-key="branches"', false)
        ->assertDontSee('data-sidebar-child-key="modules"', false)
        ->assertDontSee('data-sidebar-key="modules"', false);

    $html = $response->getContent();

    expect(preg_match('/data-sidebar-key="branches"[\s\S]{0,1200}mf-admin-group-summary is-current-group/', $html))->toBe(1)
        ->and(preg_match('/data-sidebar-child-key="branches-browse"[^>]*mf-admin-subnav-link-active/', $html))->toBe(1)
        ->and(preg_match('/data-sidebar-child-key="customers"[^>]*mf-admin-subnav-link-active/', $html))->toBe(0);
});

test('marketing customers route opens marketing hub and only marks Customers active', function () {
    $tenant = Tenant::query()->create([
        'name' => 'Navigation Tenant',
        'slug' => 'navigation-tenant',
    ]);

    TenantAccessProfile::query()->create([
        'tenant_id' => $tenant->id,
        'plan_key' => 'starter',
        'operating_mode' => 'direct',
        'source' => 'test',
    ]);

    MarketingProfile::query()->create([
        'tenant_id' => $tenant->id,
        'first_name' => 'Casey',
        'last_name' => 'Ng',
        'email' => 'casey@example.test',
    ]);

    $user = User::factory()->create(['role' => 'marketing_manager']);
    $user->tenants()->attach($tenant->id, ['role' => 'owner']);

    $response = $this->actingAs($user)
        ->get('http://navigation-tenant.theeverbranch.com/marketing/customers')
        ->assertOk()
        ->assertSee('data-sidebar-key="marketing"', false)
        ->assertSee('data-sidebar-child-key="customers"', false)
        ->assertDontSee('data-sidebar-key="modules"', false);

    $html = $response->getContent();

    expect(preg_match('/data-sidebar-child-key="customers"[^>]*mf-admin-subnav-link-active/', $html))->toBe(1)
        ->and(preg_match('/data-sidebar-child-key="modules"[^>]*mf-admin-subnav-link-active/', $html))->toBe(0);
});

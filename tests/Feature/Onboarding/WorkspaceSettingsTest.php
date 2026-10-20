<?php

use App\Models\FieldServiceJob;
use App\Models\MarketingProfile;
use App\Models\Tenant;
use App\Models\TenantAccessProfile;
use App\Models\TenantModuleEntitlement;
use App\Models\User;
use App\Services\Onboarding\WorkspaceSetupGuide;
use App\Services\Tenancy\TenantBlueprintProfileService;
use App\Services\Tenancy\TenantExperienceProfileService;
use App\Services\Tenancy\WorkspaceSettingsService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    $this->withoutVite();
    Http::preventStrayRequests();
    Mail::fake();
});

function workspaceSettingsFixture(string $role = 'owner', string $global = 'admin'): array
{
    $tenant = Tenant::create(['name' => 'Fictional Guided Business', 'slug' => 'guided-settings']);
    TenantAccessProfile::create(['tenant_id' => $tenant->id, 'plan_key' => 'base', 'operating_mode' => 'direct', 'source' => 'test',
        'metadata' => ['manual_override' => 'keep-me', 'tenant_blueprint' => ['business_template' => 'generic', 'customer_label' => 'Partners']]]);
    $user = User::factory()->create(['role' => $global, 'is_active' => true, 'email_verified_at' => now()]);
    $user->tenants()->attach($tenant->id, ['role' => $role, 'membership_active' => true]);

    return [$tenant, $user];
}

function settingsSaveProfile($test, Tenant $tenant, User $user, array $fields): void
{
    $revision = app(TenantBlueprintProfileService::class)->companyContextRevision($tenant->fresh());
    $test->actingAs($user)->put(route('workspace-settings.business.save', ['tenant' => $tenant->slug]), [...$fields, 'revision' => $revision])->assertSessionHasNoErrors()->assertRedirect();
}

test('business profile resumes between three server-saved steps without changing access terms or manual labels', function (string $category, string $specialty, string $model) {
    [$tenant, $user] = workspaceSettingsFixture();
    settingsSaveProfile($this, $tenant, $user, ['step' => 'business', 'category' => $category, 'specialty' => $specialty, 'description' => 'Fictional example']);
    $this->get(route('workspace-settings.business', ['tenant' => $tenant->slug]))->assertOk()->assertSee('How does your business work?');
    settingsSaveProfile($this, $tenant, $user, ['step' => 'model', 'model' => $model, 'channels' => ['online', 'in_person'], 'customer_types' => ['businesses']]);
    $this->get(route('workspace-settings.business', ['tenant' => $tenant->slug]))->assertOk()->assertSee('What would you like to accomplish first?');
    settingsSaveProfile($this, $tenant, $user, ['step' => 'goal', 'goal' => '']);
    $profile = $tenant->fresh()->accessProfile;
    $context = app(TenantBlueprintProfileService::class)->companyContext($tenant->fresh());
    expect($context)->toMatchArray(['category' => $category, 'specialty' => $specialty, 'model' => $model, 'channels' => ['online', 'in_person']])
        ->and($context['confirmed_at'])->not->toBeNull()
        ->and($profile->plan_key)->toBe('base')->and($profile->operating_mode)->toBe('direct')
        ->and(data_get($profile->metadata, 'manual_override'))->toBe('keep-me')
        ->and(data_get($profile->metadata, 'tenant_blueprint.customer_label'))->toBe('Partners');
    expect(DB::table('landlord_operator_actions')->where('action_type', 'tenant.business_profile.updated')->count())->toBe(3);
    $this->assertDatabaseCount('tenants', 1);
    $this->assertDatabaseCount('tenant_module_entitlements', 0);
    Http::assertNothingSent();
    Mail::assertNothingSent();
})->with([
    'electrical contractor' => ['field_service', 'Electrical', 'services'],
    'retailer' => ['retail', 'Specialty retail', 'products'],
    'mixed maker and services' => ['retail', 'Furniture and repairs', 'both'],
    'other generic organization' => ['other', 'Community workshop', 'both'],
]);

test('ordinary member sees personal settings but cannot discover or write owner-only business settings', function () {
    [$tenant, $user] = workspaceSettingsFixture('member', 'member');
    $this->actingAs($user)->get(route('workspace-settings.index', ['tenant' => $tenant->slug, 'q' => 'password']))->assertOk()->assertSee('Change the password');
    $this->get(route('workspace-settings.index', ['tenant' => $tenant->slug, 'q' => 'business profile']))->assertOk()->assertSee('No matching settings')->assertDontSee('Your industry, operating model');
    $this->get(route('workspace-settings.business', ['tenant' => $tenant->slug]))->assertForbidden();
    $this->get(route('admin.users', ['tenant' => $tenant->slug]))->assertForbidden();
    $this->put(route('workspace-settings.business.save', ['tenant' => $tenant->slug]), ['step' => 'business', 'category' => 'retail'])->assertForbidden();
    $this->get(route('workspace-settings.setup', ['tenant' => $tenant->slug]))->assertOk()->assertDontSee('Confirm your business profile');
});

test('foreign and inactive membership cannot access settings or setup through direct identifiers', function () {
    [$tenant, $user] = workspaceSettingsFixture();
    $foreign = Tenant::create(['name' => 'Private Foreign Business', 'slug' => 'foreign-settings']);
    $this->actingAs($user)->get(route('workspace-settings.index', ['tenant' => $foreign->slug]))->assertForbidden();
    $this->put(route('workspace-settings.business.save', ['tenant' => $tenant->slug]), ['tenant_id' => $foreign->id])->assertForbidden();
    $user->tenants()->updateExistingPivot($tenant->id, ['membership_active' => false]);
    $this->get(route('workspace-settings.setup', ['tenant' => $tenant->slug]))->assertForbidden();
});

test('search uses authorized metadata synonyms and never indexes stored values or redirect destinations', function () {
    [$tenant, $user] = workspaceSettingsFixture();
    $service = app(WorkspaceSettingsService::class);
    $catalog = $service->catalog($tenant, $user);
    expect($service->search($catalog, 'employees', ''))->not->toBeEmpty()
        ->and($service->search($catalog, 'logo', ''))->not->toBeEmpty()
        ->and($service->search($catalog, 'keep-me', ''))->toBeEmpty()
        ->and($service->search($catalog, 'https://outside.example', ''))->toBeEmpty();
    foreach ($catalog as $row) {
        expect($row['url'])->toStartWith('/')->not->toStartWith('//');
    }
    $this->actingAs($user)->get(route('workspace-settings.index', ['tenant' => $tenant->slug, 'category' => 'invalid']))->assertSessionHasErrors('category');
});

test('stale profile saves and invalid answers preserve data and do not report success', function () {
    [$tenant, $user] = workspaceSettingsFixture();
    $old = app(TenantBlueprintProfileService::class)->companyContextRevision($tenant);
    settingsSaveProfile($this, $tenant, $user, ['step' => 'business', 'category' => 'field_service', 'specialty' => 'Electrical']);
    $this->actingAs($user)->from(route('workspace-settings.business', ['tenant' => $tenant->slug]))->put(route('workspace-settings.business.save', ['tenant' => $tenant->slug]), ['step' => 'business', 'category' => 'retail', 'revision' => $old])->assertSessionHasErrors('revision');
    expect(app(TenantBlueprintProfileService::class)->companyContext($tenant->fresh())['category'])->toBe('field_service');
    settingsSaveProfile($this, $tenant, $user, ['step' => 'model', 'model' => 'services']);
    $this->put(route('workspace-settings.business.save', ['tenant' => $tenant->slug]), ['step' => 'goal', 'goal' => 'planned_livestream', 'revision' => app(TenantBlueprintProfileService::class)->companyContextRevision($tenant->fresh())])->assertSessionHasErrors('goal');
});

test('setup reconciles existing tenant records and personal dismissal does not complete organization work', function () {
    [$tenant, $user] = workspaceSettingsFixture();
    TenantModuleEntitlement::create(['tenant_id' => $tenant->id, 'module_key' => 'field_service', 'enabled_status' => 'enabled', 'availability_status' => 'available', 'billing_status' => 'included']);
    FieldServiceJob::create(['tenant_id' => $tenant->id, 'title' => 'Fictional inspection', 'status' => 'open']);
    $guide = app(WorkspaceSetupGuide::class)->forTenant($tenant, $user);
    expect(collect($guide['steps'])->firstWhere('id', 'job')['state'])->toBe('complete');
    $other = User::factory()->create(['role' => 'admin', 'is_active' => true, 'email_verified_at' => now()]);
    $other->tenants()->attach($tenant->id, ['role' => 'owner', 'membership_active' => true]);
    $this->actingAs($user)->put(route('workspace-settings.preferences', ['tenant' => $tenant->slug]), ['hidden' => 1])->assertRedirect();
    expect(app(WorkspaceSetupGuide::class)->forTenant($tenant, $user->fresh())['guidance_hidden'])->toBeTrue()
        ->and(app(WorkspaceSetupGuide::class)->forTenant($tenant, $other)['guidance_hidden'])->toBeFalse()
        ->and(app(WorkspaceSetupGuide::class)->forTenant($tenant, $other)['required_complete'])->toBe($guide['required_complete']);
    $this->put(route('workspace-settings.preferences', ['tenant' => $tenant->slug]), ['dismissed_steps' => ['business']])->assertSessionHasErrors('dismissed_steps.0');
});

test('switching tenants re-scopes profile settings goals and personal preferences without stale context', function () {
    [$tenant, $user] = workspaceSettingsFixture();
    settingsSaveProfile($this, $tenant, $user, ['step' => 'business', 'category' => 'field_service', 'specialty' => 'Private Electrical Specialty']);
    $other = Tenant::create(['name' => 'Second Business', 'slug' => 'second-guided-settings']);
    TenantAccessProfile::create(['tenant_id' => $other->id, 'plan_key' => 'base', 'operating_mode' => 'direct']);
    $user->tenants()->attach($other->id, ['role' => 'owner', 'membership_active' => true]);
    $this->actingAs($user)->get(route('workspace-settings.business', ['tenant' => $other->slug]))->assertOk()->assertDontSee('Private Electrical Specialty');
    $a = app(TenantExperienceProfileService::class)->forTenant($tenant->id, $user, $tenant->fresh());
    $b = app(TenantExperienceProfileService::class)->forTenant($other->id, $user, $other->fresh());
    expect($a['company_context']['specialty'])->toBe('Private Electrical Specialty')->and($b['company_context'])->toBe([]);
    $this->get(route('workspace-settings.index', ['tenant' => $other->slug]))->assertOk()->assertSee('Second Business');
});

test('new owner questionnaire drafts are private resumable and cannot create or duplicate an invited workspace', function () {
    $user = User::factory()->create(['role' => 'member', 'is_active' => true, 'email_verified_at' => now()]);
    $this->actingAs($user)->putJson(route('workspace.first-login.draft'), ['revision' => 0, 'fields' => ['workspace_name' => 'Saved Fictional Studio', 'template_key' => 'electrician', 'resume_step' => 3, 'surface' => 'popup']])->assertOk()->assertJsonPath('saved', true)->assertJsonPath('revision', 1);
    $this->get(route('workspace.first-login'))->assertOk()->assertSee('Saved Fictional Studio');
    $this->putJson(route('workspace.first-login.draft'), ['revision' => 0, 'fields' => ['workspace_name' => 'Stale Name', 'surface' => 'popup']])->assertConflict();
    $this->assertDatabaseCount('tenants', 0);
    $other = User::factory()->create(['role' => 'member', 'is_active' => true, 'email_verified_at' => now()]);
    $this->actingAs($other)->get(route('workspace.first-login'))->assertOk()->assertDontSee('Saved Fictional Studio');
    $tenant = Tenant::create(['name' => 'Invitation Workspace', 'slug' => 'invitation-workspace']);
    $user->tenants()->attach($tenant->id, ['role' => 'member', 'membership_active' => true]);
    $this->actingAs($user)->putJson(route('workspace.first-login.draft'), ['revision' => 1, 'fields' => ['workspace_name' => 'Duplicate', 'surface' => 'popup']])->assertConflict();
    $this->post(route('workspace.first-login.store'), ['workspace_name' => 'Duplicate'])->assertRedirect();
    $this->assertDatabaseCount('tenants', 1);
    Http::assertNothingSent();
    Mail::assertNothingSent();
});

test('a new personal draft rejects arbitrary fields and unavailable capability choices', function () {
    $user = User::factory()->create(['role' => 'member', 'is_active' => true, 'email_verified_at' => now()]);
    $this->actingAs($user)->putJson(route('workspace.first-login.draft'), ['revision' => 0, 'fields' => ['surface' => 'page', 'tenant_id' => 123]])->assertUnprocessable();
    $this->putJson(route('workspace.first-login.draft'), ['revision' => 0, 'fields' => ['surface' => 'page', 'module_choices' => ['unknown_private_tool']]])->assertUnprocessable();
    expect(data_get($user->fresh()->onboarding_guide_answers, 'workspace_draft'))->toBeNull();
});

test('export guided settings browser fixtures', function () {
    if (! getenv('GUIDED_SETTINGS_FIXTURES')) {
        $this->markTestSkipped('Synthetic rendered fixtures are opt-in for isolated browser checks.');
    }
    $this->withVite();
    $directory = storage_path('framework/testing/guided-settings');
    if (! is_dir($directory)) {
        mkdir($directory, 0755, true);
    }
    $newOwner = User::factory()->create(['role' => 'member', 'is_active' => true, 'email_verified_at' => now(),
        'onboarding_guide_answers' => ['workspace_draft' => ['revision' => 2, 'fields' => ['workspace_name' => 'Fictional Artisan Studio', 'template_key' => 'electrician', 'team_size' => '2_5', 'resume_step' => 3, 'surface' => 'popup']]]]);
    foreach (['popup' => true, 'page' => false] as $name => $enabled) {
        config(['features.first_login_modal' => $enabled]);
        $response = $this->actingAs($newOwner)->get(route('workspace.first-login'))->assertOk();
        file_put_contents($directory.'/new-owner-'.$name.'.html', $response->getContent());
    }
    [$tenant, $user] = workspaceSettingsFixture();
    TenantModuleEntitlement::create(['tenant_id' => $tenant->id, 'module_key' => 'field_service', 'enabled_status' => 'enabled', 'availability_status' => 'available', 'billing_status' => 'included']);
    settingsSaveProfile($this, $tenant, $user, ['step' => 'business', 'category' => 'field_service', 'specialty' => 'Electrical']);
    settingsSaveProfile($this, $tenant, $user, ['step' => 'model', 'model' => 'both', 'channels' => ['on_site', 'wholesale']]);
    foreach (['settings-owner' => '/workspace/settings', 'search-logo' => '/workspace/settings?q=logo', 'business' => '/workspace/settings/business?step=business', 'model' => '/workspace/settings/business?step=model', 'goal' => '/workspace/settings/business?step=goal', 'setup' => '/workspace/setup'] as $name => $path) {
        $response = $this->actingAs($user)->get($path)->assertOk();
        file_put_contents($directory.'/'.$name.'.html', $response->getContent());
    }
    $member = User::factory()->create(['role' => 'member', 'is_active' => true, 'email_verified_at' => now()]);
    $member->tenants()->attach($tenant->id, ['role' => 'member', 'membership_active' => true]);
    $response = $this->actingAs($member)->get(route('workspace-settings.index', ['tenant' => $tenant->slug]))->assertOk();
    file_put_contents($directory.'/settings-member.html', $response->getContent());
    Http::assertNothingSent();
    Mail::assertNothingSent();
});

test('optional questionnaire answers never block one idempotent useful workspace creation', function () {
    $user = User::factory()->create(['role' => 'member', 'is_active' => true, 'email_verified_at' => now()]);
    $payload = ['workspace_name' => 'Fictional Optional Answers', 'template_key' => 'electrician', 'start_path' => 'self'];
    $this->actingAs($user)->post(route('workspace.first-login.store'), $payload)->assertSessionHasNoErrors()->assertRedirect();
    $this->post(route('workspace.first-login.store'), $payload)->assertRedirect();
    $this->assertDatabaseCount('tenants', 1);
    $tenant = Tenant::firstOrFail();
    $this->get(route('workspace-settings.setup', ['tenant' => $tenant->slug]))->assertOk()->assertSee('A good place to start');
    Http::assertNothingSent();
    Mail::assertNothingSent();
});

test('a newly created owner completes a real first customer action and sees reconciled setup progress', function () {
    $user = User::factory()->create(['role' => 'member', 'is_active' => true, 'email_verified_at' => now()]);
    $this->actingAs($user)->post(route('workspace.first-login.store'), ['workspace_name' => 'Fictional First Value', 'template_key' => 'electrician', 'start_path' => 'self'])->assertSessionHasNoErrors()->assertRedirect();
    $tenant = Tenant::firstOrFail();
    $this->actingAs($user->fresh())->get(route('workspace-settings.setup', ['tenant' => $tenant->slug]))->assertOk();
    foreach ([
        ['step' => 1, 'first_name' => 'Fictional', 'last_name' => 'Customer', 'email' => 'first-value@example.test'],
        ['step' => 2, 'customer_context' => 'wholesale'],
        ['step' => 3, 'decision' => 'continue'],
        ['step' => 4, 'notes' => 'Synthetic first useful task', 'accepts_email_marketing' => '0', 'accepts_sms_marketing' => '0'],
        ['step' => 5, 'confirm_create' => '1'],
    ] as $input) {
        $this->post(route('marketing.customers.store-create', ['tenant' => $tenant->slug]), [...$input, 'direction' => 'next'])->assertSessionHasNoErrors()->assertRedirect();
    }
    $customer = MarketingProfile::query()->where('tenant_id', $tenant->id)->where('email', 'first-value@example.test')->sole();
    $this->get(route('marketing.customers.show', ['marketingProfile' => $customer, 'tenant' => $tenant->slug]))->assertOk()->assertSee('Fictional');
    expect(collect(app(WorkspaceSetupGuide::class)->forTenant($tenant->fresh(), $user->fresh())['steps'])->firstWhere('id', 'customer')['state'])->toBe('complete');
    $this->assertDatabaseCount('tenants', 1);
    Http::assertNothingSent();
    Mail::assertNothingSent();
});

function settingsWebsiteFixture(Tenant $tenant, User $user): \App\Models\TenantSite
{
    TenantModuleEntitlement::create(['tenant_id' => $tenant->id, 'module_key' => 'managed_website', 'availability_status' => 'available', 'enabled_status' => 'enabled', 'billing_status' => 'add_on_comped', 'entitlement_source' => 'test', 'price_source' => 'catalog']);
    config(['managed_website.editor_enabled' => true, 'managed_website.editor_tenant_ids' => [$tenant->id], 'managed_website.custom_domains_enabled' => true, 'managed_website.custom_domain_tenant_ids' => [$tenant->id]]);

    return app(\App\Services\ManagedWebsite\ManagedWebsiteService::class)->createSite($tenant, $user);
}

test('website address and domain search reuses authorized native settings with independent domain availability', function () {
    [$tenant,$user] = workspaceSettingsFixture();
    settingsWebsiteFixture($tenant, $user);
    $service = app(WorkspaceSettingsService::class);
    foreach (['domain', 'website address'] as $query) {
        $row = $service->search($service->catalog($tenant->fresh(), $user), $query, '')[0];
        expect($row['id'])->toBe('website')->and($row['description'])->toContain('Custom domain setup is available');
        $this->actingAs($user)->get($row['url'])->assertOk();
    }
    config(['managed_website.custom_domains_enabled' => false]);
    expect($service->search($service->catalog($tenant->fresh(), $user), 'domain', '')[0]['description'])->toContain('Custom domain changes are not offered');
    config(['managed_website.editor_enabled' => false]);
    expect($service->search($service->catalog($tenant->fresh(), $user), 'domain', ''))->toBeEmpty();
    Http::assertNothingSent();
});

test('website metadata discovery remains role tenant and entitlement scoped', function () {
    [$tenant,$owner] = workspaceSettingsFixture();
    settingsWebsiteFixture($tenant, $owner);
    $member = User::factory()->create(['role' => 'member', 'is_active' => true, 'email_verified_at' => now()]);
    $member->tenants()->attach($tenant, ['role' => 'member', 'membership_active' => true]);
    $service = app(WorkspaceSettingsService::class);
    expect($service->search($service->catalog($tenant, $member), 'domain', ''))->toBeEmpty();
    $other = Tenant::create(['name' => 'Unprovisioned retailer', 'slug' => 'unprovisioned-retailer']);
    $owner->tenants()->attach($other, ['role' => 'owner', 'membership_active' => true]);
    expect($service->search($service->catalog($other, $owner), 'domain', ''))->toBeEmpty();
    TenantModuleEntitlement::where('tenant_id', $tenant->id)->update(['enabled_status' => 'disabled']);
    app()->forgetInstance(\App\Services\Tenancy\TenantModuleAccessResolver::class);
    expect($service->search($service->catalog($tenant->fresh(), $owner), 'domain', ''))->toBeEmpty();
    Http::assertNothingSent();
});

test('connected website discovery retains its editor and external domain distinction', function () {
    [$tenant,$user] = workspaceSettingsFixture();
    $site = settingsWebsiteFixture($tenant, $user);
    $tenant->update(['slug' => \App\Services\ManagedWebsite\ConnectedWebsiteService::SLUG]);
    $site->update(['settings' => ['connected_renderer' => \App\Services\ManagedWebsite\ConnectedWebsiteService::RENDERER]]);
    $service = app(WorkspaceSettingsService::class);
    $row = $service->search($service->catalog($tenant->fresh(), $user), 'website address', '')[0];
    expect($row['description'])->toContain('connected site editor', 'Custom domain changes are not offered')->and($row['url'])->toContain('/website');
    config(['managed_website.editor_enabled' => false]);
    expect($service->search($service->catalog($tenant->fresh(), $user), 'domain', ''))->toBeEmpty();
    Http::assertNothingSent();
});

test('a retailer prepares a real draft product and sees first-value completion without publishing', function () {
    [$tenant,$user] = workspaceSettingsFixture();
    $site = settingsWebsiteFixture($tenant, $user);
    settingsSaveProfile($this, $tenant, $user, ['step' => 'business', 'category' => 'retail', 'specialty' => 'Handmade goods']);
    settingsSaveProfile($this, $tenant, $user, ['step' => 'model', 'model' => 'products', 'channels' => ['online', 'in_person']]);
    settingsSaveProfile($this, $tenant, $user, ['step' => 'goal', 'goal' => 'product']);
    $step = collect(app(WorkspaceSetupGuide::class)->forTenant($tenant->fresh(), $user)['steps'])->firstWhere('id', 'product');
    expect($step['state'])->toBe('not_started')->and($step['required'])->toBeTrue();
    $this->get($step['url'])->assertOk();
    $this->post(route('managed-website.products.store', ['tenant' => $tenant->slug]), ['catalog_form' => 1, 'title' => 'Fictional handmade bowl', 'handle' => 'fictional-handmade-bowl', 'status' => 'draft', 'product_type' => 'physical', 'track_inventory' => true, 'variants' => [['title' => 'Natural', 'sku' => 'EX-BOWL', 'price' => '36.00', 'inventory_quantity' => 4, 'is_available' => true]], 'collection_ids' => []])->assertSessionHasNoErrors()->assertRedirect();
    $product = \App\Models\WebsiteProduct::where('tenant_id', $tenant->id)->sole();
    expect($product->status)->toBe('draft')->and($product->tenant_site_id)->toBe($site->id);
    $step = collect(app(WorkspaceSetupGuide::class)->forTenant($tenant->fresh(), $user)['steps'])->firstWhere('id', 'product');
    expect($step['state'])->toBe('complete');
    $this->get($step['url'])->assertOk()->assertSeeText('Fictional handmade bowl');
    expect($site->fresh()->public_enabled)->toBeFalse();
    Http::assertNothingSent();
    Mail::assertNothingSent();
});

<?php

use App\Models\Tenant;
use App\Models\TenantModuleEntitlement;
use App\Models\User;
use App\Services\Onboarding\BranchSelectionService;
use App\Services\Onboarding\SelfServiceTemplateSelectionService;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    $this->withoutVite();
    Http::preventStrayRequests();
});

test('business templates use canonical blueprints photos and safe included setup tools', function (): void {
    $service = app(SelfServiceTemplateSelectionService::class);
    $templates = $service->templates();
    expect(array_column($templates, 'key'))->toBe(['generic', 'candle_maker', 'landscaping', 'electrician', 'law', 'apparel', 'custom']);
    $public = array_column(app(BranchSelectionService::class)->modules(), 'key');
    foreach ($templates as $template) {
        expect(config('tenant_blueprints.templates.'.$template['blueprint_template']))->toBeArray()
            ->and(array_diff($template['branches'], $public))->toBe([])
            ->and($template['branches'])->toContain('managed_website');
        $this->assertFileExists(public_path($template['image']));
    }
    expect($service->normalize(null))->toBeNull()
        ->and($service->normalize(''))->toBeNull();
});

test('choosing a template derives its tools on the server and saves no paid access', function (): void {
    $service = app(SelfServiceTemplateSelectionService::class);
    $this->post(route('platform.build.save'), [
        'template' => 'electrician', 'branches' => ['ai_branch', 'teams', 'unknown_internal'], 'monthly_cents' => 1,
    ])->assertRedirect(route('platform.build'))
        ->assertSessionHas(SelfServiceTemplateSelectionService::SESSION_KEY, 'electrician')
        ->assertSessionHas(BranchSelectionService::SESSION_KEY, $service->branches('electrician'));
    $this->get(route('platform.build'))->assertOk()
        ->assertViewHas('selectedTemplate', 'electrician')
        ->assertSeeText('Your template is saved.')
        ->assertSee('value="electrician" required checked', false);
    expect(Tenant::query()->count())->toBe(0)
        ->and(TenantModuleEntitlement::withoutGlobalScopes()->count())->toBe(0);
    Http::assertNothingSent();
});

test('unknown template keys cannot change a saved selection', function (string $key): void {
    $this->withSession([SelfServiceTemplateSelectionService::SESSION_KEY => 'generic', BranchSelectionService::SESSION_KEY => ['customers']])
        ->post(route('platform.build.save'), ['template' => $key])
        ->assertSessionHasErrors('template')
        ->assertSessionHas(SelfServiceTemplateSelectionService::SESSION_KEY, 'generic')
        ->assertSessionHas(BranchSelectionService::SESSION_KEY, ['customers']);
    Http::assertNothingSent();
})->with(['platform_admin', '../billing', 'teams', 'Generic']);

test('signed in template selection preserves other guide answers and restores on a later visit', function (): void {
    $user = User::factory()->create(['onboarding_guide_answers' => ['unrelated_answer' => 'preserved']]);
    $service = app(SelfServiceTemplateSelectionService::class);
    $this->actingAs($user)->post(route('platform.build.save'), ['template' => 'law'])
        ->assertRedirect(route('platform.build'));
    $answers = $user->refresh()->onboarding_guide_answers;
    expect($answers['business_template'])->toBe('law')
        ->and($answers['branch_selection'])->toBe($service->branches('law'))
        ->and($answers['unrelated_answer'])->toBe('preserved');
    session()->forget([SelfServiceTemplateSelectionService::SESSION_KEY, BranchSelectionService::SESSION_KEY]);
    $this->get(route('platform.build'))->assertOk()
        ->assertViewHas('selectedTemplate', 'law')
        ->assertViewHas('summary', fn (array $summary): bool => $summary['selected'] === $service->branches('law'));
    expect(TenantModuleEntitlement::withoutGlobalScopes()->count())->toBe(0);
});

test('template continue carries the preset into public account creation', function (): void {
    config()->set('self_service.registration_enabled', true);
    $this->post(route('platform.build.save'), ['template' => 'landscaping', 'continue' => '1'])
        ->assertRedirect(route('self-service.signup'))
        ->assertSessionHas(SelfServiceTemplateSelectionService::SESSION_KEY, 'landscaping');
});

test('stale saved templates fail closed on the chooser without losing legacy branch compatibility', function (): void {
    $this->withSession([SelfServiceTemplateSelectionService::SESSION_KEY => 'retired_template'])
        ->get(route('platform.build'))->assertOk()->assertViewHas('selectedTemplate', null);
    $this->post(route('platform.build.save'), ['branches' => ['customers', 'customers']])
        ->assertRedirect(route('platform.build'))->assertSessionHas(BranchSelectionService::SESSION_KEY, ['customers']);
});

test('every business template provisions its selected preset without charging or granting paid branches', function (string $key): void {
    config()->set('self_service.registration_enabled', true);
    $user = User::factory()->create([
        'name' => 'Template Owner', 'role' => 'self_service_pending', 'requested_via' => 'self_service_registration',
        'is_active' => true, 'email_verified_at' => now(),
    ]);
    $templates = app(SelfServiceTemplateSelectionService::class);
    $this->actingAs($user)->withSession([
        SelfServiceTemplateSelectionService::SESSION_KEY => $key,
        BranchSelectionService::SESSION_KEY => $templates->branches($key),
    ])->post(route('workspace.first-login.store'), [
        'workspace_name' => 'Template '.$key, 'template_key' => 'law', 'start_path' => 'self',
    ])->assertSessionHasNoErrors()->assertRedirect();
    $tenant = $user->fresh()->tenants()->firstOrFail();
    expect($user->fresh()->onboarding_guide_answers['business_template'])->toBe($key)
        ->and($user->fresh()->onboarding_guide_answers['branch_selection'])->toBe($templates->branches($key))
        ->and($tenant->accessProfile->metadata['self_service_registration'])->toBeTrue()
        ->and(Tenant::query()->count())->toBe(1)
        ->and(TenantModuleEntitlement::withoutGlobalScopes()->count())->toBe(0);
    Http::assertNothingSent();
})->with(['generic', 'candle_maker', 'landscaping', 'electrician', 'law', 'apparel', 'custom']);

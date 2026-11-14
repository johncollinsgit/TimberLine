<?php

use App\Models\Tenant;
use App\Models\TenantAccessProfile;
use App\Models\TenantModuleEntitlement;
use App\Models\User;
use App\Services\Onboarding\BranchSelectionService;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    $this->withoutVite();
    config()->set('self_service.registration_enabled', false);
    Http::preventStrayRequests();
    config()->set('services.google.enabled', true);
    config()->set('services.google.auto_provision', true);
    config()->set('services.google.client_id', 'fictional');
    config()->set('services.google.client_secret', 'fictional');
    config()->set('services.google.redirect', 'https://example.test/auth/google/callback');
});

test('signup presents templates with the server-priced offer', function (): void {
    $this->get(route('platform.start'))->assertOk()
        ->assertSeeText('Choose a template.')
        ->assertSeeText('Included tools')
        ->assertSee('name="template"', false)
        ->assertDontSee('name="branches[]"', false)
        ->assertSeeText('Continue to account')
        ->assertSeeText('$798')
        ->assertSeeText('Before applicable tax.')
        ->assertSeeText('Payment and activation are separate steps.')
        ->assertSee(route('platform.request-access'), false);
});

test('branch basket deduplicates choices and resumes without changing access', function (): void {
    $this->post(route('platform.build.save'), ['branches' => ['customers', 'messaging', 'customers'], 'monthly_cents' => 1])
        ->assertRedirect(route('platform.build'))
        ->assertSessionHas(BranchSelectionService::SESSION_KEY, ['customers', 'messaging']);
    $this->get(route('platform.build'))->assertOk()->assertSeeText('Choose a template.')->assertSeeText('$299');
    expect(app(BranchSelectionService::class)->summary(session(BranchSelectionService::SESSION_KEY))['selected'])->toBe(['customers', 'messaging']);
    expect(Tenant::query()->count())->toBe(0)
        ->and(TenantModuleEntitlement::query()->count())->toBe(0);
    Http::assertNothingSent();
});

test('premium and internal branches cannot be smuggled into the included basket', function (string $key): void {
    $this->post(route('platform.build.save'), ['branches' => [$key]])
        ->assertSessionHasErrors('branches');
    expect(session(BranchSelectionService::SESSION_KEY, []))->toBe([]);
})->with(['ai_branch', 'teams', 'unknown_internal']);

test('adding a branch preserves earlier choices and clear removes all choices', function (): void {
    $this->withSession([BranchSelectionService::SESSION_KEY => ['customers']])
        ->post(route('platform.build.add'), ['branch' => 'messaging'])
        ->assertRedirect(route('platform.build'))
        ->assertSessionHas(BranchSelectionService::SESSION_KEY, ['customers', 'messaging']);
    $this->post(route('platform.build.save'), [])->assertSessionHas(BranchSelectionService::SESSION_KEY, []);
});

test('continue saves choices before entering the existing authenticated workspace flow', function (): void {
    $this->post(route('platform.build.save'), ['branches' => ['customers'], 'continue' => '1'])
        ->assertRedirect(route('workspace.first-login'))
        ->assertSessionHas(BranchSelectionService::SESSION_KEY, ['customers']);
});

test('stale session choices are filtered against the current public catalog', function (): void {
    $summary = app(BranchSelectionService::class)->summary(['customers', 'removed_module', 'ai_branch']);
    expect($summary['selected'])->toBe(['customers'])
        ->and($summary['due_before_tax_cents'])->toBe(79800);
});

test('account creation without a configured provider falls back to assisted setup with choices intact', function (): void {
    config()->set('services.google.enabled', false);
    $this->post(route('platform.build.save'), ['branches' => ['customers'], 'continue' => '1'])
        ->assertRedirect(route('platform.request-access'))
        ->assertSessionHas(BranchSelectionService::SESSION_KEY, ['customers']);
    $response = $this->get(route('platform.request-access'))->assertOk();
    expect($response->getContent())->toMatch('/value="customers"\s+checked/');
});

test('the assisted request form remains reachable separately', function (): void {
    $this->get(route('platform.request-access'))->assertOk()
        ->assertSee('name="intent" value="production"', false)
        ->assertSeeText('Request production access');
});

test('workspace exploration uses direct destinations and requires tenant membership', function (): void {
    config()->set('tenancy.domains.canonical.base_domain', 'theeverbranch.com');
    config()->set('tenancy.domains.canonical.public_host', 'theeverbranch.com');
    config()->set('tenancy.domains.canonical.landlord_host', 'app.theeverbranch.com');
    $tenant = Tenant::query()->create(['name' => 'Fictional Workshop', 'slug' => 'guide-workshop']);
    TenantAccessProfile::query()->create(['tenant_id' => $tenant->id, 'plan_key' => 'base', 'operating_mode' => 'direct', 'source' => 'test']);
    $user = User::factory()->create(['role' => 'manager', 'is_active' => true, 'email_verified_at' => now()]);
    $tenant->users()->attach($user, ['role' => 'manager']);
    $this->actingAs($user)->get('https://guide-workshop.theeverbranch.com/workspace/guide?tenant=guide-workshop')
        ->assertOk()->assertSeeText('Explore Everbranch')->assertDontSee('/shopify/app', false);
    $other = User::factory()->create(['role' => 'manager', 'is_active' => true, 'email_verified_at' => now()]);
    $this->actingAs($other)->get('https://guide-workshop.theeverbranch.com/workspace/guide?tenant=guide-workshop')
        ->assertRedirect(route('workspace.first-login', absolute: false));
});

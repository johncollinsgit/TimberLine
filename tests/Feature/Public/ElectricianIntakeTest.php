<?php

use App\Models\CustomerAccessRequest;
use App\Models\Tenant;
use App\Models\TenantAccessProfile;
use App\Models\TenantModuleEntitlement;
use App\Models\User;
use App\Services\Tenancy\TenantCommercialExperienceService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

beforeEach(function (): void {
    $this->withoutVite();
    Http::preventStrayRequests();
    Notification::fake();
});

test('electrician industry selection records interest independently of optional tutorial rollout', function (bool $tutorialEnabled, string $intent): void {
    config()->set('features.customer_electrician_tutorial', $tutorialEnabled);
    config()->set('tenancy.onboarding.demo_tenant_slug', 'demo');
    $this->get(route($intent === 'demo' ? 'platform.demo' : 'platform.start'))->assertOk()->assertSee('value="electrician"', false);
    $this->post(route('platform.access-request'), [
        'intent' => $intent, 'name' => 'Fictional Electrician', 'email' => 'quality-electrician@example.com',
        'business_type' => 'electrician', 'preferred_plan_key' => 'standard', 'branches_interest' => ['field_service'],
    ])->assertRedirect(route('platform.request-submitted', ['intent' => $intent], absolute: false))->assertSessionHasNoErrors();
    $request = CustomerAccessRequest::query()->where('email', 'quality-electrician@example.com')->sole();
    expect(data_get($request->metadata, 'business_type'))->toBe('electrician')
        ->and(data_get($request->metadata, 'preferred_plan_key'))->toBe('standard')
        ->and(data_get($request->metadata, 'branches_interest'))->toBe(['field_service'])
        ->and($request->status)->toBe('pending')
        ->and(Tenant::query()->count())->toBe(0)
        ->and(TenantAccessProfile::query()->count())->toBe(0)
        ->and(TenantModuleEntitlement::query()->count())->toBe(0)
        ->and(User::query()->sole()->is_active)->toBeFalse()
        ->and(User::query()->sole()->tenants()->count())->toBe(0);
    $profiles = app(TenantCommercialExperienceService::class)->publicPromoConfig()['preview_profiles'];
    expect(in_array('electrician', array_column($profiles, 'key'), true))->toBe($tutorialEnabled);
    $this->get(route('platform.request-submitted', ['intent' => $intent]))->assertOk()
        ->assertSeeText('1. Review')->assertSeeText('2. Activation')->assertSeeText('3. Start Here')
        ->assertSeeText('We review your business needs and the branches you selected.');
    Http::assertNothingSent();
})->with([[false, 'demo'], [false, 'production'], [true, 'demo'], [true, 'production']]);

test('public intake still rejects unsupported industry keys', function (): void {
    $this->post(route('platform.access-request'), [
        'intent' => 'production', 'name' => 'Fictional Owner', 'email' => 'quality-unsupported@example.com',
        'business_type' => 'unsupported_quality_industry',
    ])->assertSessionHasErrors('business_type');
    expect(CustomerAccessRequest::query()->count())->toBe(0);
    Http::assertNothingSent();
});

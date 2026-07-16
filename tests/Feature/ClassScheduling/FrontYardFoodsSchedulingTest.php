<?php

use App\Models\ClassEnrollment;
use App\Models\ClassReminder;
use App\Models\ClassSchedulingSetting;
use App\Models\MarketingProfile;
use App\Models\ScheduledClass;
use App\Models\Tenant;
use App\Models\TenantAccessProfile;
use App\Models\TenantModuleState;
use App\Models\User;
use App\Models\WorkspaceAsset;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    $this->withoutVite();
    Storage::fake('local');
    Http::fake(['*' => Http::response('demo-image-bytes', 200, ['Content-Type' => 'image/jpeg'])]);
});

test('front yard foods preparation is idempotent and creates tenant four demo access', function (): void {
    $this->artisan('everbranch:prepare-front-yard-foods')->assertSuccessful();
    $this->artisan('everbranch:prepare-front-yard-foods')->assertSuccessful();

    $tenant = Tenant::query()->where('slug', 'front-yard-foods')->firstOrFail();
    $john = User::query()->where('email', 'johncollinsemail@gmail.com')->firstOrFail();

    expect((int) $tenant->id)->toBe(4)
        ->and($john->tenants()->whereKey(4)->first()?->pivot?->role)->toBe('admin')
        ->and(ScheduledClass::query()->forTenantId(4)->count())->toBe(4)
        ->and(ClassEnrollment::query()->forTenantId(4)->count())->toBe(10)
        ->and(MarketingProfile::query()->forTenantId(4)->count())->toBe(6)
        ->and(MarketingProfile::query()->forTenantId(4)->where('normalized_phone', '!=', '8646165468')->exists())->toBeFalse()
        ->and(WorkspaceAsset::query()->forTenantId(4)->where('source', 'demo_seed')->count())->toBe(4)
        ->and(WorkspaceAsset::query()->forTenantId(4)->where('metadata->demo_reference', true)->count())->toBe(4);

    $this->actingAs($john)
        ->get(route('class-scheduling.index', ['tenant' => $tenant->slug]))
        ->assertOk()
        ->assertSeeText('Sourdough Basics')
        ->assertSeeText('Classes & appointments', false);

});

test('front yard foods uses the next open tenant id when tenant four is occupied', function (): void {
    Tenant::query()->forceCreate(['id' => 4, 'name' => 'Existing Tenant', 'slug' => 'existing-tenant']);

    $this->artisan('everbranch:prepare-front-yard-foods')->assertSuccessful();

    $tenant = Tenant::query()->where('slug', 'front-yard-foods')->firstOrFail();
    expect((int) $tenant->id)->toBe(5)
        ->and(Tenant::query()->findOrFail(4)->slug)->toBe('existing-tenant');
});

test('public signup creates a tenant customer enrollment and consent gated reminders', function (): void {
    $this->artisan('everbranch:prepare-front-yard-foods')->assertSuccessful();
    $tenant = Tenant::query()->findOrFail(4);
    $class = ScheduledClass::query()->forTenantId(4)->where('slug', 'sourdough-basics')->firstOrFail();

    $this->get(route('public.classes.index', ['tenant' => $tenant->slug]))
        ->assertOk()
        ->assertSeeText('Learn with Laura')
        ->assertSeeText('Sourdough Basics');

    $this->post(route('public.classes.store', ['tenant' => $tenant->slug, 'class' => $class->slug]), [
        'name' => 'Taylor Garden',
        'email' => 'taylor.garden@example.test',
        'phone' => '8646165468',
        'seats' => 1,
        'email_reminders_enabled' => 1,
        'sms_reminders_enabled' => 1,
    ])->assertRedirect(route('public.classes.show', ['tenant' => $tenant->slug, 'class' => $class->slug]));

    $this->from(route('public.classes.show', ['tenant' => $tenant->slug, 'class' => $class->slug]))
        ->post(route('public.classes.store', ['tenant' => $tenant->slug, 'class' => $class->slug]), [
            'name' => 'Taylor Garden',
            'email' => 'TAYLOR.GARDEN@example.test',
            'phone' => '8646165468',
            'seats' => 1,
        ])
        ->assertRedirect()
        ->assertSessionHasErrors('email');

    $profile = MarketingProfile::query()->forTenantId(4)->where('normalized_email', 'taylor.garden@example.test')->firstOrFail();
    $enrollment = ClassEnrollment::query()->forTenantId(4)->where('marketing_profile_id', $profile->id)->firstOrFail();

    expect($enrollment->scheduled_class_id)->toBe($class->id)
        ->and($enrollment->normalized_phone)->toBe('8646165468')
        ->and(ClassReminder::query()->forTenantId(4)->where('class_enrollment_id', $enrollment->id)->pluck('channel')->sort()->values()->all())->toBe(['email', 'sms'])
        ->and(ClassReminder::query()->forTenantId(4)->where('class_enrollment_id', $enrollment->id)->where('status', 'scheduled')->count())->toBe(2)
        ->and(ClassReminder::query()->forTenantId(4)->where('class_enrollment_id', $enrollment->id)->where('provider_metadata->delivery_gate', 'tenant_provider_and_consent_required')->count())->toBe(2);
});

test('public class surfaces fail closed until a tenant explicitly publishes signup', function (): void {
    $tenant = Tenant::query()->create(['name' => 'Private Classes', 'slug' => 'private-classes']);
    TenantAccessProfile::query()->create(['tenant_id' => $tenant->id, 'plan_key' => 'base', 'operating_mode' => 'direct', 'source' => 'test']);
    TenantModuleState::query()->create(['tenant_id' => $tenant->id, 'module_key' => 'class_scheduling', 'enabled_override' => true, 'setup_status' => 'configured']);
    ClassSchedulingSetting::query()->create(['tenant_id' => $tenant->id, 'public_signup_enabled' => false]);

    $this->get(route('public.classes.index', ['tenant' => $tenant->slug]))->assertNotFound();
});

test('class detail rejects a class owned by another active scheduling tenant', function (): void {
    $this->artisan('everbranch:prepare-front-yard-foods')->assertSuccessful();
    $frontYard = Tenant::query()->findOrFail(4);
    $john = User::query()->where('email', 'johncollinsemail@gmail.com')->firstOrFail();
    $other = Tenant::query()->create(['name' => 'Other Educator', 'slug' => 'other-educator']);
    TenantAccessProfile::query()->create(['tenant_id' => $other->id, 'plan_key' => 'base', 'operating_mode' => 'direct', 'source' => 'test']);
    TenantModuleState::query()->create(['tenant_id' => $other->id, 'module_key' => 'class_scheduling', 'enabled_override' => true, 'setup_status' => 'configured']);
    $other->users()->attach($john->id, ['role' => 'admin']);
    $frontYardClass = ScheduledClass::query()->forTenantId($frontYard->id)->firstOrFail();

    $this->actingAs($john)
        ->get(route('class-scheduling.show', ['scheduledClass' => $frontYardClass, 'tenant' => $other->slug]))
        ->assertNotFound();
});

test('mobile scheduling exposes clickable classes customers reminders and canonical job photos', function (): void {
    $this->artisan('everbranch:prepare-front-yard-foods')->assertSuccessful();
    $tenant = Tenant::query()->where('slug', 'front-yard-foods')->firstOrFail();
    $john = User::query()->where('email', 'johncollinsemail@gmail.com')->firstOrFail();
    $class = ScheduledClass::query()->forTenantId($tenant->id)->where('slug', 'sourdough-basics')->firstOrFail();
    $enrollment = ClassEnrollment::query()->forTenantId($tenant->id)->where('scheduled_class_id', $class->id)->firstOrFail();
    $job = $tenant->fieldServiceJobs()->where('external_id', 'fyf-demo-fruit-tree-installation')->firstOrFail();
    Sanctum::actingAs($john, ['mobile:read', 'mobile:write']);

    $this->getJson('/api/mobile/v1/workspaces/'.$tenant->slug.'/class-scheduling')
        ->assertOk()
        ->assertJsonPath('classes.0.destination.kind', 'scheduled_class')
        ->assertJsonFragment(['title' => 'Sourdough Basics']);

    $this->getJson('/api/mobile/v1/workspaces/'.$tenant->slug.'/class-scheduling/classes/'.$class->id)
        ->assertOk()
        ->assertJsonPath('class.attendees.0.destination.kind', 'customer')
        ->assertJsonPath('class.attendees.0.message_destination.kind', 'message_customer');

    $this->postJson('/api/mobile/v1/workspaces/'.$tenant->slug.'/class-scheduling/enrollments/'.$enrollment->id.'/reminders', [
        'channel' => 'email',
        'scheduled_for' => now()->addHours(6)->toIso8601String(),
    ])->assertCreated()->assertJsonPath('reminder.status', 'scheduled');

    $this->getJson('/api/mobile/v1/workspaces/'.$tenant->slug.'/field-service/jobs/'.$job->id)
        ->assertOk()
        ->assertJsonCount(2, 'job.photos');
});

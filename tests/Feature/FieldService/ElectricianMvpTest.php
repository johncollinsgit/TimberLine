<?php

use App\Models\FieldServiceJob;
use App\Models\FieldServiceJobNote;
use App\Models\FieldServiceJobPhoto;
use App\Models\FieldServiceMaterial;
use App\Models\FieldServiceReminderSetting;
use App\Models\MarketingProfile;
use App\Models\Tenant;
use App\Models\TenantAccessProfile;
use App\Models\TenantOnboardingBlueprint;
use App\Models\User;
use App\Services\Search\GlobalSearchCoordinator;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    $this->withoutVite();
});

test('collins electric prep command creates guided workspace and john mobile admin access', function (): void {
    $this->artisan('everbranch:prepare-collins-electric --seed-demo-job')
        ->assertSuccessful();

    $tenant = Tenant::query()->where('slug', 'collins-electric')->firstOrFail();
    $john = User::query()->where('email', 'johncollinsemail@gmail.com')->firstOrFail();

    expect($john->is_active)->toBeTrue()
        ->and($john->email_verified_at)->not->toBeNull()
        ->and($john->tenants()->whereKey($tenant->id)->exists())->toBeTrue()
        ->and($john->tenants()->whereKey($tenant->id)->first()?->pivot?->role)->toBe('admin');

    expect($tenant->accessProfile?->plan_key)->toBe('base')
        ->and($tenant->accessProfile?->operating_mode)->toBe('direct')
        ->and($tenant->setupStatus?->module_interests)->toContain('quickbooks')
        ->and($tenant->setupStatus?->module_interests)->toContain('uploads');

    $blueprint = TenantOnboardingBlueprint::query()->where('tenant_id', $tenant->id)->latest('id')->firstOrFail();
    expect(data_get($blueprint->payload, 'selected_modules'))->toContain('field_service')
        ->and(data_get($blueprint->payload, 'selected_modules'))->not->toContain('quickbooks');

    $reminders = FieldServiceReminderSetting::query()->where('tenant_id', $tenant->id)->firstOrFail();
    expect($reminders->enabled)->toBeFalse()
        ->and($reminders->provider_status)->toBe('not_verified');

    Sanctum::actingAs($john, ['mobile:read']);
    $this->getJson('/api/mobile/v1/workspaces')
        ->assertOk()
        ->assertJsonFragment(['slug' => 'collins-electric']);
});

test('collins electric prep command preserves an approved launch review', function (): void {
    $this->artisan('everbranch:prepare-collins-electric')->assertSuccessful();

    $tenant = Tenant::query()->where('slug', 'collins-electric')->firstOrFail();
    $john = User::query()->where('email', 'johncollinsemail@gmail.com')->firstOrFail();
    $status = $tenant->setupStatus()->firstOrFail();
    $reviewedAt = now()->subMinute();
    $status->forceFill([
        'business_profile_status' => 'ready',
        'csv_manual_status' => 'ready',
        'landlord_review_status' => 'reviewed',
        'commercial_review_status' => 'reviewed',
        'reviewed_by' => (int) $john->id,
        'reviewed_at' => $reviewedAt,
        'commercial_reviewed_by' => (int) $john->id,
        'commercial_reviewed_at' => $reviewedAt,
        'next_recommended_action' => 'Core launch approved.',
        'commercial_next_action' => 'Billing remains separately gated.',
        'internal_notes' => 'Verified launch evidence.',
        'module_interests' => ['custom_future_module'],
    ])->save();

    $this->artisan('everbranch:prepare-collins-electric')->assertSuccessful();

    $status->refresh();
    expect($status->business_profile_status)->toBe('ready')
        ->and($status->csv_manual_status)->toBe('ready')
        ->and($status->landlord_review_status)->toBe('reviewed')
        ->and($status->commercial_review_status)->toBe('reviewed')
        ->and($status->reviewed_by)->toBe((int) $john->id)
        ->and($status->commercial_reviewed_by)->toBe((int) $john->id)
        ->and($status->next_recommended_action)->toBe('Core launch approved.')
        ->and($status->commercial_next_action)->toBe('Billing remains separately gated.')
        ->and($status->internal_notes)->toBe('Verified launch evidence.')
        ->and($status->module_interests)->toContain('custom_future_module')
        ->and($status->module_interests)->toContain('field_service');
});

test('collins electric keeps electrician workspace navigation and rejects plant inventory', function (): void {
    $this->artisan('everbranch:prepare-collins-electric --seed-demo-job')
        ->assertSuccessful();

    $tenant = Tenant::query()->where('slug', 'collins-electric')->firstOrFail();
    $john = User::query()->where('email', 'johncollinsemail@gmail.com')->firstOrFail();

    $this->actingAs($john)
        ->get(route('dashboard', ['tenant' => $tenant->slug]))
        ->assertOk()
        ->assertSeeText('Work')
        ->assertSeeText('Jobs')
        ->assertSeeText('Materials')
        ->assertSeeText('Work vans')
        ->assertSeeText('Create a job')
        ->assertDontSeeText('Welcome, Laura')
        ->assertDontSeeText('Plant Inventory')
        ->assertDontSeeText('Events & Classes');

    $this->actingAs($john)
        ->get(route('plant-inventory.index', ['tenant' => $tenant->slug]))
        ->assertForbidden();
});

test('electrician field service captures lock box notes calendar and searchable updates', function (): void {
    [$tenant, $user] = electricianTenantAndUser();

    $this->actingAs($user)
        ->post(route('field-service.jobs.store', ['tenant' => $tenant->slug]), [
            'customer_name' => 'Pat Electric',
            'customer_email' => 'pat@example.com',
            'customer_phone' => '555-111-2222',
            'lock_box_code' => '8124',
            'title' => 'Garage subpanel inspection',
            'description' => 'Customer says lights flicker near workbench.',
            'service_address_line_1' => '100 Main Street',
            'service_city' => 'Fort Wayne',
            'service_state' => 'IN',
            'service_postal_code' => '46802',
            'assigned_user_id' => $user->id,
            'scheduled_for' => now()->addDay()->format('Y-m-d H:i:s'),
        ])
        ->assertRedirect();

    $job = FieldServiceJob::query()->where('tenant_id', $tenant->id)->where('title', 'Garage subpanel inspection')->firstOrFail();
    expect($job->lock_box_code)->toBe('8124');

    $this->actingAs($user)
        ->post(route('field-service.notes.store', ['tenant' => $tenant->slug, 'job' => $job]), [
            'body' => 'Employee found loose neutral in the garage subpanel.',
            'status_update' => 'in_progress',
            'photo_file_path' => 'https://example.test/photo.jpg',
            'photo_caption' => 'Loose neutral',
        ])
        ->assertRedirect();

    expect(FieldServiceJobNote::query()->where('field_service_job_id', $job->id)->count())->toBe(1)
        ->and(FieldServiceJobPhoto::query()->where('field_service_job_id', $job->id)->whereNotNull('field_service_job_note_id')->count())->toBe(1)
        ->and($job->fresh()->status)->toBe('in_progress');

    $this->actingAs($user)
        ->get(route('field-service.jobs.show', ['tenant' => $tenant->slug, 'job' => $job, 'back' => 'calendar']))
        ->assertOk()
        ->assertSeeText('8124')
        ->assertSeeText('Back to calendar')
        ->assertSeeText('loose neutral');

    $this->actingAs($user)
        ->get(route('field-service.calendar', ['tenant' => $tenant->slug]))
        ->assertOk()
        ->assertSeeText('Garage subpanel inspection');

    $search = app(GlobalSearchCoordinator::class)->search('loose neutral', [
        'tenant_id' => (int) $tenant->id,
        'user' => $user,
        'limit' => 10,
    ]);
    expect(collect($search['results'])->pluck('type'))->toContain('work');
});

test('mobile field service exposes lock box notes and tenant scoped note action', function (): void {
    [$tenant, $user] = electricianTenantAndUser();
    $job = FieldServiceJob::query()->create([
        'tenant_id' => $tenant->id,
        'title' => 'Attic junction box repair',
        'customer_name' => 'Sam Homeowner',
        'customer_phone' => '555-222-3333',
        'lock_box_code' => '4455',
        'service_address_line_1' => '12 Cedar Lane',
        'scheduled_for' => now()->addDay(),
    ]);
    Sanctum::actingAs($user, ['mobile:read', 'mobile:write']);

    $this->postJson('/api/mobile/v1/workspaces/electrician-mvp/modules/field_service/actions/add_note', [
        'job_id' => $job->id,
        'body' => 'Crew replaced damaged attic junction cover.',
        'status_update' => 'done',
    ])->assertCreated()->assertJsonPath('action', 'add_note');

    $this->getJson('/api/mobile/v1/workspaces/electrician-mvp/work/jobs/'.$job->id)
        ->assertOk()
        ->assertJsonPath('item.lock_box_code', '4455')
        ->assertJsonPath('item.notes.0.body', 'Crew replaced damaged attic junction cover.');

    $otherTenant = Tenant::query()->create(['name' => 'Other Tenant', 'slug' => 'other-tenant']);
    TenantAccessProfile::query()->create(['tenant_id' => $otherTenant->id, 'plan_key' => 'base', 'operating_mode' => 'direct', 'source' => 'test']);
    $otherJob = FieldServiceJob::query()->create(['tenant_id' => $otherTenant->id, 'title' => 'Spoofed']);

    $this->postJson('/api/mobile/v1/workspaces/electrician-mvp/modules/field_service/actions/add_note', [
        'job_id' => $otherJob->id,
        'body' => 'Should not cross tenants.',
    ])->assertNotFound();
});

test('collins team members can use operational reporting without mobile financial cards', function (): void {
    [$tenant, $admin] = electricianTenantAndUser();
    $member = User::factory()->create([
        'role' => 'member',
        'is_active' => true,
        'email_verified_at' => now(),
    ]);
    $member->tenants()->attach($tenant->id, ['role' => 'member']);
    FieldServiceJob::query()->create([
        'tenant_id' => $tenant->id,
        'assigned_user_id' => $member->id,
        'title' => 'Member-visible service call',
        'customer_name' => 'Field Customer',
        'scheduled_for' => now()->addDay(),
    ]);

    $this->actingAs($member)
        ->get(route('field-service.calendar', ['tenant' => $tenant->slug]))
        ->assertOk()
        ->assertSeeText('Member-visible service call');

    $this->actingAs($member)
        ->get(route('app.search', ['tenant' => $tenant->slug, 'q' => 'Member-visible']))
        ->assertOk();

    Sanctum::actingAs($member, ['mobile:read']);
    $this->getJson('/api/mobile/v1/workspaces/'.$tenant->slug.'/modules/reporting')
        ->assertOk()
        ->assertJsonFragment(['label' => 'Upcoming jobs'])
        ->assertJsonMissing(['label' => 'Unpaid invoices'])
        ->assertJsonMissing(['label' => 'Contract labor']);
    $this->getJson('/api/mobile/v1/workspaces/'.$tenant->slug.'/bootstrap')
        ->assertOk()
        ->assertJsonFragment(['module_key' => 'reporting']);

    expect($admin->id)->not->toBe($member->id);
});

test('quickbooks csv importer accepts customers jobs and items without live sync promises', function (): void {
    [$tenant] = electricianTenantAndUser();
    $path = Storage::path('quickbooks-electrician.csv');
    if (! is_dir(dirname($path))) {
        mkdir(dirname($path), 0777, true);
    }
    file_put_contents($path, implode("\n", [
        'Customer,Email,Phone,Job,Amount,Service Address,City,State',
        'Alice Homeowner,alice@example.com,555-1111,Invoice 1001 Panel Repair,450.00,1 Oak St,Fort Wayne,IN',
    ]));

    $this->artisan('field-service:import-quickbooks', [
        'file' => $path,
        '--tenant' => $tenant->slug,
        '--type' => 'jobs',
        '--dry-run' => true,
    ])->assertSuccessful();
    expect(FieldServiceJob::query()->where('tenant_id', $tenant->id)->count())->toBe(0);

    $this->artisan('field-service:import-quickbooks', [
        'file' => $path,
        '--tenant' => $tenant->slug,
        '--type' => 'jobs',
    ])->assertSuccessful();

    expect(MarketingProfile::query()->where('tenant_id', $tenant->id)->where('normalized_email', 'alice@example.com')->exists())->toBeTrue()
        ->and(FieldServiceJob::query()->where('tenant_id', $tenant->id)->where('external_source', 'quickbooks')->count())->toBe(1);

    $itemsPath = Storage::path('quickbooks-items.csv');
    file_put_contents($itemsPath, implode("\n", [
        'Item ID,Name,Quantity,Cost',
        'breaker-20a,20A breaker,3,12.50',
    ]));
    $this->artisan('field-service:import-quickbooks', [
        'file' => $itemsPath,
        '--tenant' => $tenant->slug,
        '--type' => 'items',
    ])->assertSuccessful();

    expect(FieldServiceMaterial::query()->where('tenant_id', $tenant->id)->where('external_source', 'quickbooks')->count())->toBe(1);
});

/**
 * @return array{0:Tenant,1:User}
 */
function electricianTenantAndUser(): array
{
    $tenant = Tenant::query()->create([
        'name' => 'Electrician MVP',
        'slug' => 'electrician-mvp',
    ]);

    TenantAccessProfile::query()->create([
        'tenant_id' => $tenant->id,
        'plan_key' => 'base',
        'operating_mode' => 'direct',
        'source' => 'test',
    ]);

    $user = User::factory()->tenantAdmin()->create([
        'role' => 'admin',
        'is_active' => true,
        'email_verified_at' => now(),
    ]);
    $user->tenants()->attach($tenant->id, ['role' => 'admin']);

    return [$tenant, $user];
}

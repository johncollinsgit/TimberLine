<?php

use App\Models\FieldServiceJob;
use App\Models\FieldServiceMaterial;
use App\Models\Tenant;
use App\Models\TenantAccessProfile;
use App\Models\TenantModuleEntitlement;
use App\Models\User;
use App\Models\WorkspaceAsset;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

function materialEvidenceFixture(): array
{
    $tenant = Tenant::query()->create(['name' => 'Materials Test Electric', 'slug' => 'materials-test-electric']);
    TenantAccessProfile::query()->create([
        'tenant_id' => $tenant->id,
        'plan_key' => 'base',
        'operating_mode' => 'direct',
        'source' => 'test',
        'metadata' => ['tenant_blueprint' => ['business_template' => 'electrician', 'starter_modules' => ['customers', 'field_service']]],
    ]);
    foreach (['field_service', 'work_core', 'documents'] as $module) {
        TenantModuleEntitlement::query()->create([
            'tenant_id' => $tenant->id,
            'module_key' => $module,
            'availability_status' => 'available',
            'enabled_status' => 'enabled',
            'billing_status' => 'included_in_plan',
            'entitlement_source' => 'test',
            'price_source' => 'catalog',
        ]);
    }
    $member = User::factory()->create(['role' => 'member', 'is_active' => true, 'email_verified_at' => now()]);
    $other = User::factory()->create(['role' => 'member', 'is_active' => true, 'email_verified_at' => now()]);
    $member->tenants()->attach($tenant->id, ['role' => 'member', 'membership_active' => true]);
    $other->tenants()->attach($tenant->id, ['role' => 'member', 'membership_active' => true]);
    $job = FieldServiceJob::query()->create([
        'tenant_id' => $tenant->id,
        'assigned_user_id' => $member->id,
        'title' => 'Panel repair',
        'status' => 'open',
        'operational_status' => 'active',
    ]);
    $material = FieldServiceMaterial::query()->create([
        'tenant_id' => $tenant->id,
        'field_service_job_id' => $job->id,
        'requested_by_user_id' => $member->id,
        'name' => 'Breaker',
        'quantity' => 1,
        'status' => 'needed',
    ]);

    return [$tenant, $member, $other, $job, $material];
}

test('an assigned employee can comment and add a private photo to a material request', function (): void {
    Storage::fake('local');
    [$tenant, $member, , $job, $material] = materialEvidenceFixture();
    Sanctum::actingAs($member, ['mobile:read', 'mobile:write']);
    $base = '/api/mobile/v1/workspaces/'.$tenant->slug.'/field-service/jobs/'.$job->id.'/materials/'.$material->id;

    $this->postJson($base.'/comments', ['body' => 'Need the two-pole model.'])
        ->assertCreated()->assertJsonPath('comment.body', 'Need the two-pole model.');
    $this->post($base.'/photos', ['photos' => [
        UploadedFile::fake()->image('one.jpg', 20, 20),
        UploadedFile::fake()->image('two.jpg', 20, 20),
    ]], ['Accept' => 'application/json'])->assertUnprocessable();
    $this->post($base.'/photos', ['photos' => [UploadedFile::fake()->image('breaker.jpg', 20, 20)]], [
        'Accept' => 'application/json', 'Idempotency-Key' => 'material-photo-1',
    ])->assertCreated()->assertJsonPath('photos.0.mime_type', 'image/jpeg');
    $this->post($base.'/photos', ['photos' => [UploadedFile::fake()->image('breaker.jpg', 20, 20)]], [
        'Accept' => 'application/json', 'Idempotency-Key' => 'material-photo-1',
    ])->assertOk()->assertJsonPath('replayed', true);

    $this->getJson('/api/mobile/v1/workspaces/'.$tenant->slug.'/field-service/jobs/'.$job->id)
        ->assertOk()
        ->assertJsonPath('job.materials.0.comments.0.body', 'Need the two-pole model.')
        ->assertJsonPath('job.materials.0.photos.0.name', 'breaker.jpg');
    expect(WorkspaceAsset::query()->forTenantId($tenant->id)->where('metadata->field_service_material_id', $material->id)->count())->toBe(1);
});

test('material evidence is shared across teammates but remains within its job and tenant', function (): void {
    [$tenant, $member, $other, $job, $material] = materialEvidenceFixture();
    $base = '/api/mobile/v1/workspaces/'.$tenant->slug.'/field-service/jobs/'.$job->id.'/materials/';
    Sanctum::actingAs($other, ['mobile:read', 'mobile:write']);
    $this->postJson($base.$material->id.'/comments', ['body' => 'Team follow-up'])->assertCreated();

    $otherJob = FieldServiceJob::query()->create([
        'tenant_id' => $tenant->id,
        'assigned_user_id' => $member->id,
        'title' => 'Other repair',
        'status' => 'open',
        'operational_status' => 'active',
    ]);
    Sanctum::actingAs($member, ['mobile:read', 'mobile:write']);
    $this->postJson('/api/mobile/v1/workspaces/'.$tenant->slug.'/field-service/jobs/'.$otherJob->id.'/materials/'.$material->id.'/comments', [
        'body' => 'Wrong job',
    ])->assertNotFound();
    $this->postJson('/api/mobile/v1/workspaces/another-tenant/field-service/jobs/'.$job->id.'/materials/'.$material->id.'/comments', [
        'body' => 'Wrong tenant',
    ])->assertNotFound();
});

test('iPhone photo payload attaches to a material request and rejects false MIME types', function (): void {
    Storage::fake('local');
    [$tenant, $member, , $job, $material] = materialEvidenceFixture();
    Sanctum::actingAs($member, ['mobile:read', 'mobile:write']);
    $base = '/api/mobile/v1/workspaces/'.$tenant->slug.'/field-service/jobs/'.$job->id.'/materials/'.$material->id.'/photos/payload';
    $image = UploadedFile::fake()->image('breaker.jpg', 20, 20);
    $encoded = base64_encode((string) file_get_contents($image->getRealPath()));

    $this->postJson($base, ['file_name' => 'breaker.jpg', 'mime_type' => 'image/png', 'contents_base64' => $encoded])
        ->assertUnprocessable();
    $this->postJson($base, ['photos' => [['file_name' => 'breaker.jpg', 'mime_type' => 'image/jpeg', 'contents_base64' => $encoded]]])
        ->assertCreated()->assertJsonPath('photos.0.name', 'breaker.jpg');
});

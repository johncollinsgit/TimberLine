<?php

use App\Models\AiBranchAccount;
use App\Models\FieldServiceJob;
use App\Models\FieldServiceJobNote;
use App\Models\Tenant;
use App\Models\TenantAccessProfile;
use App\Models\TenantAiUsageEvent;
use App\Models\TenantModuleEntitlement;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

function fieldWebFixture(): array
{
    $tenant = Tenant::create(['name' => 'Field Crew', 'slug' => 'field-web-crew']);
    TenantAccessProfile::create(['tenant_id' => $tenant->id, 'plan_key' => 'base', 'operating_mode' => 'direct', 'source' => 'test',
        'metadata' => ['field_crew_assistant' => ['enabled' => true, 'monthly_cap_cents' => 2500]]]);
    foreach (['field_service', 'ai_branch'] as $module) {
        TenantModuleEntitlement::create(['tenant_id' => $tenant->id, 'module_key' => $module, 'availability_status' => 'available', 'enabled_status' => 'enabled', 'entitlement_source' => 'test']);
    }
    $user = User::factory()->create(['is_active' => true, 'email_verified_at' => now(), 'ui_preferences' => ['unrelated' => 'preserved']]);
    $user->tenants()->attach($tenant->id, ['role' => 'member', 'membership_active' => true]);
    Sanctum::actingAs($user, ['mobile:read', 'mobile:write']);

    return [$tenant, $user, '/api/mobile/v1/workspaces/'.$tenant->slug.'/field-service'];
}

test('installed field login keeps its revocable token in a secure http only cookie', function (): void {
    $user = User::factory()->create(['email' => 'field@example.test', 'password' => bcrypt('test-password'), 'email_verified_at' => now(), 'is_active' => true]);
    $response = $this->withHeaders(['X-Everbranch-Field-Web' => '1', 'Origin' => 'http://localhost'])->postJson('/api/mobile/v1/auth/password', ['email' => $user->email, 'password' => 'test-password']);
    $response->assertOk()->assertJsonPath('access_token', 'field-cookie-session');
    $cookie = collect($response->headers->getCookies())->first(fn ($cookie) => $cookie->getName() === 'everbranch_field_session');
    expect($cookie)->not->toBeNull()->and($cookie->isHttpOnly())->toBeTrue()->and($cookie->isSecure())->toBeTrue()->and($cookie->getSameSite())->toBe('strict');
    expect($cookie->getValue())->not->toContain('field-cookie-session');
    $this->withCredentials()->withUnencryptedCookie('everbranch_field_session', $cookie->getValue())->getJson('/api/mobile/v1/workspaces')->assertOk();
    $this->postJson('/api/mobile/v1/auth/logout')->assertOk();
    expect($user->tokens()->count())->toBe(0);
    Illuminate\Support\Facades\Auth::forgetGuards();
    $this->getJson('/api/mobile/v1/workspaces')->assertUnauthorized();
    $this->withHeaders(['X-Everbranch-Field-Web' => '1', 'Origin' => 'https://elsewhere.test'])->postJson('/api/mobile/v1/auth/password', ['email' => $user->email, 'password' => 'test-password'])->assertForbidden();
});

test('personal job ordering is scoped and preserves unrelated preferences and other accounts', function (): void {
    [$tenant, $user, $base] = fieldWebFixture();
    $one = FieldServiceJob::create(['tenant_id' => $tenant->id, 'title' => 'One', 'status' => 'open']);
    $two = FieldServiceJob::create(['tenant_id' => $tenant->id, 'title' => 'Two', 'status' => 'open']);
    $other = Tenant::create(['name' => 'Other', 'slug' => 'other-field-web']);
    $foreign = FieldServiceJob::create(['tenant_id' => $other->id, 'title' => 'Private', 'status' => 'open']);
    $this->putJson($base.'/web/job-order', ['job_ids' => [$two->id, $one->id]])->assertOk();
    $this->getJson($base.'/web/job-order')->assertJsonPath('job_order', [$two->id, $one->id]);
    expect($user->fresh()->ui_preferences['unrelated'])->toBe('preserved');
    $this->putJson($base.'/web/job-order', ['job_ids' => [$foreign->id]])->assertForbidden();
    $this->putJson($base.'/web/job-order', ['job_ids' => [$one->id, $one->id]])->assertUnprocessable();
    $tenant->users()->updateExistingPivot($user->id, ['membership_active' => false]);
    $this->getJson($base.'/web/job-order')->assertNotFound();
});

test('resumable photos retain full pixels and exact bytes and reject disguised images', function (): void {
    [$tenant, $user, $base] = fieldWebFixture();
    Storage::fake('local');
    config(['filesystems.workspace_asset_disk' => 'local']);
    $job = FieldServiceJob::create(['tenant_id' => $tenant->id, 'title' => 'Original photo', 'status' => 'open']);
    $photo = UploadedFile::fake()->image('panel.jpg', 4000, 3000);
    $bytes = file_get_contents($photo->getRealPath());
    foreach ([['panel.jpg', 'image/jpeg', $bytes, true], ['fake.png', 'image/png', '<html>not an image</html>', false]] as [$name, $mime, $contents, $valid]) {
        $upload = $this->withHeader('Idempotency-Key', (string) Str::uuid())->postJson($base.'/uploads/initialize', ['file_name' => $name, 'mime_type' => $mime, 'file_size' => strlen($contents), 'job_id' => $job->id])->assertCreated()->json();
        foreach (str_split($contents, $upload['chunk_size']) as $index => $chunk) {
            $this->putJson($base.'/uploads/'.$upload['upload_id'].'/chunks/'.$index, ['token' => $upload['token'], 'offset' => $index * $upload['chunk_size'], 'contents_base64' => base64_encode($chunk), 'checksum_sha256' => hash('sha256', $chunk)])->assertOk();
        }
        $response = $this->postJson($base.'/uploads/complete', ['token' => $upload['token'], 'checksum_sha256' => hash('sha256', $contents)]);
        if (! $valid) {
            $response->assertUnprocessable();

            continue;
        }
        $response->assertCreated();
        $asset = App\Models\WorkspaceAsset::findOrFail($response->json('asset_id'));
        expect(Storage::disk('local')->get($asset->storage_path))->toBe($contents);
        expect(getimagesize(Storage::disk('local')->path($asset->storage_path))[0])->toBe(4000);
        expect($asset->captured_at)->not->toBeNull();
        $this->withHeaders(['X-Everbranch-Field-Web' => '1', 'Origin' => 'http://localhost'])->get($base.'/assets/'.$asset->id)->assertOk()->assertHeader('Cache-Control', 'no-store, private');
    }
});

function fieldWebAiReady(Tenant $tenant, User $user): void
{
    config(['ai_branch.api_key' => 'test-only', 'ai_branch.rates_valid_until' => now()->addMonth()->toIso8601String()]);
    AiBranchAccount::create(['tenant_id' => $tenant->id, 'enabled' => true, 'budget_cents' => 2500, 'approved_at' => now()]);
    Http::fake(['api.openai.com/v1/responses' => Http::response(['id' => 'resp_test', 'status' => 'completed', 'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => 'Panel installation is scheduled. [job:1]']]]], 'usage' => ['input_tokens' => 400, 'output_tokens' => 30, 'input_tokens_details' => ['cached_tokens' => 0]]])]);
}

test('crew AI uses current team records without private notes or other tenants and retries once only', function (): void {
    [$tenant, $user, $base] = fieldWebFixture();
    fieldWebAiReady($tenant, $user);
    $job = FieldServiceJob::create(['tenant_id' => $tenant->id, 'title' => 'Panel installation', 'status' => 'open']);
    FieldServiceJobNote::create(['tenant_id' => $tenant->id, 'field_service_job_id' => $job->id, 'body' => 'OWNER ONLY SECRET', 'metadata' => ['visibility' => 'owner']]);
    $data = ['question' => 'What is happening with the panel?', 'request_id' => (string) Str::uuid()];
    $this->postJson($base.'/web/assistant', $data)->assertOk()->assertJsonPath('monthly_cap_cents', 2500);
    $this->postJson($base.'/web/assistant', $data)->assertOk();
    Http::assertSentCount(1);
    Http::assertSent(fn ($request) => str_contains(json_encode($request->data()), 'Panel installation') && ! str_contains(json_encode($request->data()), 'OWNER ONLY SECRET'));
    $this->postJson($base.'/web/assistant', [...$data, 'question' => 'Different intent'])->assertConflict();
    $tenant->users()->updateExistingPivot($user->id, ['membership_active' => false]);
    $this->postJson($base.'/web/assistant', $data)->assertNotFound();
    Http::assertSentCount(1);
});

test('shared monthly crew AI budget refuses a reservation before dispatch', function (): void {
    [$tenant, $user, $base] = fieldWebFixture();
    fieldWebAiReady($tenant, $user);
    TenantAiUsageEvent::create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'client_uuid' => (string) Str::uuid(), 'feature' => 'ai_branch_chat', 'context' => 'workspace', 'model' => 'gpt-6-luna', 'status' => 'uncertain', 'duration_seconds' => 0, 'provider_cost_micros' => 25000000, 'buyer_charge_micros' => 0, 'occurred_at' => now()]);
    $this->postJson($base.'/web/assistant', ['question' => 'What is next?', 'request_id' => (string) Str::uuid()])->assertStatus(429);
    Http::assertNothingSent();
});

test('chunked photo work links cannot move a photo to another tenant or another work item', function (): void {
    [$tenant, $user, $base] = fieldWebFixture();
    $job = FieldServiceJob::create(['tenant_id' => $tenant->id, 'title' => 'Photo links', 'status' => 'open']);
    $task = App\Models\FieldServiceTask::create(['tenant_id' => $tenant->id, 'field_service_job_id' => $job->id, 'title' => 'First', 'status' => 'open']);
    $second = App\Models\FieldServiceTask::create(['tenant_id' => $tenant->id, 'field_service_job_id' => $job->id, 'title' => 'Second', 'status' => 'open']);
    $asset = App\Models\WorkspaceAsset::create(['tenant_id' => $tenant->id, 'uploaded_by_user_id' => $user->id, 'source' => 'resumable_upload', 'visibility' => 'team', 'storage_disk' => 'local', 'storage_path' => 'photo.jpg', 'file_name' => 'photo.jpg', 'mime_type' => 'image/jpeg', 'file_size' => 100]);
    $asset->jobs()->attach($job->id, ['tenant_id' => $tenant->id, 'linked_by_user_id' => $user->id]);
    $data = ['asset_id' => $asset->id, 'job_id' => $job->id, 'task_id' => $task->id];
    $this->postJson($base.'/web/photo-links', $data)->assertOk();
    $this->postJson($base.'/web/photo-links', $data)->assertOk();
    $this->postJson($base.'/web/photo-links', [...$data, 'task_id' => $second->id])->assertConflict();
    expect((int) $asset->fresh()->metadata['field_service_task_id'])->toBe($task->id);
    $foreign = Tenant::create(['name' => 'Foreign', 'slug' => 'foreign-links']);
    $foreignJob = FieldServiceJob::create(['tenant_id' => $foreign->id, 'title' => 'Private', 'status' => 'open']);
    $this->postJson($base.'/web/photo-links', [...$data, 'job_id' => $foreignJob->id])->assertNotFound();
});

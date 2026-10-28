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

test('crew navigation opens only current scoped jobs and fixed pages without a provider dispatch', function (): void {
    $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
    [$tenant, $user, $base] = fieldWebFixture();
    fieldWebAiReady($tenant, $user);
    $job = FieldServiceJob::create(['tenant_id' => $tenant->id, 'title' => 'DB-Keys', 'status' => 'open']);
    $foreign = Tenant::create(['name' => 'Other crew', 'slug' => 'navigation-other']);
    $foreignJob = FieldServiceJob::create(['tenant_id' => $foreign->id, 'title' => 'Private school', 'status' => 'open']);
    $ask = fn ($question, $context = null) => $this->postJson($base.'/web/assistant', ['question' => $question, 'request_id' => (string) Str::uuid(), 'context_job_id' => $context]);
    $ask('Open Keys')->assertOk()->assertJsonPath('navigation.id', $job->id)->assertJsonPath('navigation.kind', 'job');
    $ask('Show materials for Keys')->assertOk()->assertJsonPath('navigation.tab', 'materials');
    $ask('Open that job', $job->id)->assertOk()->assertJsonPath('navigation.id', $job->id);
    $ask('Open that job', $foreignJob->id)->assertOk()->assertJsonPath('navigation', null)->assertDontSee('Private school');
    $ask('Open job '.$foreignJob->id)->assertOk()->assertJsonPath('navigation', null);
    $ask('What is scheduled here?', $foreignJob->id)->assertNotFound();
    $ask('Open messages')->assertOk()->assertJsonPath('navigation.page', 'messages');
    $ask('Open Fleet Tracker')->assertOk()->assertJsonPath('navigation', null);
    $ask('Open https://evil.test')->assertOk()->assertJsonPath('navigation', null);
    expect(TenantAiUsageEvent::count())->toBe(0);
    Http::assertNothingSent();
    $tenant->users()->updateExistingPivot($user->id, ['membership_active' => false]);
    $ask('Open Keys')->assertNotFound();
});

test('ambiguous job names need a choice and customer navigation requires current admin authority', function (): void {
    $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
    [$tenant, $user, $base] = fieldWebFixture();
    fieldWebAiReady($tenant, $user);
    foreach (['Keys phase one', 'Keys phase two'] as $title) {
        FieldServiceJob::create(['tenant_id' => $tenant->id, 'title' => $title, 'status' => 'open']);
    }
    $ask = fn ($question) => $this->postJson($base.'/web/assistant', ['question' => $question, 'request_id' => (string) Str::uuid()]);
    $ask('Open Keys')->assertOk()->assertJsonPath('navigation', null)->assertJsonCount(2, 'sources');
    foreach (['member', 'manager'] as $role) {
        $tenant->users()->updateExistingPivot($user->id, ['role' => $role]);
        $ask('Open customers')->assertOk()->assertJsonPath('navigation', null)->assertJsonPath('text', 'Customer records and contact details are admin-only.');
        $ask('What are the customer phone numbers?')->assertOk()->assertJsonCount(0, 'sources');
    }
    $tenant->users()->updateExistingPivot($user->id, ['role' => 'admin']);
    $ask('Open customers')->assertOk()->assertJsonPath('navigation.page', 'customers');
    $tenant->users()->updateExistingPivot($user->id, ['role' => 'member']);
    $ask('Open customers')->assertOk()->assertJsonPath('navigation', null);
    Http::assertNothingSent();
});

test('admin material queue spans jobs and pages with permitted material photos and no foreign requests', function (): void {
    [$tenant, $user, $base] = fieldWebFixture();
    $job = FieldServiceJob::create(['tenant_id' => $tenant->id, 'title' => 'Materials one', 'status' => 'open']);
    $oldJob = FieldServiceJob::create(['tenant_id' => $tenant->id, 'title' => 'Older job', 'status' => 'open', 'archived_at' => now()]);
    for ($i = 0; $i < 55; $i++) {
        $material = \App\Models\FieldServiceMaterial::create(['tenant_id' => $tenant->id, 'field_service_job_id' => $i === 0 ? $oldJob->id : $job->id, 'requested_by_user_id' => $user->id, 'name' => 'Request '.$i, 'quantity' => 2, 'status' => 'needed']);
    }
    $photo = \App\Models\WorkspaceAsset::create(['tenant_id' => $tenant->id, 'uploaded_by_user_id' => $user->id, 'source' => 'test', 'visibility' => 'team', 'storage_disk' => 'local', 'storage_path' => 'material.jpg', 'file_name' => 'Material photo.jpg', 'mime_type' => 'image/jpeg', 'file_size' => 100, 'metadata' => ['field_service_material_id' => $material->id]]);
    $photo->jobs()->attach($job->id, ['tenant_id' => $tenant->id, 'linked_by_user_id' => $user->id]);
    \App\Models\FieldServiceMaterial::create(['tenant_id' => $tenant->id, 'field_service_job_id' => $job->id, 'name' => 'Already purchased', 'quantity' => 1, 'status' => 'purchased']);
    $foreign = Tenant::create(['name' => 'Private material tenant', 'slug' => 'foreign-material-queue']);
    $foreignJob = FieldServiceJob::create(['tenant_id' => $foreign->id, 'title' => 'Private materials job', 'status' => 'open']);
    \App\Models\FieldServiceMaterial::create(['tenant_id' => $foreign->id, 'field_service_job_id' => $foreignJob->id, 'name' => 'Private request', 'quantity' => 1, 'status' => 'needed']);
    $this->getJson($base.'/web/materials')->assertForbidden();
    $tenant->users()->updateExistingPivot($user->id, ['role' => 'manager']);
    $this->getJson($base.'/web/materials')->assertForbidden();
    $tenant->users()->updateExistingPivot($user->id, ['role' => 'admin']);
    $page = $this->getJson($base.'/web/materials')->assertOk()->assertJsonPath('total', 55)->assertJsonCount(50, 'materials')
        ->assertJsonPath('materials.0.photo.id', $photo->id)->assertDontSee('Private request')->assertDontSee('Already purchased')->json();
    $this->getJson($base.'/web/materials?before_id='.$page['next_cursor'])->assertOk()->assertJsonCount(5, 'materials')->assertJsonPath('next_cursor', null)->assertSee('Older job');
    $tenant->users()->updateExistingPivot($user->id, ['role' => 'member']);
    $this->getJson($base.'/web/materials')->assertForbidden();
});

test('AI clock answers use fresh active crew status for managers and self-only status for members', function (): void {
    $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
    [$tenant, $user, $base] = fieldWebFixture();
    fieldWebAiReady($tenant, $user);
    $user->update(['name' => 'Signed-in worker']);
    $colleague = User::factory()->create(['name' => 'Other active worker', 'is_active' => true]);
    $inactive = User::factory()->create(['name' => 'Removed person', 'is_active' => true]);
    $tenant->users()->attach($colleague->id, ['role' => 'member', 'membership_active' => true]);
    $tenant->users()->attach($inactive->id, ['role' => 'member', 'membership_active' => false]);
    foreach ([[$user, 'running'], [$colleague, 'paused'], [$inactive, 'running']] as [$person, $status]) {
        \App\Models\FieldServiceTimeSession::create(['tenant_id' => $tenant->id, 'user_id' => $person->id, 'status' => $status, 'clocked_in_at' => now()->subHour(), 'client_uuid' => (string) Str::uuid(), 'active_user_key' => $person->id]);
    }
    $ask = fn () => $this->postJson($base.'/web/assistant', ['question' => 'Who is clocked in?', 'request_id' => (string) Str::uuid()]);
    $ask()->assertOk()->assertSee('You are clocked in.')->assertDontSee('Other active worker')->assertDontSee('Removed person');
    $tenant->users()->updateExistingPivot($user->id, ['role' => 'admin']);
    $ask()->assertOk()->assertSee('Clocked in: Signed-in worker.')->assertSee('On break: Other active worker.')->assertDontSee('Removed person');
    \App\Models\FieldServiceTimeSession::where('user_id', $user->id)->update(['status' => 'submitted', 'clocked_out_at' => now()]);
    $ask()->assertOk()->assertSee('No one is clocked in and working right now.');
    Http::assertNothingSent();
});

test('AI hours reuse canonical totals, explicit periods and self-only employee scope', function (): void {
    $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
    $this->travelTo(\Carbon\CarbonImmutable::parse('2026-10-28 12:00:00', 'America/New_York'));
    [$tenant, $user, $base] = fieldWebFixture();
    fieldWebAiReady($tenant, $user);
    TenantModuleEntitlement::create(['tenant_id' => $tenant->id, 'module_key' => 'time_tracking', 'availability_status' => 'available', 'enabled_status' => 'enabled', 'entitlement_source' => 'test']);
    \App\Models\FieldServiceReminderSetting::create(['tenant_id' => $tenant->id, 'timezone' => 'America/New_York']);
    $person = User::factory()->create(['name' => 'Andrew Smith', 'is_active' => true]);
    $tenant->users()->attach($person->id, ['role' => 'member', 'membership_active' => true]);
    foreach ([[$user, 120, 'approved'], [$person, 90, 'submitted'], [$person, 600, 'rejected']] as [$worker, $minutes, $status]) {
        \App\Models\FieldServiceTimeEntry::create(['tenant_id' => $tenant->id, 'user_id' => $worker->id, 'work_date' => '2026-10-27', 'started_at' => '08:00:00', 'ended_at' => '10:00:00', 'duration_minutes' => $minutes, 'status' => $status]);
    }
    \App\Models\FieldServiceTimeSession::create(['tenant_id' => $tenant->id, 'user_id' => $person->id, 'status' => 'running', 'clocked_in_at' => now()->subHour(), 'duration_seconds' => 3600, 'client_uuid' => (string) Str::uuid(), 'active_user_key' => $person->id]);
    $foreign = Tenant::create(['name' => 'Other hours', 'slug' => 'other-hours-answer']);
    \App\Models\FieldServiceTimeEntry::create(['tenant_id' => $foreign->id, 'user_id' => $person->id, 'work_date' => '2026-10-27', 'started_at' => '08:00:00', 'ended_at' => '10:00:00', 'duration_minutes' => 999, 'status' => 'approved']);
    $ask = fn ($question, $history = []) => $this->postJson($base.'/web/assistant', ['question' => $question, 'request_id' => (string) Str::uuid(), 'history' => $history]);
    $ask('How many hours did I work this week?')->assertOk()->assertSee('2 hours recorded')->assertDontSee('Andrew');
    $ask('How many hours did Andrew work this week?')->assertOk()->assertSee('own hours')->assertDontSee('1 hour');
    $ask('How many hours were worked by all this week?')->assertOk()->assertSee('own hours');
    $tenant->users()->updateExistingPivot($user->id, ['role' => 'admin']);
    $ask('How many hours did Andrew work this week?')->assertOk()->assertSee('Andrew Smith has 1 hour 30 minutes')->assertSee('2026-10-26 through 2026-11-01')->assertJsonPath('text', fn ($text) => str_contains($text, 'America/New_York'));
    $ask('How many hours were worked by all this week?')->assertOk()->assertSee('3 hours 30 minutes')->assertSee('Active timers are not included.');
    $ask('How many hours did Andrew work?')->assertOk()->assertSee('Which period');
    $ask('this week', [['role' => 'user', 'text' => 'How many hours did Andrew work?'], ['role' => 'assistant', 'text' => 'Which period?']])->assertOk()->assertSee('1 hour 30 minutes');
    $ask('How many hours did Andrew work last week?')->assertOk()->assertSee('0 hours recorded');
    $ask('How many hours did Andrew work from 2026-02-30 through 2026-03-01?')->assertOk()->assertSee('valid start and end dates');
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

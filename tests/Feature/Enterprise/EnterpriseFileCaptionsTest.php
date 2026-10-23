<?php

use App\Enterprise\FileCaptionController;
use App\Enterprise\FileCaptions;
use App\Enterprise\RequestWorkflow;
use App\Enterprise\ResolveWorkspace;
use App\Enterprise\WorkspaceAccess;
use App\Models\TeamOperationRecord;
use App\Services\Team\TeamWorkRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

require_once __DIR__.'/../../Support/TeamFixtures.php';

beforeEach(function (): void {
    config(['team_work.enabled' => true, 'enterprise.runtime' => true, 'enterprise.enabled' => true, 'enterprise.writes_enabled' => true,
        'enterprise.operations_enabled' => true, 'enterprise.collaboration_enabled' => true]);
    Route::middleware(['web', 'auth', 'verified', ResolveWorkspace::class])->prefix('caption-fixture')->group(function (): void {
        Route::get('/{id}', [FileCaptionController::class, 'show'])->name('caption.fixture.show');
        Route::post('/{id}', [FileCaptionController::class, 'save'])->name('caption.fixture.save');
    });
    Route::middleware('web')->prefix('caption-campus-fixture')->group(base_path('routes/enterprise.php'));
    Route::getRoutes()->refreshNameLookups();
    Http::preventStrayRequests();
    Storage::fake('team_private');
});

function captionFixture(string $status = 'ready'): array
{
    [$tenant, $owner] = teamWorkspace();
    config(['enterprise.tenant_allowlist' => [...config('enterprise.tenant_allowlist', []), (string) $tenant->id]]);
    $request = app(RequestWorkflow::class);
    $area = $request->execute($tenant, $owner, ['action' => 'workspace.create', 'id' => (string) Str::uuid(), 'request_id' => (string) Str::uuid(),
        'title' => 'Synthetic caption department', 'visibility' => 'department_only'])['id'];
    $project = $request->execute($tenant, $owner, ['action' => 'project.create', 'id' => (string) Str::uuid(), 'request_id' => (string) Str::uuid(),
        'title' => 'Synthetic caption project', 'area_id' => $area])['id'];
    $task = app(TeamWorkRepository::class)->execute($tenant->id, fn () => app(WorkspaceAccess::class)->actor($tenant, $owner, true),
        ['kind' => 'task.create', 'id' => (string) Str::uuid(), 'idempotency_key' => (string) Str::uuid(), 'title' => 'Synthetic caption task', 'memberships' => [$project => []]])['result'];
    $file = (string) Str::uuid();
    DB::table('team_file_uploads')->insert(['id' => $file, 'tenant_id' => $tenant->id, 'task_id' => $task['id'], 'uploader_user_id' => $owner->id,
        'client_key' => (string) Str::uuid(), 'filename' => 'synthetic.txt', 'mime_type' => 'text/plain', 'expected_size' => 3,
        'sha256' => hash('sha256', 'abc'), 'status' => $status, 'storage_key' => null, 'created_at' => now(), 'updated_at' => now()]);

    return [$tenant, $owner, $area, $task, $file];
}

function captionMember($tenant, string $area, string $role = 'editor')
{
    $user = teamMember($tenant, 'member');
    DB::table('team_area_members')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'area_id' => $area, 'user_id' => $user->id, 'role' => $role]);

    return $user;
}

function captionInput(array $extra = []): array
{
    return $extra + ['caption' => 'Synthetic evidence caption', 'expected_version' => 0, 'request_id' => (string) Str::uuid()];
}

function captionUrl($tenant, string $file): string
{
    return route('caption.fixture.save', ['tenant' => $tenant->slug, 'id' => $file]);
}

test('file captions persist encrypted literal text with exact replay versions and audit without bytes or grants', function (): void {
    [$tenant, $owner, $area, $task, $file] = captionFixture();
    $grants = [DB::table('tenant_user')->count(), DB::table('team_area_members')->count(), DB::table('team_project_members')->count()];
    $url = captionUrl($tenant, $file);
    $response = $this->actingAs($owner)->getJson($url)->assertOk()->assertExactJson(['id' => $file, 'caption' => '', 'version' => 0]);
    expect($response->headers->get('Cache-Control'))->toContain('private', 'no-store');
    expect(TeamOperationRecord::where('kind', FileCaptions::KIND)->count())->toBe(0);
    $input = captionInput(['caption' => '<img src=x onerror=alert(1)> literal Unicode café']);
    $this->postJson($url, $input)->assertOk()->assertJsonPath('version', 1)->assertJsonPath('caption', $input['caption'])->assertJsonPath('replayed', false);
    $this->postJson($url, $input)->assertOk()->assertJsonPath('replayed', true)->assertJsonPath('version', 1);
    $this->postJson($url, array_replace($input, ['caption' => 'Changed retry']))->assertConflict();
    $this->postJson($url, captionInput())->assertConflict();
    $this->postJson($url, captionInput(['caption' => '', 'expected_version' => 1]))->assertOk()->assertJsonPath('version', 2);
    $this->postJson($url, $input)->assertOk()->assertJsonPath('caption', $input['caption'])->assertJsonPath('version', 1)->assertJsonPath('replayed', true);
    $row = TeamOperationRecord::where('kind', FileCaptions::KIND)->sole();
    expect(DB::table('team_operation_records')->where('id', $row->id)->value('data'))->not->toContain($input['caption']);
    expect(DB::table('team_operation_revisions')->where('record_id', $row->id)->count())->toBe(2);
    expect(DB::table('team_operation_attempts')->where('record_id', $row->id)->value('result'))->not->toContain($input['caption']);
    expect(DB::table('team_operation_revisions')->where('record_id', $row->id)->value('payload'))->not->toContain($input['caption']);
    expect(app(FileCaptions::class)->forTask($tenant, $owner, $task['id']))->toBe([['id' => $file, 'caption' => '', 'version' => 2]]);
    expect([DB::table('tenant_user')->count(), DB::table('team_area_members')->count(), DB::table('team_project_members')->count()])->toBe($grants);
    expect(Storage::disk('team_private')->allFiles())->toBe([]);
    Http::assertNothingSent();
});

test('ready captions inherit native visibility while only uploader with current write access may edit or replay', function (): void {
    [$tenant, $owner, $area, $task, $file] = captionFixture();
    $input = captionInput();
    $url = captionUrl($tenant, $file);
    $this->actingAs($owner)->postJson($url, $input)->assertOk();
    $editor = captionMember($tenant, $area);
    $viewer = captionMember($tenant, $area, 'viewer');
    foreach ([$editor, $viewer] as $reader) {
        $this->actingAs($reader)->getJson($url)->assertOk()->assertJsonPath('caption', $input['caption']);
        $this->postJson($url, captionInput(['expected_version' => 1]))->assertNotFound();
    }
    $outsider = teamMember($tenant, 'member');
    $this->actingAs($outsider)->getJson($url)->assertNotFound();
    [$foreignTenant, $foreignOwner] = teamWorkspace();
    config(['enterprise.tenant_allowlist' => [(string) $tenant->id, (string) $foreignTenant->id]]);
    $this->actingAs($foreignOwner)->getJson(captionUrl($foreignTenant, $file))->assertNotFound();
    DB::table('tenant_user')->where(['tenant_id' => $tenant->id, 'user_id' => $owner->id])->update(['membership_active' => false]);
    $this->actingAs($owner)->postJson($url, $input)->assertForbidden();
    $this->getJson($url)->assertForbidden();
});

test('captions are hidden for cancelled rejected uploading deleted and revoked task access including old save receipts', function (): void {
    [$tenant, $owner, $area, $task, $file] = captionFixture('quarantined');
    $input = captionInput();
    $url = captionUrl($tenant, $file);
    $this->actingAs($owner)->postJson($url, $input)->assertOk();
    $editor = captionMember($tenant, $area);
    $this->actingAs($editor)->getJson($url)->assertNotFound();
    expect(app(FileCaptions::class)->forTask($tenant, $editor, $task['id']))->toBe([]);
    foreach (['cancelled', 'rejected', 'uploading'] as $status) {
        DB::table('team_file_uploads')->where('id', $file)->update(['status' => $status]);
        $this->actingAs($owner)->getJson($url)->assertNotFound();
        $this->postJson($url, $input)->assertNotFound();
        expect(app(FileCaptions::class)->forTask($tenant, $owner, $task['id']))->toBe([]);
    }
    DB::table('team_file_uploads')->where('id', $file)->update(['status' => 'ready']);
    app(TeamWorkRepository::class)->execute($tenant->id, fn () => app(WorkspaceAccess::class)->actor($tenant, $owner, true),
        ['kind' => 'task.delete', 'id' => $task['id'], 'idempotency_key' => (string) Str::uuid(), 'expected_version' => $task['version']]);
    $this->getJson($url)->assertNotFound();
    $this->postJson($url, $input)->assertNotFound();
});

test('caption gates validation and frozen native binding fail closed', function (): void {
    [$tenant, $owner, $area, $task, $file] = captionFixture();
    $url = captionUrl($tenant, $file);
    $this->actingAs($owner)->postJson($url, captionInput(['caption' => str_repeat('é', 301)]))->assertUnprocessable();
    $this->postJson($url, captionInput(['caption' => "invalid\0caption"]))->assertUnprocessable();
    $this->postJson($url, captionInput(['expected_version' => -1]))->assertUnprocessable();
    $this->postJson($url, captionInput(['request_id' => 'unsafe']))->assertUnprocessable();
    $input = captionInput();
    $this->postJson($url, $input)->assertOk();
    foreach (['enterprise.operations_enabled', 'enterprise.collaboration_enabled', 'enterprise.runtime'] as $gate) {
        config([$gate => false]);
        $this->getJson($url)->assertNotFound();
        $this->postJson($url, $input)->assertNotFound();
        config([$gate => true]);
    }
    config(['enterprise.writes_enabled' => false]);
    $this->getJson($url)->assertOk();
    $this->postJson($url, $input)->assertForbidden();
    config(['enterprise.writes_enabled' => true]);
    $editor = captionMember($tenant, $area);
    DB::table('team_file_uploads')->where('id', $file)->update(['uploader_user_id' => $editor->id]);
    $this->actingAs($editor)->getJson($url)->assertNotFound();
    $this->postJson($url, captionInput(['expected_version' => 1]))->assertNotFound();
});

test('batched caption search independently checks native tenant department and own quarantine visibility', function (): void {
    [$tenant, $owner, $area, $task, $file] = captionFixture();
    $captions = app(FileCaptions::class);
    $captions->save($tenant, $owner, $file, captionInput());
    $reader = captionMember($tenant, $area);
    $quarantine = (array) DB::table('team_file_uploads')->where('id', $file)->first();
    $quarantine['id'] = (string) Str::uuid();
    $quarantine['client_key'] = (string) Str::uuid();
    $quarantine['status'] = 'quarantined';
    $quarantine['uploader_user_id'] = $reader->id;
    DB::table('team_file_uploads')->insert($quarantine);
    $captions->save($tenant, $reader, $quarantine['id'], captionInput(['caption' => 'Own quarantine evidence']));
    DB::table('team_area_members')->where('user_id', $reader->id)->update(['role' => 'viewer']);
    $hidden = $quarantine;
    $hidden['id'] = (string) Str::uuid();
    $hidden['client_key'] = (string) Str::uuid();
    $hidden['uploader_user_id'] = $owner->id;
    DB::table('team_file_uploads')->insert($hidden);
    // Hidden ciphertext must never be fetched/decrypted by the reader's metadata projection.
    TeamOperationRecord::query()->create(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'area_id' => $area,
        'kind' => FileCaptions::KIND, 'source_key' => hash('sha256', 'ent-file-caption:'.$hidden['id']),
        'visibility' => 'area', 'title' => 'Private file caption', 'status' => 'active', 'version' => 1, 'data' => []]);
    DB::table('team_operation_records')->where('source_key', hash('sha256', 'ent-file-caption:'.$hidden['id']))->update(['data' => 'invalid-unauthorized-ciphertext']);
    [$otherTenant, $otherOwner, $otherArea, $otherTask] = captionFixture();
    $results = collect($captions->searchable($tenant, $reader, [$task['id'], $otherTask['id']]))->keyBy('id');
    expect($results->keys()->all())->toContain($file, $quarantine['id'])->not->toContain($hidden['id']);
    expect($results[$file]['caption'])->toBe('Synthetic evidence caption');
    expect($results[$quarantine['id']]['caption'])->toBe('Own quarantine evidence');
    expect(array_keys($results[$file]))->toBe(['id', 'task_id', 'filename', 'status', 'caption']);
    expect($captions->searchable($otherTenant, $otherOwner, [$task['id']]))->toBe([]);
    DB::table('team_area_members')->where('user_id', $reader->id)->delete();
    expect($captions->searchable($tenant, $reader, [$task['id']]))->toBe([]);
    Http::assertNothingSent();
});

test('batched caption search drops stale moved bindings and exposes no cancelled file or rejected caption', function (): void {
    [$tenant, $owner, $area, $task, $file] = captionFixture();
    $captions = app(FileCaptions::class);
    $captions->save($tenant, $owner, $file, captionInput());
    $moved = app(TeamWorkRepository::class)->execute($tenant->id, fn () => app(WorkspaceAccess::class)->actor($tenant, $owner, true),
        ['kind' => 'task.create', 'id' => (string) Str::uuid(), 'idempotency_key' => (string) Str::uuid(), 'title' => 'Synthetic move target',
            'memberships' => $task['memberships']])['result'];
    DB::table('team_file_uploads')->where('id', $file)->update(['task_id' => $moved['id']]);
    expect($captions->searchable($tenant, $owner, [$task['id']]))->toBe([]);
    expect($captions->searchable($tenant, $owner, [$moved['id']])[0]['caption'])->toBe('');
    DB::table('team_file_uploads')->where('id', $file)->update(['task_id' => $task['id'], 'status' => 'rejected']);
    expect($captions->searchable($tenant, $owner, [$task['id']])[0]['caption'])->toBe('');
    DB::table('team_file_uploads')->where('id', $file)->update(['status' => 'cancelled']);
    expect($captions->searchable($tenant, $owner, [$task['id']]))->toBe([]);
    DB::table('team_file_uploads')->where('id', $file)->update(['status' => 'ready']);
    DB::table('team_operation_records')->where('kind', FileCaptions::KIND)->update(['area_id' => (string) Str::uuid(), 'data' => 'invalid-old-area-ciphertext']);
    expect($captions->searchable($tenant, $owner, [$task['id']])[0]['caption'])->toBe('');
});

test('batched file search limits file task and query counts without loading chunks or bytes', function (): void {
    [$tenant, $owner, $area, $task, $file] = captionFixture();
    app(FileCaptions::class)->save($tenant, $owner, $file, captionInput());
    $tasks = [$task['id']];
    for ($index = 0; $index < 8; $index++) {
        $tasks[] = app(TeamWorkRepository::class)->execute($tenant->id, fn () => app(WorkspaceAccess::class)->actor($tenant, $owner, true),
            ['kind' => 'task.create', 'id' => (string) Str::uuid(), 'idempotency_key' => (string) Str::uuid(), 'title' => 'Synthetic batch task',
                'memberships' => $task['memberships']])['result']['id'];
    }
    $original = (array) DB::table('team_file_uploads')->where('id', $file)->first();
    $files = [];
    for ($index = 0; $index < 1001; $index++) {
        $files[] = array_replace($original, ['id' => (string) Str::uuid(), 'client_key' => (string) Str::uuid(), 'task_id' => $tasks[$index % count($tasks)]]);
    }
    foreach (array_chunk($files, 100) as $batch) {
        DB::table('team_file_uploads')->insert($batch);
    }
    DB::enableQueryLog();
    DB::flushQueryLog();
    try {
        $results = app(FileCaptions::class)->searchable($tenant, $owner, $tasks);
        $queries = collect(DB::getQueryLog())->pluck('query');
    } finally {
        DB::disableQueryLog();
    }
    expect($results)->toHaveCount(1000);
    foreach (['team_tasks', 'team_file_uploads'] as $table) {
        expect($queries->filter(fn ($query) => str_contains($query, 'from "'.$table.'"'))->count())->toBe(1);
    }
    // The fresh actor reads operational graph authority once; captions add one batch.
    expect($queries->filter(fn ($query) => str_contains($query, 'from "team_operation_records"'))->count())->toBe(2);
    expect($queries->filter(fn ($query) => str_contains($query, 'team_file_chunks'))->count())->toBe(0);
    expect(Storage::disk('team_private')->allFiles())->toBe([]);
    expect(fn () => app(FileCaptions::class)->searchable($tenant, $owner, array_fill(0, 1001, $task['id'])))
        ->toThrow(\Illuminate\Validation\ValidationException::class);
});

test('actual campus search endpoint returns permitted captions and filenames with bounded matches and current native visibility', function (): void {
    [$tenant, $owner, $area, $task, $file] = captionFixture();
    DB::table('team_file_uploads')->where('id', $file)->update(['filename' => 'permittedneedle.txt']);
    app(FileCaptions::class)->save($tenant, $owner, $file, captionInput(['caption' => 'captionneedle synthetic evidence']));
    $reader = captionMember($tenant, $area, 'viewer');
    $search = fn (string $q, $scope = null) => route('enterprise.search', ['tenant' => ($scope ?? $tenant)->slug, 'q' => $q]);
    $this->actingAs($reader)->getJson($search('captionneedle'))->assertOk()->assertJsonCount(1, 'items')
        ->assertJsonPath('items.0.kind', 'attachment')->assertJsonPath('items.0.id', $file)->assertJsonPath('items.0.title', 'permittedneedle.txt');
    $this->getJson($search('permittedneedle'))->assertOk()->assertJsonCount(1, 'items')->assertJsonPath('items.0.task_id', $task['id']);
    $quarantine = (array) DB::table('team_file_uploads')->where('id', $file)->first();
    $quarantine = array_replace($quarantine, ['id' => (string) Str::uuid(), 'client_key' => (string) Str::uuid(),
        'filename' => 'hiddenquarantineneedle.txt', 'status' => 'quarantined']);
    DB::table('team_file_uploads')->insert($quarantine);
    app(FileCaptions::class)->save($tenant, $owner, $quarantine['id'], captionInput(['caption' => 'hiddenquarantineneedle caption']));
    $this->getJson($search('hiddenquarantineneedle'))->assertOk()->assertJsonCount(0, 'items');
    [$foreignTenant, $foreignOwner, $foreignArea, $foreignTask, $foreignFile] = captionFixture();
    DB::table('team_file_uploads')->where('id', $foreignFile)->update(['filename' => 'foreigntenantneedle.txt']);
    app(FileCaptions::class)->save($foreignTenant, $foreignOwner, $foreignFile, captionInput(['caption' => 'foreigntenantneedle caption']));
    $this->getJson($search('foreigntenantneedle'))->assertOk()->assertJsonCount(0, 'items');
    $this->actingAs($foreignOwner)->getJson($search('captionneedle', $foreignTenant))->assertOk()->assertJsonCount(0, 'items');
    // Native uploader rebinding cannot carry the prior author's caption into search.
    DB::table('team_file_uploads')->where('id', $file)->update(['uploader_user_id' => $reader->id]);
    $this->actingAs($reader)->getJson($search('captionneedle'))->assertOk()->assertJsonCount(0, 'items');
    DB::table('team_area_members')->where('user_id', $reader->id)->delete();
    $this->getJson($search('permittedneedle'))->assertOk()->assertJsonCount(0, 'items');
    $original = (array) DB::table('team_file_uploads')->where('id', $file)->first();
    $files = [];
    for ($index = 0; $index < 120; $index++) {
        $files[] = array_replace($original, ['id' => (string) Str::uuid(), 'client_key' => (string) Str::uuid(), 'filename' => 'permittedneedle-'.$index.'.txt']);
    }
    DB::table('team_file_uploads')->insert($files);
    DB::enableQueryLog();
    DB::flushQueryLog();
    try {
        $this->actingAs($owner)->getJson($search('permittedneedle'))->assertOk()->assertJsonCount(100, 'items')->assertJsonPath('visible_count', 100);
        expect(collect(DB::getQueryLog())->filter(fn ($query) => str_contains($query['query'], 'from "team_file_uploads"'))->count())->toBe(1);
    } finally {
        DB::disableQueryLog();
    }
    Http::assertNothingSent();
});

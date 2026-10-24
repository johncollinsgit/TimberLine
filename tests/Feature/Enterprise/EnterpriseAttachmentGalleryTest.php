<?php

use App\Models\TeamOperationRecord;
use App\Services\Team\TeamFileScanner;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

require_once __DIR__.'/../../Support/TeamFixtures.php';

beforeEach(function (): void {
    $this->withoutVite();
    config(['team_work.enabled' => true, 'enterprise.runtime' => true, 'enterprise.enabled' => true,
        'enterprise.writes_enabled' => true, 'enterprise.collaboration_enabled' => true]);
    Route::middleware('web')->prefix('gallery-fixture')->group(base_path('routes/enterprise.php'));
    Route::getRoutes()->refreshNameLookups();
    Storage::fake('team_private');
    Http::preventStrayRequests();
});

function galleryCommand($tenant, $owner, array $body)
{
    return test()->actingAs($owner)->postJson(route('enterprise.commands', ['tenant' => $tenant->slug]),
        $body + ['id' => (string) Str::uuid(), 'request_id' => (string) Str::uuid(), 'expected_version' => 0])->assertOk()->json();
}
function galleryFixture(): array
{
    [$tenant, $owner] = teamWorkspace();
    config(['enterprise.tenant_allowlist' => [(string) $tenant->id]]);
    $area = galleryCommand($tenant, $owner, ['action' => 'workspace.create', 'title' => 'Synthetic gallery department', 'visibility' => 'department_only'])['id'];
    $project = galleryCommand($tenant, $owner, ['action' => 'project.create', 'area_id' => $area, 'title' => 'Synthetic gallery project'])['id'];
    $task = test()->postJson(route('enterprise.discussion.open', ['tenant' => $tenant->slug, 'kind' => 'project', 'id' => $project]), [])->assertOk()->json('task_id');

    return [$tenant, $owner, $area, $project, $task];
}
function galleryUpload($tenant, string $task, string $bytes, string $mime = 'image/png', string $filename = 'fixture.png', string $scan = 'clean'): array
{
    app()->instance(TeamFileScanner::class, new class($scan) extends TeamFileScanner
    {
        public function __construct(private string $result) {}

        public function scan(string $privateAbsolutePath, string $mimeType, string $sha256): string
        {
            return $this->result;
        }
    });
    $id = test()->postJson(route('enterprise.files.begin', ['tenant' => $tenant->slug, 'task' => $task]),
        ['client_key' => (string) Str::uuid(), 'filename' => $filename, 'mime_type' => $mime, 'size' => strlen($bytes), 'sha256' => hash('sha256', $bytes)])->assertOk()->json('id');
    foreach (str_split($bytes, 262144) as $index => $part) {
        test()->call('PUT', route('enterprise.files.chunk', ['tenant' => $tenant->slug, 'upload' => $id, 'index' => $index]), [], [], [],
            ['CONTENT_TYPE' => 'application/octet-stream', 'HTTP_ACCEPT' => 'application/json'], $part)->assertOk();
    }

    return test()->postJson(route('enterprise.files.complete', ['tenant' => $tenant->slug, 'upload' => $id]), [])->assertOk()->json();
}
function galleryPreview($tenant, string $project, string $upload, string $kind = 'project'): string
{
    return route('enterprise.discussion.preview', ['tenant' => $tenant->slug, 'kind' => $kind, 'id' => $project, 'upload' => $upload]);
}
function galleryPng(): string
{
    return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jxioAAAAASUVORK5CYII=');
}

test('only scanned ready JPEG and PNG bytes receive a private typed discussion preview while downloads keep their existing contract', function (): void {
    [$tenant, $owner, $area, $project, $task] = galleryFixture();
    foreach (['png' => 'image/png', 'jpg' => 'image/jpeg'] as $extension => $mime) {
        $image = UploadedFile::fake()->image('fixture.jpg', 12, 12);
        $bytes = $extension === 'png' ? galleryPng() : file_get_contents($image->getPathname());
        $file = galleryUpload($tenant, $task, $bytes, $mime, 'fixture.'.$extension);
        expect($file['status'])->toBe('ready');
        $preview = $this->get(galleryPreview($tenant, $project, $file['id']))->assertOk()->assertHeader('Content-Type', $mime)
            ->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('Cross-Origin-Resource-Policy', 'same-origin')
            ->assertHeader('X-Team-Viewer', (string) $owner->id)->assertHeader('X-Team-Workspace', (string) $tenant->id);
        expect($preview->headers->get('Cache-Control'))->toContain('private', 'no-store');
        expect($preview->headers->get('Content-Security-Policy'))->toBe("sandbox; default-src 'none'");
        expect(file_get_contents($preview->baseResponse->getFile()->getPathname()))->toBe($bytes);
        $this->getJson(route('enterprise.discussion', ['tenant' => $tenant->slug, 'kind' => 'project', 'id' => $project]))->assertOk()
            ->assertJsonPath('files.'.($extension === 'png' ? 0 : 1).'.preview_url', galleryPreview($tenant, $project, $file['id']));
        $download = $this->get(route('team.files.download', ['tenant' => $tenant->slug, 'upload' => $file['id']]))->assertOk()->assertHeader('Content-Type', 'application/octet-stream');
        expect($download->headers->get('Content-Disposition'))->toStartWith('attachment;');
    }
});

test('quarantine rejected uploading and nonimage files cannot preview and receive no preview URL', function (): void {
    [$tenant, $owner, $area, $project, $task] = galleryFixture();
    $quarantine = galleryUpload($tenant, $task, galleryPng(), scan: 'unavailable');
    $text = galleryUpload($tenant, $task, 'Synthetic text only', 'text/plain', 'fixture.txt');
    foreach ([$quarantine, $text] as $file) {
        $this->get(galleryPreview($tenant, $project, $file['id']))->assertNotFound();
    }
    $this->getJson(route('enterprise.discussion', ['tenant' => $tenant->slug, 'kind' => 'project', 'id' => $project]))->assertOk()
        ->assertJsonPath('files.0.preview_url', null)->assertJsonPath('files.1.preview_url', null);
    foreach (['uploading', 'rejected', 'cancelled'] as $status) {
        DB::table('team_file_uploads')->where('id', $quarantine['id'])->update(['status' => $status]);
        $this->get(galleryPreview($tenant, $project, $quarantine['id']))->assertNotFound();
    }
});

test('image access is bound to the current canonical discussion task and current department membership', function (): void {
    [$tenant, $owner, $area, $project, $task] = galleryFixture();
    $file = galleryUpload($tenant, $task, galleryPng());
    $other = galleryCommand($tenant, $owner, ['action' => 'project.create', 'area_id' => $area, 'title' => 'Other project'])['id'];
    $this->get(galleryPreview($tenant, $other, $file['id']))->assertNotFound();
    $this->get(galleryPreview($tenant, $task, $file['id'], 'task'))->assertOk();
    $reader = teamMember($tenant, 'member');
    DB::table('team_area_members')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'area_id' => $area, 'user_id' => $reader->id, 'role' => 'viewer']);
    $this->actingAs($reader)->get(galleryPreview($tenant, $project, $file['id']))->assertOk();
    DB::table('team_area_members')->where('user_id', $reader->id)->delete();
    $this->get(galleryPreview($tenant, $project, $file['id']))->assertNotFound();
    $this->actingAs($owner)->withHeaders(['X-Team-Viewer' => (string) $reader->id])->get(galleryPreview($tenant, $project, $file['id']))->assertForbidden();
    $this->flushHeaders();
    config(['enterprise.collaboration_enabled' => false]);
    $this->get(galleryPreview($tenant, $project, $file['id']))->assertNotFound();
});

test('preview refuses changed private bytes and forged image metadata without creating records', function (): void {
    [$tenant, $owner, $area, $project, $task] = galleryFixture();
    $file = galleryUpload($tenant, $task, galleryPng());
    $row = DB::table('team_file_uploads')->where('id', $file['id'])->first();
    $before = TeamOperationRecord::count();
    Storage::disk('team_private')->put($row->storage_key, str_repeat('x', strlen(galleryPng())));
    $this->get(galleryPreview($tenant, $project, $file['id']))->assertStatus(503);
    $text = galleryUpload($tenant, $task, 'Synthetic text only', 'text/plain', 'fixture.txt');
    DB::table('team_file_uploads')->where('id', $text['id'])->update(['mime_type' => 'image/png']);
    $this->get(galleryPreview($tenant, $project, $text['id']))->assertNotFound();
    expect(TeamOperationRecord::count())->toBe($before);
});

test('request preview follows its current assigned canonical task and never an older or neighboring ticket attachment', function (): void {
    [$tenant, $owner, $area, $project, $projectThread] = galleryFixture();
    $tickets = [];
    foreach (['A', 'B'] as $label) {
        $request = galleryCommand($tenant, $owner, ['action' => 'request.create', 'area_id' => $area, 'title' => 'Synthetic ticket '.$label]);
        $request = galleryCommand($tenant, $owner, ['action' => 'request.triage', 'area_id' => $area, 'id' => $request['id'], 'expected_version' => $request['version'], 'note' => 'Synthetic triage']);
        $request = galleryCommand($tenant, $owner, ['action' => 'request.assign', 'area_id' => $area, 'id' => $request['id'], 'expected_version' => $request['version'], 'project_id' => $project, 'assignee_id' => (string) $owner->id]);
        $tickets[] = $request;
    }
    $file = galleryUpload($tenant, $tickets[0]['task']['id'], galleryPng());
    $this->get(galleryPreview($tenant, $tickets[0]['id'], $file['id'], 'request'))->assertOk();
    $this->get(galleryPreview($tenant, $tickets[1]['id'], $file['id'], 'request'))->assertNotFound();
    $this->get(galleryPreview($tenant, $project, $file['id']))->assertNotFound();
    $row = TeamOperationRecord::findOrFail($tickets[0]['id']);
    $row->data = array_replace($row->data, ['task_id' => $tickets[1]['task']['id']]);
    $row->save();
    $this->get(galleryPreview($tenant, $tickets[0]['id'], $file['id'], 'request'))->assertNotFound();
});

test('another tenant and stale workspace identity cannot use an existing image URL', function (): void {
    [$tenant, $owner, $area, $project, $task] = galleryFixture();
    $file = galleryUpload($tenant, $task, galleryPng());
    [$other, $otherOwner] = teamWorkspace();
    config(['enterprise.tenant_allowlist' => [(string) $tenant->id, (string) $other->id]]);
    $this->actingAs($otherOwner)->get(galleryPreview($other, $project, $file['id']))->assertNotFound();
    $this->actingAs($owner)->withHeaders(['X-Team-Workspace' => (string) $other->id])->get(galleryPreview($tenant, $project, $file['id']))->assertForbidden();
});

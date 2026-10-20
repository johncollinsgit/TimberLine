<?php

use App\Models\Tenant;
use App\Models\User;
use App\Services\Team\TeamFileScanner;
use App\Services\Team\TeamFileService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

require_once __DIR__.'/../../Support/TeamFixtures.php';

beforeEach(function (): void {
    $this->withoutVite();
    config(['team_work.enabled' => true]);
    Storage::fake('team_private');
    \Illuminate\Support\Facades\Http::preventStrayRequests();
});

function cleanTeamScanner(): void
{
    app()->instance(TeamFileScanner::class, new class extends TeamFileScanner
    {
        public function scan(string $path, string $mime, string $hash): string
        {
            return 'clean';
        }
    });
}
function beginTeamFile(Tenant $tenant, User $user, string $taskId, string $bytes, array $overrides = [])
{
    return test()->actingAs($user)->postJson(route('team.files.begin', ['tenant' => $tenant->slug, 'task' => $taskId]), array_replace([
        'client_key' => (string) Str::uuid(), 'filename' => 'fictional.txt', 'mime_type' => 'text/plain', 'size' => strlen($bytes), 'sha256' => hash('sha256', $bytes),
    ], $overrides));
}
function sendTeamChunk(Tenant $tenant, User $user, string $id, int $index, string $bytes)
{
    return test()->actingAs($user)->call('PUT', route('team.files.chunk', ['tenant' => $tenant->slug, 'upload' => $id, 'index' => $index]), [], [], [], ['CONTENT_TYPE' => 'application/octet-stream', 'HTTP_ACCEPT' => 'application/json'], $bytes);
}
function finishTeamFile(Tenant $tenant, User $user, string $id)
{
    return test()->actingAs($user)->postJson(route('team.files.complete', ['tenant' => $tenant->slug, 'upload' => $id]));
}

test('real chunk upload resumes deduplicates and publishes one immutable private attachment after a clean scan', function (): void {
    cleanTeamScanner();
    [$tenant, $owner] = teamWorkspace();
    $task = teamTask($tenant, $owner);
    $bytes = str_repeat('Fictional field note. ', 15000);
    $key = (string) Str::uuid();
    $upload = beginTeamFile($tenant, $owner, $task['id'], $bytes, ['client_key' => $key])->assertOk()->json();
    beginTeamFile($tenant, $owner, $task['id'], $bytes, ['client_key' => $key])->assertOk()->assertJsonPath('id', $upload['id']);
    $parts = str_split($bytes, TeamFileService::CHUNK_BYTES);
    sendTeamChunk($tenant, $owner, $upload['id'], 0, $parts[0])->assertOk();
    finishTeamFile($tenant, $owner, $upload['id'])->assertConflict();
    sendTeamChunk($tenant, $owner, $upload['id'], 0, $parts[0])->assertOk()->assertJsonPath('received_bytes', strlen($parts[0]));
    sendTeamChunk($tenant, $owner, $upload['id'], 1, $parts[1])->assertOk();
    finishTeamFile($tenant, $owner, $upload['id'])->assertOk()->assertJsonPath('status', 'ready');
    finishTeamFile($tenant, $owner, $upload['id'])->assertOk()->assertJsonPath('status', 'ready');
    expect(DB::table('team_file_uploads')->count())->toBe(1)->and(DB::table('team_file_chunks')->count())->toBe(2)
        ->and(DB::table('team_work_events')->where('entity_id', $task['id'])->count())->toBe(2)
        ->and(DB::table('team_tasks')->where('id', $task['id'])->value('version'))->toBe(2);
});

test('unavailable scanner keeps full bytes quarantined and no download or attachment event is exposed', function (): void {
    [$tenant, $owner] = teamWorkspace();
    $task = teamTask($tenant, $owner);
    $bytes = 'Fictional retained original.';
    $upload = beginTeamFile($tenant, $owner, $task['id'], $bytes)->assertOk()->json();
    sendTeamChunk($tenant, $owner, $upload['id'], 0, $bytes)->assertOk();
    finishTeamFile($tenant, $owner, $upload['id'])->assertOk()->assertJsonPath('status', 'quarantined')->assertJsonPath('downloadable', false);
    $this->get(route('team.files.download', ['tenant' => $tenant->slug, 'upload' => $upload['id']]))->assertNotFound();
    expect(DB::table('team_work_events')->where('tenant_id', $tenant->id)->count())->toBe(1);
    $row = DB::table('team_file_uploads')->first();
    expect(Storage::disk('team_private')->get($row->storage_key))->toBe($bytes);
});

test('approved bytes download through task ACL only with no public storage key or cacheable preview', function (): void {
    cleanTeamScanner();
    [$tenant, $owner] = teamWorkspace();
    $outsider = teamMember($tenant, 'member');
    $task = teamTask($tenant, $owner);
    $bytes = 'Fictional approved attachment.';
    $upload = beginTeamFile($tenant, $owner, $task['id'], $bytes)->assertOk()->json();
    sendTeamChunk($tenant, $owner, $upload['id'], 0, $bytes)->assertOk();
    finishTeamFile($tenant, $owner, $upload['id'])->assertOk()->assertDontSee('storage_key');
    $download = $this->get(route('team.files.download', ['tenant' => $tenant->slug, 'upload' => $upload['id']]));
    $download->assertOk()->assertDownload('fictional.txt')->assertHeader('X-Content-Type-Options', 'nosniff');
    expect($download->headers->get('Cache-Control'))->toContain('no-store');
    $this->actingAs($outsider)->getJson(route('team.files.index', ['tenant' => $tenant->slug, 'task' => $task['id']]))->assertNotFound();
    $this->get(route('team.files.download', ['tenant' => $tenant->slug, 'upload' => $upload['id']]))->assertNotFound();
    $this->postJson(route('team.files.complete', ['tenant' => $tenant->slug, 'upload' => $upload['id']]))->assertNotFound();
});

test('cross tenant IDs viewer writes and another uploader cannot operate upload sessions', function (): void {
    [$tenant, $owner] = teamWorkspace();
    [$other, $otherOwner] = teamWorkspace();
    $member = teamMember($tenant, 'member');
    $viewer = teamMember($tenant, 'viewer');
    $project = teamProject($tenant, $owner, 'workspace');
    $task = teamTask($tenant, $owner, $project);
    $bytes = 'Fictional bytes.';
    $upload = beginTeamFile($tenant, $owner, $task['id'], $bytes)->assertOk()->json();
    beginTeamFile($tenant, $viewer, $task['id'], $bytes)->assertNotFound();
    sendTeamChunk($tenant, $member, $upload['id'], 0, $bytes)->assertNotFound();
    sendTeamChunk($other, $otherOwner, $upload['id'], 0, $bytes)->assertNotFound();
    $this->actingAs($otherOwner)->get(route('team.files.download', ['tenant' => $other->slug, 'upload' => $upload['id']]))->assertNotFound();
    expect(DB::table('team_file_chunks')->count())->toBe(0);
});

test('same key with changed content and repeated different chunks cannot replace original bytes', function (): void {
    [$tenant, $owner] = teamWorkspace();
    $task = teamTask($tenant, $owner);
    $bytes = 'Original fictional bytes';
    $key = (string) Str::uuid();
    $upload = beginTeamFile($tenant, $owner, $task['id'], $bytes, ['client_key' => $key])->assertOk()->json();
    beginTeamFile($tenant, $owner, $task['id'], str_repeat('x', strlen($bytes)), ['client_key' => $key])->assertConflict();
    sendTeamChunk($tenant, $owner, $upload['id'], 0, $bytes)->assertOk();
    sendTeamChunk($tenant, $owner, $upload['id'], 0, str_repeat('x', strlen($bytes)))->assertConflict();
    expect(DB::table('team_file_chunks')->first()->sha256)->toBe(hash('sha256', $bytes));
});

test('truncated chunk storage can be repaired by identical retry without duplicate records', function (): void {
    cleanTeamScanner();
    [$tenant, $owner] = teamWorkspace();
    $task = teamTask($tenant, $owner);
    $bytes = 'Fictional resumable content.';
    $upload = beginTeamFile($tenant, $owner, $task['id'], $bytes)->assertOk()->json();
    sendTeamChunk($tenant, $owner, $upload['id'], 0, $bytes)->assertOk();
    Storage::disk('team_private')->put('uploads/'.$tenant->id.'/'.$upload['id'].'/chunks/0', 'partial');
    finishTeamFile($tenant, $owner, $upload['id'])->assertConflict();
    sendTeamChunk($tenant, $owner, $upload['id'], 0, $bytes)->assertOk();
    finishTeamFile($tenant, $owner, $upload['id'])->assertOk()->assertJsonPath('status', 'ready');
    expect(DB::table('team_file_chunks')->count())->toBe(1);
});

test('cancellation is replay safe and frees only the owned generated upload directory', function (): void {
    [$tenant, $owner] = teamWorkspace();
    $task = teamTask($tenant, $owner);
    $bytes = 'Fictional cancelled content.';
    Storage::disk('team_private')->put('unrelated-preserved', 'keep');
    $upload = beginTeamFile($tenant, $owner, $task['id'], $bytes)->assertOk()->json();
    sendTeamChunk($tenant, $owner, $upload['id'], 0, $bytes)->assertOk();
    $route = route('team.files.cancel', ['tenant' => $tenant->slug, 'upload' => $upload['id']]);
    $this->deleteJson($route)->assertOk()->assertJsonPath('status', 'cancelled');
    $this->deleteJson($route)->assertOk();
    expect(Storage::disk('team_private')->get('unrelated-preserved'))->toBe('keep')->and(Storage::disk('team_private')->exists('uploads/'.$tenant->id.'/'.$upload['id'].'/chunks/0'))->toBeFalse();
});

test('filename traversal oversized files quota limits and invalid chunk sizes fail before accepted storage', function (): void {
    [$tenant, $owner] = teamWorkspace();
    $task = teamTask($tenant, $owner);
    $bytes = 'Fictional validation.';
    foreach (['../notes.txt', 'folder\\notes.txt', "bad\r\nname.txt"] as $filename) {
        beginTeamFile($tenant, $owner, $task['id'], $bytes, ['filename' => $filename])->assertUnprocessable();
    }
    beginTeamFile($tenant, $owner, $task['id'], $bytes, ['size' => config('team_work.file_max_bytes') + 1])->assertUnprocessable();
    config(['team_work.file_tenant_quota_bytes' => 1]);
    beginTeamFile($tenant, $owner, $task['id'], $bytes)->assertUnprocessable();
    config(['team_work.file_tenant_quota_bytes' => 64000000]);
    $upload = beginTeamFile($tenant, $owner, $task['id'], $bytes)->assertOk()->json();
    sendTeamChunk($tenant, $owner, $upload['id'], 0, 'short')->assertUnprocessable();
    sendTeamChunk($tenant, $owner, $upload['id'], 9, $bytes)->assertUnprocessable();
    expect(DB::table('team_file_chunks')->count())->toBe(0);
});

test('MIME mismatch checksum mismatch and scanner rejection never publish bytes', function (): void {
    cleanTeamScanner();
    [$tenant, $owner] = teamWorkspace();
    $task = teamTask($tenant, $owner);
    $bytes = 'Fictional plain text.';
    $mismatch = beginTeamFile($tenant, $owner, $task['id'], $bytes, ['mime_type' => 'image/png', 'filename' => 'pretend.png'])->assertOk()->json();
    sendTeamChunk($tenant, $owner, $mismatch['id'], 0, $bytes)->assertOk();
    finishTeamFile($tenant, $owner, $mismatch['id'])->assertOk()->assertJsonPath('status', 'rejected');
    $checksum = beginTeamFile($tenant, $owner, $task['id'], $bytes, ['sha256' => str_repeat('0', 64)])->assertOk()->json();
    sendTeamChunk($tenant, $owner, $checksum['id'], 0, $bytes)->assertOk();
    finishTeamFile($tenant, $owner, $checksum['id'])->assertUnprocessable();
    app()->instance(TeamFileScanner::class, new class extends TeamFileScanner
    {
        public function scan(string $p, string $m, string $h): string
        {
            return 'rejected';
        }
    });
    $rejected = beginTeamFile($tenant, $owner, $task['id'], $bytes)->assertOk()->json();
    sendTeamChunk($tenant, $owner, $rejected['id'], 0, $bytes)->assertOk();
    finishTeamFile($tenant, $owner, $rejected['id'])->assertOk()->assertJsonPath('status', 'rejected');
    expect(DB::table('team_work_events')->where('tenant_id', $tenant->id)->count())->toBe(1);
});

test('scanner errors and source mutation quarantine or reject rather than invent clean verification', function (): void {
    [$tenant, $owner] = teamWorkspace();
    $task = teamTask($tenant, $owner);
    $bytes = 'Fictional scanner test.';
    app()->instance(TeamFileScanner::class, new class extends TeamFileScanner
    {
        public function scan(string $p, string $m, string $h): string
        {
            throw new RuntimeException('private scanner error');
        }
    });
    $upload = beginTeamFile($tenant, $owner, $task['id'], $bytes)->assertOk()->json();
    sendTeamChunk($tenant, $owner, $upload['id'], 0, $bytes)->assertOk();
    finishTeamFile($tenant, $owner, $upload['id'])->assertOk()->assertJsonPath('status', 'quarantined')->assertDontSee('private scanner error');
    app()->instance(TeamFileScanner::class, new class extends TeamFileScanner
    {
        public function scan(string $p, string $m, string $h): string
        {
            file_put_contents($p, 'mutated');

            return 'clean';
        }
    });
    finishTeamFile($tenant, $owner, $upload['id'])->assertOk()->assertJsonPath('status', 'rejected');
});

test('access removal during scanning cannot publish an attachment or preserve download authority', function (): void {
    [$tenant, $owner] = teamWorkspace();
    $member = teamMember($tenant, 'member');
    $project = teamProject($tenant, $owner, 'workspace');
    $task = teamTask($tenant, $owner, $project);
    $bytes = 'Fictional revoked upload.';
    $upload = beginTeamFile($tenant, $member, $task['id'], $bytes)->assertOk()->json();
    sendTeamChunk($tenant, $member, $upload['id'], 0, $bytes)->assertOk();
    app()->instance(TeamFileScanner::class, new class($tenant->id, $member->id) extends TeamFileScanner
    {
        public function __construct(private int $tenantId, private int $userId) {}

        public function scan(string $p, string $m, string $h): string
        {
            DB::table('tenant_user')->where('tenant_id', $this->tenantId)->where('user_id', $this->userId)->update(['membership_active' => false]);

            return 'clean';
        }
    });
    finishTeamFile($tenant, $member, $upload['id'])->assertForbidden();
    expect(DB::table('team_file_uploads')->where('id', $upload['id'])->value('status'))->toBe('uploading')->and(DB::table('team_tasks')->where('id', $task['id'])->value('version'))->toBe(1);
});

test('ready file download rechecks revoked private sharing and rejects later stored byte tampering', function (): void {
    cleanTeamScanner();
    [$tenant, $owner] = teamWorkspace();
    $guest = teamMember($tenant, 'guest');
    $project = teamProject($tenant, $owner);
    teamPost($tenant, $owner, ['kind' => 'project.member', 'id' => $project['id'], 'expected_version' => 1, 'user_id' => $guest->id, 'access' => 'viewer'])->assertOk();
    $task = teamTask($tenant, $owner, $project);
    $bytes = 'Fictional protected bytes.';
    $upload = beginTeamFile($tenant, $owner, $task['id'], $bytes)->assertOk()->json();
    sendTeamChunk($tenant, $owner, $upload['id'], 0, $bytes)->assertOk();
    finishTeamFile($tenant, $owner, $upload['id'])->assertOk();
    $this->actingAs($guest)->get(route('team.files.download', ['tenant' => $tenant->slug, 'upload' => $upload['id']]))->assertOk();
    teamPost($tenant, $owner, ['kind' => 'project.member', 'id' => $project['id'], 'expected_version' => 2, 'user_id' => $guest->id, 'access' => null])->assertOk();
    $this->actingAs($guest)->getJson(route('team.files.download', ['tenant' => $tenant->slug, 'upload' => $upload['id']]))->assertNotFound();
    $row = DB::table('team_file_uploads')->where('id', $upload['id'])->first();
    Storage::disk('team_private')->put($row->storage_key, str_repeat('x', strlen($bytes)));
    $this->actingAs($owner)->getJson(route('team.files.download', ['tenant' => $tenant->slug, 'upload' => $upload['id']]))->assertStatus(503)->assertDontSee($bytes);
});

test('empty upload cancellation releases bounded active reservations without creating filesystem paths', function (): void {
    [$tenant, $owner] = teamWorkspace();
    $task = teamTask($tenant, $owner);
    $bytes = 'Fictional reservation.';
    $uploads = [];
    for ($i = 0; $i < 3; $i++) {
        $uploads[] = beginTeamFile($tenant, $owner, $task['id'], $bytes)->assertOk()->json();
    }
    beginTeamFile($tenant, $owner, $task['id'], $bytes)->assertUnprocessable();
    $this->deleteJson(route('team.files.cancel', ['tenant' => $tenant->slug, 'upload' => $uploads[0]['id']]))->assertOk();
    beginTeamFile($tenant, $owner, $task['id'], $bytes)->assertOk();
    expect(Storage::disk('team_private')->allFiles())->toBe([]);
});

test('clients cannot forge scan acceptance through generic task commands or filename extensions', function (): void {
    [$tenant, $owner] = teamWorkspace();
    $task = teamTask($tenant, $owner);
    teamPost($tenant, $owner, ['kind' => 'task.attachment', 'id' => $task['id'], 'expected_version' => 1, 'attachment_id' => (string) Str::uuid()])->assertUnprocessable();
    beginTeamFile($tenant, $owner, $task['id'], 'Fictional script text.', ['filename' => 'run.ps1', 'status' => 'ready'])->assertUnprocessable();
    expect(DB::table('team_file_uploads')->count())->toBe(0);
});

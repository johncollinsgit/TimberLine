<?php

namespace Tests\Feature\Proposals;

use App\Models\TeamOperationRecord;
use App\Proposals\ProposalService;
use App\Proposals\ReviewerWorkspace;
use App\Proposals\ReviewerWorkspaceAttachmentBlobs;
use App\Proposals\ReviewerWorkspaceAttachmentsAccess;
use App\Proposals\ReviewerWorkspaceAttachmentsStore;
use App\Proposals\ReviewerWorkspaceFiles;
use App\Proposals\ReviewerWorkspaceFilesController;
use App\Proposals\ReviewerWorkspaceSchema;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\Support\ReviewerWorkspaceFixture;

/** Actual private fixture DB/filesystem, fictional image only; never a real upload, scan, hosted or provider acceptance. */
final class ReviewerWorkspaceFilesTest extends ReviewerWorkspaceFixture
{
    protected function setUp(): void
    {
        parent::setUp();
        $comments = ['schema_version' => 1, 'comments_enabled' => true, 'uploads_enabled' => false, 'write_personas' => ['demo_manager', 'manager', 'worker', 'requester']];
        $files = ['schema_version' => 1, 'uploads_enabled' => true, 'metadata_read_enabled' => true, 'scan_enabled' => false, 'write_personas' => ['demo_manager', 'manager', 'worker', 'requester']];
        $ch = ReviewerWorkspaceSchema::digest($comments);
        $fh = ReviewerWorkspaceSchema::digest($files);
        config(['service_proposals.reviewer_workspace.collaboration' => ['enabled' => true, 'comments_write_enabled' => false, 'policies' => [$ch => $comments]],
            'service_proposals.reviewer_workspace.attachments' => ['enabled' => true, 'write_enabled' => true, 'policies' => [$fh => $files]]]);
        $draft = app(ProposalService::class)->leesMcRaeDraft($this->tenant, $this->owner->id);
        $payload = $draft->payload;
        $payload['review_workspace'] = $this->version->payload['review_workspace'];
        foreach (['collaboration' => $ch, 'attachments' => $fh] as $kind => $hash) {
            $payload['review_workspace_'.$kind] = ['schema_version' => 1, 'policy_sha256' => $hash, 'expires_at' => now()->addDays(5)->utc()->format('Y-m-d\TH:i:s\Z')];
        }
        $draft->payload = $payload;
        $draft->save();
        $draft->refresh();
        $published = app(ProposalService::class)->publish($this->tenant, $this->owner->id, $draft->id, $draft->revision,
            'FICTIONAL_RECIPIENT_PASSWORD', 7, (string) Str::uuid(), 'reviewer');
        $this->version = $published['version'];
        $this->token = $published['token'];
        $this->initialize();
        Storage::forgetDisk('team_private');
    }

    private function reader(): Request
    {
        $r = $this->recipient();
        $r->session()->put('service_proposal_name.'.$this->version->id, $this->version->payload['reviewer_names'][0]);

        return $r;
    }

    private function base(array $replace = []): array
    {
        return $replace + ['binding' => $this->binding(), 'selection' => $this->selection(), 'target' => ['kind' => 'ticket', 'id' => $this->ids['incoming']]];
    }

    private function png(): string
    {
        // Genuine locally decoded synthetic fixture; never labelled as a clean scan or a real reviewer upload.
        $image = imagecreatetruecolor(1, 1);
        ob_start();
        imagepng($image);
        $bytes = ob_get_clean();
        unset($image);

        return $bytes;
    }

    private function beginBody(Request $r, string $bytes, array $replace = []): array
    {
        return $this->base($replace + ['request_id' => (string) Str::uuid(), 'expected_target_version' => 1,
            'expected_reviewer_name' => $r->session()->get('service_proposal_name.'.$this->version->id),
            'name' => 'FICTIONAL PHOTO.png', 'size' => strlen($bytes), 'mime' => 'image/png', 'sha256' => hash('sha256', $bytes)]);
    }

    private function upload(Request $r, string $bytes, array $replace = []): array
    {
        $svc = app(ReviewerWorkspaceFiles::class);
        $b = $this->beginBody($r, $bytes, $replace);
        $started = $svc->begin($r, $this->token, $b);
        $id = $started['file']['id'];
        foreach (str_split($bytes, ReviewerWorkspaceAttachmentsStore::CHUNK_BYTES) as $i => $part) {
            $svc->chunk($r, $this->token, $this->base(['upload_id' => $id, 'index' => $i, 'bytes' => base64_encode($part), 'sha256' => hash('sha256', $part)]));
        }

        return [$b, $id];
    }

    private function denied(callable $f, int $status): void
    {
        try {
            $f();
            $this->fail('Unsafe attachment operation admitted');
        } catch (HttpExceptionInterface $e) {
            $this->assertSame($status, $e->getStatusCode());
        }
    }

    private function current(Request $r): array
    {
        $b = $this->base();

        return app(ReviewerWorkspaceAttachmentsAccess::class)->resolve($r, $this->token, $b['binding'], $b['selection'], $b['target']);
    }

    public function test_actual_persisted_chunks_and_two_session_reload_remain_encrypted_quarantined_never_ready_or_readable(): void
    {
        $one = $this->reader();
        $two = $this->reader();
        $svc = app(ReviewerWorkspaceFiles::class);
        $graph = TeamOperationRecord::find($this->version->payload['review_workspace']['workspace_id'])->getRawOriginal('data');
        $ledger = $this->ledger->getRawOriginal('data');
        [$body, $id] = $this->upload($one, $this->png());
        $done = $svc->complete($one, $this->token, $this->base(['upload_id' => $id]));
        $this->assertSame('quarantined', $done['status']);
        $this->assertFalse($done['file']['preview_available']);
        $this->assertFalse($done['file']['download_available']);
        $this->assertSame('not_admitted', $done['file']['scan_status']);
        $again = $svc->complete($one, $this->token, $this->base(['upload_id' => $id]));
        $this->assertSame($done, $again);
        $shown = $svc->show($two, $this->token, $this->base());
        $this->assertCount(1, $shown['files']);
        $this->assertFalse($shown['files'][0]['mine']);
        $this->assertSame('quarantined', $shown['files'][0]['status']);
        $row = TeamOperationRecord::find($id);
        $this->assertStringNotContainsString('FICTIONAL PHOTO', $row->getRawOriginal('data'));
        $this->assertStringNotContainsString(base64_encode($this->png()), $row->getRawOriginal('data'));
        $bytes = app(ReviewerWorkspaceAttachmentBlobs::class)->read($row, 'original');
        $this->assertSame($this->png(), $bytes);
        foreach (Storage::disk('team_private')->allFiles('proposal-review-workspace') as $file) {
            $raw = Storage::disk('team_private')->get($file);
            $this->assertStringNotContainsString(base64_encode($this->png()), $raw);
            $this->assertStringNotContainsString($this->png(), $raw);
        }
        $this->assertSame($graph, TeamOperationRecord::find($this->version->payload['review_workspace']['workspace_id'])->getRawOriginal('data'));
        $this->assertSame($ledger, $this->ledger->fresh()->getRawOriginal('data'));
        $this->assertSame(0, DB::table('team_tasks')->count());
        Http::assertNothingSent();
    }

    public function test_lost_begin_recovers_once_and_changed_uuid_or_body_cannot_replace_unknown_upload(): void
    {
        $r = $this->reader();
        $svc = app(ReviewerWorkspaceFiles::class);
        $b = $this->beginBody($r, $this->png());
        $first = $svc->begin($r, $this->token, $b);
        $this->assertTrue($svc->begin($r, $this->token, $b)['replayed']);
        $status = $svc->status($r, $this->token, $this->base(['request_id' => $b['request_id'], 'digest' => ReviewerWorkspaceSchema::digest($b)]));
        $this->assertTrue($status['settled']);
        $this->assertSame($first['file'], $status['file']);
        $this->denied(fn () => $svc->begin($r, $this->token, [...$b, 'request_id' => (string) Str::uuid()]), 409);
        $this->denied(fn () => $svc->begin($r, $this->token, [...$b, 'name' => 'changed.png']), 409);
        $this->denied(fn () => $svc->begin($r, $this->token, [...$b, 'request_id' => (string) Str::uuid(), 'name' => 'renamed.png']), 409);
        $new = $svc->status($r, $this->token, $this->base(['request_id' => (string) Str::uuid(), 'digest' => str_repeat('a', 64)]));
        $this->assertSame(['status' => 'not_observed', 'settled' => false], $new);
        $this->assertSame(1, TeamOperationRecord::where('kind', ReviewerWorkspaceAttachmentsStore::KIND)->count());
    }

    public function test_other_capability_hidden_scope_archived_parent_and_readonly_persona_cannot_mutate_or_claim_files(): void
    {
        $r = $this->reader();
        $other = $this->reader();
        $svc = app(ReviewerWorkspaceFiles::class);
        $b = $this->beginBody($r, $this->png(), ['target' => ['kind' => 'ticket', 'id' => $this->ids['ticket']]]);
        $id = $svc->begin($r, $this->token, $b)['file']['id'];
        $this->denied(fn () => $svc->cancel($other, $this->token, $this->base(['target' => $b['target'], 'upload_id' => $id])), 404);
        $this->denied(fn () => $svc->begin($r, $this->token, $this->beginBody($r, $this->png(), ['selection' => $this->selection('executive')])), 403);
        $this->denied(fn () => $svc->begin($r, $this->token, $this->beginBody($r, $this->png(), ['target' => ['kind' => 'project', 'id' => $this->ids['other_project']]])), 404);
        app(ReviewerWorkspace::class)->command($r, $this->token, $this->command(['action' => 'project.archive', 'payload' => ['archived' => true]]));
        foreach ([$this->selection(), $this->selection('worker', 'p1')] as $selection) {
            $this->denied(fn () => $svc->begin($r, $this->token, [...$b, 'selection' => $selection, 'request_id' => (string) Str::uuid()]), 403);
        }
        $this->assertSame('cancelled', $svc->cancel($r, $this->token, $this->base(['target' => $b['target'], 'upload_id' => $id]))['status']);
    }

    public function test_disabled_zero_sql_or_bytes_unapproved_descriptor_scan_policy_and_expiry_fail_closed(): void
    {
        $r = $this->reader();
        $svc = app(ReviewerWorkspaceFiles::class);
        $b = $this->beginBody($r, $this->png());
        config(['service_proposals.reviewer_workspace.attachments.enabled' => false]);
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->denied(fn () => $svc->begin($r, $this->token, $b), 404);
        // Store context must not run before the default-off attachment gate.
        $this->assertSame([], DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertSame([], Storage::disk('team_private')->allFiles());
        config(['service_proposals.reviewer_workspace.attachments.enabled' => true]);
        $hash = $this->version->payload['review_workspace_attachments']['policy_sha256'];
        config(['service_proposals.reviewer_workspace.attachments.policies.'.$hash.'.scan_enabled' => true]);
        $this->denied(fn () => $svc->show($r, $this->token, $this->base()), 503);
        config(['service_proposals.reviewer_workspace.attachments.policies.'.$hash.'.scan_enabled' => false]);
        $this->travel(6)->days();
        $this->denied(fn () => $svc->show($r, $this->token, $this->base()), 403);
        Http::assertNothingSent();
    }

    public function test_chunk_replay_is_exact_invalid_bounds_cannot_claim_and_cancellation_retires_late_writers(): void
    {
        $r = $this->reader();
        $svc = app(ReviewerWorkspaceFiles::class);
        $b = $this->beginBody($r, $this->png());
        $id = $svc->begin($r, $this->token, $b)['file']['id'];
        $chunk = $this->base(['upload_id' => $id, 'index' => 0, 'bytes' => base64_encode($this->png()), 'sha256' => hash('sha256', $this->png())]);
        $this->denied(fn () => $svc->chunk($r, $this->token, [...$chunk, 'index' => 19]), 422);
        $this->assertNull(TeamOperationRecord::find($id)->data['payload']['claim']);
        $one = $svc->chunk($r, $this->token, $chunk);
        $two = $svc->chunk($r, $this->token, $chunk);
        $this->assertSame($one['file']['received'], $two['file']['received']);
        $this->assertCount(1, $two['file']['received']);
        $bad = str_repeat('a', strlen($this->png()));
        $this->denied(fn () => $svc->chunk($r, $this->token, [...$chunk, 'bytes' => base64_encode($bad), 'sha256' => hash('sha256', $bad)]), 409);
        $row = TeamOperationRecord::find($id);
        $cancelled = $svc->cancel($r, $this->token, $this->base(['upload_id' => $id]));
        $this->assertSame('cancelled', $cancelled['status']);
        $this->denied(fn () => app(ReviewerWorkspaceAttachmentBlobs::class)->put($row, 'chunk-0', $this->png()), 409);
        $this->assertSame($cancelled, $svc->cancel($r, $this->token, $this->base(['upload_id' => $id])));
        $this->assertFalse($svc->status($this->reader(), $this->token, $this->base(['request_id' => $b['request_id'], 'digest' => ReviewerWorkspaceSchema::digest($b)]))['settled']);
        $this->assertSame([], $svc->show($r, $this->token, $this->base())['files']);
    }

    public function test_full_hash_mime_extension_and_image_dimension_checks_never_produce_a_ready_state(): void
    {
        $r = $this->reader();
        $svc = app(ReviewerWorkspaceFiles::class);
        $this->denied(fn () => $svc->begin($r, $this->token, $this->beginBody($r, $this->png(), ['name' => '../unsafe.png'])), 422);
        $this->denied(fn () => $svc->begin($r, $this->token, $this->beginBody($r, $this->png(), ['name' => 'script.html'])), 422);
        [, $id] = $this->upload($r, 'this is not an image', ['name' => 'bad.png']);
        $this->denied(fn () => $svc->complete($r, $this->token, $this->base(['upload_id' => $id])), 422);
        $this->assertSame('assembling', TeamOperationRecord::find($id)->status);
        $huge = substr_replace($this->png(), pack('N', 17000000), 16, 4);
        [, $hugeId] = $this->upload($r, $huge, ['name' => 'huge.png']);
        $this->denied(fn () => $svc->complete($r, $this->token, $this->base(['upload_id' => $hugeId])), 422);
        [, $hashId] = $this->upload($r, $this->png(), ['name' => 'wrong-hash.png', 'sha256' => str_repeat('a', 64)]);
        $this->denied(fn () => $svc->complete($r, $this->token, $this->base(['upload_id' => $hashId])), 422);
        $this->assertSame(0, TeamOperationRecord::where('kind', ReviewerWorkspaceAttachmentsStore::KIND)->where('status', 'ready')->count());
        Http::assertNothingSent();
    }

    public function test_incomplete_assembly_and_chunk_claim_crash_recovery_only_replays_exact_intent_after_lease(): void
    {
        $r = $this->reader();
        $svc = app(ReviewerWorkspaceFiles::class);
        $b = $this->beginBody($r, $this->png());
        $id = $svc->begin($r, $this->token, $b)['file']['id'];
        $this->denied(fn () => $svc->complete($r, $this->token, $this->base(['upload_id' => $id])), 409);
        $this->assertNull(TeamOperationRecord::find($id)->data['payload']['claim']);
        $row = TeamOperationRecord::find($id);
        $data = $row->data;
        $data['payload']['claim'] = ['kind' => 'chunk', 'index' => 0, 'sha256' => hash('sha256', $this->png()), 'nonce' => str_repeat('a', 64), 'until' => now()->addSeconds(90)->utc()->format('Y-m-d\TH:i:s\Z')];
        $row->data = $data;
        $row->save();
        $chunk = $this->base(['upload_id' => $id, 'index' => 0, 'bytes' => base64_encode($this->png()), 'sha256' => hash('sha256', $this->png())]);
        $this->denied(fn () => $svc->chunk($r, $this->token, $chunk), 409);
        $this->travel(91)->seconds();
        $bad = str_repeat('a', strlen($this->png()));
        $this->denied(fn () => $svc->chunk($r, $this->token, [...$chunk, 'bytes' => base64_encode($bad), 'sha256' => hash('sha256', $bad)]), 409);
        $this->assertSame('uploading', $svc->chunk($r, $this->token, $chunk)['status']);
        $this->assertSame('quarantined', $svc->complete($r, $this->token, $this->base(['upload_id' => $id]))['status']);
    }

    public function test_admission_limits_are_reserved_before_bytes_and_no_public_or_symlink_disk_is_accepted(): void
    {
        $r = $this->reader();
        $svc = app(ReviewerWorkspaceFiles::class);
        for ($i = 0; $i < 3; $i++) {
            $svc->begin($r, $this->token, $this->beginBody($r, $this->png().$i, ['name' => 'file-'.$i.'.png']));
        }
        $this->denied(fn () => $svc->begin($r, $this->token, $this->beginBody($r, $this->png().'fourth', ['name' => 'fourth.png'])), 429);
        $this->assertSame([], Storage::disk('team_private')->allFiles());
        $row = TeamOperationRecord::where('kind', ReviewerWorkspaceAttachmentsStore::KIND)->first();
        config(['filesystems.disks.team_private.visibility' => 'public']);
        $this->denied(fn () => app(ReviewerWorkspaceAttachmentBlobs::class)->put($row, 'chunk-0', $this->png()), 503);
        config(['filesystems.disks.team_private.visibility' => 'private']);
        $path = Storage::disk('team_private')->path('proposal-review-workspace');
        symlink($this->runtime.'/storage/logs', $path);
        try {
            $this->denied(fn () => app(ReviewerWorkspaceAttachmentBlobs::class)->put($row, 'chunk-0', $this->png()), 503);
        } finally {
            unlink($path);
        }
    }

    public function test_controller_enforces_csrf_origin_bounded_json_no_raw_error_and_does_not_use_small_ai_body_limit(): void
    {
        $r = $this->reader();
        $body = $this->beginBody($r, $this->png());
        $raw = json_encode($body, JSON_THROW_ON_ERROR);
        $request = Request::create('https://proposal.example.invalid/enterprise-owner/service-proposals/'.$this->token.'/campus/workspace/files/begin', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ORIGIN' => 'https://proposal.example.invalid',
            'SCRIPT_NAME' => '/enterprise-owner/index.php', 'PHP_SELF' => '/enterprise-owner/index.php', 'SCRIPT_FILENAME' => '/FICTIONAL/index.php', 'HTTPS' => 'on'], $raw);
        $request->setLaravelSession($r->session());
        $c = app(ReviewerWorkspaceFilesController::class);
        $this->assertSame(419, $c->begin($request, $this->token)->status());
        $request->headers->set('X-CSRF-TOKEN', $r->session()->token());
        $response = $c->begin($request, $this->token);
        $this->assertSame(200, $response->status());
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $request->headers->set('Origin', 'https://attacker.example.invalid');
        $error = $c->begin($request, $this->token);
        $this->assertSame(403, $error->status());
        $this->assertSame(['error', 'automatic_retry'], array_keys($error->getData(true)));
        Http::assertNothingSent();
    }

    public function test_new_file_routes_keep_session_lock_and_outer_precontroller_errors_private_without_widening_other_paths(): void
    {
        foreach (['show', 'begin', 'status', 'chunk', 'complete', 'cancel'] as $action) {
            $route = app('router')->getRoutes()->getByName('service-proposals.recipient.workspace.files.'.$action);
            $this->assertSame(10, $route->locksFor());
            $this->assertSame(10, $route->waitsFor());
            foreach ([405, 419, 429, 503] as $status) {
                $r = Request::create('https://proposal.example.invalid/enterprise-owner/service-proposals/FICTIONAL/campus/work%73pace/files/'.$action.'/', 'GET');
                $out = (new \App\Http\Middleware\ReviewerAiBoundary)->handle($r, fn () => throw new \Symfony\Component\HttpKernel\Exception\HttpException($status, 'PRIVATE SENTINEL'));
                $this->assertSame($status, $out->getStatusCode());
                $this->assertStringNotContainsString('SENTINEL', $out->getContent());
                $this->assertStringContainsString('no-store', $out->headers->get('Cache-Control'));
            }
        }
        $r = Request::create('https://proposal.example.invalid/enterprise-owner/service-proposals/FICTIONAL/campus/workspace/files/preview', 'GET');
        $out = (new \App\Http\Middleware\ReviewerAiBoundary)->handle($r, fn () => response('UNRELATED SENTINEL', 418));
        $this->assertSame(418, $out->getStatusCode());
        $this->assertSame('UNRELATED SENTINEL', $out->getContent());
    }

    public function test_full_chunk_passes_closed_controller_body_limit_and_larger_or_extra_input_is_rejected(): void
    {
        $r = $this->reader();
        $svc = app(ReviewerWorkspaceFiles::class);
        $bytes = str_pad($this->png(), ReviewerWorkspaceAttachmentsStore::CHUNK_BYTES + 17, 'x');
        $id = $svc->begin($r, $this->token, $this->beginBody($r, $bytes))['file']['id'];
        $part = substr($bytes, 0, ReviewerWorkspaceAttachmentsStore::CHUNK_BYTES);
        $body = $this->base(['upload_id' => $id, 'index' => 0, 'bytes' => base64_encode($part), 'sha256' => hash('sha256', $part)]);
        $create = function ($input) use ($r) {
            $q = Request::create('https://proposal.example.invalid/enterprise-owner/service-proposals/'.$this->token.'/campus/workspace/files/chunk', 'POST', server: [
                'CONTENT_TYPE' => 'application/json', 'HTTP_ORIGIN' => 'https://proposal.example.invalid',
                'SCRIPT_NAME' => '/enterprise-owner/index.php', 'PHP_SELF' => '/enterprise-owner/index.php', 'SCRIPT_FILENAME' => '/FICTIONAL/index.php', 'HTTPS' => 'on'], content: json_encode($input));
            $q->setLaravelSession($r->session());
            $q->headers->set('X-CSRF-TOKEN', $r->session()->token());

            return $q;
        };
        $controller = app(ReviewerWorkspaceFilesController::class);
        $this->assertGreaterThan(65536, strlen($create($body)->getContent()));
        $this->assertSame(200, $controller->chunk($create($body), $this->token)->getStatusCode());
        $this->assertSame(422, $controller->chunk($create([...$body, 'url' => 'https://example.invalid']), $this->token)->getStatusCode());
        $this->assertSame(400, $controller->chunk($create([...$body, 'bytes' => str_repeat('x', 384 * 1024)]), $this->token)->getStatusCode());
        $this->assertCount(1, TeamOperationRecord::find($id)->data['payload']['chunks']);
    }

    public function test_owner_expiry_cleanup_is_dry_run_first_works_with_feature_off_and_retains_replay_tombstone(): void
    {
        $r = $this->reader();
        [$begin, $id] = $this->upload($r, $this->png());
        app(ReviewerWorkspaceFiles::class)->complete($r, $this->token, $this->base(['upload_id' => $id]));
        $owner = $this->request(path: '/owner-workspaces/'.$this->tenant->slug.'/fixture', owner: true);
        $prune = app(\App\Proposals\ReviewerWorkspaceFilesPruner::class);
        $this->assertSame(0, $prune->prune($owner)['eligible']);
        $this->version->revoked_at = now();
        $this->version->save();
        config(['service_proposals.reviewer_workspace.enabled' => false, 'service_proposals.reviewer_workspace.attachments.enabled' => false]);
        $preview = $prune->prune($owner);
        $this->assertSame(1, $preview['eligible']);
        $this->assertSame(0, $preview['erased']);
        $this->assertSame('quarantined', TeamOperationRecord::find($id)->status);
        $done = $prune->prune($owner, dryRun: false);
        $this->assertSame(1, $done['erased']);
        $this->assertSame(0, $done['failed']);
        $row = TeamOperationRecord::find($id);
        $this->assertSame('expired', $row->status);
        $this->assertSame('Expired attachment', $row->data['payload']['name']);
        $this->assertSame([], $row->data['payload']['chunks']);
        $this->assertSame($begin['request_id'], $row->data['payload']['request_id']);
        $this->assertSame(0, $prune->prune($owner, dryRun: false)['erased']);
        $this->assertTrue(collect(Storage::disk('team_private')->allFiles('proposal-review-workspace'))->every(fn ($f) => str_ends_with($f, '.retired') || str_ends_with($f, '.quota.lock')));
        $anonymous = $this->request(path: '/owner-workspaces/'.$this->tenant->slug.'/fixture', owner: true);
        $anonymous->setUserResolver(fn () => null);
        $this->denied(fn () => $prune->prune($anonymous, dryRun: false), 403);
        Http::assertNothingSent();
    }

    public function test_target_six_file_and_workspace_fifty_mib_reservations_include_all_unfinished_bytes(): void
    {
        $svc = app(ReviewerWorkspaceFiles::class);
        $first = null;
        for ($i = 0; $i < 10; $i++) {
            $r = $this->reader();
            $target = $i < 5 ? ['kind' => 'ticket', 'id' => $this->ids['incoming']] : ['kind' => 'task', 'id' => $this->ids['task']];
            $result = $svc->begin($r, $this->token, $this->beginBody($r, $this->png(), ['target' => $target, 'size' => 5 * 1024 * 1024, 'sha256' => hash('sha256', 'fictional declared bytes-'.$i)]));
            $first ??= [$r, $result['file']['id']];
        }
        $r = $this->reader();
        $this->denied(fn () => $svc->begin($r, $this->token, $this->beginBody($r, $this->png())), 429);
        $this->assertSame(10, TeamOperationRecord::where('kind', ReviewerWorkspaceAttachmentsStore::KIND)->count());
        $this->assertSame([], Storage::disk('team_private')->allFiles());
        // Cancelling a proven reservation releases its logical allocation only after retirement succeeds.
        $this->assertSame('cancelled', $svc->cancel($first[0], $this->token, $this->base(['upload_id' => $first[1]]))['status']);
        for ($i = 0; $i < 2; $i++) {
            $fresh = $this->reader();
            $svc->begin($fresh, $this->token, $this->beginBody($fresh, $this->png()));
        }
        $other = $this->reader();
        $this->denied(fn () => $svc->begin($other, $this->token, $this->beginBody($other, $this->png())), 429);
        $this->assertSame(12, TeamOperationRecord::where('kind', ReviewerWorkspaceAttachmentsStore::KIND)->count());
    }

    public function test_missing_blob_read_is_non_creating_cipher_tamper_refuses_and_physical_cap_counts_encryption_overhead(): void
    {
        $r = $this->reader();
        $svc = app(ReviewerWorkspaceFiles::class);
        $id = $svc->begin($r, $this->token, $this->beginBody($r, $this->png()))['file']['id'];
        $row = TeamOperationRecord::find($id);
        $blobs = app(ReviewerWorkspaceAttachmentBlobs::class);
        $this->denied(fn () => $blobs->read($row, 'chunk-0'), 503);
        $this->assertSame([], Storage::disk('team_private')->allDirectories());
        $blobs->put($row, 'chunk-0', $this->png());
        $chunk = collect(Storage::disk('team_private')->allFiles())->first(fn ($f) => str_ends_with($f, '/chunk-0'));
        Storage::disk('team_private')->put($chunk, 'CIPHER TAMPER');
        $this->denied(fn () => $blobs->read($row, 'chunk-0'), 503);
        $quota = Storage::disk('team_private')->path('proposal-review-workspace/fictional-quota-filler');
        $handle = fopen($quota, 'w');
        ftruncate($handle, 64 * 1024 * 1024 - 32);
        fclose($handle);
        $this->denied(fn () => $blobs->put($row, 'chunk-0', $this->png()), 429);
        unlink($quota);
    }

    public function test_cleanup_failure_preserves_reserved_state_and_retries_after_lease_without_touching_symlink_target(): void
    {
        $r = $this->reader();
        [, $id] = $this->upload($r, $this->png());
        app(ReviewerWorkspaceFiles::class)->complete($r, $this->token, $this->base(['upload_id' => $id]));
        $original = collect(Storage::disk('team_private')->allFiles())->first(fn ($f) => str_ends_with($f, '/original'));
        $path = Storage::disk('team_private')->path($original);
        $cipher = file_get_contents($path);
        unlink($path);
        $sentinel = $this->runtime.'/storage/logs/FICTIONAL-SENTINEL';
        file_put_contents($sentinel, 'UNCHANGED SYNTHETIC FILE');
        symlink($sentinel, $path);
        $this->version->revoked_at = now();
        $this->version->save();
        $owner = $this->request(path: '/owner-workspaces/'.$this->tenant->slug.'/fixture', owner: true);
        $prune = app(\App\Proposals\ReviewerWorkspaceFilesPruner::class);
        $out = $prune->prune($owner, dryRun: false);
        $this->assertSame(1, $out['failed']);
        $this->assertSame(0, $out['erased']);
        $this->assertSame('cleanup_pending', TeamOperationRecord::find($id)->status);
        $this->assertSame(strlen($this->png()), TeamOperationRecord::find($id)->data['payload']['size']);
        $this->assertSame('UNCHANGED SYNTHETIC FILE', file_get_contents($sentinel));
        unlink($path);
        file_put_contents($path, $cipher);
        $this->travel(91)->seconds();
        $this->assertSame(1, $prune->prune($owner, dryRun: false)['erased']);
        $this->assertSame('UNCHANGED SYNTHETIC FILE', file_get_contents($sentinel));
    }

    public function test_revocation_and_exact_current_target_version_rechecked_before_any_chunk_or_final_read(): void
    {
        $r = $this->reader();
        $svc = app(ReviewerWorkspaceFiles::class);
        $id = $svc->begin($r, $this->token, $this->beginBody($r, $this->png()))['file']['id'];
        app(ReviewerWorkspace::class)->command($r, $this->token, $this->command(['action' => 'ticket.reorder', 'record_id' => $this->ids['incoming'],
            'payload' => ['position' => 1]]));
        $chunk = $this->base(['upload_id' => $id, 'index' => 0, 'bytes' => base64_encode($this->png()), 'sha256' => hash('sha256', $this->png())]);
        $this->denied(fn () => $svc->chunk($r, $this->token, $chunk), 409);
        $this->assertSame([], Storage::disk('team_private')->allFiles());
        $this->version->revoked_at = now();
        $this->version->save();
        $this->denied(fn () => $svc->show($r, $this->token, $this->base()), 410);
        $this->denied(fn () => $svc->chunk($r, $this->token, $chunk), 410);
        $this->assertNull(TeamOperationRecord::find($id)->data['payload']['claim']);
    }

    public function test_target_object_key_order_cannot_hide_persisted_file_or_break_exact_replay_recovery_and_cancel(): void
    {
        $r = $this->reader();
        $svc = app(ReviewerWorkspaceFiles::class);
        [$begin, $id] = $this->upload($r, $this->png());
        $svc->complete($r, $this->token, $this->base(['upload_id' => $id]));
        $reversed = ['id' => $this->ids['incoming'], 'kind' => 'ticket'];
        $shown = $svc->show($r, $this->token, $this->base(['target' => $reversed]));
        $this->assertCount(1, $shown['files']);
        $this->assertSame($id, $shown['files'][0]['id']);
        $replay = $svc->begin($r, $this->token, [...$begin, 'target' => $reversed]);
        $this->assertTrue($replay['replayed']);
        $this->assertSame($id, $replay['file']['id']);
        $status = $svc->status($r, $this->token, $this->base(['target' => $reversed, 'request_id' => $begin['request_id'], 'digest' => ReviewerWorkspaceSchema::digest($begin)]));
        $this->assertTrue($status['settled']);
        $this->assertSame($id, $status['file']['id']);
        $this->assertSame('quarantined', $svc->complete($r, $this->token, $this->base(['target' => $reversed, 'upload_id' => $id]))['status']);
        $this->assertSame('cancelled', $svc->cancel($r, $this->token, $this->base(['target' => $reversed, 'upload_id' => $id]))['status']);
        $this->assertSame(1, TeamOperationRecord::where('kind', ReviewerWorkspaceAttachmentsStore::KIND)->count());
    }

    public function test_target_key_order_cannot_evade_six_completed_files_quota_or_commit_a_seventh_reservation(): void
    {
        $r = $this->reader();
        $svc = app(ReviewerWorkspaceFiles::class);
        for ($i = 0; $i < 6; $i++) {
            [, $id] = $this->upload($r, $this->png().$i, ['name' => 'FILE-'.$i.'.png']);
            $svc->complete($r, $this->token, $this->base(['upload_id' => $id]));
        }
        $before = DB::table('team_operation_records')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        $reversed = ['id' => $this->ids['incoming'], 'kind' => 'ticket'];
        $this->denied(fn () => $svc->begin($r, $this->token, $this->beginBody($r, $this->png().'seventh', ['target' => $reversed, 'name' => 'SEVENTH.png'])), 429);
        $this->assertSame($before, DB::table('team_operation_records')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all());
        $this->assertSame(6, TeamOperationRecord::where('kind', ReviewerWorkspaceAttachmentsStore::KIND)->count());
    }

    public function test_target_identity_accepts_only_closed_typed_exact_identity_independent_of_object_key_order(): void
    {
        $target = ['kind' => 'ticket', 'id' => $this->ids['incoming']];
        $this->assertTrue(ReviewerWorkspaceSchema::sameTarget($target, array_reverse($target, true)));
        foreach ([null, false, '', [], ['kind' => 'ticket'], ['id' => $this->ids['incoming']],
            ['kind' => 'ticket', 'id' => $this->ids['incoming'], 'extra' => null],
            ['kind' => 'ticket', 'id' => 1], ['kind' => true, 'id' => $this->ids['incoming']],
            ['kind' => 'Ticket', 'id' => $this->ids['incoming']], ['kind' => 'project', 'id' => $this->ids['incoming']],
            ['kind' => 'ticket', 'id' => $this->ids['ticket']], ['kind' => 'ticket', 'id' => 'invalid']] as $invalid) {
            $this->assertFalse(ReviewerWorkspaceSchema::sameTarget($target, $invalid));
            $this->assertFalse(ReviewerWorkspaceSchema::sameTarget($invalid, $target));
        }
    }

    public function test_reordered_target_chunks_bind_same_row_but_foreign_identity_cannot_read_or_mutate_it(): void
    {
        $r = $this->reader();
        $svc = app(ReviewerWorkspaceFiles::class);
        $bytes = $this->png();
        $started = $svc->begin($r, $this->token, $this->beginBody($r, $bytes));
        $id = $started['file']['id'];
        $part = $this->base(['target' => ['id' => $this->ids['incoming'], 'kind' => 'ticket'],
            'upload_id' => $id, 'index' => 0, 'bytes' => base64_encode($bytes), 'sha256' => hash('sha256', $bytes)]);
        $first = $svc->chunk($r, $this->token, $part);
        $again = $svc->chunk($r, $this->token, $part);
        $this->assertSame($first['file']['id'], $again['file']['id']);
        $this->assertSame($first['file']['received'], $again['file']['received']);
        $before = TeamOperationRecord::find($id)->getRawOriginal('data');
        $foreign = [...$part, 'target' => ['id' => $this->ids['ticket'], 'kind' => 'ticket']];
        $this->denied(fn () => $svc->chunk($r, $this->token, $foreign), 404);
        $this->denied(fn () => $svc->complete($r, $this->token, $this->base(['target' => $foreign['target'], 'upload_id' => $id])), 404);
        $this->denied(fn () => $svc->cancel($r, $this->token, $this->base(['target' => $foreign['target'], 'upload_id' => $id])), 404);
        $this->assertSame($before, TeamOperationRecord::find($id)->getRawOriginal('data'));
        $this->assertSame('quarantined', $svc->complete($r, $this->token, $this->base(['target' => $part['target'], 'upload_id' => $id]))['status']);
    }

    public function test_independent_mixed_order_multi_client_quota_and_foreign_target_isolation(): void
    {
        $one = $this->reader();
        $two = $this->reader();
        $svc = app(ReviewerWorkspaceFiles::class);
        $target = ['kind' => 'ticket', 'id' => $this->ids['incoming']];
        $reversed = array_reverse($target, true);
        for ($i = 0; $i < 6; $i++) {
            $reader = $i % 2 === 0 ? $one : $two;
            [, $id] = $this->upload($reader, $this->png().':independent-'.$i,
                ['target' => $i % 2 === 0 ? $target : $reversed, 'name' => 'Fictional mixed '.$i.'.png']);
            $svc->complete($reader, $this->token, $this->base(['target' => $i % 2 === 0 ? $reversed : $target, 'upload_id' => $id]));
        }
        $before = DB::table('team_operation_records')->orderBy('id')->get()->toJson();
        $this->denied(fn () => $svc->begin($two, $this->token,
            $this->beginBody($two, $this->png().':seventh', ['target' => $target])), 429);
        $this->denied(fn () => $svc->begin($one, $this->token,
            $this->beginBody($one, $this->png().':eighth', ['target' => $reversed])), 429);
        $this->assertSame($before, DB::table('team_operation_records')->orderBy('id')->get()->toJson());
        $this->assertCount(6, $svc->show($one, $this->token, $this->base(['target' => $target]))['files']);
        $this->assertCount(6, $svc->show($two, $this->token, $this->base(['target' => $reversed]))['files']);
        $other = ['id' => $this->ids['ticket'], 'kind' => 'ticket'];
        $otherBytes = $this->png().':independent-other';
        $otherBegin = $svc->begin($two, $this->token, $this->beginBody($two, $otherBytes, ['target' => $other]));
        $otherId = $otherBegin['file']['id'];
        $svc->chunk($two, $this->token, $this->base(['target' => $other, 'upload_id' => $otherId, 'index' => 0,
            'bytes' => base64_encode($otherBytes), 'sha256' => hash('sha256', $otherBytes)]));
        $svc->complete($two, $this->token, $this->base(['target' => array_reverse($other, true), 'upload_id' => $otherId]));
        $this->assertCount(1, $svc->show($one, $this->token, $this->base(['target' => $other]))['files']);
        $this->assertCount(6, $svc->show($two, $this->token, $this->base(['target' => $target]))['files']);
    }
}

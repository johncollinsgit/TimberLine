<?php

namespace Tests\Feature\Proposals;

use App\Models\TeamOperationRecord;
use App\Proposals\ReviewerWorkspaceAttachmentBlobs;
use App\Proposals\ReviewerWorkspaceFiles;
use App\Proposals\ReviewerWorkspaceImages;
use App\Proposals\ReviewerWorkspaceImagesController;
use App\Proposals\ReviewerWorkspaceImagesScan;
use App\Proposals\ReviewerWorkspaceImagesStore;
use App\Proposals\ReviewerWorkspaceScannerEvidence;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\Support\ReviewerWorkspaceImagesFixture;

/** Every clean/evidence verdict is FAKE; this is source authority/lifecycle proof, NOT real scanner or live readiness. */
final class ReviewerWorkspaceImagesTest extends ReviewerWorkspaceImagesFixture
{
    private function denied(callable $f, int $status): void
    {
        try {
            $f();
            $this->fail('Unsafe image access admitted');
        } catch (HttpExceptionInterface $e) {
            $this->assertSame($status, $e->getStatusCode());
        }
    }

    private function scan(string $id, ?array $intent = null, bool $dryRun = false): array
    {
        return app(ReviewerWorkspaceImagesScan::class)->scan($this->ownerRequest(), $this->version->id, $id, $intent ?? $this->intent($id), $dryRun);
    }

    private function metadata(Request $r): array
    {
        return app(ReviewerWorkspaceImages::class)->metadata($r, $this->token, $this->base());
    }

    private function binaryInput(Request $r): array
    {
        $file = $this->metadata($r)['files'][0];

        return $this->base() + ['upload_id' => $file['id'], 'attestation_id' => $file['attestation_id'], 'attestation_revision' => $file['attestation_revision']];
    }

    public function test_default_off_has_zero_sql_or_scan_and_owner_preview_never_dispatches(): void
    {
        $r = $this->reader();
        $id = $this->upload($r);
        $intent = $this->intent($id);
        $out = app(ReviewerWorkspaceImagesScan::class)->scan($this->ownerRequest(), $this->version->id, $id, $intent);
        $this->assertTrue($out['dry_run']);
        $this->assertFalse($out['will_dispatch']);
        $this->assertSame(0, $this->fakeEvidence->calls);
        $this->assertSame(0, $this->fakeScanner->calls);
        $this->assertSame(0, TeamOperationRecord::where('kind', ReviewerWorkspaceImagesStore::KIND)->count());
        config(['service_proposals.reviewer_workspace.image_access.enabled' => false]);
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->denied(fn () => $this->metadata($r), 404);
        $this->assertSame([], DB::getQueryLog());
        DB::disableQueryLog();
        config(['service_proposals.reviewer_workspace.image_access.enabled' => true, 'service_proposals.reviewer_workspace.image_access.scan_enabled' => false]);
        $this->denied(fn () => $this->scan($id, $intent), 403);
        $this->assertSame(0, $this->fakeEvidence->calls);
        Http::assertNothingSent();
    }

    public function test_fake_full_evidence_and_fake_clean_create_only_synthetic_attestation_and_private_derivative_without_changing_quarantine_or_ledger(): void
    {
        $r = $this->reader();
        $bytes = $this->png(1000, 500);
        $id = $this->upload($r, $bytes);
        $rawUpload = TeamOperationRecord::find($id)->getRawOriginal('data');
        $ledger = $this->ledger->getRawOriginal('data');
        $before = $this->metadata($r);
        $this->assertSame('held', $before['files'][0]['state']);
        $out = $this->scan($id);
        $this->assertSame('clean', $out['status']);
        $shown = $this->metadata($this->reader());
        $file = $shown['files'][0];
        $this->assertSame('ready', $file['state']);
        $this->assertTrue($file['preview_available']);
        $this->assertSame(320, $file['thumbnail']['width']);
        $this->assertSame(160, $file['thumbnail']['height']);
        $this->assertSame(2, $this->fakeEvidence->calls);
        $this->assertSame(1, $this->fakeScanner->calls);
        $this->assertSame([['transaction_level' => 0, 'permissions' => 0600, 'exact_bytes' => true]], $this->fakeScanner->observations);
        $this->assertSame('quarantined', TeamOperationRecord::find($id)->status);
        $this->assertSame($rawUpload, TeamOperationRecord::find($id)->getRawOriginal('data'));
        $this->assertSame($ledger, $this->ledger->fresh()->getRawOriginal('data'));
        $this->assertSame(0, DB::table('team_tasks')->count());
        $input = $this->binaryInput($r);
        $full = app(ReviewerWorkspaceImages::class)->binary($r, $this->token, $input, 'preview');
        $thumb = app(ReviewerWorkspaceImages::class)->binary($r, $this->token, $input, 'thumbnail');
        $this->assertSame($bytes, $full['bytes']);
        $this->assertSame(hash('sha256', $thumb['bytes']), $thumb['sha256']);
        $this->assertLessThanOrEqual(262144, strlen($thumb['bytes']));
        $this->assertFalse(collect(Storage::disk('team_private')->allFiles())->contains(fn ($p) => str_contains($p, '/scan-')));
        Http::assertNothingSent();
    }

    public function test_status_only_clean_or_missing_authentic_engine_definitions_can_never_become_ready(): void
    {
        $r = $this->reader();
        $id = $this->upload($r);
        $this->fakeEvidence->replies = [null];
        $this->assertSame('unavailable', $this->scan($id)['status']);
        $this->assertSame(0, $this->fakeScanner->calls);
        $this->assertSame('held', $this->metadata($r)['files'][0]['state']);
        $row = TeamOperationRecord::where('kind', ReviewerWorkspaceImagesStore::KIND)->sole();
        $this->assertNull($row->data['payload']['evidence']);
        $this->assertNull($row->data['payload']['thumbnail']);
        $this->denied(fn () => app(ReviewerWorkspaceImages::class)->binary($r, $this->token,
            $this->base() + ['upload_id' => $id, 'attestation_id' => $row->id, 'attestation_revision' => $row->version], 'preview'), 404);
    }

    public function test_changed_engine_definition_reply_across_fake_scan_remains_held(): void
    {
        $r = $this->reader();
        $id = $this->upload($r);
        $first = $this->fakeEvidence->defaultReply;
        $this->fakeEvidence->replies = [$first, str_replace('/123456/', '/123457/', $first)];
        $this->assertSame('unavailable', $this->scan($id)['status']);
        $this->assertSame(1, $this->fakeScanner->calls);
        $this->assertFalse($this->metadata($r)['files'][0]['preview_available']);
    }

    public function test_fake_rejected_and_unavailable_do_not_create_readable_binary_and_rejected_cannot_replacement_retry(): void
    {
        $r = $this->reader();
        $id = $this->upload($r);
        $this->fakeScanner->result = 'rejected';
        $intent = $this->intent($id);
        $this->assertSame('rejected', $this->scan($id, $intent)['status']);
        $this->assertSame('held', $this->metadata($r)['files'][0]['state']);
        $this->travel(301)->seconds();
        $next = $this->intent($id);
        $next['expected_attestation_revision'] = 2;
        $this->denied(fn () => $this->scan($id, $next), 409);
        $this->assertSame(1, $this->fakeScanner->calls);
    }

    public function test_once_only_replay_and_wrong_revisions_or_new_uuid_do_not_dispatch_another_fake_scan(): void
    {
        $r = $this->reader();
        $id = $this->upload($r);
        $intent = $this->intent($id);
        $one = $this->scan($id, $intent);
        $two = $this->scan($id, $intent);
        $this->assertTrue($two['replayed']);
        $this->assertSame($one['attestation_id'], $two['attestation_id']);
        $this->assertSame(1, $this->fakeScanner->calls);
        $changed = [...$intent, 'expected_target_version' => 2];
        $this->denied(fn () => $this->scan($id, $changed), 409);
        $new = [...$intent, 'request_id' => (string) Str::uuid(), 'expected_attestation_revision' => 2];
        $this->denied(fn () => $this->scan($id, $new), 409);
        $this->assertSame(1, $this->fakeScanner->calls);
    }

    public function test_recipient_is_not_a_scan_owner_and_scan_service_has_no_recipient_route(): void
    {
        $r = $this->reader();
        $id = $this->upload($r);
        $owner = $this->ownerRequest();
        $owner->setUserResolver(fn () => null);
        $this->denied(fn () => app(ReviewerWorkspaceImagesScan::class)->scan($owner, $this->version->id, $id, $this->intent($id), false), 403);
        $this->assertNull(app('router')->getRoutes()->getByName('service-proposals.recipient.workspace.images.scan'));
        $this->assertSame(0, $this->fakeScanner->calls);
    }

    public function test_unsupported_version_reply_engine_stale_or_future_database_never_admits_evidence(): void
    {
        $e = new ReviewerWorkspaceScannerEvidence;
        $hash = $this->version->payload['review_workspace_images']['policy_sha256'];
        $policy = config('service_proposals.reviewer_workspace.image_access.policies.'.$hash);
        $config = config('service_proposals.reviewer_workspace.image_access.scanners.'.$policy['scan_config_sha256']);
        foreach ([null, 'stream: OK', 'COMMAND UNAVAILABLE', str_replace('9.9.9', '9.9.8', $this->fakeEvidence->defaultReply),
            'ClamAV 9.9.9/123456/'.now()->subDays(3)->format('D M j H:i:s Y'), 'ClamAV 9.9.9/123456/'.now()->addDay()->format('D M j H:i:s Y'),
            str_replace('/123456/', '/1/', $this->fakeEvidence->defaultReply)] as $reply) {
            $this->assertNull($e->parse($reply, $config));
        }
        $this->assertNotNull($e->parse($this->fakeEvidence->defaultReply, $config));
        config(['service_proposals.reviewer_workspace.image_access.scan_enabled' => false]);
        $this->assertNull($e->version($config)); // Actual adapter is gated OFF: zero real socket call.
    }

    public function test_revocation_during_fake_scan_never_commits_clean_and_private_plaintext_is_removed(): void
    {
        $r = $this->reader();
        $id = $this->upload($r);
        $this->fakeScanner->callback = function () {
            $this->version->revoked_at = now();
            $this->version->save();
        };
        $this->denied(fn () => $this->scan($id), 410);
        $this->assertSame('scanning', TeamOperationRecord::where('kind', ReviewerWorkspaceImagesStore::KIND)->sole()->status);
        $this->assertFalse(collect(Storage::disk('team_private')->allFiles())->contains(fn ($p) => str_contains($p, '/scan-')));
        $this->denied(fn () => $this->metadata($r), 410);
    }

    public function test_expiry_crossing_and_disabled_dispatch_gate_after_fake_evidence_prevent_clean_or_scan(): void
    {
        $r = $this->reader();
        $id = $this->upload($r);
        $this->fakeEvidence->callback = fn () => config(['service_proposals.reviewer_workspace.image_access.scan_enabled' => false]);
        $this->denied(fn () => $this->scan($id), 403);
        $this->assertSame(0, $this->fakeScanner->calls);
        $this->assertSame('scanning', TeamOperationRecord::where('kind', ReviewerWorkspaceImagesStore::KIND)->sole()->status);
    }

    public function test_clean_freshness_expiry_and_scanner_configuration_change_withdraw_access_without_any_automatic_rescan(): void
    {
        $r = $this->reader();
        $id = $this->upload($r);
        $this->scan($id);
        $this->assertSame('ready', $this->metadata($r)['files'][0]['state']);
        $this->travel(3601)->seconds();
        $this->denied(fn () => $this->metadata($r), 403);
        $r = $this->reader();
        $this->assertSame('held', $this->metadata($r)['files'][0]['state']);
        $this->assertSame(1, $this->fakeScanner->calls);
        config(['team_work.scanner.enabled' => false]);
        $this->denied(fn () => $this->metadata($r), 503);
        $this->assertSame(1, $this->fakeScanner->calls);
    }

    public function test_cancel_and_cipher_tamper_withdraw_previous_synthetic_readiness(): void
    {
        $r = $this->reader();
        $id = $this->upload($r);
        $this->scan($id);
        $input = $this->binaryInput($r);
        $row = TeamOperationRecord::find($id);
        app(ReviewerWorkspaceAttachmentBlobs::class)->put($row, 'original', 'changed bytes');
        $this->denied(fn () => app(ReviewerWorkspaceImages::class)->binary($r, $this->token, $input, 'preview'), 503);
        app(ReviewerWorkspaceFiles::class)->cancel($r, $this->token, $this->base() + ['upload_id' => $id]);
        $this->assertSame([], $this->metadata($r)['files']);
        $this->denied(fn () => app(ReviewerWorkspaceImages::class)->binary($r, $this->token, $input, 'preview'), 404);
    }

    public function test_binary_controller_retains_real_csrf_private_headers_and_own_identity_without_native_headers(): void
    {
        $r = $this->reader();
        $id = $this->upload($r);
        $this->scan($id);
        $input = $this->binaryInput($r);
        $q = Request::create('https://proposal.example.invalid/enterprise-owner/service-proposals/'.$this->token.'/campus/workspace/images/preview', 'POST', server: [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ORIGIN' => 'https://proposal.example.invalid', 'SCRIPT_NAME' => '/enterprise-owner/index.php', 'PHP_SELF' => '/enterprise-owner/index.php', 'SCRIPT_FILENAME' => '/FICTIONAL/index.php', 'HTTPS' => 'on'], content: json_encode($input));
        $q->setLaravelSession($r->session());
        $c = app(ReviewerWorkspaceImagesController::class);
        $this->assertSame(419, $c->preview($q, $this->token)->getStatusCode());
        $q->headers->set('X-CSRF-TOKEN', $r->session()->token());
        $out = $c->preview($q, $this->token);
        $this->assertSame(200, $out->getStatusCode());
        $this->assertSame('image/png', $out->headers->get('Content-Type'));
        $this->assertStringContainsString('no-store', $out->headers->get('Cache-Control'));
        $this->assertSame($id, $out->headers->get('X-Everbranch-Image-Upload'));
        $this->assertFalse($out->headers->has('X-Team-File-Id'));
        $this->assertSame(hash('sha256', $out->getContent()), $out->headers->get('X-Everbranch-Image-SHA256'));
        $boundary = new \App\Http\Middleware\ReviewerAiBoundary;
        $wrapped = $boundary->handle($q, fn () => $out);
        $this->assertSame($out->getContent(), $wrapped->getContent());
        $q->headers->set('Origin', 'https://attacker.invalid');
        $this->assertSame(403, $c->preview($q, $this->token)->getStatusCode());
    }

    public function test_all_image_routes_keep_session_locks_and_method_errors_stay_private(): void
    {
        foreach (['metadata', 'thumbnail', 'preview'] as $action) {
            $route = app('router')->getRoutes()->getByName('service-proposals.recipient.workspace.images.'.$action);
            $this->assertSame(10, $route->locksFor());
            $this->assertSame(10, $route->waitsFor());
            $q = Request::create('https://proposal.example.invalid/enterprise-owner/service-proposals/FICTIONAL/campus/workspace/images/'.$action, 'GET');
            $out = (new \App\Http\Middleware\ReviewerAiBoundary)->handle($q, fn () => throw new \Symfony\Component\HttpKernel\Exception\HttpException(405, 'SECRET TRACE'));
            $this->assertSame(405, $out->getStatusCode());
            $this->assertStringNotContainsString('SECRET', $out->getContent());
            $this->assertStringContainsString('no-store', $out->headers->get('Cache-Control'));
        }
    }

    public function test_actual_owner_reaper_cleans_simulated_crash_plaintext_after_lease_with_all_image_flags_off(): void
    {
        $r = $this->reader();
        $id = $this->upload($r);
        $raw = TeamOperationRecord::find($id)->getRawOriginal('data');
        $this->fakeScanner->callback = fn () => throw new \RuntimeException('SIMULATED PROCESS FAILURE');
        try {
            $this->scan($id);
            $this->fail('Expected simulated failure');
        } catch (\RuntimeException $e) {
            $this->assertSame('SIMULATED PROCESS FAILURE', $e->getMessage());
        }
        $scan = TeamOperationRecord::where('kind', ReviewerWorkspaceImagesStore::KIND)->sole();
        // Explicit simulated process-crash orphan. Normal exception path above correctly removed its temporary file.
        $path = app(ReviewerWorkspaceAttachmentBlobs::class)->scanTemporary(TeamOperationRecord::find($id), $this->png(), $scan->data['payload']['claim']['nonce']);
        $prune = app(\App\Proposals\ReviewerWorkspaceImagesPruner::class);
        $this->assertSame(0, $prune->prune($this->ownerRequest())['eligible']);
        $this->travel(91)->seconds();
        config(['service_proposals.reviewer_workspace.enabled' => false, 'service_proposals.reviewer_workspace.image_access.enabled' => false,
            'service_proposals.reviewer_workspace.image_access.scan_enabled' => false, 'team_work.scanner.enabled' => false]);
        $this->assertSame(1, $prune->prune($this->ownerRequest())['eligible']);
        $this->assertFileExists($path);
        $done = $prune->prune($this->ownerRequest(), dryRun: false);
        $this->assertSame(1, $done['erased']);
        $this->assertSame(0, $done['failed']);
        $this->assertFileDoesNotExist($path);
        $this->assertSame('expired', $scan->fresh()->status);
        $this->assertNull($scan->fresh()->data['payload']['claim']);
        $this->assertSame($raw, TeamOperationRecord::find($id)->getRawOriginal('data'));
        $this->assertSame('quarantined', TeamOperationRecord::find($id)->status);
        $this->assertSame(0, $prune->prune($this->ownerRequest(), dryRun: false)['erased']);
    }

    public function test_rejected_tombstone_survives_derivative_cleanup_and_never_becomes_an_automatic_rescan(): void
    {
        $r = $this->reader();
        $id = $this->upload($r);
        $this->fakeScanner->result = 'rejected';
        $this->scan($id);
        $prune = app(\App\Proposals\ReviewerWorkspaceImagesPruner::class);
        $this->assertSame(1, $prune->prune($this->ownerRequest(), dryRun: false)['erased']);
        $row = TeamOperationRecord::where('kind', ReviewerWorkspaceImagesStore::KIND)->sole();
        $this->assertTrue($row->data['payload']['rejected_permanently']);
        $this->travel(301)->seconds();
        $new = $this->intent($id);
        $new['expected_attestation_revision'] = $row->version;
        $this->denied(fn () => $this->scan($id, $new), 409);
        $this->assertSame(1, $this->fakeScanner->calls);
    }

    public function test_reordered_closed_target_keys_preserve_exact_upload_attestation_binary_and_cleanup_identity(): void
    {
        $r = $this->reader();
        $id = $this->upload($r);
        $upload = TeamOperationRecord::find($id);
        $data = $upload->data;
        $data['target'] = array_reverse($data['target'], true);
        $upload->data = $data;
        $upload->save();
        $this->assertSame('clean', $this->scan($id)['status']);
        $file = $this->metadata($r)['files'][0];
        $this->assertSame('ready', $file['state']);
        $input = $this->binaryInput($r);
        $this->assertSame($this->png(), app(ReviewerWorkspaceImages::class)->binary($r, $this->token, $input, 'preview')['bytes']);
        $this->denied(fn () => app(ReviewerWorkspaceImages::class)->binary($r, $this->token,
            [...$input, 'target' => ['id' => $this->ids['ticket'], 'kind' => 'ticket']], 'preview'), 404);
        $upload->refresh();
        $data = $upload->data;
        $data['target'] = array_reverse($data['target'], true);
        $upload->data = $data;
        $upload->save();
        $this->assertSame('ready', $this->metadata($r)['files'][0]['state']);
        $this->travel(3601)->seconds();
        config(['service_proposals.reviewer_workspace.image_access.enabled' => false,
            'service_proposals.reviewer_workspace.image_access.scan_enabled' => false, 'team_work.scanner.enabled' => false]);
        $out = app(\App\Proposals\ReviewerWorkspaceImagesPruner::class)->prune($this->ownerRequest(), dryRun: false);
        $this->assertSame(1, $out['erased']);
        $this->assertSame('quarantined', $upload->fresh()->status);
        $this->assertSame($this->png(), app(ReviewerWorkspaceAttachmentBlobs::class)->read($upload->fresh(), 'original'));
    }

    public function test_cached_real_owner_deactivation_before_preview_cannot_authorize_scanner_work(): void
    {
        $r = $this->reader();
        $id = $this->upload($r);
        $owner = $this->ownerRequest();
        $cached = $this->owner->fresh();
        $owner->setUserResolver(fn () => $cached);
        DB::table('users')->where('id', $cached->id)->update(['is_active' => false]);
        $this->denied(fn () => app(ReviewerWorkspaceImagesScan::class)->scan($owner, $this->version->id, $id, $this->intent($id)), 403);
        $this->assertSame(0, $this->fakeEvidence->calls);
        $this->assertSame(0, $this->fakeScanner->calls);
        $this->assertSame(0, TeamOperationRecord::where('kind', ReviewerWorkspaceImagesStore::KIND)->count());
    }

    public function test_cached_real_owner_deactivation_during_evidence_prevents_fake_scanner_dispatch_and_clean_commit(): void
    {
        $r = $this->reader();
        $id = $this->upload($r);
        $owner = $this->ownerRequest();
        $cached = $this->owner->fresh();
        $owner->setUserResolver(fn () => $cached);
        $this->fakeEvidence->callback = fn () => DB::table('users')->where('id', $cached->id)->update(['is_active' => false]);
        $this->denied(fn () => app(ReviewerWorkspaceImagesScan::class)->scan($owner, $this->version->id, $id, $this->intent($id), false), 403);
        $this->assertSame(0, $this->fakeScanner->calls);
        $this->assertSame(0, TeamOperationRecord::where('kind', ReviewerWorkspaceImagesStore::KIND)->where('status', 'clean')->count());
        $this->assertFalse(collect(Storage::disk('team_private')->allFiles())->contains(fn ($p) => str_contains($p, '/scan-')));
    }

    public function test_cached_real_owner_deactivation_blocks_private_image_cleanup_even_when_flags_are_off(): void
    {
        $r = $this->reader();
        $id = $this->upload($r);
        $this->scan($id);
        $owner = $this->ownerRequest();
        $cached = $this->owner->fresh();
        $owner->setUserResolver(fn () => $cached);
        $this->travel(3601)->seconds();
        config(['service_proposals.reviewer_workspace.image_access.enabled' => false]);
        $paths = Storage::disk('team_private')->allFiles();
        $raw = TeamOperationRecord::where('kind', ReviewerWorkspaceImagesStore::KIND)->sole()->getRawOriginal('data');
        DB::table('users')->where('id', $cached->id)->update(['is_active' => false]);
        $this->denied(fn () => app(\App\Proposals\ReviewerWorkspaceImagesPruner::class)->prune($owner, dryRun: false), 403);
        $this->assertSame($paths, Storage::disk('team_private')->allFiles());
        $this->assertSame($raw, TeamOperationRecord::where('kind', ReviewerWorkspaceImagesStore::KIND)->sole()->getRawOriginal('data'));
    }

    public function test_cached_real_owner_revocation_after_fake_scanner_cannot_commit_clean_and_plaintext_is_removed(): void
    {
        $r = $this->reader();
        $id = $this->upload($r);
        $owner = $this->ownerRequest();
        $cached = $this->owner->fresh();
        $owner->setUserResolver(fn () => $cached);
        $this->fakeScanner->callback = fn () => DB::table('users')->where('id', $cached->id)->update(['email_verified_at' => null]);
        $this->denied(fn () => app(ReviewerWorkspaceImagesScan::class)->scan($owner, $this->version->id, $id, $this->intent($id), false), 403);
        $this->assertSame(1, $this->fakeScanner->calls);
        $this->assertSame(0, TeamOperationRecord::where('kind', ReviewerWorkspaceImagesStore::KIND)->where('status', 'clean')->count());
        $this->assertFalse(collect(Storage::disk('team_private')->allFiles())->contains(fn ($p) => str_contains($p, '/scan-')));
        $this->assertSame('quarantined', TeamOperationRecord::find($id)->status);
    }

    public function test_fresh_real_owner_check_after_cleanup_reservation_prevents_private_erasure_io(): void
    {
        $r = $this->reader();
        $id = $this->upload($r);
        $this->scan($id);
        $owner = $this->ownerRequest();
        $cached = $this->owner->fresh();
        $owner->setUserResolver(fn () => $cached);
        $this->travel(3601)->seconds();
        $before = collect(Storage::disk('team_private')->allFiles())->mapWithKeys(fn ($p) => [$p => Storage::disk('team_private')->get($p)])->all();
        $revoked = false;
        DB::listen(function ($query) use (&$revoked, $cached) {
            if (! $revoked && preg_match('/^update ["`]?team_operation_records["`]? /i', $query->sql)) {
                $revoked = true;
                DB::table('users')->where('id', $cached->id)->update(['is_active' => false]);
            }
        });
        $out = app(\App\Proposals\ReviewerWorkspaceImagesPruner::class)->prune($owner, dryRun: false);
        $this->assertTrue($revoked);
        $this->assertSame(0, $out['erased']);
        $this->assertSame(1, $out['failed']);
        $this->assertSame($before, collect(Storage::disk('team_private')->allFiles())->mapWithKeys(fn ($p) => [$p => Storage::disk('team_private')->get($p)])->all());
        $this->assertSame('cleanup_pending', TeamOperationRecord::where('kind', ReviewerWorkspaceImagesStore::KIND)->sole()->status);
        $this->assertSame('quarantined', TeamOperationRecord::find($id)->status);
    }

    public function test_all_six_maximum_unicode_names_fit_real_private_json_wire_caps_without_truncation(): void
    {
        $r = $this->reader();
        $name = str_repeat('😀', 116).'.png';
        for ($i = 0; $i < 6; $i++) {
            $this->upload($r, $this->png($i + 1, 1), $name);
        }
        $images = $this->metadata($r);
        $files = app(ReviewerWorkspaceFiles::class)->show($r, $this->token, $this->base());
        $this->assertCount(6, $images['files']);
        $this->assertCount(6, $files['files']);
        $this->assertLessThanOrEqual(65536, strlen(response()->json($images)->getContent()));
        $this->assertLessThanOrEqual(131072, strlen(response()->json($files)->getContent()));
        foreach ($images['files'] as $file) {
            $this->assertSame($name, $file['name']);
            $this->assertSame('held', $file['state']);
        }
        $this->assertSame(0, $this->fakeScanner->calls);
    }
}

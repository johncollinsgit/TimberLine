<?php

namespace Tests\Feature\Proposals;

use App\Models\TeamOperationRecord;
use App\Proposals\ReviewerWorkspaceAttachmentBlobs;
use App\Proposals\ReviewerWorkspaceDownloads;
use App\Proposals\ReviewerWorkspaceDownloadsAccess;
use App\Proposals\ReviewerWorkspaceDownloadsPruner;
use App\Proposals\ReviewerWorkspaceDownloadsScan;
use App\Proposals\ReviewerWorkspaceDownloadsStore;
use App\Proposals\ReviewerWorkspaceFiles;
use App\Proposals\ReviewerWorkspaceFilesPruner;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\Support\ReviewerWorkspaceDownloadsFixture;

/** Actual isolated fictional SQLite/private bytes. ALL clean/evidence results are FAKE, never genuine daemon acceptance. */
final class ReviewerWorkspaceDownloadsTest extends ReviewerWorkspaceDownloadsFixture
{
    private function denied(callable $call, int $status): void
    {
        try {
            $call();
            $this->fail('Unadmitted PDF operation accepted');
        } catch (HttpExceptionInterface $error) {
            $this->assertSame($status, $error->getStatusCode());
        }
    }

    private function scan(string $id, ?array $intent = null, bool $dryRun = false): array
    {
        return app(ReviewerWorkspaceDownloadsScan::class)->scan($this->ownerRequest(), $this->version->id, $id, $intent ?? $this->intent($id), $dryRun);
    }

    private function metadata(Request $r): array
    {
        return app(ReviewerWorkspaceDownloads::class)->metadata($r, $this->token, $this->base());
    }

    private function input(Request $r, string $id): array
    {
        $file = collect($this->metadata($r)['files'])->firstWhere('id', $id);

        return $this->base() + ['upload_id' => $id, 'attestation_id' => $file['attestation_id'], 'attestation_revision' => $file['attestation_revision']];
    }

    public function test_default_off_zero_sql_sockets_http_or_storage_and_real_owner_dryrun_does_not_dispatch(): void
    {
        $r = $this->reader();
        $id = $this->upload($r);
        $preview = app(ReviewerWorkspaceDownloadsScan::class)->scan($this->ownerRequest(), $this->version->id, $id, $this->intent($id));
        $this->assertTrue($preview['dry_run']);
        $this->assertFalse($preview['will_dispatch']);
        $this->assertSame(0, $this->fakeEvidence->calls);
        $this->assertSame(0, $this->fakeScanner->calls);
        $this->assertSame(0, TeamOperationRecord::where('kind', ReviewerWorkspaceDownloadsStore::KIND)->count());
        config(['service_proposals.reviewer_workspace.downloads.enabled' => false]);
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->denied(fn () => $this->metadata($r), 404);
        $this->denied(fn () => app(ReviewerWorkspaceDownloadsAccess::class)->publicationDescriptor($r, $this->token, $this->binding(), $this->selection()), 404);
        $this->assertSame([], DB::getQueryLog());
        DB::disableQueryLog();
        config(['service_proposals.reviewer_workspace.downloads.enabled' => true, 'service_proposals.reviewer_workspace.downloads.scan_enabled' => false]);
        $this->denied(fn () => $this->scan($id), 403);
        $this->assertSame(0, $this->fakeEvidence->calls);
        Http::assertNothingSent();
    }

    public function test_fake_authentic_shaped_evidence_and_fake_clean_allow_exact_original_only_without_images_or_native_authority(): void
    {
        $r = $this->reader();
        $id = $this->upload($r);
        $raw = TeamOperationRecord::find($id)->getRawOriginal('data');
        $ledger = $this->ledger->getRawOriginal('data');
        $this->assertFalse(config('service_proposals.reviewer_workspace.image_access.enabled'));
        $this->assertSame('held', $this->metadata($r)['files'][0]['state']);
        $this->assertSame('clean', $this->scan($id)['status']);
        $other = $this->reader();
        $file = $this->metadata($other)['files'][0];
        $this->assertSame('ready', $file['state']);
        $this->assertTrue($file['download_available']);
        $bytes = app(ReviewerWorkspaceDownloads::class)->download($other, $this->token, $this->input($other, $id));
        $this->assertSame($this->pdf(), $bytes['bytes']);
        $this->assertSame(hash('sha256', $this->pdf()), $bytes['original_sha256']);
        $this->assertSame('quarantined', TeamOperationRecord::find($id)->status);
        $this->assertSame($raw, TeamOperationRecord::find($id)->getRawOriginal('data'));
        $this->assertSame($ledger, $this->ledger->fresh()->getRawOriginal('data'));
        $this->assertSame(0, DB::table('team_tasks')->count());
        $this->assertSame(2, $this->fakeEvidence->calls);
        $this->assertSame([['transaction_level' => 0, 'permissions' => 0600, 'exact_bytes' => true]], $this->fakeScanner->observations);
        $this->assertFalse(collect(Storage::disk('team_private')->allFiles())->contains(fn ($p) => str_contains($p, '/scan-')));
        $row = TeamOperationRecord::where('kind', ReviewerWorkspaceDownloadsStore::KIND)->sole();
        $this->assertStringNotContainsString('123456', $row->getRawOriginal('data'));
        $this->assertStringNotContainsString('%PDF-', $row->getRawOriginal('data'));
        Http::assertNothingSent();
    }

    public function test_status_only_missing_or_changed_definition_evidence_never_admits_pdf_download(): void
    {
        $r = $this->reader();
        $id = $this->upload($r);
        $this->fakeEvidence->replies = [null];
        $this->assertSame('unavailable', $this->scan($id)['status']);
        $this->assertSame(0, $this->fakeScanner->calls);
        $this->assertSame('held', $this->metadata($r)['files'][0]['state']);
        $row = TeamOperationRecord::where('kind', ReviewerWorkspaceDownloadsStore::KIND)->sole();
        $this->denied(fn () => app(ReviewerWorkspaceDownloads::class)->download($r, $this->token, $this->base() + ['upload_id' => $id,
            'attestation_id' => $row->id, 'attestation_revision' => $row->version]), 404);
        $this->travel(301)->seconds();
        $this->fakeEvidence->replies = [$this->fakeEvidence->defaultReply, str_replace('/123456/', '/123457/', $this->fakeEvidence->defaultReply)];
        $intent = [...$this->intent($id), 'expected_attestation_revision' => $row->version];
        $this->assertSame('unavailable', $this->scan($id, $intent)['status']);
        $this->assertFalse($this->metadata($r)['files'][0]['download_available']);
    }

    public function test_exact_settled_scan_replay_never_redispatches_and_terminal_rejection_survives_new_upload_uuid_browser_and_cleanup(): void
    {
        $r = $this->reader();
        $id = $this->upload($r);
        $this->fakeScanner->result = 'rejected';
        $intent = $this->intent($id);
        $first = $this->scan($id, $intent);
        $replay = $this->scan($id, $intent);
        $this->assertSame('rejected', $first['status']);
        $this->assertTrue($replay['replayed']);
        $this->assertSame(1, $this->fakeScanner->calls);
        $this->assertSame('rejected', $this->metadata($r)['files'][0]['state']);
        $this->assertSame(1, app(ReviewerWorkspaceDownloadsPruner::class)->prune($this->ownerRequest(), dryRun: false)['erased']);
        $this->assertTrue(TeamOperationRecord::where('kind', ReviewerWorkspaceDownloadsStore::KIND)->sole()->data['payload']['rejected_permanently']);
        $this->assertSame('cancelled', app(ReviewerWorkspaceFiles::class)->cancel($r, $this->token, $this->base() + ['upload_id' => $id])['status']);
        $other = $this->reader();
        $new = $this->upload($other, name: 'RENAMED.pdf');
        $this->assertNotSame($id, $new);
        $this->fakeScanner->result = 'clean';
        $this->denied(fn () => $this->scan($new), 409);
        $this->assertSame(1, $this->fakeScanner->calls);
        $this->assertSame('rejected', collect($this->metadata($other)['files'])->firstWhere('id', $new)['state']);
    }

    public function test_active_pdf_child_scan_lease_blocks_actual_uploader_cancel_and_preserves_original_until_scan_settles(): void
    {
        $r = $this->reader();
        $id = $this->upload($r);
        $blocked = false;
        $this->fakeScanner->callback = function () use ($r, $id, &$blocked) {
            $this->denied(fn () => app(ReviewerWorkspaceFiles::class)->cancel($r, $this->token, $this->base() + ['upload_id' => $id]), 409);
            $blocked = true;
        };
        $this->assertSame('clean', $this->scan($id)['status']);
        $this->assertTrue($blocked);
        $this->assertSame($this->pdf(), app(ReviewerWorkspaceAttachmentBlobs::class)->read(TeamOperationRecord::find($id), 'original'));
        $this->assertSame('cancelled', app(ReviewerWorkspaceFiles::class)->cancel($r, $this->token, $this->base() + ['upload_id' => $id])['status']);
        $this->assertSame([], $this->metadata($r)['files']);
    }

    public function test_live_child_lease_blocks_owner_upload_expiry_cleanup_and_no_late_clean_after_revocation(): void
    {
        $r = $this->reader();
        $id = $this->upload($r);
        $out = null;
        $this->fakeScanner->callback = function () use (&$out) {
            $this->version->revoked_at = now();
            $this->version->save();
            $out = app(ReviewerWorkspaceFilesPruner::class)->prune($this->ownerRequest(), dryRun: false);
        };
        $this->denied(fn () => $this->scan($id), 410);
        $this->assertSame(0, $out['eligible']);
        $this->assertSame(0, $out['erased']);
        $this->assertSame($this->pdf(), app(ReviewerWorkspaceAttachmentBlobs::class)->read(TeamOperationRecord::find($id), 'original'));
        $this->assertSame(0, TeamOperationRecord::where('kind', ReviewerWorkspaceDownloadsStore::KIND)->where('status', 'clean')->count());
        $this->travel(91)->seconds();
        config(['service_proposals.reviewer_workspace.downloads.enabled' => false, 'service_proposals.reviewer_workspace.downloads.scan_enabled' => false, 'team_work.scanner.enabled' => false]);
        $this->assertSame(1, app(ReviewerWorkspaceFilesPruner::class)->prune($this->ownerRequest(), dryRun: false)['erased']);
        $this->assertSame('expired', TeamOperationRecord::find($id)->status);
    }

    public function test_cached_real_owner_deactivation_before_evidence_or_after_fake_scan_cannot_commit_or_dispatch(): void
    {
        $r = $this->reader();
        $id = $this->upload($r);
        $owner = $this->ownerRequest();
        $cached = $this->owner->fresh();
        $owner->setUserResolver(fn () => $cached);
        $this->fakeEvidence->callback = fn () => DB::table('users')->where('id', $cached->id)->update(['is_active' => false]);
        $this->denied(fn () => app(ReviewerWorkspaceDownloadsScan::class)->scan($owner, $this->version->id, $id, $this->intent($id), false), 403);
        $this->assertSame(0, $this->fakeScanner->calls);
        $this->assertSame(0, TeamOperationRecord::where('kind', ReviewerWorkspaceDownloadsStore::KIND)->where('status', 'clean')->count());
    }

    public function test_freshness_config_change_wrong_target_and_cipher_tamper_withdraw_previously_simulated_readiness(): void
    {
        $r = $this->reader();
        $id = $this->upload($r);
        $this->scan($id);
        $input = $this->input($r, $id);
        $this->denied(fn () => app(ReviewerWorkspaceDownloads::class)->download($r, $this->token, [...$input,
            'target' => ['kind' => 'project', 'id' => $this->ids['other_project']]]), 404);
        config(['team_work.scanner.port' => 3311]);
        $this->denied(fn () => app(ReviewerWorkspaceDownloads::class)->download($r, $this->token, $input), 503);
        config(['team_work.scanner.port' => 3310]);
        $path = collect(Storage::disk('team_private')->allFiles())->first(fn ($p) => str_ends_with($p, '/original'));
        $cipher = Storage::disk('team_private')->get($path);
        Storage::disk('team_private')->put($path, 'TAMPER');
        $this->denied(fn () => app(ReviewerWorkspaceDownloads::class)->download($r, $this->token, $input), 503);
        Storage::disk('team_private')->put($path, $cipher);
        $this->travel(3601)->seconds();
        $fresh = $this->reader();
        $this->assertSame('held', $this->metadata($fresh)['files'][0]['state']);
        $this->denied(fn () => app(ReviewerWorkspaceDownloads::class)->download($fresh, $this->token, $input), 404);
    }

    private function jsonRequest(Request $r, array $input, string $action = 'download'): Request
    {
        $q = Request::create('https://proposal.example.invalid/enterprise-owner/service-proposals/'.$this->token.'/campus/workspace/downloads/'.$action, 'POST', server: [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ORIGIN' => 'https://proposal.example.invalid', 'SCRIPT_NAME' => '/enterprise-owner/index.php', 'PHP_SELF' => '/enterprise-owner/index.php', 'SCRIPT_FILENAME' => '/FICTIONAL/index.php', 'HTTPS' => 'on'], content: json_encode($input));
        $q->setLaravelSession($r->session());
        $q->headers->set('X-CSRF-TOKEN', $r->session()->token());

        return $q;
    }

    public function test_pdf_controller_private_exact_identity_octetstream_attachment_and_csrf_range_origin_guards(): void
    {
        $r = $this->reader();
        $id = $this->upload($r, name: 'Fictional résumé.pdf');
        $this->scan($id);
        $q = $this->jsonRequest($r, $this->input($r, $id));
        $controller = app(\App\Proposals\ReviewerWorkspaceDownloadsController::class);
        $out = $controller->download($q, $this->token);
        $wrapped = (new \App\Http\Middleware\ReviewerAiBoundary)->handle($q, fn () => $out);
        $this->assertSame(200, $wrapped->getStatusCode());
        $this->assertSame($this->pdf(), $wrapped->getContent());
        $this->assertSame('application/octet-stream', $wrapped->headers->get('Content-Type'));
        $this->assertStringStartsWith('attachment;', $wrapped->headers->get('Content-Disposition'));
        $this->assertStringContainsString("filename*=utf-8''", $wrapped->headers->get('Content-Disposition'));
        $this->assertSame((string) strlen($this->pdf()), $wrapped->headers->get('Content-Length'));
        $this->assertSame('identity', $wrapped->headers->get('Content-Encoding'));
        $this->assertStringContainsString('no-transform', $wrapped->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-store', $wrapped->headers->get('Cache-Control'));
        $this->assertStringContainsString('sandbox;', $wrapped->headers->get('Content-Security-Policy'));
        $this->assertSame('none', $wrapped->headers->get('Accept-Ranges'));
        $this->assertSame($id, $wrapped->headers->get('X-Everbranch-Attachment-Upload'));
        $this->assertSame(hash('sha256', $this->pdf()), $wrapped->headers->get('X-Everbranch-Attachment-SHA256'));
        $this->assertFalse($wrapped->headers->has('X-Team-File-Id'));
        $meta = $controller->metadata($this->jsonRequest($r, $this->base(), 'metadata'), $this->token);
        $this->assertSame('identity', $meta->headers->get('Content-Encoding'));
        $this->assertSame((string) strlen($meta->getContent()), $meta->headers->get('Content-Length'));
        foreach ([['X-CSRF-TOKEN', 'WRONG', 419], ['Origin', 'https://foreign.invalid', 403], ['Sec-Fetch-Site', 'cross-site', 403], ['Range', 'bytes=0-9', 400], ['If-None-Match', 'FICTIONAL', 400]] as [$header, $value, $status]) {
            $q = $this->jsonRequest($r, $this->input($r, $id));
            $q->headers->set($header, $value);
            $denied = $controller->download($q, $this->token);
            $this->assertSame($status, $denied->getStatusCode());
            $this->assertSame('identity', $denied->headers->get('Content-Encoding'));
            $this->assertSame((string) strlen($denied->getContent()), $denied->headers->get('Content-Length'));
            $this->assertStringNotContainsString('%PDF-', $denied->getContent());
        }
    }

    public function test_pdf_outer_boundary_sanitizes_method_runtime_throttle_before_controller_and_preserves_other_paths(): void
    {
        config(['app.debug' => true]);
        $boundary = new \App\Http\Middleware\ReviewerAiBoundary;
        foreach (['metadata', 'download'] as $action) {
            $route = app('router')->getRoutes()->getByName('service-proposals.recipient.workspace.downloads.'.$action);
            $this->assertSame(10, $route->locksFor());
            $this->assertSame(10, $route->waitsFor());
            foreach ([405, 403, 419, 429, 503] as $status) {
                $q = Request::create('https://proposal.example.invalid/enterprise-owner/service-proposals/FICTIONAL/campus/workspace/downloads/'.$action.'/', 'GET');
                $out = $boundary->handle($q, fn () => throw new \Symfony\Component\HttpKernel\Exception\HttpException($status, 'SECRET TRACE', headers: ['Retry-After' => '12']));
                $this->assertSame($status, $out->getStatusCode());
                $this->assertSame(['error', 'automatic_retry'], array_keys(json_decode($out->getContent(), true)));
                $this->assertStringNotContainsString('SECRET', $out->getContent());
                $this->assertSame('12', $out->headers->get('Retry-After'));
                $this->assertSame('identity', $out->headers->get('Content-Encoding'));
                $this->assertSame((string) strlen($out->getContent()), $out->headers->get('Content-Length'));
                $this->assertStringContainsString('no-transform', $out->headers->get('Cache-Control'));
            }
        }
        $response = response('UNRELATED', 201, ['Cache-Control' => 'public, max-age=60', 'Content-Encoding' => 'gzip']);
        $this->assertSame($response, $boundary->handle(Request::create('/unrelated'), fn () => $response));
        $this->assertSame('gzip', $response->headers->get('Content-Encoding'));
    }

    public function test_real_owner_freshness_after_fake_scan_cannot_commit_readiness(): void
    {
        $r = $this->reader();
        $id = $this->upload($r);
        $owner = $this->ownerRequest();
        $cached = $this->owner->fresh();
        $owner->setUserResolver(fn () => $cached);
        $this->fakeScanner->callback = fn () => DB::table('users')->where('id', $cached->id)->update(['is_active' => false]);
        $this->denied(fn () => app(ReviewerWorkspaceDownloadsScan::class)->scan($owner, $this->version->id, $id, $this->intent($id), false), 403);
        $this->assertSame(1, $this->fakeScanner->calls);
        $this->assertSame(0, TeamOperationRecord::where('kind', ReviewerWorkspaceDownloadsStore::KIND)->where('status', 'clean')->count());
        $this->assertFalse(collect(Storage::disk('team_private')->allFiles())->contains(fn ($p) => str_contains($p, '/scan-')));
    }

    public function test_actual_pdf_reaper_handles_simulated_crash_with_flags_off_without_erasing_original_or_rejection(): void
    {
        $r = $this->reader();
        $id = $this->upload($r);
        $original = TeamOperationRecord::find($id)->getRawOriginal('data');
        $this->fakeScanner->callback = fn () => throw new \RuntimeException('SIMULATED CRASH');
        try {
            $this->scan($id);
            $this->fail('Expected simulated scanner failure');
        } catch (\RuntimeException $e) {
            $this->assertSame('SIMULATED CRASH', $e->getMessage());
        }
        $scan = TeamOperationRecord::where('kind', ReviewerWorkspaceDownloadsStore::KIND)->sole();
        $path = app(ReviewerWorkspaceAttachmentBlobs::class)->scanTemporary(TeamOperationRecord::find($id), $this->pdf(), $scan->data['payload']['claim']['nonce']);
        $this->assertFileExists($path);
        $this->assertSame(0, app(ReviewerWorkspaceDownloadsPruner::class)->prune($this->ownerRequest())['eligible']);
        $this->travel(91)->seconds();
        config(['service_proposals.reviewer_workspace.enabled' => false, 'service_proposals.reviewer_workspace.downloads.enabled' => false,
            'service_proposals.reviewer_workspace.downloads.scan_enabled' => false, 'team_work.scanner.enabled' => false]);
        $this->assertSame(1, app(ReviewerWorkspaceDownloadsPruner::class)->prune($this->ownerRequest())['eligible']);
        $this->assertFileExists($path);
        $this->assertSame(1, app(ReviewerWorkspaceDownloadsPruner::class)->prune($this->ownerRequest(), dryRun: false)['erased']);
        $this->assertFileDoesNotExist($path);
        $this->assertSame('expired', $scan->fresh()->status);
        $this->assertSame($original, TeamOperationRecord::find($id)->getRawOriginal('data'));
        $this->assertSame($this->pdf(), app(ReviewerWorkspaceAttachmentBlobs::class)->read(TeamOperationRecord::find($id), 'original'));
        $this->assertSame(0, app(ReviewerWorkspaceDownloadsPruner::class)->prune($this->ownerRequest(), dryRun: false)['erased']);
    }

    public function test_bound_pdf_target_order_is_semantic_and_wrong_attestation_or_upload_never_yields_bytes(): void
    {
        $r = $this->reader();
        $id = $this->upload($r);
        $this->scan($id);
        $input = $this->input($r, $id);
        $ordered = $input;
        $ordered['target'] = ['id' => $input['target']['id'], 'kind' => $input['target']['kind']];
        $this->assertSame($this->pdf(), app(ReviewerWorkspaceDownloads::class)->download($r, $this->token, $ordered)['bytes']);
        foreach ([['attestation_id' => (string) \Illuminate\Support\Str::uuid()], ['attestation_revision' => $input['attestation_revision'] + 1], ['upload_id' => (string) \Illuminate\Support\Str::uuid()]] as $wrong) {
            try {
                app(ReviewerWorkspaceDownloads::class)->download($r, $this->token, array_replace($input, $wrong));
                $this->fail('Foreign PDF accepted');
            } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
                $this->assertTrue(true);
            } catch (HttpExceptionInterface $e) {
                $this->assertSame(404, $e->getStatusCode());
            }
        }
        $this->assertSame(1, $this->fakeScanner->calls);
    }

    public function test_target_free_pdf_boot_requires_actual_download_descriptor_and_never_scans_or_grants_hidden_target(): void
    {
        $r = $this->reader();
        $descriptor = app(ReviewerWorkspaceDownloadsAccess::class)->publicationDescriptor($r, $this->token, $this->binding(), $this->selection());
        $this->assertSame(['metadata', 'download'], array_keys($descriptor['endpoints']));
        $this->assertSame($this->version->payload['review_workspace_downloads']['expires_at'], $descriptor['expires_at']);
        $this->assertSame(0, $this->fakeEvidence->calls);
        $this->denied(fn () => app(ReviewerWorkspaceDownloads::class)->metadata($r, $this->token, array_replace($this->base(), ['target' => ['kind' => 'project', 'id' => $this->ids['other_project']]])), 404);
        $this->version->revoked_at = now();
        $this->version->save();
        $this->denied(fn () => app(ReviewerWorkspaceDownloadsAccess::class)->publicationDescriptor($r, $this->token, $this->binding(), $this->selection()), 410);
        $this->assertSame(0, $this->fakeScanner->calls);
    }

    public function test_exact_mounted_get_routes_do_not_expose_debug_exception_or_transport_metadata(): void
    {
        config(['app.debug' => true]);
        foreach (['metadata', 'download'] as $action) {
            $q = Request::create('https://proposal.example.invalid/enterprise-owner/service-proposals/FICTIONAL/campus/workspace/downloads/'.$action, 'GET', server: [
                'SCRIPT_NAME' => '/enterprise-owner/index.php', 'PHP_SELF' => '/enterprise-owner/index.php', 'SCRIPT_FILENAME' => '/FICTIONAL/index.php', 'HTTPS' => 'on', 'HTTP_ACCEPT' => 'application/json']);
            $out = app(\Illuminate\Contracts\Http\Kernel::class)->handle($q);
            $this->assertSame(405, $out->getStatusCode());
            $this->assertSame(['error', 'automatic_retry'], array_keys(json_decode($out->getContent(), true)));
            $this->assertSame('identity', $out->headers->get('Content-Encoding'));
            $this->assertSame((string) strlen($out->getContent()), $out->headers->get('Content-Length'));
            $this->assertStringContainsString('no-transform', $out->headers->get('Cache-Control'));
            $this->assertStringNotContainsString('trace', $out->getContent());
        }
    }
}

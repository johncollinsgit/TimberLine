<?php

namespace Tests\Feature\Proposals;

use App\Models\TeamOperationRecord;
use App\Proposals\ProposalService;
use App\Proposals\ReviewerWorkspace;
use App\Proposals\ReviewerWorkspaceAttachmentsAccess;
use App\Proposals\ReviewerWorkspaceAttachmentsStore;
use App\Proposals\ReviewerWorkspaceFiles;
use App\Proposals\ReviewerWorkspaceSchema;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\Support\ReviewerWorkspaceFixture;

/** Actual private fixture DB/filesystem, fictional image only; never a real upload, scan, hosted or provider acceptance. */
final class ReviewerWorkspaceMaintenanceTest extends ReviewerWorkspaceFixture
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

    private function sidecars(): string
    {
        $r = $this->reader();
        config(['service_proposals.reviewer_workspace.collaboration.comments_write_enabled' => true]);
        app(\App\Proposals\ReviewerWorkspaceDiscussion::class)->comment($r, $this->token, $this->base([
            'request_id' => (string) Str::uuid(), 'expected_target_version' => 1, 'expected_thread_revision' => 0,
            'expected_reviewer_name' => $r->session()->get('service_proposal_name.'.$this->version->id), 'body' => 'PRIVATE MAINTENANCE FIXTURE COMMENT']));
        [, $id] = $this->upload($r, $this->png());

        return $id;
    }

    private function closeCrud(): void
    {
        config(['service_proposals.writes_enabled' => false, 'service_proposals.publish_enabled' => false,
            'service_proposals.reviewer_workspace.enabled' => false, 'service_proposals.reviewer_workspace.writes_enabled' => false,
            'service_proposals.reviewer_workspace.initialization_enabled' => false, 'service_proposals.reviewer_workspace.financial_enabled' => false,
            'service_proposals.reviewer_workspace.collaboration.enabled' => false, 'service_proposals.reviewer_workspace.collaboration.comments_write_enabled' => false,
            'service_proposals.reviewer_workspace.attachments.enabled' => false, 'service_proposals.reviewer_workspace.attachments.write_enabled' => false,
            'service_proposals.reviewer_workspace.maintenance_enabled' => true]);
    }

    private function maintenance(string $kind, array $body = [], array $headers = [], string $method = 'POST')
    {
        return $this->withSession(['_token' => 'FICTIONAL_MAINTENANCE_CSRF'])->call($method,
            'https://proposal.example.invalid/enterprise-owner/owner-workspaces/'.$this->tenant->slug.'/proposals/reviewer-maintenance/'.$kind,
            [], [], [], array_replace(['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_ORIGIN' => 'https://proposal.example.invalid',
                'HTTP_X_CSRF_TOKEN' => 'FICTIONAL_MAINTENANCE_CSRF', 'SCRIPT_NAME' => '/enterprise-owner/index.php', 'PHP_SELF' => '/enterprise-owner/index.php',
                'SCRIPT_FILENAME' => '/FICTIONAL/index.php', 'HTTPS' => 'on'], $headers), json_encode((object) $body, JSON_THROW_ON_ERROR));
    }

    private function rawRows(): string
    {
        return DB::table('team_operation_records')->orderBy('id')->get()->toJson();
    }

    public function test_default_off_controller_refuses_before_database_or_storage_work(): void
    {
        config(['service_proposals.reviewer_workspace.maintenance_enabled' => false]);
        $r = $this->request(path: '/owner-workspaces/'.$this->tenant->slug.'/fixture', owner: true);
        DB::flushQueryLog();
        DB::enableQueryLog();
        $out = app(\App\Proposals\ReviewerWorkspaceMaintenanceController::class)->prune($r, $this->tenant->slug, 'graph');
        $this->assertSame(404, $out->getStatusCode());
        $this->assertSame([], DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertSame([], Storage::disk('team_private')->allFiles());
    }

    public function test_mounted_preview_then_explicit_apply_cleans_actual_expiry_with_crud_off_and_preserves_other_records(): void
    {
        $upload = $this->sidecars();
        $this->travel(8)->days();
        $this->closeCrud();
        $this->actingAs($this->owner);
        TeamOperationRecord::query()->create(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id,
            'area_id' => config('service_proposals.reviewer_workspace.anchor_area_id'), 'kind' => 'fixture_unrelated',
            'title' => 'Unrelated synthetic owner record', 'status' => 'active', 'visibility' => 'area', 'version' => 1,
            'source_key' => 'unrelated-maintenance-sentinel', 'data' => ['body' => 'UNRELATED SYNTHETIC CONTENT']]);
        $unrelated = DB::table('team_operation_records')->whereNotIn('kind', [\App\Proposals\ReviewerWorkspaceStore::KIND,
            \App\Proposals\ReviewerWorkspaceCollaborationStore::THREAD, ReviewerWorkspaceAttachmentsStore::KIND])->orderBy('id')->get()->toJson();
        $before = $this->rawRows();
        $native = [DB::table('users')->get()->toJson(), DB::table('tenant_user')->get()->toJson(), DB::table('team_area_members')->get()->toJson(), $this->ledger->fresh()->getRawOriginal('data')];
        foreach (['graph', 'comments', 'quarantine'] as $kind) {
            $this->maintenance($kind)->assertOk()->assertJsonPath('resource', $kind)->assertJsonPath('dry_run', true)->assertJsonPath('result.eligible', 1)->assertJsonPath('result.erased', 0);
            $this->assertSame($before, $this->rawRows());
        }
        foreach (['graph', 'comments', 'quarantine'] as $kind) {
            $out = $this->maintenance($kind, ['apply' => true]);
            $out->assertOk()->assertJsonPath('dry_run', false)->assertJsonPath('result.erased', 1);
            $this->assertStringContainsString('no-store', $out->headers->get('Cache-Control'));
            $this->assertSame("default-src 'none'; base-uri 'none'; frame-ancestors 'none'", $out->headers->get('Content-Security-Policy'));
        }
        $row = TeamOperationRecord::find($this->version->payload['review_workspace']['workspace_id']);
        $this->assertSame('expired', $row->status);
        $this->assertNull($row->data['graph']);
        $thread = TeamOperationRecord::where('kind', \App\Proposals\ReviewerWorkspaceCollaborationStore::THREAD)->first();
        $this->assertSame(['comments' => []], $thread->data['payload']);
        $this->assertSame('expired', TeamOperationRecord::find($upload)->status);
        $this->assertSame($native, [DB::table('users')->get()->toJson(), DB::table('tenant_user')->get()->toJson(), DB::table('team_area_members')->get()->toJson(), $this->ledger->fresh()->getRawOriginal('data')]);
        $this->assertSame($unrelated, DB::table('team_operation_records')->whereNotIn('kind', [\App\Proposals\ReviewerWorkspaceStore::KIND,
            \App\Proposals\ReviewerWorkspaceCollaborationStore::THREAD, ReviewerWorkspaceAttachmentsStore::KIND])->orderBy('id')->get()->toJson());
        $this->assertSame(0, DB::table('team_tasks')->count());
        $this->assertTrue(collect(Storage::disk('team_private')->allFiles())->every(fn ($f) => str_ends_with($f, '.retired') || str_ends_with($f, '.quota.lock')));
        Http::assertNothingSent();
    }

    public function test_active_content_never_erases_even_on_explicit_apply(): void
    {
        $this->sidecars();
        $this->closeCrud();
        $this->actingAs($this->owner);
        $before = $this->rawRows();
        foreach (['graph', 'comments', 'quarantine'] as $kind) {
            $this->maintenance($kind, ['apply' => true])->assertOk()->assertJsonPath('result.erased', 0);
        }
        $this->assertSame($before, $this->rawRows());
    }

    public function test_revoked_content_is_previewed_before_explicit_cleanup(): void
    {
        $this->sidecars();
        $this->version->revoked_at = now();
        $this->version->save();
        $this->closeCrud();
        $this->actingAs($this->owner);
        foreach (['graph', 'comments', 'quarantine'] as $kind) {
            $this->maintenance($kind)->assertOk()->assertJsonPath('result.eligible', 1)->assertJsonPath('result.erased', 0);
            $this->maintenance($kind, ['apply' => true])->assertOk()->assertJsonPath('result.erased', 1);
        }
    }

    public function test_real_owner_auth_verification_membership_and_anchor_are_required(): void
    {
        $this->closeCrud();
        $this->travel(8)->days();
        $before = $this->rawRows();
        $this->maintenance('graph', ['apply' => true])->assertStatus(403);
        $this->actingAs($this->owner);
        $this->owner->tenants()->updateExistingPivot($this->tenant->id, ['role' => 'member']);
        $this->maintenance('graph', ['apply' => true])->assertStatus(403);
        $this->owner->tenants()->updateExistingPivot($this->tenant->id, ['role' => 'owner', 'membership_active' => false]);
        $this->maintenance('graph', ['apply' => true])->assertStatus(403);
        $this->owner->tenants()->updateExistingPivot($this->tenant->id, ['membership_active' => true]);
        $this->owner->forceFill(['email_verified_at' => null])->save();
        $this->maintenance('graph', ['apply' => true])->assertStatus(403);
        $this->owner->forceFill(['email_verified_at' => now()])->save();
        $this->actingAs($this->owner);
        config(['service_proposals.reviewer_workspace.anchor_marker_sha256' => str_repeat('f', 64)]);
        $this->maintenance('graph', ['apply' => true])->assertStatus(503);
        $this->assertSame($before, $this->rawRows());
    }

    public function test_mounted_csrf_origin_method_and_direct_closed_scalar_validation_preserve_content(): void
    {
        $this->closeCrud();
        $this->travel(8)->days();
        $this->actingAs($this->owner);
        $before = $this->rawRows();
        $this->maintenance('graph', ['apply' => true], ['HTTP_X_CSRF_TOKEN' => 'wrong'])->assertStatus(419);
        $this->maintenance('graph', [], ['HTTP_ORIGIN' => 'https://foreign.invalid'])->assertStatus(403);
        $this->maintenance('graph', [], ['HTTP_SEC_FETCH_SITE' => 'cross-site'])->assertStatus(403);
        $this->maintenance('graph', [], [], 'GET')->assertStatus(405);
        // Separate direct-controller input checks avoid spending/bypassing the real six/minute route limiter.
        foreach ([['apply' => 'true'], ['apply' => 1], ['apply' => null], ['limit' => 0], ['limit' => 26], ['limit' => 1.5], ['limit' => null], ['after' => 'not-a-uuid'], ['tenant_id' => $this->tenant->id], ['path' => '../native'], ['extra' => str_repeat('x', 2048)]] as $body) {
            $r = Request::create('https://proposal.example.invalid/fixture', 'POST', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ORIGIN' => 'https://proposal.example.invalid'], content: json_encode((object) $body));
            $ownerRequest = $this->request(path: '/owner-workspaces/'.$this->tenant->slug.'/fixture', owner: true);
            $r->setLaravelSession($ownerRequest->session());
            $r->setUserResolver($ownerRequest->getUserResolver());
            $r->setRouteResolver($ownerRequest->getRouteResolver());
            $r->headers->set('X-CSRF-TOKEN', $r->session()->token());
            $out = app(\App\Proposals\ReviewerWorkspaceMaintenanceController::class)->prune($r, $this->tenant->slug, 'graph');
            $this->assertSame(isset($body['extra']) ? 400 : 422, $out->getStatusCode());
        }
        $this->assertSame($before, $this->rawRows());
    }

    public function test_lock_private_outer_envelope_and_unrelated_paths_are_preserved(): void
    {
        $route = app('router')->getRoutes()->getByName('service-proposals.owner.maintenance.prune');
        $this->assertSame(10, $route->locksFor());
        $this->assertSame(10, $route->waitsFor());
        foreach (['auth', 'verified', \App\Enterprise\ProposalRuntimeBoundary::class] as $middleware) {
            $this->assertContains($middleware, $route->gatherMiddleware());
        }
        foreach ([403, 404, 405, 419, 429, 503] as $status) {
            $r = Request::create('https://proposal.example.invalid/enterprise-owner/owner-workspaces/synthetic-ai-owner/proposals/reviewer-maintenan%63e/graph/', 'POST');
            $out = (new \App\Http\Middleware\ReviewerAiBoundary)->handle($r, fn () => throw new \Symfony\Component\HttpKernel\Exception\HttpException($status, 'PRIVATE ERROR SENTINEL'));
            $this->assertSame($status, $out->getStatusCode());
            $this->assertStringNotContainsString('SENTINEL', $out->getContent());
            $this->assertStringContainsString('no-store', $out->headers->get('Cache-Control'));
        }
        $original = response('UNRELATED SENTINEL', 418);
        $r = Request::create('/owner-workspaces/regular/proposals/other');
        $this->assertSame($original, (new \App\Http\Middleware\ReviewerAiBoundary)->handle($r, fn () => $original));
    }

    public function test_graph_preview_preserves_original_default_result_and_apply_semantics(): void
    {
        $this->travel(8)->days();
        $this->closeCrud();
        $r = $this->request(path: '/owner-workspaces/'.$this->tenant->slug.'/fixture', owner: true);
        $before = $this->rawRows();
        $preview = app(ReviewerWorkspace::class)->pruneExpired($r, dryRun: true);
        $this->assertSame(1, $preview['eligible']);
        $this->assertSame(0, $preview['erased']);
        $this->assertSame($before, $this->rawRows());
        $old = app(ReviewerWorkspace::class)->pruneExpired($r);
        $this->assertSame(['processed', 'erased', 'next_cursor'], array_keys($old));
        $this->assertSame(1, $old['erased']);
        $this->assertNull($old['next_cursor']);
    }

    public function test_mounted_graph_cursor_pages_recheck_current_eligibility_and_apply_is_idempotent(): void
    {
        $this->publish();
        $this->initialize();
        $this->travel(8)->days();
        $this->closeCrud();
        $this->actingAs($this->owner);
        $before = $this->rawRows();
        $a = $this->maintenance('graph', ['limit' => 1]);
        $a->assertOk()->assertJsonPath('result.processed', 1)->assertJsonPath('result.erased', 0);
        $cursor = $a->json('result.next_cursor');
        $this->assertNotNull($cursor);
        $this->assertSame($before, $this->rawRows());
        $this->maintenance('graph', ['limit' => 1, 'apply' => true])->assertOk()->assertJsonPath('result.erased', 1);
        $this->maintenance('graph', ['limit' => 1, 'after' => $cursor])->assertOk()->assertJsonPath('result.processed', 1)->assertJsonPath('result.eligible', 1)->assertJsonPath('result.next_cursor', null);
        $this->maintenance('graph', ['limit' => 1, 'after' => $cursor, 'apply' => true])->assertOk()->assertJsonPath('result.erased', 1);
        $revisions = DB::table('team_operation_revisions')->count();
        $this->maintenance('graph', ['apply' => true])->assertOk()->assertJsonPath('result.erased', 0);
        $this->assertSame($revisions, DB::table('team_operation_revisions')->count());
        $this->assertSame(2, TeamOperationRecord::where('kind', \App\Proposals\ReviewerWorkspaceStore::KIND)->where('status', 'expired')->count());
    }

    public function test_modern_manifest_covers_controller_route_and_runtime_gate_without_touching_legacy_registry(): void
    {
        \Tests\Support\ReviewerWorkspaceSourceSnapshot::run(function (): void {
            $manifest = app(\App\Proposals\CampusReviewManifest::class);
            $before = $manifest->current()['source_hash'];
            foreach (['app/Proposals/ReviewerWorkspaceMaintenanceController.php', 'routes/service-proposals-reviewer-maintenance.php', 'app/Models/User.php', 'config/service_proposals.php', 'apps/enterprise/config/service_proposals.php'] as $path) {
                $file = base_path($path);
                $original = file_get_contents($file);
                try {
                    file_put_contents($file, $original."\n// SYNTHETIC SOURCE FINGERPRINT TEST\n");
                    $this->assertNotSame($before, $manifest->current()['source_hash']);
                } finally {
                    file_put_contents($file, $original);
                }
            }
            $this->assertSame($before, $manifest->current()['source_hash']);
            $this->assertSame([], config('service_proposals.campus_review.legacy_versions', []));
        });
    }
}

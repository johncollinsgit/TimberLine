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
final class ReviewerWorkspaceMaintenanceAuthorityTest extends ReviewerWorkspaceFixture
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

    private function revokeBeforeLock(string $change, int $onCall = 1): void
    {
        $id = $this->owner->id;
        $this->app->instance(\App\Enterprise\RecordStore::class, new class($id, $change, $onCall) extends \App\Enterprise\RecordStore
        {
            private int $calls = 0;

            public function __construct(private int $id, private string $change, private int $onCall) {}

            public function locked(\App\Models\Tenant $tenant, callable $callback): mixed
            {
                if (++$this->calls === $this->onCall) {
                    match ($this->change) {
                        'membership' => DB::table('tenant_user')->where(['tenant_id' => $tenant->id, 'user_id' => $this->id])->update(['membership_active' => false]),
                        'role' => DB::table('tenant_user')->where(['tenant_id' => $tenant->id, 'user_id' => $this->id])->update(['role' => 'member']),
                        'active' => DB::table('users')->where('id', $this->id)->update(['is_active' => false]),
                        'verified' => DB::table('users')->where('id', $this->id)->update(['email_verified_at' => null]),
                    };
                }

                return parent::locked($tenant, $callback);
            }
        });
    }

    private function restoreOwner(): void
    {
        DB::table('users')->where('id', $this->owner->id)->update(['is_active' => true, 'email_verified_at' => now()]);
        DB::table('tenant_user')->where(['tenant_id' => $this->tenant->id, 'user_id' => $this->owner->id])->update(['role' => 'owner', 'membership_active' => true]);
        $this->app->forgetInstance(\App\Enterprise\RecordStore::class);
    }

    private function eraser(string $kind, Request $request, bool $dryRun): array
    {
        return match ($kind) {
            'graph' => app(ReviewerWorkspace::class)->pruneExpired($request, dryRun: $dryRun),
            'comments' => app(\App\Proposals\ReviewerWorkspaceCollaborationPruner::class)->prune($request, dryRun: $dryRun),
            'quarantine' => app(\App\Proposals\ReviewerWorkspaceFilesPruner::class)->prune($request, dryRun: $dryRun),
        };
    }

    private function fileHashes(): array
    {
        $out = [];
        foreach (Storage::disk('team_private')->allFiles() as $f) {
            $out[$f] = hash('sha256', Storage::disk('team_private')->get($f));
        }ksort($out);

        return $out;
    }

    public function test_all_erasers_recheck_revoked_membership_and_role_inside_actual_preview_and_apply_transactions(): void
    {
        $this->sidecars();
        $this->version->revoked_at = now();
        $this->version->save();
        $this->closeCrud();
        foreach (['graph', 'comments', 'quarantine'] as $kind) {
            foreach ([true, false] as $dryRun) {
                foreach (['membership', 'role'] as $change) {
                    $this->restoreOwner();
                    $request = $this->request(path: '/owner-workspaces/'.$this->tenant->slug.'/fixture', owner: true);
                    $cached = $request->user();
                    $request->setUserResolver(fn () => $cached);
                    $before = $this->rawRows();
                    $audit = DB::table('team_operation_revisions')->orderBy('id')->get()->toJson();
                    $bytes = $this->fileHashes();
                    $this->revokeBeforeLock($change);
                    $this->denied(fn () => $this->eraser($kind, $request, $dryRun), 403);
                    $this->assertSame($before, $this->rawRows());
                    $this->assertSame($audit, DB::table('team_operation_revisions')->orderBy('id')->get()->toJson());
                    $this->assertSame($bytes, $this->fileHashes());
                    $this->assertSame(0, DB::transactionLevel());
                }
            }
        }
        $this->restoreOwner();
        Http::assertNothingSent();
    }

    public function test_cached_user_deactivation_or_unverification_cannot_authorize_any_eraser_preview_or_apply(): void
    {
        $this->sidecars();
        $this->version->revoked_at = now();
        $this->version->save();
        $this->closeCrud();
        foreach (['graph', 'comments', 'quarantine'] as $kind) {
            foreach ([true, false] as $dryRun) {
                foreach (['active', 'verified'] as $change) {
                    $this->restoreOwner();
                    $request = $this->request(path: '/owner-workspaces/'.$this->tenant->slug.'/fixture', owner: true);
                    $cached = $request->user();
                    $request->setUserResolver(fn () => $cached);
                    $this->assertTrue($cached->is_active);
                    $this->assertNotNull($cached->email_verified_at);
                    $before = $this->rawRows();
                    $audit = DB::table('team_operation_revisions')->orderBy('id')->get()->toJson();
                    $bytes = $this->fileHashes();
                    $this->revokeBeforeLock($change);
                    $this->denied(fn () => $this->eraser($kind, $request, $dryRun), 403);
                    $this->assertTrue($cached->is_active);
                    $this->assertNotNull($cached->email_verified_at);
                    $this->assertSame($before, $this->rawRows());
                    $this->assertSame($audit, DB::table('team_operation_revisions')->orderBy('id')->get()->toJson());
                    $this->assertSame($bytes, $this->fileHashes());
                    $this->assertSame(0, DB::transactionLevel());
                }
            }
        }
        $this->restoreOwner();
        Http::assertNothingSent();
    }

    public function test_cached_account_revocation_after_blob_io_cannot_commit_false_erasure_success(): void
    {
        $upload = $this->sidecars();
        $this->version->revoked_at = now();
        $this->version->save();
        $this->closeCrud();
        $request = $this->request(path: '/owner-workspaces/'.$this->tenant->slug.'/fixture', owner: true);
        $cached = $request->user();
        $request->setUserResolver(fn () => $cached);
        $this->revokeBeforeLock('active', 2);
        $result = $this->eraser('quarantine', $request, false);
        $this->assertSame(0, $result['erased']);
        $this->assertSame(1, $result['failed']);
        $row = TeamOperationRecord::find($upload);
        $this->assertSame('cleanup_pending', $row->status);
        $this->assertNotNull($row->data['payload']['claim']);
        $this->assertNotSame('Expired attachment', $row->data['payload']['name']);
        $this->assertTrue(collect(Storage::disk('team_private')->allFiles())->every(fn ($f) => str_ends_with($f, '.retired') || str_ends_with($f, '.quota.lock')));
        $this->assertTrue($cached->is_active);
        $this->assertFalse(\App\Models\User::find($this->owner->id)->is_active);
        $this->assertSame(0,DB::transactionLevel());
        $this->restoreOwner();
        Http::assertNothingSent();
    }
}

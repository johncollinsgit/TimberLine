<?php

namespace Tests\Feature\Proposals;

use App\Models\TeamOperationRecord;
use App\Proposals\ReviewerWorkspace;
use App\Proposals\ReviewerWorkspaceInitialization;
use App\Proposals\ReviewerWorkspaceInitializationController;
use App\Proposals\ReviewerWorkspaceSchema;
use App\Proposals\ReviewerWorkspaceStore;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Support\ReviewerWorkspaceFixture;

/** Real mounted isolated owner HTTP, native publication/CSRF/storage; no provider or production authority. */
final class ReviewerWorkspaceInitializationTest extends ReviewerWorkspaceFixture
{
    private array $approval;

    private array $input;

    private string $predecessor;

    protected function setUp(): void
    {
        parent::setUp();
        $old = $this->version;
        $this->predecessor = $old->id;
        // Explicit fictional future draft publication. No initializer creates/changes a Version.
        $this->publish();
        $this->approval = ['schema_version' => 1, 'data_basis' => 'reviewed_current_edits', 'version_id' => $this->version->id,
            'version_hash' => $this->version->content_hash, 'build_id' => $this->binding()['build_id'], 'source_hash' => $this->binding()['source_hash'],
            'workspace_id' => $this->version->payload['review_workspace']['workspace_id'],
            'descriptor_sha256' => ReviewerWorkspaceSchema::digest($this->version->payload['review_workspace']),
            'seed_sha256' => ReviewerWorkspaceSchema::digest($this->seed), 'import_evidence_sha256' => str_repeat('e', 64),
            'predecessor_version_id' => $old->id, 'predecessor_version_hash' => $old->content_hash,
            'expires_at' => now()->addMinutes(5)->utc()->format('Y-m-d\TH:i:s\Z')];
        $hash = ReviewerWorkspaceSchema::digest($this->approval);
        config(['service_proposals.reviewer_workspace.owner_initialization' => ['enabled' => true, 'approvals' => [$hash => $this->approval]]]);
        $this->input = ['version_id' => $this->version->id, 'request_id' => (string) Str::uuid(), 'approval_sha256' => $hash,
            'import_evidence_sha256' => $this->approval['import_evidence_sha256'], 'seed' => $this->seed];
        $this->actingAs($this->owner);
    }

    private function callOwner(string $action, array $body, array $headers = [], string $method = 'POST')
    {
        // Keep this actual HTTP client's session cookie across its requests.
        $this->withCookie(config('session.cookie'), $this->app['session.store']->getId());

        return $this->withSession(['_token' => 'FICTIONAL_INITIALIZER_CSRF'])->call($method,
            'https://proposal.example.invalid/enterprise-owner/owner-workspaces/'.$this->tenant->slug.'/proposals/reviewer-initialization/'.$action,
            [], $this->prepareCookiesForRequest(), [], array_replace(['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_ORIGIN' => 'https://proposal.example.invalid',
                'HTTP_X_CSRF_TOKEN' => 'FICTIONAL_INITIALIZER_CSRF', 'SCRIPT_NAME' => '/enterprise-owner/index.php', 'PHP_SELF' => '/enterprise-owner/index.php',
                'SCRIPT_FILENAME' => '/FICTIONAL/index.php', 'HTTPS' => 'on'], $headers), json_encode((object) $body, JSON_THROW_ON_ERROR));
    }

    private function statusBody(): array
    {
        return array_intersect_key($this->input, array_flip(['version_id', 'request_id', 'approval_sha256']));
    }

    private function tables(): array
    {
        $result = [];
        foreach (['users', 'tenants', 'tenant_user', 'team_area_members', 'team_tasks', 'service_proposal_versions', 'service_proposal_drafts'] as $table) {
            $result[$table] = DB::table($table)->orderBy('id')->get()->toJson();
        }
        $result['budget'] = (array) DB::table('team_operation_records')->where('id', $this->ledger->id)->first();

        return $result;
    }

    private function rows(): string
    {
        return DB::table('team_operation_records')->orderBy('id')->get()->toJson();
    }

    public function test_real_owner_preview_apply_status_and_exact_replay_preserve_changed_graph_original_version_and_all_native_authority(): void
    {
        $before = $this->tables();
        $rows = $this->rows();
        $preview = $this->callOwner('preview', $this->input)->assertOk()->assertJsonPath('status', 'preview')
            ->assertJsonPath('mutated', false)->assertJsonPath('observation.settled', false)->json('preview');
        $this->assertSame($rows, $this->rows());
        $this->assertSame($before, $this->tables());
        $apply = $this->input + ['preview' => $preview];
        $out = $this->callOwner('apply', $apply)->assertOk()->assertJsonPath('status', 'initialized')->assertJsonPath('settled', true)->assertJsonPath('replayed', false);
        $receipt = $out->json('receipt');
        $this->assertSame($this->input['request_id'], $receipt['request_id']);
        $this->assertStringNotContainsString('Fictional roof', $out->getContent());
        $this->assertSame(1, TeamOperationRecord::where('kind', ReviewerWorkspaceStore::KIND)->count());
        $this->callOwner('status', $this->statusBody())->assertOk()->assertJsonPath('receipt', $receipt);
        $reader = $this->recipient();
        app(ReviewerWorkspace::class)->command($reader, $this->token, $this->command());
        $changed = $this->rows();
        $this->callOwner('apply', $apply)->assertOk()->assertJsonPath('replayed', true)->assertJsonPath('receipt', $receipt);
        $this->assertSame($changed, $this->rows());
        $this->assertSame('Edited fictional roof check', app(ReviewerWorkspace::class)->projection($reader, $this->token, $this->binding(), $this->selection())['graph']['projects'][0]['title']);
        $this->assertSame($before, $this->tables());
        Http::assertNothingSent();
    }

    public function test_status_recovers_same_receipt_after_preview_and_data_approval_expiry_and_never_uses_absence_as_settled(): void
    {
        $this->callOwner('status', $this->statusBody())->assertOk()->assertExactJson(['status' => 'not_observed', 'settled' => false]);
        $preview = $this->callOwner('preview', $this->input)->assertOk()->json('preview');
        $this->callOwner('apply', $this->input + ['preview' => $preview])->assertOk();
        $rows = $this->rows();
        $this->travel(6)->minutes();
        config(['service_proposals.writes_enabled' => false, 'service_proposals.reviewer_workspace.initialization_enabled' => false]);
        $this->callOwner('apply', $this->input + ['preview' => $preview])->assertStatus(410)->assertJsonPath('settled', false);
        $this->callOwner('status', $this->statusBody())->assertOk()->assertJsonPath('settled', true)->assertJsonPath('receipt.request_id', $this->input['request_id']);
        $this->assertSame($rows, $this->rows());
    }

    public function test_preview_expiry_before_apply_leaves_no_graph_and_status_not_observed_is_still_unknown(): void
    {
        $preview = $this->callOwner('preview', $this->input)->assertOk()->json('preview');
        $this->travel(121)->seconds();
        $rows = $this->rows();
        $this->callOwner('apply', $this->input + ['preview' => $preview])->assertStatus(410)->assertJsonPath('settled', false);
        $this->callOwner('status', $this->statusBody())->assertOk()->assertExactJson(['status' => 'not_observed', 'settled' => false]);
        $this->assertSame($rows, $this->rows());
    }

    public function test_default_off_refuses_before_service_database_work(): void
    {
        config(['service_proposals.reviewer_workspace.owner_initialization.enabled' => false]);
        DB::flushQueryLog();
        DB::enableQueryLog();
        $out = app(ReviewerWorkspaceInitializationController::class)->handle($this->request(owner: true), $this->tenant->slug, 'preview');
        $this->assertSame(404, $out->getStatusCode());
        $this->assertSame([], DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertFalse($out->getData(true)['settled']);
    }

    public function test_session_csrf_origin_method_and_closed_body_are_actual_private_mounted_guards(): void
    {
        $before = $this->rows();
        config(['app.debug' => true]);
        $this->callOwner('preview', $this->input, ['HTTP_X_CSRF_TOKEN' => 'wrong'])->assertStatus(419)
            ->assertHeader('Content-Security-Policy', "default-src 'none'; base-uri 'none'; frame-ancestors 'none'")
            ->assertDontSee('Trace', false)->assertDontSee('vendor/', false);
        $this->callOwner('preview', $this->input, ['HTTP_ORIGIN' => 'https://foreign.example.invalid'])->assertStatus(403);
        $this->callOwner('preview', $this->input + ['actor_id' => $this->owner->id])->assertStatus(422);
        $this->callOwner('preview', $this->input, [], 'GET')->assertStatus(404)->assertHeader('Referrer-Policy', 'no-referrer');
        $this->app['auth']->forgetGuards();
        $this->callOwner('preview', $this->input)->assertStatus(403)->assertHeader('Cache-Control', 'no-store, private');
        $this->assertSame($before, $this->rows());
    }

    public function test_cached_request_user_cannot_override_current_inactive_or_unverified_user_or_revoked_membership(): void
    {
        $before = $this->rows();
        $preview = $this->callOwner('preview', $this->input)->assertOk()->json('preview');
        // The guard still holds the original active User instance; only direct DB state changes.
        DB::table('users')->where('id', $this->owner->id)->update(['is_active' => false]);
        $this->callOwner('apply', $this->input + ['preview' => $preview])->assertStatus(403);
        DB::table('users')->where('id', $this->owner->id)->update(['is_active' => true, 'email_verified_at' => null]);
        $this->callOwner('apply', $this->input + ['preview' => $preview])->assertStatus(403);
        DB::table('users')->where('id', $this->owner->id)->update(['email_verified_at' => now()]);
        DB::table('tenant_user')->where('user_id', $this->owner->id)->update(['membership_active' => false]);
        $this->callOwner('apply', $this->input + ['preview' => $preview])->assertStatus(403);
        $this->assertSame($before, $this->rows());
    }

    public function test_tampered_seed_import_preview_and_foreign_initial_intent_never_reset_or_write(): void
    {
        $preview = $this->callOwner('preview', $this->input)->assertOk()->json('preview');
        $rows = $this->rows();
        $bad = $this->input;
        $bad['seed']['projects'][0]['title'] = 'Unreviewed baseline';
        $this->callOwner('preview', $bad)->assertStatus(409);
        $bad = $this->input;
        $bad['import_evidence_sha256'] = str_repeat('0', 64);
        $this->callOwner('preview', $bad)->assertStatus(409);
        $badPreview = $preview;
        $badPreview['claims']['counts']['projects']++;
        $this->callOwner('apply', $this->input + ['preview' => $badPreview])->assertStatus(409);
        $this->assertSame($rows, $this->rows());
        $this->callOwner('apply', $this->input + ['preview' => $preview])->assertOk();
        $committed = $this->rows();
        $foreign = $this->statusBody();
        $foreign['request_id'] = (string) Str::uuid();
        $this->callOwner('status', $foreign)->assertStatus(409)->assertJsonPath('settled', false);
        $this->assertSame($committed, $this->rows());
    }

    public function test_current_version_predecessor_and_legacy_admission_are_not_bypassed_by_approved_json(): void
    {
        $rows = $this->rows();
        $preview = $this->callOwner('preview', $this->input)->assertOk()->json('preview');
        $this->version->revoked_at = now();
        $this->version->save();
        $this->callOwner('apply', $this->input + ['preview' => $preview])->assertStatus(410);
        $this->version->revoked_at = null;
        $this->version->save();
        config(['service_proposals.campus_review.legacy_versions' => [$this->version->id => ['tenant_id' => $this->tenant->id,
            'content_hash' => $this->version->content_hash, 'renderer' => 'lees-mcrae-v5']]]);
        $this->callOwner('apply', $this->input + ['preview' => $preview])->assertStatus(404);
        config(['service_proposals.campus_review.legacy_versions' => []]);
        DB::table('service_proposal_versions')->where('id', $this->predecessor)->update(['revoked_at' => now()]);
        $this->callOwner('apply', $this->input + ['preview' => $preview])->assertStatus(410);
        $this->assertSame($rows, $this->rows());
    }

    public function test_other_authenticated_session_cannot_reuse_preview_and_anchor_drift_is_unavailable(): void
    {
        $preview = $this->callOwner('preview', $this->input)->assertOk()->json('preview');
        $rows = $this->rows();
        $this->app['session.store']->migrate(true);
        $this->callOwner('apply', $this->input + ['preview' => $preview])->assertStatus(409)->assertJsonPath('settled', false);
        $current = $this->callOwner('preview', $this->input)->assertOk()->json('preview');
        config(['service_proposals.reviewer_workspace.anchor_marker_sha256' => str_repeat('0', 64)]);
        $this->callOwner('apply', $this->input + ['preview' => $current])->assertStatus(503)->assertJsonPath('settled', false);
        $this->assertSame($rows, $this->rows());
    }

    public function test_current_build_drift_and_expired_version_never_become_a_definitive_not_applied_result(): void
    {
        $preview = $this->callOwner('preview', $this->input)->assertOk()->json('preview');
        $rows = $this->rows();
        config(['service_proposals.campus_review.build_id' => 'FICTIONAL_DIFFERENT_BUILD']);
        $this->callOwner('apply', $this->input + ['preview' => $preview])->assertStatus(409)->assertJsonPath('settled', false);
        config(['service_proposals.campus_review.build_id' => $this->binding()['build_id']]);
        $this->travel(8)->days();
        $this->callOwner('status', $this->statusBody())->assertStatus(410)->assertJsonPath('settled', false);
        $this->assertSame($rows, $this->rows());
    }

    public function test_late_membership_change_inside_native_initializer_rolls_back_before_outer_commit(): void
    {
        $preview = $this->callOwner('preview', $this->input)->assertOk()->json('preview');
        $before = [$this->rows(), $this->tables()];
        $event = 'eloquent.created: '.TeamOperationRecord::class;
        // Explicit local SQLite transaction seam, NOT another-process MySQL race proof.
        Event::listen($event, function (TeamOperationRecord $row): void {
            if ($row->kind === ReviewerWorkspaceStore::KIND) {
                DB::table('tenant_user')->where('tenant_id', $this->tenant->id)->where('user_id', $this->owner->id)
                    ->update(['membership_active' => false]);
            }
        });
        try {
            $this->callOwner('apply', $this->input + ['preview' => $preview])->assertStatus(403)->assertJsonPath('settled', false);
        } finally {
            Event::forget($event);
        }
        $this->assertSame($before, [$this->rows(), $this->tables()]);
        $this->assertSame(0, TeamOperationRecord::where('kind', ReviewerWorkspaceStore::KIND)->count());
    }

    public function test_runtime_approval_parser_refuses_unreviewed_baseline_types_unknown_authority_and_digest_drift(): void
    {
        $json = json_encode((object) [$this->input['approval_sha256'] => $this->approval]);
        $this->assertSame([$this->input['approval_sha256'] => $this->approval], ReviewerWorkspaceInitialization::parseApprovals($json));
        $this->assertSame([], ReviewerWorkspaceInitialization::parseApprovals(''));
        foreach (['[]', 'null', '{', str_repeat('x', 24001)] as $bad) {
            $this->assertNull(ReviewerWorkspaceInitialization::parseApprovals($bad));
        }
        foreach (['data_basis' => 'fresh_source_baseline', 'schema_version' => '1', 'actor_id' => $this->owner->id,
            'seed_sha256' => str_repeat('f', 64)] as $key => $value) {
            $bad = $this->approval;
            $bad[$key] = $value;
            $this->assertNull(ReviewerWorkspaceInitialization::parseApprovals(json_encode((object) [$this->input['approval_sha256'] => $bad])));
        }
    }
}

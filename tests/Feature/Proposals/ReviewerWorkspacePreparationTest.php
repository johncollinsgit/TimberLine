<?php

namespace Tests\Feature\Proposals;

use App\Proposals\Draft;
use App\Proposals\ProposalService;
use App\Proposals\ReviewerWorkspacePreparation;
use App\Proposals\ReviewerWorkspaceSchema;
use App\Proposals\Version;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Support\ReviewerWorkspaceFixture;

/** Actual mounted owner/CSRF, private SQLite fixture. No runtime capture or publication authority. */
final class ReviewerWorkspacePreparationTest extends ReviewerWorkspaceFixture
{
    private Draft $original;

    private array $approval;

    private array $input;

    protected function setUp(): void
    {
        parent::setUp();
        $this->original = app(ProposalService::class)->leesMcRaeDraft($this->tenant, $this->owner->id);
        $this->original->refresh();
        $descriptor = $this->version->payload['review_workspace'];
        $descriptor['workspace_id'] = (string) Str::uuid();
        $comments = ['schema_version' => 1, 'comments_enabled' => true, 'uploads_enabled' => false, 'write_personas' => ['demo_manager']];
        $hash = ReviewerWorkspaceSchema::digest($comments);
        config(['service_proposals.reviewer_workspace.collaboration.policies' => [$hash => $comments]]);
        $this->approval = ['schema_version' => 1, 'data_basis' => 'reviewed_current_edits',
            'source_draft_id' => $this->original->id, 'source_draft_revision' => (int) $this->original->revision,
            'source_draft_sha256' => app(ProposalService::class)->hash($this->original->payload),
            'prepared_draft_id' => (string) Str::uuid(), 'predecessor_version_id' => $this->version->id,
            'predecessor_version_hash' => $this->version->content_hash, 'seed_sha256' => $descriptor['seed_sha256'],
            'import_evidence_sha256' => str_repeat('e', 64), 'workspace_descriptor' => $descriptor,
            'collaboration_descriptor' => ['schema_version' => 1, 'policy_sha256' => $hash, 'expires_at' => $descriptor['expires_at']],
            'build_id' => $this->binding()['build_id'], 'source_hash' => $this->binding()['source_hash'],
            'expires_at' => now()->addMinutes(5)->utc()->format('Y-m-d\TH:i:s\Z')];
        $this->input = ['approval_sha256' => ReviewerWorkspaceSchema::digest($this->approval), 'request_id' => (string) Str::uuid(),
            'import_evidence_sha256' => $this->approval['import_evidence_sha256']];
        config(['service_proposals.reviewer_workspace.owner_preparation' => ['enabled' => true, 'approvals' => [$this->input['approval_sha256'] => $this->approval]]]);
        $this->actingAs($this->owner);
    }

    private function ownerCall(string $path, array $body, bool $json = true, array $headers = [], string $method = 'POST')
    {
        $this->withCookie(config('session.cookie'), $this->app['session.store']->getId());
        $server = array_replace(['CONTENT_TYPE' => $json ? 'application/json' : 'application/x-www-form-urlencoded',
            'HTTP_ACCEPT' => $json ? 'application/json' : 'text/html', 'HTTP_ORIGIN' => 'https://proposal.example.invalid',
            'HTTP_X_CSRF_TOKEN' => 'FICTIONAL_PREPARATION_CSRF', 'SCRIPT_NAME' => '/enterprise-owner/index.php',
            'PHP_SELF' => '/enterprise-owner/index.php', 'SCRIPT_FILENAME' => '/FICTIONAL/index.php', 'HTTPS' => 'on'], $headers);

        return $this->withSession(['_token' => 'FICTIONAL_PREPARATION_CSRF'])->call($method,
            'https://proposal.example.invalid/enterprise-owner/owner-workspaces/'.$this->tenant->slug.'/proposals/'.$path,
            $json ? [] : $body, $this->prepareCookiesForRequest(), [], $server, $json ? json_encode((object) $body, JSON_THROW_ON_ERROR) : null);
    }

    private function prep(string $action, ?array $body = null)
    {
        return $this->ownerCall('reviewer-preparation/'.$action, $body ?? $this->input);
    }

    private function prepare(): array
    {
        $preview = $this->prep('preview')->assertOk()->assertJsonPath('mutated', false)->json('preview');
        $body = $this->input + ['preview' => $preview];
        $this->prep('apply', $body)->assertOk()->assertJsonPath('status', 'prepared')->assertJsonPath('replayed', false);

        return $body;
    }

    private function statusBody(): array
    {
        return array_intersect_key($this->input, array_flip(['approval_sha256', 'request_id']));
    }

    private function protectedState(): array
    {
        $rows = [];
        foreach (['users', 'tenants', 'tenant_user', 'team_areas', 'team_area_members', 'team_tasks', 'team_operation_records',
            'service_proposal_versions', 'service_proposal_comments', 'service_proposal_reviews'] as $table) {
            $rows[$table] = DB::table($table)->orderBy('id')->get()->toJson();
        }
        $rows['original'] = (array) DB::table('service_proposal_drafts')->where('id', $this->original->id)->first();

        return $rows;
    }

    private function publishBody(?string $key = null): array
    {
        return ['_token' => 'FICTIONAL_PREPARATION_CSRF', 'revision' => 1, 'password' => 'FICTIONAL_NEW_PRIVATE_PASSWORD',
            'days' => 7, 'publish_key' => $key ?? (string) Str::uuid(), 'confirm' => '1', 'audience' => 'reviewer'];
    }

    private function publishPrepared(array $body, array $headers = [])
    {
        return $this->ownerCall($this->approval['prepared_draft_id'].'/publish', $body, false, $headers);
    }

    public function test_preview_apply_status_replay_create_only_one_new_private_draft_preserving_original_and_all_authority(): void
    {
        $before = $this->protectedState();
        $drafts = DB::table('service_proposal_drafts')->orderBy('id')->get()->toJson();
        $preview = $this->prep('preview')->assertOk()->json('preview');
        $this->assertSame($drafts, DB::table('service_proposal_drafts')->orderBy('id')->get()->toJson());
        $input = $this->input + ['preview' => $preview];
        $out = $this->prep('apply', $input)->assertOk()->assertJsonPath('replayed', false);
        $receipt = $out->json('receipt');
        $this->assertSame($before, $this->protectedState());
        $this->assertSame(3, Draft::count());
        $prepared = Draft::findOrFail($receipt['draft_id']);
        $payload = $prepared->payload;
        unset($payload[ReviewerWorkspacePreparation::MARKER], $payload['review_workspace'], $payload['review_workspace_collaboration']);
        $this->assertSame($this->original->payload, $payload);
        $this->assertSame($this->approval['workspace_descriptor'], $prepared->payload['review_workspace']);
        $committed = DB::table('service_proposal_drafts')->orderBy('id')->get()->toJson();
        $this->prep('apply', $input)->assertOk()->assertJsonPath('receipt', $receipt)->assertJsonPath('replayed', true);
        $this->prep('status', $this->statusBody())->assertOk()->assertJsonPath('receipt', $receipt);
        $this->assertSame($committed, DB::table('service_proposal_drafts')->orderBy('id')->get()->toJson());
        Http::assertNothingSent();
    }

    public function test_normal_html_publish_uses_hidden_csrf_strips_private_marker_then_exact_replay_after_expiry_and_original_edit_is_not_new_publication(): void
    {
        $this->prepare();
        $before = $this->protectedState();
        $body = $this->publishBody();
        $this->publishPrepared($body, ['HTTP_X_CSRF_TOKEN' => ''])->assertRedirect();
        $published = Version::where('draft_id', $this->approval['prepared_draft_id'])->firstOrFail();
        $this->assertArrayNotHasKey(ReviewerWorkspacePreparation::MARKER, $published->payload);
        $this->assertSame($this->approval['workspace_descriptor'], $published->payload['review_workspace']);
        $this->assertSame($this->approval['collaboration_descriptor'], $published->payload['review_workspace_collaboration']);
        $this->assertSame(app(ProposalService::class)->hash($published->payload), $published->content_hash);
        $this->assertSame(1, Version::where('draft_id', $this->approval['prepared_draft_id'])->count());
        $this->travel(6)->minutes();
        $payload = $this->original->payload;
        $payload['title'] = 'A later commercial edit';
        $this->original->update(['payload' => $payload, 'revision' => 2]);
        config(['service_proposals.reviewer_workspace.owner_preparation.enabled' => false]);
        $this->prep('status', $this->statusBody())->assertOk()->assertJsonPath('publishable', false)->assertJsonPath('settled', true);
        $rows = DB::table('service_proposal_versions')->orderBy('id')->get()->toJson();
        $this->publishPrepared($body, ['HTTP_X_CSRF_TOKEN' => ''])->assertRedirect();
        $this->assertSame($rows, DB::table('service_proposal_versions')->orderBy('id')->get()->toJson());
        $this->publishPrepared($this->publishBody())->assertStatus(404);
        unset($before['service_proposal_versions'], $before['original']);
        $after = $this->protectedState();
        unset($after['service_proposal_versions'], $after['original']);
        $this->assertSame($before, $after);
    }

    public function test_stale_commercial_source_blocks_new_apply_and_new_publish_without_erasing_receipt(): void
    {
        $preview = $this->prep('preview')->assertOk()->json('preview');
        $this->original->update(['revision' => 2]);
        $this->prep('apply', $this->input + ['preview' => $preview])->assertStatus(409);
        $this->assertNull(Draft::find($this->approval['prepared_draft_id']));
        $this->original->update(['revision' => 1]);
        $this->prepare();
        $this->original->update(['revision' => 2]);
        $this->publishPrepared($this->publishBody())->assertStatus(409);
        $this->prep('status', $this->statusBody())->assertOk()->assertJsonPath('publishable', false);
        $this->assertSame(0, Version::where('draft_id', $this->approval['prepared_draft_id'])->count());
    }

    public function test_prepared_save_and_missing_marker_reserved_id_are_read_only_even_with_gate_off(): void
    {
        $this->prepare();
        config(['service_proposals.reviewer_workspace.owner_preparation.enabled' => false]);
        $draft = Draft::findOrFail($this->approval['prepared_draft_id']);
        $input = ['revision' => 1, 'client_id' => $draft->client_id, 'title' => 'Remove descriptors', 'catalog_ids' => array_column($draft->payload['items'], 'id')];
        $before = $draft->getRawOriginal('payload');
        $this->ownerCall($draft->id, $input, false, [], 'PUT')->assertStatus(409);
        $this->assertSame($before, $draft->fresh()->getRawOriginal('payload'));
        $this->ownerCall($draft->id.'/edit', [], false, [], 'GET')->assertOk()->assertSee('prepared draft is read-only')->assertDontSee('Save draft');
        $payload = $draft->payload;
        unset($payload[ReviewerWorkspacePreparation::MARKER]);
        $draft->update(['payload' => $payload]);
        $this->publishPrepared($this->publishBody())->assertStatus(409);
        $this->assertTrue(ReviewerWorkspacePreparation::isPrepared($draft->fresh()));
    }

    public function test_missing_csrf_wrong_origin_closed_body_expired_preview_and_revoked_owner_leave_no_prepared_draft(): void
    {
        config(['app.debug' => true]);
        $this->ownerCall('reviewer-preparation/preview', $this->input, true, ['HTTP_X_CSRF_TOKEN' => 'bad'])->assertStatus(419)
            ->assertHeader('Referrer-Policy', 'no-referrer')->assertDontSee('Trace', false);
        $this->ownerCall('reviewer-preparation/preview', $this->input, true, ['HTTP_ORIGIN' => 'https://foreign.invalid'])->assertStatus(403);
        $this->prep('preview', $this->input + ['seed' => $this->seed])->assertStatus(422);
        $preview = $this->prep('preview')->assertOk()->json('preview');
        $this->travel(121)->seconds();
        $this->prep('apply', $this->input + ['preview' => $preview])->assertStatus(410);
        $this->travelBack();
        DB::table('tenant_user')->where('user_id', $this->owner->id)->update(['membership_active' => false]);
        $this->prep('apply', $this->input + ['preview' => $preview])->assertStatus(403);
        $this->assertNull(Draft::find($this->approval['prepared_draft_id']));
    }

    public function test_different_session_import_and_intent_cannot_replay_and_status_absence_is_unsettled(): void
    {
        $this->prep('status', $this->statusBody())->assertOk()->assertExactJson(['status' => 'not_observed', 'settled' => false]);
        $preview = $this->prep('preview')->assertOk()->json('preview');
        $this->app['session.store']->migrate(true);
        $this->prep('apply', $this->input + ['preview' => $preview])->assertStatus(409);
        $this->prepare();
        $foreign = $this->statusBody();
        $foreign['request_id'] = (string) Str::uuid();
        $this->prep('status', $foreign)->assertStatus(409)->assertJsonPath('settled', false);
    }

    public function test_unreviewed_import_proof_is_not_a_recovery_seed(): void
    {
        $before = $this->protectedState();
        $bad = $this->input;
        $bad['import_evidence_sha256'] = str_repeat('0', 64);
        $this->prep('preview', $bad)->assertStatus(409);
        $this->assertSame($before, $this->protectedState());
    }

    public function test_late_owner_revocation_during_draft_creation_rolls_back_clone_and_authority_change(): void
    {
        $preview = $this->prep('preview')->assertOk()->json('preview');
        $before = $this->protectedState();
        $event = 'eloquent.created: '.Draft::class;
        Event::listen($event, function (Draft $draft): void {
            if ($draft->id === $this->approval['prepared_draft_id']) {
                DB::table('tenant_user')->where('user_id', $this->owner->id)->update(['membership_active' => false]);
            }
        });
        try {
            $this->prep('apply', $this->input + ['preview' => $preview])->assertStatus(403);
        } finally {
            Event::forget($event);
        }
        $this->assertSame($before, $this->protectedState());
        $this->assertNull(Draft::find($this->approval['prepared_draft_id']));
    }

    public function test_marker_tamper_wrong_audience_second_publish_and_late_version_creation_revocation_cannot_widen_publication(): void
    {
        $this->prepare();
        $body = $this->publishBody();
        $bad = $body;
        $bad['audience'] = 'client';
        $this->publishPrepared($bad)->assertStatus(409);
        $this->publishPrepared($body)->assertRedirect();
        $this->publishPrepared($this->publishBody())->assertStatus(409);
        $this->assertSame(1, Version::where('draft_id', $this->approval['prepared_draft_id'])->count());
        $draft = Draft::findOrFail($this->approval['prepared_draft_id']);
        $payload = $draft->payload;
        $payload['review_workspace']['seed_sha256'] = str_repeat('0', 64);
        $draft->update(['payload' => $payload]);
        $this->publishPrepared($body)->assertStatus(409);
    }

    public function test_late_owner_revocation_inside_new_publication_rolls_back_version_and_does_not_change_original(): void
    {
        $this->prepare();
        $before = $this->protectedState();
        $event = 'eloquent.created: '.Version::class;
        Event::listen($event, function (Version $version): void {
            if ($version->draft_id === $this->approval['prepared_draft_id']) {
                DB::table('tenant_user')->where('user_id', $this->owner->id)->update(['membership_active' => false]);
            }
        });
        try {
            $this->publishPrepared($this->publishBody())->assertStatus(403);
        } finally {
            Event::forget($event);
        }
        $this->assertSame($before, $this->protectedState());
    }

    public function test_expired_preparation_replay_and_gate_off_status_keep_original_receipt_but_not_new_intent(): void
    {
        $body = $this->prepare();
        $rows = DB::table('service_proposal_drafts')->orderBy('id')->get()->toJson();
        $this->travel(6)->minutes();
        $this->prep('apply', $body)->assertOk()->assertJsonPath('replayed', true);
        config(['service_proposals.reviewer_workspace.owner_preparation.enabled' => false]);
        $this->prep('status', $this->statusBody())->assertOk()->assertJsonPath('publishable', false);
        $this->assertSame($rows, DB::table('service_proposal_drafts')->orderBy('id')->get()->toJson());
    }

    public function test_current_release_policy_predecessor_hash_and_plain_marker_cannot_be_supplied_as_authority(): void
    {
        $before = $this->protectedState();
        config(['service_proposals.campus_review.build_id' => 'changed-source']);
        $this->prep('preview')->assertStatus(409);
        config(['service_proposals.campus_review.build_id' => $this->approval['build_id']]);
        config(['service_proposals.reviewer_workspace.collaboration.policies' => []]);
        $this->prep('preview')->assertStatus(503);
        $this->assertSame($before, $this->protectedState());
        foreach (['data_basis' => 'fresh_source_baseline', 'source_draft_revision' => '1', 'actor_id' => $this->owner->id,
            'workspace_descriptor' => [], 'collaboration_descriptor' => [], 'prepared_draft_id' => $this->original->id] as $key => $value) {
            $bad = $this->approval;
            $bad[$key] = $value;
            $this->assertNull(ReviewerWorkspacePreparation::parseApprovals(json_encode((object) [ReviewerWorkspaceSchema::digest($bad) => $bad])));
        }
    }

    public function test_optional_comments_are_absent_and_predecessor_revocation_or_raw_hash_drift_prevents_preparation(): void
    {
        $this->approval['collaboration_descriptor'] = null;
        $this->input['approval_sha256'] = ReviewerWorkspaceSchema::digest($this->approval);
        config(['service_proposals.reviewer_workspace.owner_preparation.approvals' => [$this->input['approval_sha256'] => $this->approval],
            'service_proposals.reviewer_workspace.collaboration.policies' => []]);
        $preview = $this->prep('preview')->assertOk()->json('preview');
        $version = $this->version;
        $originalRaw = $version->getRawOriginal('payload');
        $payload = $version->payload;
        $payload['title'] = 'Unbound persisted predecessor';
        // Explicit test-only raw corruption: the normal immutable model correctly forbids this update.
        $version->payload = $payload;
        DB::table('service_proposal_versions')->where('id', $version->id)->update(['payload' => $version->getAttributes()['payload']]);
        $this->prep('apply', $this->input + ['preview' => $preview])->assertStatus(409);
        DB::table('service_proposal_versions')->where('id', $version->id)->update(['payload' => $originalRaw, 'revoked_at' => now()]);
        $this->prep('apply', $this->input + ['preview' => $preview])->assertStatus(410);
        DB::table('service_proposal_versions')->where('id', $version->id)->update(['revoked_at' => null]);
        $this->prep('apply', $this->input + ['preview' => $preview])->assertOk();
        $prepared = Draft::findOrFail($this->approval['prepared_draft_id']);
        $this->assertArrayNotHasKey('review_workspace_collaboration', $prepared->payload);
    }

    public function test_json_array_object_null_or_numeric_approval_hash_is_closed_422_before_authority_work(): void
    {
        $before = $this->protectedState();
        foreach ([[], (object) [], null, 17] as $invalid) {
            $bad = $this->input;
            $bad['approval_sha256'] = $invalid;
            $this->prep('preview', $bad)->assertStatus(422)->assertJsonPath('settled', false);
        }
        $this->assertSame($before, $this->protectedState());
    }

    public function test_new_prepared_publication_refuses_short_lifetime_before_any_version_and_keeps_original_intent_available(): void
    {
        $this->prepare();
        $before = $this->protectedState();
        $body = $this->publishBody();
        $body['days'] = 1;
        $this->publishPrepared($body)->assertStatus(409);
        $this->assertSame(0, Version::where('draft_id', $this->approval['prepared_draft_id'])->count());
        $this->assertSame($before, $this->protectedState());
        $body['days'] = 7;
        $this->publishPrepared($body)->assertRedirect();
        $published = Version::where('draft_id', $this->approval['prepared_draft_id'])->firstOrFail();
        $this->assertSame($this->approval['workspace_descriptor'], app(\App\Proposals\ReviewerWorkspaceAccess::class)->publication($published)['descriptor']);
    }

    public function test_new_prepared_publication_accepts_equal_descriptor_expiry_and_preserves_normal_one_day_publication(): void
    {
        $this->travelTo(now()->startOfSecond());
        $this->prepare();
        $body = $this->publishBody();
        $body['days'] = 6;
        $this->publishPrepared($body)->assertRedirect();
        $published = Version::where('draft_id', $this->approval['prepared_draft_id'])->firstOrFail();
        $this->assertSame($this->approval['workspace_descriptor']['expires_at'], $published->expires_at->utc()->format('Y-m-d\TH:i:s\Z'));
        app(\App\Proposals\ReviewerWorkspaceAccess::class)->publication($published);
        $ordinary = $this->publishBody();
        $ordinary['days'] = 1;
        $this->ownerCall($this->original->id.'/publish', $ordinary, false)->assertRedirect();
        $normal = Version::where('draft_id', $this->original->id)->firstOrFail();
        $this->assertSame(now()->addDay()->utc()->format('Y-m-d\TH:i:s\Z'), $normal->expires_at->utc()->format('Y-m-d\TH:i:s\Z'));
    }

    public function test_expired_optional_comments_prevents_new_preview_and_publication_but_status_and_original_receipts_recover(): void
    {
        $this->approval['collaboration_descriptor']['expires_at'] = now()->addSeconds(60)->utc()->format('Y-m-d\TH:i:s\Z');
        $this->input['approval_sha256'] = ReviewerWorkspaceSchema::digest($this->approval);
        config(['service_proposals.reviewer_workspace.owner_preparation.approvals' => [$this->input['approval_sha256'] => $this->approval]]);
        $apply = $this->prepare();
        $before = $this->protectedState();
        $body = $this->publishBody();
        $this->travel(61)->seconds();
        $this->prep('status', $this->statusBody())->assertOk()->assertJsonPath('settled', true)->assertJsonPath('publishable', false);
        $this->prep('preview')->assertStatus(410);
        $this->prep('apply', $apply)->assertOk()->assertJsonPath('replayed', true);
        $this->publishPrepared($body)->assertStatus(410);
        $this->assertSame(0, Version::where('draft_id', $this->approval['prepared_draft_id'])->count());
        $this->assertSame($before, $this->protectedState());
        $this->travelBack();
        $this->publishPrepared($body)->assertRedirect();
        $published = DB::table('service_proposal_versions')->where('draft_id', $this->approval['prepared_draft_id'])->get()->toJson();
        $this->travel(61)->seconds();
        $this->publishPrepared($body)->assertRedirect();
        $this->assertSame($published, DB::table('service_proposal_versions')->where('draft_id', $this->approval['prepared_draft_id'])->get()->toJson());
    }

    public function test_default_off_parser_and_no_direct_service_request_identity_for_prepared_publication(): void
    {
        $this->assertSame([], ReviewerWorkspacePreparation::parseApprovals(''));
        $encoded = json_encode((object) [$this->input['approval_sha256'] => $this->approval]);
        $this->assertSame([$this->input['approval_sha256'] => $this->approval], ReviewerWorkspacePreparation::parseApprovals($encoded));
        foreach (['[]', 'null', '{', str_repeat('x', 24001)] as $bad) {
            $this->assertNull(ReviewerWorkspacePreparation::parseApprovals($bad));
        }
        $this->prepare();
        try {
            app(ProposalService::class)->publish($this->tenant, $this->owner->id, $this->approval['prepared_draft_id'], 1,
                'FICTIONAL_NEW_PRIVATE_PASSWORD', 7, (string) Str::uuid(), 'reviewer', $this->request(owner: true));
            $this->fail('A constructed Request cannot publish a prepared draft.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $error) {
            $this->assertSame(403, $error->getStatusCode());
        }
        config(['service_proposals.reviewer_workspace.owner_preparation.enabled' => false]);
        $this->prep('preview')->assertNotFound();
        $this->assertSame(1, Version::count());
    }
}

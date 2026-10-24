<?php

namespace Tests\Feature\Proposals;

use App\Proposals\Draft;
use App\Proposals\ProposalService;
use App\Proposals\ReviewerWorkspaceSchema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\Support\ReviewerWorkspaceFixture;

/** Actual mounted owner/CSRF, private SQLite fixture. No runtime capture or publication authority. */
final class IndependentPreparationAuthorityTest extends ReviewerWorkspaceFixture
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

    public function test_consent_expiring_after_actual_clone_creation_rolls_back_before_commit(): void
    {
        $preview = $this->prep('preview')->assertOk()->json('preview');
        $before = $this->protectedState();
        $drafts = DB::table('service_proposal_drafts')->orderBy('id')->get()->toJson();
        $created = 0;
        $event = 'eloquent.created: '.Draft::class;
        Event::listen($event, function (Draft $draft) use (&$created): void {
            if ($draft->id === $this->approval['prepared_draft_id']) {
                $created++;
                $this->travel(121)->seconds();
            }
        });
        try {
            $this->prep('apply', $this->input + ['preview' => $preview])->assertStatus(410)->assertJsonPath('settled', false);
        } finally {
            Event::forget($event);
        }
        $this->assertSame(1, $created);
        $this->assertNull(Draft::find($this->approval['prepared_draft_id']));
        $this->assertSame($drafts, DB::table('service_proposal_drafts')->orderBy('id')->get()->toJson());
        $this->assertSame($before, $this->protectedState());
    }

    public function test_other_actual_admin_in_same_session_cannot_recover_or_apply_original_actors_receipt(): void
    {
        $apply = $this->prepare();
        $receipt = $this->prep('status', $this->statusBody())->assertOk()->json('receipt');
        $session = $this->app['session.store']->getId();
        $other = \App\Models\User::create(['name' => 'FICTIONAL SECOND OWNER', 'email' => 'prep-second-owner@example.invalid',
            'password' => \Illuminate\Support\Facades\Hash::make('FICTIONAL_ONLY'), 'is_active' => true]);
        $other->forceFill(['email_verified_at' => now()])->save();
        $this->tenant->users()->attach($other->id, ['role' => 'admin', 'membership_active' => true]);
        $this->actingAs($other);
        $before = $this->protectedState();
        $drafts = DB::table('service_proposal_drafts')->orderBy('id')->get()->toJson();
        $this->prep('status', $this->statusBody())->assertStatus(409)->assertJsonPath('settled', false);
        $this->prep('apply', $apply)->assertStatus(409)->assertJsonPath('settled', false);
        $this->assertSame($session, $this->app['session.store']->getId());
        $this->assertSame($drafts, DB::table('service_proposal_drafts')->orderBy('id')->get()->toJson());
        $this->assertSame($before, $this->protectedState());
        $this->assertSame($this->approval['prepared_draft_id'], $receipt['draft_id']);
    }

    public function test_unchanged_revision_does_not_hide_commercial_source_hash_drift(): void
    {
        $preview = $this->prep('preview')->assertOk()->json('preview');
        $payload = $this->original->payload;
        $payload['title'] = 'FICTIONAL changed source with same revision';
        $this->original->update(['payload' => $payload]);
        $this->assertSame($this->approval['source_draft_revision'], (int) $this->original->fresh()->revision);
        $before = $this->protectedState();
        $this->prep('apply', $this->input + ['preview' => $preview])->assertStatus(409)->assertJsonPath('settled', false);
        $this->assertNull(Draft::find($this->approval['prepared_draft_id']));
        $this->assertSame($before, $this->protectedState());
        $this->prep('status', $this->statusBody())->assertOk()->assertExactJson(['status' => 'not_observed', 'settled' => false]);
    }
}

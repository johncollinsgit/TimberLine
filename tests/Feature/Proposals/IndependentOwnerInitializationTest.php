<?php

namespace Tests\Feature\Proposals;

use App\Models\TeamOperationRecord;
use App\Proposals\ReviewerWorkspaceSchema;
use App\Proposals\ReviewerWorkspaceStore;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\Support\ReviewerWorkspaceFixture;

/** Real mounted isolated owner HTTP, native publication/CSRF/storage; no provider or production authority. */
final class IndependentOwnerInitializationTest extends ReviewerWorkspaceFixture
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
        foreach (['users', 'tenants', 'tenant_user', 'team_area_members', 'team_tasks', 'service_proposal_versions', 'service_proposal_drafts', 'team_work_locks', 'team_work_receipts', 'team_operation_revisions'] as $table) {
            $result[$table] = DB::table($table)->orderBy('id')->get()->toJson();
        }
        $result['budget'] = (array) DB::table('team_operation_records')->where('id', $this->ledger->id)->first();

        return $result;
    }

    private function rows(): string
    {
        return DB::table('team_operation_records')->orderBy('id')->get()->toJson();
    }

    public function test_consent_expiring_inside_native_create_rolls_back_all_effects(): void
    {
        $preview = $this->callOwner('preview', $this->input)->assertOk()->json('preview');
        $before = [$this->rows(), $this->tables()];
        $event = 'eloquent.created: '.TeamOperationRecord::class;
        $created = 0;
        Event::listen($event, function (TeamOperationRecord $row) use (&$created): void {
            if ($row->kind === ReviewerWorkspaceStore::KIND) {
                $created++;
                $this->travel(121)->seconds();
            }
        });
        try {
            $this->callOwner('apply', $this->input + ['preview' => $preview])->assertStatus(410)->assertJsonPath('settled', false);
        } finally {
            Event::forget($event);
        }
        $this->assertSame(1, $created, 'Consent must expire after the native create, not before dispatch.');
        $this->assertSame($before, [$this->rows(), $this->tables()]);
        $this->assertSame(0, TeamOperationRecord::where('kind', ReviewerWorkspaceStore::KIND)->count());
    }

    public function test_same_session_other_current_owner_cannot_reuse_first_actors_consent(): void
    {
        $preview = $this->callOwner('preview', $this->input)->assertOk()->json('preview');
        $session = $this->app['session.store']->getId();
        $other = \App\Models\User::query()->create(['name' => 'FICTIONAL SECOND OWNER', 'email' => 'second-owner@example.invalid',
            'password' => \Illuminate\Support\Facades\Hash::make('FICTIONAL_ONLY'), 'is_active' => true]);
        $other->forceFill(['email_verified_at' => now()])->save();
        $this->tenant->users()->attach($other->id, ['role' => 'admin', 'membership_active' => true]);
        $this->actingAs($other);
        $before = [$this->rows(), $this->tables()];
        $this->callOwner('apply', $this->input + ['preview' => $preview])->assertStatus(409)->assertJsonPath('settled', false);
        $this->assertSame($session, $this->app['session.store']->getId());
        $this->assertSame($before, [$this->rows(), $this->tables()]);
        $fresh = $this->callOwner('preview', $this->input)->assertOk()->json('preview');
        $this->assertSame($other->id, $fresh['claims']['actor_id']);
        $this->assertNotSame($preview['signature'], $fresh['signature']);
    }

    public function test_raw_predecessor_payload_drift_is_rejected_even_when_stored_content_hash_is_unchanged(): void
    {
        $preview = $this->callOwner('preview', $this->input)->assertOk()->json('preview');
        $old = \App\Proposals\Version::query()->findOrFail($this->predecessor);
        $hash = $old->content_hash;
        $payload = $old->payload;
        $payload['intro'] = 'FICTIONAL CORRUPTED PREDECESSOR';
        $old->payload = $payload;
        // Fault injection uses raw storage only, preserving the real immutable model's normal save prohibition.
        DB::table('service_proposal_versions')->where('id', $old->id)->update(['payload' => $old->getAttributes()['payload']]);
        $this->assertSame($hash, $old->fresh()->content_hash);
        $before = [$this->rows(), $this->tables()];
        $this->callOwner('apply', $this->input + ['preview' => $preview])->assertStatus(409)->assertJsonPath('settled', false);
        $this->assertSame($before, [$this->rows(), $this->tables()]);
        $this->assertSame(0, TeamOperationRecord::where('kind', ReviewerWorkspaceStore::KIND)->count());
    }

    public function test_expired_consent_recovery_with_ordinary_writes_off_issues_only_selects_and_returns_original_receipt(): void
    {
        $preview = $this->callOwner('preview', $this->input)->assertOk()->json('preview');
        $receipt = $this->callOwner('apply', $this->input + ['preview' => $preview])->assertOk()->json('receipt');
        $this->travel(6)->minutes();
        config(['service_proposals.writes_enabled' => false, 'service_proposals.reviewer_workspace.initialization_enabled' => false]);
        $before = [$this->rows(), $this->tables()];
        DB::flushQueryLog();
        DB::enableQueryLog();
        try {
            $this->callOwner('status', $this->statusBody())->assertOk()->assertJsonPath('settled', true)->assertJsonPath('receipt', $receipt);
            $queries = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
        }
        $this->assertNotEmpty($queries);
        foreach ($queries as $query) {
            $this->assertMatchesRegularExpression('/^\s*select\b/i', $query['query']);
        }
        $this->assertSame($before, [$this->rows(), $this->tables()]);
    }
}

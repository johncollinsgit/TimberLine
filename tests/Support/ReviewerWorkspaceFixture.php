<?php

namespace Tests\Support;

use App\Models\TeamArea;
use App\Models\User;
use App\Proposals\ProposalService;
use App\Proposals\RecipientController;
use App\Proposals\ReviewerWorkspace;
use App\Proposals\ReviewerWorkspaceSchema;
use App\Proposals\Version;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/** Actual private owner silo, explicit fixture publication/anchor/owner, separate anonymous unlocked sessions. */
abstract class ReviewerWorkspaceFixture extends ReviewerAiFixture
{
    protected User $owner;

    protected Version $version;

    protected string $token;

    protected array $seed;

    protected array $policy;

    protected array $ids = ['a' => '81000000-0000-4000-8000-000000000001', 'b' => '81000000-0000-4000-8000-000000000002',
        'building' => '82000000-0000-4000-8000-000000000001', 'other_building' => '82000000-0000-4000-8000-000000000002',
        'project' => '83000000-0000-4000-8000-000000000001', 'other_project' => '83000000-0000-4000-8000-000000000002',
        'task' => '84000000-0000-4000-8000-000000000001', 'ticket' => '85000000-0000-4000-8000-000000000001',
        'incoming' => '85000000-0000-4000-8000-000000000002', 'contractor' => '86000000-0000-4000-8000-000000000001',
        'plan' => '87000000-0000-4000-8000-000000000001'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::query()->create(['name' => 'FICTIONAL REAL OWNER', 'email' => 'owner@example.invalid', 'password' => Hash::make('FICTIONAL_OWNER_TEST_ONLY'), 'is_active' => true, 'email_verified_at' => now()]);
        $this->owner->forceFill(['email_verified_at' => now()])->save();
        $this->tenant->users()->attach($this->owner->id, ['role' => 'owner', 'membership_active' => true]);
        $settings = ['synthetic_fixture' => true, 'proposal_review_workspace_anchor' => true, 'reviewed_fixture' => 'source-only'];
        $anchor = TeamArea::query()->create(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'slug' => 'fictional-shared-workspace',
            'name' => 'FICTIONAL approved workspace storage', 'profile' => 'enterprise_shared', 'version' => 1, 'settings' => $settings]);
        $this->policy = app(ReviewerWorkspaceSchema::class)->policy(['schema_version' => 1, 'max_role' => 'editor', 'area_ids' => [$this->ids['a'], $this->ids['b']],
            'personas' => ReviewerWorkspaceSchema::PERSONAS, 'allow_financial' => true]);
        config(['service_proposals.reviewer_ai.enabled' => false, 'service_proposals.reviewer_ai.ui_enabled' => false,
            'service_proposals.publish_enabled' => true, 'service_proposals.writes_enabled' => true,
            'service_proposals.recipient_hosts' => ['proposal.example.invalid'],
            'service_proposals.campus_review' => ['enabled' => true, 'build_id' => 'fictional-shared-workspace-v1'],
            'service_proposals.reviewer_workspace' => ['enabled' => true, 'writes_enabled' => true, 'initialization_enabled' => true, 'financial_enabled' => true,
                'anchor_area_id' => $anchor->id, 'anchor_marker_sha256' => ReviewerWorkspaceSchema::digest($settings),
                'policies' => [ReviewerWorkspaceSchema::digest($this->policy) => $this->policy]]]);
        $this->seed = app(ReviewerWorkspaceSchema::class)->graph($this->graphSeed(), true);
        $this->publish();
    }

    protected function graphSeed(): array
    {
        $a = $this->ids['a'];
        $b = $this->ids['b'];

        return ['schema_version' => 1, 'areas' => [['id' => $a, 'name' => 'Fictional Facilities', 'visibility' => 'shared'], ['id' => $b, 'name' => 'Fictional IT', 'visibility' => 'department_only']],
            'buildings' => [['id' => $this->ids['building'], 'area_id' => $a, 'title' => 'Fictional North Hall', 'version' => 1, 'status' => 'active', 'updated_at' => null],
                ['id' => $this->ids['other_building'], 'area_id' => $b, 'title' => 'Fictional IT Hall', 'version' => 1, 'status' => 'active', 'updated_at' => null]],
            'people' => [['id' => 'p1', 'name' => 'Fictional Alex', 'version' => 1, 'area_ids' => [$a, $b], 'active' => true, 'updated_at' => null],
                ['id' => 'p2', 'name' => 'Fictional Casey', 'version' => 1, 'area_ids' => [$b], 'active' => true, 'updated_at' => null]],
            'projects' => [['id' => $this->ids['project'], 'area_id' => $a, 'title' => 'Fictional roof check', 'description' => 'Fictional work, not a college record.', 'version' => 1,
                'status' => 'active', 'owner_id' => 'p1', 'due_on' => '2026-11-01', 'updated_at' => null],
                ['id' => $this->ids['other_project'], 'area_id' => $b, 'title' => 'Fictional network check', 'description' => '', 'version' => 1,
                    'status' => 'active', 'owner_id' => 'p2', 'due_on' => null, 'updated_at' => null]],
            'tasks' => [['id' => $this->ids['task'], 'project_id' => $this->ids['project'], 'area_id' => $a, 'title' => 'Fictional inspect flashing', 'description' => '', 'version' => 1,
                'status' => 'active', 'priority' => 'high', 'due_on' => '2026-11-01', 'assignee_id' => 'p1', 'building_id' => $this->ids['building'], 'updated_at' => null]],
            'tickets' => [['id' => $this->ids['ticket'], 'area_id' => $a, 'title' => 'Fictional leak ticket', 'description' => '', 'version' => 1, 'status' => 'assigned', 'priority' => 'high', 'due_on' => '2026-11-01',
                'building_id' => $this->ids['building'], 'project_id' => $this->ids['project'], 'task_id' => $this->ids['task'], 'owner_capability_hash' => null, 'position' => 0, 'type' => 'maintenance', 'updated_at' => null],
                ['id' => $this->ids['incoming'], 'area_id' => $a, 'title' => 'Fictional incoming connection ticket', 'description' => 'Fictional intake', 'version' => 1, 'status' => 'submitted', 'priority' => 'normal', 'due_on' => null,
                    'building_id' => $this->ids['building'], 'project_id' => null, 'task_id' => null, 'owner_capability_hash' => null, 'position' => 0, 'type' => 'general', 'updated_at' => null]],
            'contractors' => [['id' => $this->ids['contractor'], 'area_id' => $a, 'title' => 'Fictional roofing crew', 'version' => 1, 'status' => 'active', 'updated_at' => null]],
            'plans' => [['id' => $this->ids['plan'], 'area_id' => $a, 'project_id' => $this->ids['project'], 'contractor_id' => $this->ids['contractor'], 'version' => 1,
                'status' => 'planned', 'starts_on' => '2026-11-01', 'ends_on' => '2026-11-03', 'updated_at' => null]],
            'costs' => [['id' => '88000000-0000-4000-8000-000000000001', 'area_id' => $a, 'project_id' => $this->ids['project'], 'version' => 1, 'amount_minor' => 0, 'currency' => 'USD', 'basis' => 'estimate', 'updated_at' => null],
                ['id' => '88000000-0000-4000-8000-000000000002', 'area_id' => $a, 'project_id' => $this->ids['project'], 'version' => 1, 'amount_minor' => 250, 'currency' => 'USD', 'basis' => 'recorded_cost', 'updated_at' => null],
                ['id' => '88000000-0000-4000-8000-000000000003', 'area_id' => $a, 'project_id' => $this->ids['project'], 'version' => 1, 'amount_minor' => 90, 'currency' => 'EUR', 'basis' => 'recorded_cost', 'updated_at' => null]]];
    }

    protected function publish(bool $descriptor = true): void
    {
        $service = app(ProposalService::class);
        $draft = $service->leesMcRaeDraft($this->tenant, $this->owner->id);
        if ($descriptor) {
            $payload = $draft->payload;
            $payload['review_workspace'] = ['schema_version' => 1, 'workspace_id' => (string) Str::uuid(), 'seed_sha256' => ReviewerWorkspaceSchema::digest($this->seed),
                'policy_sha256' => ReviewerWorkspaceSchema::digest($this->policy), 'expires_at' => now()->addDays(6)->utc()->format('Y-m-d\TH:i:s\Z')];
            // Explicit fictional future-publication setup; production integration hook remains absent in this source leaf.
            $draft->payload = $payload;
            $draft->save();
        }
        $draft->refresh();
        $result = $service->publish($this->tenant, $this->owner->id, $draft->id, (int) $draft->revision, 'FICTIONAL_RECIPIENT_PASSWORD', 7, (string) Str::uuid(), 'reviewer');
        $this->version = $result['version'];
        $this->token = $result['token'];
    }

    protected function request(?Store $session = null, array $body = [], string $path = '/fixture', bool $owner = false): Request
    {
        $session ??= new Store('workspace-fixture', new ArraySessionHandler(60));
        $session->start();
        $request = Request::create('https://proposal.example.invalid/enterprise-owner'.$path, 'POST', $body, [], [], [
            'SCRIPT_NAME' => '/enterprise-owner/index.php', 'PHP_SELF' => '/enterprise-owner/index.php', 'SCRIPT_FILENAME' => '/FICTIONAL/index.php', 'HTTPS' => 'on']);
        $request->setLaravelSession($session);
        $request->setUserResolver(fn () => $owner ? $this->owner->fresh() : null);
        if ($owner) {
            $route = new Route('POST', 'owner-workspaces/{workspace}/fixture', fn () => null);
            $route->bind($request);
            $route->setParameter('workspace', $this->tenant->slug);
            $request->setRouteResolver(fn () => $route);
        }

        return $request;
    }

    protected function recipient(): Request
    {
        $request = $this->request(body: ['password' => 'FICTIONAL_RECIPIENT_PASSWORD']);
        app(RecipientController::class)->unlock($request, $this->token);

        return $request;
    }

    protected function binding(): array
    {
        return ['version_hash' => $this->version->content_hash, 'build_id' => $this->version->payload['campus_review']['build_id'], 'source_hash' => $this->version->payload['campus_review']['source_hash']];
    }

    protected function selection(string $persona = 'demo_manager', ?string $person = null): array
    {
        return ['persona' => $persona, 'area_ids' => [$this->ids['a']], 'person_id' => $person];
    }

    protected function initialize(): array
    {
        return app(ReviewerWorkspace::class)->initialize($this->request(path: '/owner-workspaces/'.$this->tenant->slug.'/fixture', owner: true), $this->version->id, $this->seed,
            ['request_id' => '89000000-0000-4000-8000-000000000001', 'descriptor_sha256' => ReviewerWorkspaceSchema::digest($this->version->payload['review_workspace'])]);
    }

    protected function command(array $values = []): array
    {
        return $values + ['binding' => $this->binding(), 'selection' => $this->selection(), 'request_id' => (string) Str::uuid(), 'expected_revision' => 1,
            'action' => 'project.update', 'record_id' => $this->ids['project'], 'expected_record_version' => 1,
            'payload' => ['title' => 'Edited fictional roof check', 'description' => 'Fictional edit', 'owner_id' => 'p1', 'due_on' => null]];
    }
}

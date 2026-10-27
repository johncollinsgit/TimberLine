<?php

namespace Tests\Feature\Proposals;

use App\Models\TeamOperationRecord;
use App\Proposals\ProposalService;
use App\Proposals\ReviewerWorkspaceCollaborationStore;
use App\Proposals\ReviewerWorkspaceDiscussion;
use App\Proposals\ReviewerWorkspaceSchema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\Support\ReviewerWorkspaceFixture;

final class ReviewerWorkspaceTargetOrderTest extends ReviewerWorkspaceFixture
{
    protected function setUp(): void
    {
        parent::setUp();
        $policy = ['schema_version' => 1, 'comments_enabled' => true, 'uploads_enabled' => false, 'write_personas' => ['demo_manager']];
        $hash = ReviewerWorkspaceSchema::digest($policy);
        config(['service_proposals.reviewer_workspace.collaboration' => ['enabled' => true, 'comments_write_enabled' => true, 'policies' => [$hash => $policy]]]);
        $draft = app(ProposalService::class)->leesMcRaeDraft($this->tenant, $this->owner->id);
        $payload = $draft->payload;
        $payload['review_workspace'] = $this->version->payload['review_workspace'];
        $payload['review_workspace_collaboration'] = ['schema_version' => 1, 'policy_sha256' => $hash,
            'expires_at' => now()->addDays(5)->utc()->format('Y-m-d\TH:i:s\Z')];
        $draft->payload = $payload;
        $draft->save();
        $draft->refresh();
        $published = app(ProposalService::class)->publish($this->tenant, $this->owner->id, $draft->id, $draft->revision,
            'FICTIONAL_RECIPIENT_PASSWORD', 7, (string) Str::uuid(), 'reviewer');
        $this->version = $published['version'];
        $this->token = $published['token'];
        $this->initialize();
    }

    public function test_reordered_closed_json_target_reads_replays_and_recovers_the_same_comment_without_writes(): void
    {
        $one = $this->recipient();
        $name = $this->version->payload['reviewer_names'][0];
        $one->session()->put('service_proposal_name.'.$this->version->id, $name);
        $service = app(ReviewerWorkspaceDiscussion::class);
        $target = ['id' => $this->ids['incoming'], 'kind' => 'ticket'];
        $reordered = array_reverse($target, true);
        $body = ['binding' => $this->binding(), 'selection' => $this->selection(), 'target' => $target,
            'request_id' => (string) Str::uuid(), 'expected_target_version' => 1, 'expected_thread_revision' => 0,
            'expected_reviewer_name' => $name, 'body' => 'Fictional shared target equivalence'];
        $first = $service->comment($one, $this->token, $body);
        $raw = DB::table('team_operation_records')->orderBy('id')->get()->toJson();
        $shown = $service->show($one, $this->token, $this->binding(), $this->selection(), $reordered);
        $this->assertCount(1, $shown['comments']);
        $this->assertSame($body['body'], $shown['comments'][0]['body']);
        $replay = $service->comment($one, $this->token, array_replace($body, ['target' => $reordered]));
        $this->assertTrue($replay['replayed']);
        $this->assertSame($first['receipt'], $replay['receipt']);
        $status = $service->status($one, $this->token, $this->binding(), $this->selection(), $reordered,
            $body['request_id'], ReviewerWorkspaceSchema::digest($body));
        $this->assertTrue($status['settled']);
        $this->assertSame($first['receipt'], $status['receipt']);
        $this->assertSame($raw, DB::table('team_operation_records')->orderBy('id')->get()->toJson());
        $this->assertSame(1, TeamOperationRecord::where('kind', ReviewerWorkspaceCollaborationStore::THREAD)->count());
        $this->assertSame(1, TeamOperationRecord::where('kind', ReviewerWorkspaceCollaborationStore::RECEIPT)->count());
        foreach ([['id' => $target['id'], 'kind' => 'project'], ['id' => $this->ids['other_project'], 'kind' => 'ticket'], $target + ['native_id' => 1]] as $foreign) {
            $status = null;
            try {
                $service->show($one, $this->token, $this->binding(), $this->selection(), $foreign);
            } catch (HttpExceptionInterface $error) {
                $status = $error->getStatusCode();
            }
            $this->assertContains($status, [404, 422]);
        }
    }
}

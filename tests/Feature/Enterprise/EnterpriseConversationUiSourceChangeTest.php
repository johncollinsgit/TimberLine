<?php

namespace Tests\Feature\Enterprise;

use App\Enterprise\WorkspaceAccess;
use App\Services\Team\TeamWorkRepository;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Support\EnterpriseConversationFixture;

final class EnterpriseConversationUiSourceChangeTest extends EnterpriseConversationFixture
{
    public function test_real_native_project_archive_invalidates_recovery_under_the_same_approval_and_actor_without_redispatch(): void
    {
        $this->fake();
        $result = $this->turn()->assertOk()->json();
        $url = $this->url('/enterprise/conversation/sessions/'.$result['session_id']).'&area_id='.$this->area;
        $approval = config('enterprise.conversation.approval_sha256');
        $this->getJson($url)->assertOk()->assertJsonPath('provider_dispatch', false);
        $work = app(TeamWorkRepository::class);
        $state = $work->state($this->tenant->id);
        $work->execute($this->tenant->id, fn () => app(WorkspaceAccess::class)->actor($this->tenant, $this->user, true),
            ['kind' => 'project.archive', 'id' => $this->project, 'archived' => true,
                'expected_version' => $state['projects'][$this->project]['version'], 'idempotency_key' => (string) Str::uuid()]);
        $current = $work->state($this->tenant->id)['projects'][$this->project];
        $this->assertTrue($current['archived']);
        $this->assertSame($state['projects'][$this->project]['version'] + 1, $current['version']);
        $this->assertSame($approval, config('enterprise.conversation.approval_sha256'));
        $this->getJson($url)->assertConflict()->assertJsonMissingPath('history')->assertJsonMissingPath('last_result');
        Http::assertSentCount(1);
    }
}

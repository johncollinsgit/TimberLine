<?php

namespace Tests\Feature\Enterprise;

use App\Console\Commands\PruneProposalReviewerAi;
use App\Models\TeamOperationRecord;
use App\Proposals\ReviewerAiConnection;
use App\Proposals\ReviewerAiReceipts;
use App\Proposals\ReviewerAiRecovery;
use App\Proposals\ReviewerAiSnapshot;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\ReviewerAiConnectionFixture;

final class ReviewerAiRetentionCommandTest extends ReviewerAiConnectionFixture
{
    public function test_registered_command_erases_only_bounded_pinned_content_after_deactivation_and_keeps_all_cost_evidence(): void
    {
        $scope = $this->configuredConnection();
        $snapshot = ['schema_version' => 1, 'persona' => 'demo_manager', 'local_revision' => 1,
            'area_ids' => ['73000000-0000-4000-8000-000000000001'], 'sources' => [['ref' => 'project:75000000-0000-4000-8000-000000000001',
                'kind' => 'project', 'area_id' => '73000000-0000-4000-8000-000000000001', 'title' => 'Fictional job', 'version' => 1,
                'status' => 'active', 'tasks' => [], 'buildings' => []]], 'coverage' => ReviewerAiSnapshot::COVERAGE];
        $receipt = app(ReviewerAiReceipts::class)->prepare($scope, 'PRIVATE CONTENT SENTINEL', $snapshot, (string) Str::uuid());
        $pins = app(ReviewerAiRecovery::class)->pins(app(ReviewerAiConnection::class)->settings($scope));
        $path = $this->runtime.'/reviewer-ai-pins.json';
        file_put_contents($path, json_encode($pins, JSON_THROW_ON_ERROR));
        $digest = hash_file('sha256', $path);
        $kernel = app(Kernel::class);
        $kernel->registerCommand(app(PruneProposalReviewerAi::class));
        $args = ['--pins' => $path, '--sha256' => $digest, '--limit' => '1', '--deactivated' => true];
        $this->assertSame(1, $kernel->call('proposals:prune-reviewer-ai', $args));
        $this->assertArrayHasKey('question', TeamOperationRecord::query()->findOrFail($receipt['id'])->data);
        config(['service_proposals.reviewer_ai.enabled' => false, 'service_proposals.reviewer_ai.ui_enabled' => false]);
        $before = DB::table('team_operation_records')->where('kind', 'ent_trial_budget')->first();
        $this->assertSame(0, $kernel->call('proposals:prune-reviewer-ai', $args));
        $output = $kernel->output();
        $this->assertStringNotContainsString('PRIVATE CONTENT SENTINEL', $output);
        $this->assertStringContainsString('"scrubbed":1', $output);
        $this->assertArrayNotHasKey('question', TeamOperationRecord::query()->findOrFail($receipt['id'])->data);
        $this->assertEquals($before, DB::table('team_operation_records')->where('kind', 'ent_trial_budget')->first());
        $this->assertSame(0, TeamOperationRecord::query()->where('kind', 'ent_trial_charge')->count());
        $args['--sha256'] = str_repeat('0', 64);
        $this->assertSame(1, $kernel->call('proposals:prune-reviewer-ai', $args));
        $this->assertStringNotContainsString($path, $kernel->output());
        $this->assertSame(1, TeamOperationRecord::query()->where('kind', 'ent_trial_budget')->count());
    }
}

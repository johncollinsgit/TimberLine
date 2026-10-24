<?php

namespace Tests\Feature\Enterprise;

use App\Enterprise\GroundedEvidence;
use App\Enterprise\ReadOnlyAssistant;
use App\Enterprise\Trial\TrialBudget;
use App\Models\TeamOperationRecord;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\EnterpriseConversationFixture;

final class EnterpriseGroundedConversationTest extends EnterpriseConversationFixture
{
    private function scene(): array
    {
        $building = $this->postJson($this->url('/enterprise/resources/commands'), ['action' => 'location.save',
            'id' => (string) Str::uuid(), 'request_id' => (string) Str::uuid(), 'expected_version' => 0,
            'area_id' => $this->area, 'title' => 'Synthetic grounded hall', 'level' => 'facility'])->assertOk()->json();
        $command = fn ($input) => $this->postJson($this->url('/enterprise/commands'), $input + [
            'area_id' => $this->area, 'id' => (string) Str::uuid(), 'request_id' => (string) Str::uuid(), 'expected_version' => 0])->assertOk()->json();
        $request = $command(['action' => 'request.create', 'title' => 'Synthetic grounded ticket', 'location_id' => $building['id'], 'location_version' => $building['version']]);
        $command(['action' => 'request.triage', 'id' => $request['id'], 'expected_version' => 1]);
        $assigned = $command(['action' => 'request.assign', 'id' => $request['id'], 'expected_version' => 2, 'project_id' => $this->project, 'assignee_id' => $this->user->id]);
        for ($index = 0; $index < 10; $index++) {
            $command(['action' => 'request.create', 'title' => 'Synthetic bounded ticket '.$index, 'location_id' => $building['id'], 'location_version' => $building['version']]);
        }
        $record = fn ($kind, $data, $status = 'recorded') => TeamOperationRecord::query()->create(['id' => (string) Str::uuid(),
            'tenant_id' => $this->tenant->id, 'area_id' => $this->area, 'kind' => 'ent_'.$kind, 'title' => 'Synthetic '.$kind,
            'source_key' => Str::random(64), 'visibility' => 'area', 'version' => 1, 'status' => $status,
            'starts_on' => '2026-11-01', 'ends_on' => '2026-11-03', 'data' => $data]);
        $contractor = $record('contractor', [], 'active');
        $schedule = $record('schedule', ['project_id' => $this->project, 'contractor_id' => $contractor->id], 'planned');
        $cost = $record('cost', ['project_id' => $this->project, 'amount_minor' => 250, 'currency' => 'USD', 'basis' => 'recorded_cost']);
        $record('cost', ['project_id' => $this->project, 'amount_minor' => 0, 'currency' => 'USD', 'basis' => 'estimate']);

        return compact('building', 'request', 'assigned', 'contractor', 'schedule', 'cost');
    }

    public function test_building_followups_keep_current_joined_facts_and_selected_math_without_changing_context_or_allowance(): void
    {
        extract($this->scene());
        $this->fake(function ($request, $response, $payload) use ($assigned) {
            $this->assertCount(8, $payload['sources']);
            $task = collect($payload['sources'])->firstWhere('ref', 'task:'.$assigned['task']['id']);
            $this->assertSame($this->user->name, $task['facts']['handler']['name']);
            $this->assertNotNull(collect($payload['sources'])->firstWhere('ref', 'schedule:'.TeamOperationRecord::query()->where('kind', 'ent_schedule')->value('id')));
            $this->assertArrayNotHasKey('building', $payload);
            $this->assertStringNotContainsString('linked_requests', json_encode($payload));
            $this->assertStringNotContainsString($this->user->email, json_encode($payload));
            $this->assertSame(250, $payload['calculations'][0]['difference_minor']);
            $this->assertSame(0, $payload['calculations'][0]['estimate_minor']);
            foreach ($payload['sources'] as $source) {
                $this->assertArrayNotHasKey('url', $source);
            }

            return Http::response($response);
        });
        $session = (string) Str::uuid();
        $key = (string) Str::uuid();
        $input = ['intent' => 'building', 'building_id' => $building['id'], 'session_id' => $session];
        $this->turn($input + ['request_id' => $key])->assertOk();
        $this->turn($input + ['request_id' => $key])->assertOk()->assertJsonPath('replayed', true);
        $this->turn($input + ['question' => 'Who is handling that work and what are the planned contractors and checked costs?'])->assertOk();
        $this->turn(['session_id' => $session, 'intent' => 'costs'])->assertConflict();
        $this->turn(array_replace($input, ['building_id' => '40000000-0000-4000-8000-000000000001']))->assertConflict();
        Http::assertSentCount(2);
        $this->assertSame(700, app(TrialBudget::class)->status()['used_or_reserved_microusd']);
        $this->assertSame(1, TeamOperationRecord::query()->where('kind', 'ent_trial_budget')->count());
    }

    public static function revocations(): array
    {
        return array_map(fn ($kind) => [$kind], ['unselected_request', 'task', 'project', 'contractor', 'schedule', 'cost', 'member', 'building']);
    }

    #[DataProvider('revocations')]
    public function test_all_bounded_historical_source_changes_deny_followup_and_exact_replay_without_another_claim(string $kind): void
    {
        extract($this->scene());
        $this->fake();
        $input = ['intent' => 'building', 'building_id' => $building['id'], 'session_id' => (string) Str::uuid()];
        $key = (string) Str::uuid();
        $this->turn($input + ['request_id' => $key])->assertOk();
        if ($kind === 'unselected_request') {
            $all = app(ReadOnlyAssistant::class)->answer($this->tenant, $this->user, $this->area, 'building', $building['id']);
            $selected = array_column(GroundedEvidence::selected($all)['sources'], 'ref');
            $source = collect($all['sources'])->first(fn ($source) => str_starts_with($source['ref'], 'request:') && ! in_array($source['ref'], $selected, true));
            $row = TeamOperationRecord::query()->findOrFail(substr($source['ref'], 8));
            $row->forceFill(['title' => 'Changed unselected historical source', 'version' => $row->version + 1])->save();
        } elseif ($kind === 'task' || $kind === 'project') {
            $table = $kind === 'task' ? 'team_tasks' : 'team_projects';
            $id = $kind === 'task' ? $assigned['task']['id'] : $this->project;
            $payload = json_decode(DB::table($table)->where('id', $id)->value('payload'), true);
            if ($kind === 'project') {
                $payload['privacy'] = 'private';
                DB::table('team_project_members')->where('project_id', $id)->delete();
            } else {
                $payload['version']++;
            }
            DB::table($table)->where('id', $id)->update(['payload' => json_encode($payload)]);
        } elseif ($kind === 'member') {
            $this->user->forceFill(['name' => 'Changed current assignment name'])->save();
        } else {
            $row = $kind === 'building' ? TeamOperationRecord::query()->findOrFail($building['id']) : $$kind;
            $row->forceFill(['version' => $row->version + 1, 'status' => $kind === 'contractor' ? 'inactive' : $row->status])->save();
        }
        $this->turn($input)->assertConflict()->assertJsonMissingPath('reply');
        $this->turn($input + ['request_id' => $key])->assertConflict()->assertJsonMissingPath('reply');
        Http::assertSentCount(1);
        $this->assertSame(1, TeamOperationRecord::query()->where('kind', 'ent_trial_charge')->count());
        $this->assertSame(350, app(TrialBudget::class)->status()['used_or_reserved_microusd']);
    }

    public function test_unselected_historical_source_revocation_during_fake_provider_work_discards_answer_after_metering(): void
    {
        extract($this->scene());
        $this->fake(function ($request, $response, $payload) {
            $selected = array_column($payload['sources'], 'ref');
            $row = TeamOperationRecord::query()->where('kind', 'enterprise_request')->get()
                ->first(fn ($row) => ! in_array('request:'.$row->id, $selected, true));
            $this->assertNotNull($row);
            $row->forceFill(['version' => $row->version + 1, 'title' => 'Changed while provider worked'])->save();

            return Http::response($response);
        });
        $input = ['intent' => 'building', 'building_id' => $building['id'], 'session_id' => (string) Str::uuid(), 'request_id' => (string) Str::uuid()];
        $this->turn($input)->assertConflict()->assertJsonMissingPath('reply');
        $this->turn($input)->assertConflict()->assertJsonMissingPath('reply');
        Http::assertSentCount(1);
        $this->assertSame(350, app(TrialBudget::class)->status()['used_or_reserved_microusd']);
        $this->assertArrayNotHasKey('last_result', TeamOperationRecord::query()->findOrFail($input['session_id'])->data);
    }
}

<?php

namespace Tests\Feature\Enterprise;

use App\Enterprise\Conversation\MemoryRetention;
use App\Enterprise\Conversation\PrivateMemory;
use App\Models\TeamOperationRecord;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Support\EnterpriseConversationFixture;

final class EnterprisePrivateMemoryTest extends EnterpriseConversationFixture
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['enterprise.conversation.memory_enabled' => true, 'enterprise.conversation.memory_retention_ready' => true]);
    }

    private function memoryUrl(string $suffix = ''): string
    {
        return $this->url('/enterprise/conversation/memories'.$suffix);
    }

    private function input(array $extra = []): array
    {
        return $extra + ['id' => (string) Str::uuid(), 'request_id' => (string) Str::uuid(), 'area_id' => $this->area,
            'expected_version' => 0, 'confirmed' => true, 'type' => 'preference', 'key' => 'reply_length', 'value' => 'brief', 'retention_days' => 7];
    }

    private function preview(string $ref, string $field = 'status'): array
    {
        return $this->getJson($this->memoryUrl('/preview').'&area_id='.$this->area.'&source_ref='.urlencode($ref).'&field='.$field)->assertOk()->json();
    }

    private function observation(string $ref, string $field = 'status'): array
    {
        $preview = $this->preview($ref, $field);

        return ['id' => (string) Str::uuid(), 'request_id' => (string) Str::uuid(), 'area_id' => $this->area, 'expected_version' => 0,
            'confirmed' => true, 'type' => 'source_observation', 'source_ref' => $ref, 'field' => $field,
            'source_sha256' => $preview['source_sha256'], 'retention_days' => 7];
    }

    private function save(array $input)
    {
        return $this->postJson($this->memoryUrl(), $input);
    }

    private function listing()
    {
        return $this->getJson($this->memoryUrl().'&area_id='.$this->area);
    }

    private function deleteInput(int $version = 1): array
    {
        return ['area_id' => $this->area, 'expected_version' => $version, 'request_id' => (string) Str::uuid(), 'confirmed' => true];
    }

    private function cost(): string
    {
        $id = (string) Str::uuid();
        $this->postJson($this->url('/enterprise/planning'), ['kind' => 'cost', 'id' => $id, 'request_id' => (string) Str::uuid(),
            'area_id' => $this->area, 'expected_version' => 0, 'title' => 'Fictional private recorded cost', 'status' => 'recorded',
            'project_id' => $this->project, 'amount_minor' => 913700, 'basis' => 'recorded_cost', 'currency' => 'USD'])->assertOk();

        return $id;
    }

    private function archiveProject(bool $archived): void
    {
        $work = app(\App\Services\Team\TeamWorkRepository::class);
        $state = $work->state($this->tenant->id);
        $work->execute($this->tenant->id, fn () => app(\App\Enterprise\WorkspaceAccess::class)->actor($this->tenant, $this->user, true),
            ['kind' => 'project.archive', 'id' => $this->project, 'archived' => $archived,
                'expected_version' => $state['projects'][$this->project]['version'], 'idempotency_key' => (string) Str::uuid()]);
    }

    public function test_preferences_are_typed_explicit_private_encrypted_and_distinct_from_current_source_observations(): void
    {
        $pref = $this->input();
        $this->save($pref)->assertOk()->assertJsonPath('type', 'preference')->assertJsonPath('key', 'reply_length')->assertJsonPath('value', 'brief');
        $obs = $this->observation('project:'.$this->project);
        $this->save($obs)->assertOk()->assertJsonPath('type', 'source_observation')
            ->assertJsonPath('source_ref', $obs['source_ref'])->assertJsonPath('source_version', 1)->assertJsonPath('value', 'active');
        $this->listing()->assertOk()->assertJsonCount(2, 'items')->assertJsonPath('provider_dispatch', false)->assertHeader('Cache-Control', 'no-store, private');
        $raw = DB::table('team_operation_records')->where('id', $pref['id'])->first();
        $this->assertSame('Private assistant memory', $raw->title);
        $this->assertStringNotContainsString('reply_length', $raw->data);
        $this->assertStringNotContainsString('brief', $raw->data);
        $stored = TeamOperationRecord::query()->findOrFail($obs['id'])->data;
        $this->assertArrayNotHasKey('source_title', $stored);
        $this->assertSame((string) $this->user->id, $this->listing()->json('items.0.captured_by'));
        $this->assertSame(0, TeamOperationRecord::query()->whereIn('kind', ['ent_trial_charge', 'ent_conversation_session'])->count());
        Http::assertNothingSent();
    }

    public function test_no_free_text_secret_auto_capture_or_forged_source_values_are_accepted(): void
    {
        foreach ([['confirmed' => false], ['key' => 'password'], ['value' => 'sk-proj-secret'], ['retention_days' => 365],
            ['body' => 'Remember secret text'], ['history' => [['role' => 'user', 'text' => 'secret']]], ['expires_at' => '+100 years'],
            ['role_preview' => 'admin'], ['tools' => ['send_email']], ['source_url' => 'https://foreign.invalid'], ['observed_value' => 'invented']] as $extra) {
            $this->save($this->input($extra))->assertUnprocessable();
        }
        $obs = $this->observation('project:'.$this->project);
        $this->save($obs + ['observed_value' => 'approved'])->assertUnprocessable();
        $this->getJson($this->memoryUrl('/preview').'&area_id='.$this->area.'&source_ref=project:'.$this->project.'&field=description')->assertUnprocessable();
        $this->getJson($this->memoryUrl('/preview').'&area_id='.$this->area.'&source_ref=project:'.$this->project.'&field=amount_minor')->assertUnprocessable();
        $this->assertSame(0, TeamOperationRecord::query()->where('kind', PrivateMemory::KIND)->count());
        Http::assertNothingSent();
    }

    public function test_observations_capture_actual_typed_fields_for_all_five_canonical_source_kinds(): void
    {
        $building = '40000000-0000-4000-8000-000000000001';
        $command = fn (array $extra) => $this->postJson($this->url('/enterprise/commands'), $extra + ['id' => (string) Str::uuid(),
            'area_id' => $this->area, 'expected_version' => 0, 'request_id' => (string) Str::uuid()]);
        $request = $command(['action' => 'request.create', 'title' => 'Fictional memory source repair', 'due_on' => '2026-11-05'])->assertOk()->json();
        $command(['action' => 'request.triage', 'id' => $request['id'], 'expected_version' => 1])->assertOk();
        $assigned = $command(['action' => 'request.assign', 'id' => $request['id'], 'expected_version' => 2,
            'project_id' => $this->project, 'assignee_id' => $this->user->id])->assertOk()->json();
        $this->postJson($this->url('/enterprise/operations/commands'), ['action' => 'task.configure', 'task_id' => $assigned['task']['id'],
            'request_id' => (string) Str::uuid(), 'expected_version' => 0, 'expected_task_version' => $assigned['task']['version'],
            'owner_user_id' => (string) $this->user->id, 'ball_user_id' => (string) $this->user->id, 'ball_team' => false,
            'next_action' => 'Inspect the fictional repair', 'priority' => 'urgent',
            'checklist' => [['id' => 'inspect', 'label' => 'Fictional inspection', 'weight' => 1]]])->assertOk();
        $cost = $this->cost();
        foreach ([['project:'.$this->project, 'status', 'active'], ['building:'.$building, 'status', 'active'],
            ['request:'.$request['id'], 'priority', 'urgent'], ['request:'.$request['id'], 'due_on', '2026-11-05'],
            ['task:'.$assigned['task']['id'], 'priority', 'urgent'], ['task:'.$assigned['task']['id'], 'due_on', '2026-11-05'],
            ['cost:'.$cost, 'amount_minor', 913700], ['cost:'.$cost, 'currency', 'USD'], ['cost:'.$cost, 'basis', 'recorded_cost']] as [$ref, $field, $value]) {
            $preview = $this->preview($ref, $field);
            $this->assertSame($value, $preview['value']);
            $this->save($this->observation($ref, $field))->assertOk()->assertJsonPath('value', $value);
            $this->get($preview['source_url'])->assertOk();
        }
        $this->listing()->assertOk()->assertJsonCount(9, 'items');
        Http::assertNothingSent();
    }

    public function test_feature_and_retention_setup_gates_fail_closed_without_initializing_or_using_a_budget(): void
    {
        config(['enterprise.conversation.memory_retention_ready' => false]);
        $this->save($this->input())->assertNotFound();
        $this->listing()->assertNotFound();
        $this->turn(['use_memory' => true])->assertNotFound();
        config(['enterprise.conversation.memory_retention_ready' => true, 'enterprise.conversation.enabled' => false]);
        TeamOperationRecord::query()->where('kind', 'ent_trial_budget')->delete();
        $this->save($this->input())->assertOk();
        $this->assertSame(0, TeamOperationRecord::query()->whereIn('kind', ['ent_trial_budget', 'ent_trial_charge'])->count());
        config(['enterprise.conversation.memory_enabled' => false]);
        $this->listing()->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_source_change_since_preview_conflicts_and_changed_saved_observations_are_withheld(): void
    {
        $old = $this->observation('project:'.$this->project);
        $this->archiveProject(true);
        $this->save($old)->assertConflict();
        $this->archiveProject(false);
        $current = $this->observation('project:'.$this->project);
        $this->save($current)->assertOk();
        $this->archiveProject(true);
        $result = $this->listing()->assertOk()->assertJsonPath('items.0.state', 'changed')->assertJsonMissingPath('items.0.value');
        $this->assertStringNotContainsString('"value":"active"', $result->getContent());
        $this->assertSame([], app(PrivateMemory::class)->context($this->tenant, $this->user, $this->area, [['ref' => 'project:'.$this->project]]));
        Http::assertNothingSent();
    }

    public function test_financial_access_loss_withholds_all_old_source_details_but_allows_explicit_erasure_of_own_memory(): void
    {
        $cost = $this->cost();
        $obs = $this->observation('cost:'.$cost, 'amount_minor');
        $this->save($obs)->assertOk()->assertJsonPath('value', 913700);
        DB::table('team_area_members')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'area_id' => $this->area, 'user_id' => $this->user->id, 'role' => 'viewer']);
        $this->tenant->users()->updateExistingPivot($this->user->id, ['role' => 'member']);
        $result = $this->listing()->assertOk()->assertJsonPath('items.0.state', 'unavailable')->assertJsonMissingPath('items.0.source_ref')->assertJsonMissingPath('items.0.value');
        $this->assertStringNotContainsString('913700', $result->getContent());
        $this->assertStringNotContainsString('Fictional private recorded cost', $result->getContent());
        $this->postJson($this->memoryUrl('/'.$obs['id'].'/delete'), $this->deleteInput())->assertOk()->assertJsonPath('status', 'deleted');
        Http::assertNothingSent();
    }

    public function test_current_user_and_department_scope_prevent_cross_user_and_wrong_kind_memory_access(): void
    {
        $input = $this->input();
        $this->save($input)->assertOk();
        $other = User::forceCreate(['name' => 'Fictional memory peer', 'email' => 'memory-peer@example.invalid', 'password' => 'fixture', 'is_active' => true, 'email_verified_at' => now()]);
        $this->tenant->users()->attach($other->id, ['role' => 'owner', 'membership_active' => true]);
        $this->actingAs($other);
        $this->listing()->assertOk()->assertJsonCount(0, 'items');
        $this->save($this->input(['id' => $input['id'], 'expected_version' => 1]))->assertNotFound();
        $this->postJson($this->memoryUrl('/'.$input['id'].'/delete'), $this->deleteInput())->assertNotFound();
        $this->actingAs($this->user);
        $this->getJson($this->memoryUrl().'&area_id='.$this->manifest()['departments'][1]['id'])->assertOk()->assertJsonCount(0, 'items');
        $this->getJson($this->memoryUrl('/preview').'&area_id='.$this->area.'&source_ref=project:'.$input['id'].'&field=status')->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_exact_retries_versioned_edits_and_explicit_delete_never_recreate_old_memory_or_retain_its_value_in_audit(): void
    {
        $input = $this->input();
        $this->save($input)->assertOk();
        $this->save($input)->assertOk()->assertJsonPath('replayed', true)->assertJsonPath('version', 1);
        $this->save(array_replace($input, ['value' => 'standard']))->assertConflict();
        $edit = $this->input(['id' => $input['id'], 'expected_version' => 1, 'value' => 'standard']);
        $this->save($edit)->assertOk()->assertJsonPath('version', 2);
        $this->save($input)->assertConflict();
        $delete = $this->deleteInput(2);
        $url = $this->memoryUrl('/'.$input['id'].'/delete');
        $this->postJson($url, array_replace($delete, ['confirmed' => false]))->assertUnprocessable();
        config(['enterprise.conversation.memory_enabled' => false]);
        $this->postJson($url, $delete)->assertOk()->assertJsonPath('status', 'deleted');
        $this->postJson($url, $delete)->assertOk()->assertJsonPath('replayed', true);
        $row = TeamOperationRecord::query()->findOrFail($input['id']);
        $this->assertSame(['deleted_at'], array_keys($row->data));
        foreach (DB::table('team_operation_revisions')->where('record_id', $row->id)->get() as $revision) {
            $text = \Illuminate\Support\Facades\Crypt::decryptString($revision->payload);
            $this->assertStringNotContainsString('reply_length', $text);
            $this->assertStringNotContainsString('standard', $text);
        }
        Http::assertNothingSent();
    }

    public function test_expiry_withholds_values_immediately_and_bounded_managed_retention_scrubs_only_memory_content(): void
    {
        $input = $this->input(['retention_days' => 1]);
        $this->save($input)->assertOk();
        $this->travel(2)->days();
        $this->listing()->assertOk()->assertJsonPath('items.0.state', 'expired')->assertJsonMissingPath('items.0.value');
        $this->assertSame(1, app(MemoryRetention::class)->prune());
        $this->assertSame(0, app(MemoryRetention::class)->prune());
        $row = TeamOperationRecord::query()->findOrFail($input['id']);
        $this->assertSame('expired', $row->status);
        $this->assertSame(['expired_at'], array_keys($row->data));
        $this->assertSame(1, TeamOperationRecord::query()->where('kind', 'ent_trial_budget')->count());
        $this->assertSame(1, DB::table('team_projects')->count());
        $this->assertSame(0, $this->artisan('enterprise:assistant-memory-prune', ['--limit' => 1])->assertSuccessful()->run());
        Http::assertNothingSent();
    }

    public function test_memory_is_opt_in_and_only_current_observations_already_in_the_selected_snapshot_can_reach_model(): void
    {
        $pref = $this->input();
        $this->save($pref)->assertOk();
        $obs = $this->observation('project:'.$this->project);
        $this->save($obs)->assertOk();
        $this->fake(function ($request, $response, $payload) {
            if ($payload['question'] === 'Use current saved memory') {
                $this->assertCount(2, $payload['private_memory']);
                foreach ($payload['private_memory'] as $memory) {
                    if ($memory['type'] === 'source_observation') {
                        $this->assertContains($memory['source_ref'], array_column($payload['sources'], 'ref'));
                    }
                }
                $this->assertStringContainsString('Private memory preferences only guide formatting', $request->data()['instructions']);
            } else {
                $this->assertArrayNotHasKey('private_memory', $payload);
            }

            return Http::response($response);
        });
        $this->turn()->assertOk();
        $this->turn(['question' => 'Use current saved memory', 'use_memory' => true])->assertOk();
        Http::assertSentCount(2);
        $cost = $this->cost();
        $costMemory = $this->observation('cost:'.$cost, 'amount_minor');
        $this->save($costMemory)->assertOk();
        $context = app(PrivateMemory::class)->context($this->tenant, $this->user, $this->area, [['ref' => 'project:'.$this->project]]);
        $this->assertNotContains('cost:'.$cost, array_column($context, 'source_ref'));
    }

    public function test_memory_change_and_removal_fence_old_short_history_and_replay_without_redispatch_or_refund(): void
    {
        $input = $this->input();
        $this->save($input)->assertOk();
        $this->fake();
        $turn = ['session_id' => (string) Str::uuid(), 'request_id' => (string) Str::uuid(), 'use_memory' => true];
        $this->turn($turn)->assertOk();
        $this->turn($turn)->assertOk()->assertJsonPath('replayed', true);
        $this->save($this->input(['id' => $input['id'], 'expected_version' => 1, 'value' => 'standard']))->assertOk();
        $this->turn($turn)->assertConflict();
        Http::assertSentCount(1);
        $this->assertSame(1, TeamOperationRecord::query()->where('kind', 'ent_trial_charge')->count());
    }

    public function test_memory_does_not_fetch_a_source_outside_the_selected_check_even_when_the_account_can_read_it(): void
    {
        $cost = $this->cost();
        $this->save($this->observation('cost:'.$cost, 'amount_minor'))->assertOk();
        $evidence = \Mockery::mock(\App\Enterprise\AuthorizedEvidence::class);
        $evidence->shouldNotReceive('capture');
        $this->app->instance(\App\Enterprise\AuthorizedEvidence::class, $evidence);
        $this->assertSame([], app(PrivateMemory::class)->context($this->tenant, $this->user, $this->area, [['ref' => 'project:'.$this->project]]));
        Http::assertNothingSent();
    }

    public function test_memory_deleted_during_provider_completion_withholds_the_reply_without_releasing_its_known_charge(): void
    {
        $input = $this->input();
        $this->save($input)->assertOk();
        $this->fake(function ($request, $response, $payload) use ($input) {
            $this->assertCount(1, $payload['private_memory']);
            app(PrivateMemory::class)->delete($this->tenant, $this->user, ['id' => $input['id']] + $this->deleteInput());

            return Http::response($response);
        });
        $turn = ['session_id' => (string) Str::uuid(), 'request_id' => (string) Str::uuid(), 'use_memory' => true];
        $this->turn($turn)->assertConflict()->assertJsonMissingPath('reply');
        $charge = TeamOperationRecord::query()->where('kind', 'ent_trial_charge')->sole();
        $this->assertSame('accepted', $charge->status);
        $this->assertGreaterThan(0, $charge->data['charged_microusd']);
        $before = $charge->data;
        $this->turn($turn)->assertConflict();
        $this->assertSame($before, $charge->fresh()->data);
        $this->assertSame('deleted', TeamOperationRecord::query()->findOrFail($input['id'])->status);
        Http::assertSentCount(1);
    }

    public function test_memory_limit_and_retention_are_bounded_and_pruning_does_not_resurrect_deleted_content(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $this->save($this->input(['retention_days' => 1]))->assertOk();
        }
        $this->save($this->input())->assertUnprocessable();
        $this->travel(2)->days();
        $this->assertSame(3, app(MemoryRetention::class)->prune(3));
        $this->assertSame(17, TeamOperationRecord::query()->where(['kind' => PrivateMemory::KIND, 'status' => 'active'])->count());
        $expired = TeamOperationRecord::query()->where(['kind' => PrivateMemory::KIND, 'status' => 'expired'])->firstOrFail();
        $this->save($this->input(['id' => $expired->id, 'expected_version' => $expired->version]))->assertConflict();
        $this->postJson($this->memoryUrl('/'.$expired->id.'/delete'), $this->deleteInput($expired->version))->assertOk();
        $this->assertSame(['deleted_at'], array_keys($expired->fresh()->data));
        $this->assertSame(17, app(MemoryRetention::class)->prune());
        $this->assertSame(0, TeamOperationRecord::query()->where(['kind' => PrivateMemory::KIND, 'status' => 'active'])->count());
        Http::assertNothingSent();
    }
}

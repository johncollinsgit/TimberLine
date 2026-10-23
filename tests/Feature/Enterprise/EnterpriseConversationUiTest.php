<?php

namespace Tests\Feature\Enterprise;

use App\Models\TeamOperationRecord;
use App\Models\TeamProject;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Support\EnterpriseConversationFixture;

final class EnterpriseConversationUiTest extends EnterpriseConversationFixture
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['enterprise.conversation.ui_enabled' => true]);
    }

    private function statusUrl(): string
    {
        return $this->url('/enterprise/conversation/status').'&area_id='.$this->area;
    }

    private function sessionUrl(string $id): string
    {
        return $this->url('/enterprise/conversation/sessions/'.$id).'&area_id='.$this->area;
    }

    public function test_projection_and_availability_are_read_only_and_bind_current_actor(): void
    {
        $this->getJson($this->statusUrl())->assertOk()->assertJsonPath('ready', true)
            ->assertJsonPath('scope.user_id', (string) $this->user->id)->assertJsonPath('intents.costs', true)
            ->assertJsonPath('remaining_microusd', 5000000)->assertJsonPath('maximum_turn_microusd', 20300)
            ->assertHeader('Cache-Control', 'no-store, private');
        $data = $this->getJson($this->url('/enterprise/data').'&area_id='.$this->area)->assertOk();
        $data->assertJsonPath('features.conversation_ui', true)->assertJsonPath('conversation.ready', true);
        $serialized = $data->getContent();
        $this->assertStringNotContainsString('synthetic-key-no-provider-access', $serialized);
        $this->assertStringNotContainsString(config('enterprise.conversation.approval_sha256'), $serialized);
        $this->assertSame(0, TeamOperationRecord::query()->whereIn('kind', ['ent_trial_charge', 'ent_conversation_session'])->count());
        Http::assertNothingSent();
    }

    public function test_default_off_and_missing_ledger_are_unavailable_without_initializing_anything(): void
    {
        config(['enterprise.conversation.enabled' => false]);
        $this->getJson($this->statusUrl())->assertOk()->assertJsonPath('ready', false)->assertJsonPath('state', 'unavailable');
        config(['enterprise.conversation.enabled' => true]);
        TeamOperationRecord::query()->where('kind', 'ent_trial_budget')->delete();
        $this->getJson($this->statusUrl())->assertOk()->assertJsonPath('ready', false);
        $this->getJson($this->url('/enterprise/data').'&area_id='.$this->area)->assertOk()->assertJsonPath('conversation.ready', false);
        $this->assertSame(0, TeamOperationRecord::query()->whereIn('kind', ['ent_trial_charge', 'ent_trial_budget', 'ent_conversation_session'])->count());
        config(['enterprise.conversation.ui_enabled' => false]);
        $this->getJson($this->statusUrl())->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_availability_respects_cost_role_paused_and_insufficient_budget(): void
    {
        DB::table('team_area_members')->insertOrIgnore(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'area_id' => $this->area, 'user_id' => $this->user->id, 'role' => 'coordinator']);
        $this->tenant->users()->updateExistingPivot($this->user->id, ['role' => 'member']);
        $this->getJson($this->statusUrl())->assertOk()->assertJsonPath('intents.costs', false);
        $row = TeamOperationRecord::query()->where('kind', 'ent_trial_budget')->firstOrFail();
        $row->data = array_replace($row->data, ['paused' => true]);
        $row->save();
        $this->getJson($this->statusUrl())->assertOk()->assertJsonPath('state', 'paused')->assertJsonPath('ready', false);
        config(['enterprise.trial.prior_spend_microusd' => 4990000]);
        $this->seedApprovedLedger();
        $this->approve();
        config(['enterprise.conversation.ui_enabled' => true]);
        $this->getJson($this->statusUrl())->assertOk()->assertJsonPath('state', 'limited')->assertJsonPath('ready', false);
        Http::assertNothingSent();
    }

    public function test_recovery_returns_owned_encrypted_session_without_provider_replay_or_budget_change(): void
    {
        $this->fake();
        $result = $this->turn()->assertOk()->json();
        $before = TeamOperationRecord::query()->where('kind', 'ent_trial_charge')->firstOrFail()->toArray();
        foreach (range(1, 3) as $unused) {
            $this->getJson($this->sessionUrl($result['session_id']))->assertOk()->assertJsonPath('provider_dispatch', false)
                ->assertJsonPath('state', 'active')->assertJsonPath('last_result.request_id', $result['request_id'])
                ->assertJsonPath('history.0.role', 'user')->assertJsonPath('history.1.role', 'assistant')
                ->assertHeader('Cache-Control', 'no-store, private');
        }
        Http::assertSentCount(1);
        $this->assertSame($before, TeamOperationRecord::query()->where('kind', 'ent_trial_charge')->firstOrFail()->toArray());
    }

    public function test_recovery_of_uncertain_turn_is_read_only_and_never_releases_held_funds(): void
    {
        $attempts = 0;
        $this->fake(function () use (&$attempts) {
            $attempts++;
            throw new \RuntimeException('Synthetic ambiguous timeout');
        });
        $session = (string) Str::uuid();
        $request = (string) Str::uuid();
        $this->turn(['session_id' => $session, 'request_id' => $request])->assertStatus(503);
        $before = TeamOperationRecord::query()->where('kind', 'ent_trial_charge')->firstOrFail()->toArray();
        $this->getJson($this->sessionUrl($session))->assertOk()->assertJsonPath('state', 'unconfirmed')
            ->assertJsonPath('pending_request_id', $request)->assertJsonPath('last_result', null)->assertJsonPath('provider_dispatch', false);
        $this->getJson($this->statusUrl())->assertOk()->assertJsonPath('state', 'paused');
        $this->assertSame(1, $attempts);
        $this->assertSame($before, TeamOperationRecord::query()->where('kind', 'ent_trial_charge')->firstOrFail()->toArray());
    }

    public function test_recovery_denies_changed_sources_membership_role_scope_expiry_and_other_user(): void
    {
        $this->fake();
        $result = $this->turn()->assertOk()->json();
        $id = $result['session_id'];
        $this->getJson($this->sessionUrl((string) Str::uuid()))->assertNotFound();
        $other = User::forceCreate(['name' => 'Other fictional user', 'email' => 'other@example.invalid', 'password' => 'fixture', 'is_active' => true, 'email_verified_at' => now()]);
        $this->tenant->users()->attach($other->id, ['role' => 'owner', 'membership_active' => true]);
        $this->approve(['user_ids' => [(string) $this->user->id, (string) $other->id]]);
        config(['enterprise.conversation.ui_enabled' => true]);
        $this->actingAs($other)->getJson($this->sessionUrl($id))->assertNotFound();
        $this->actingAs($this->user);
        $this->approve();
        config(['enterprise.conversation.ui_enabled' => true]);
        $this->getJson($this->sessionUrl($id).'&area_id='.$this->manifest()['departments'][1]['id'])->assertNotFound();
        DB::table('team_area_members')->insertOrIgnore(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'area_id' => $this->area, 'user_id' => $this->user->id, 'role' => 'coordinator']);
        $this->tenant->users()->updateExistingPivot($this->user->id, ['role' => 'member']);
        $this->getJson($this->sessionUrl($id))->assertConflict();
        $this->tenant->users()->updateExistingPivot($this->user->id, ['role' => 'owner']);
        TeamProject::query()->whereKey($this->project)->update(['archived' => true]);
        $this->getJson($this->sessionUrl($id))->assertConflict();
        $this->tenant->users()->updateExistingPivot($this->user->id, ['membership_active' => false]);
        $this->getJson($this->sessionUrl($id))->assertForbidden();
        $this->tenant->users()->updateExistingPivot($this->user->id, ['membership_active' => true]);
        $this->travel(11)->minutes();
        $this->getJson($this->sessionUrl($id))->assertConflict();
        Http::assertSentCount(1);
    }

    public function test_explicit_clear_works_when_connection_off_and_preserves_metering(): void
    {
        $this->fake();
        $result = $this->turn()->assertOk()->json();
        $before = TeamOperationRecord::query()->where('kind', 'ent_trial_charge')->firstOrFail()->toArray();
        config(['enterprise.conversation.enabled' => false]);
        $url = $this->url('/enterprise/conversation/sessions/'.$result['session_id'].'/end');
        $this->postJson($url, ['area_id' => $this->area])->assertUnprocessable();
        $this->postJson($url, ['area_id' => $this->area, 'confirm_end' => true])->assertOk()->assertJsonPath('status', 'ended');
        $row = TeamOperationRecord::query()->findOrFail($result['session_id']);
        $this->assertSame(['user_id', 'expires_at'], array_keys($row->data));
        $this->assertSame($before, TeamOperationRecord::query()->where('kind', 'ent_trial_charge')->firstOrFail()->toArray());
        Http::assertSentCount(1);
    }
}

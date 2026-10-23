<?php

namespace Tests\Feature\Enterprise;

use App\Enterprise\Conversation\Connection;
use App\Enterprise\Trial\TrialBudget;
use App\Models\TeamOperationRecord;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\EnterpriseConversationFixture;

final class EnterpriseConversationTest extends EnterpriseConversationFixture
{
    public function test_real_route_uses_one_trial_claim_then_maintains_bounded_server_history_and_exact_replay(): void
    {
        $this->fake();
        $session = (string) Str::uuid();
        $key = (string) Str::uuid();
        $first = $this->turn(['session_id' => $session, 'request_id' => $key])->assertOk()
            ->assertJsonPath('mode', 'openai_generated')->assertJsonPath('provider_simulated', true)->assertJsonPath('provider_calls_live_verified', false)
            ->assertJsonPath('sources.0.ref', 'project:'.$this->project)->json();
        $this->turn(['session_id' => $session, 'request_id' => $key])->assertOk()->assertJsonPath('replayed', true);
        $this->turn(['session_id' => $session, 'request_id' => $key, 'question' => 'Different text'])->assertConflict();
        $this->turn(['session_id' => $session, 'question' => 'And what about that same project?'])->assertOk();
        Http::assertSentCount(2);
        Http::assertSent(function ($request) {
            $body = $request->data();
            $this->assertFalse($body['store']);
            $this->assertFalse($body['stream']);
            $this->assertFalse($body['background']);
            $this->assertSame([], $body['tools']);
            $this->assertSame('none', $body['tool_choice']);
            $this->assertSame(100, $body['max_output_tokens']);
            $this->assertSame('default', $body['service_tier']);
            $this->assertSame('disabled', $body['truncation']);

            return $request->hasHeader('OpenAI-Project', 'proj_synthetic');
        });
        $this->assertSame(700, app(TrialBudget::class)->status()['used_or_reserved_microusd']);
        $row = TeamOperationRecord::query()->findOrFail($session);
        $this->assertCount(4, $row->data['history']);
        $this->assertSame(2, $row->data['turns']);
        $raw = DB::table('team_operation_records')->where('id', $session)->value('data');
        $this->assertStringNotContainsString('What work needs attention?', $raw);
        $this->assertSame(0, TeamOperationRecord::query()->whereIn('kind', ['ent_ai_usage', 'ent_email', 'ent_trial_link'])->count());
        $this->assertSame(1, DB::table('team_projects')->count());
        $this->assertSame(0, DB::table('team_tasks')->count());
    }

    public function test_missing_or_changed_exact_approval_unsafe_bounds_and_other_enterprise_paid_paths_deny_before_http(): void
    {
        foreach ([['model' => 'unversioned-alias'], ['model_context_tokens' => 0], ['valid_until' => now()->subMinute()->toIso8601String()],
            ['api_key' => ''], ['silo_id' => '90000000-0000-4000-8000-000000000002'], ['tenant_id' => '999'],
            ['prior_spend_evidence_hash' => str_repeat('0', 64)], ['input_ceiling_microusd_per_million' => 1000000000]] as $delta) {
            $this->approve($delta);
            $this->turn()->assertStatus(503);
        }
        $this->approve();
        config(['enterprise.conversation.max_output_tokens' => 99]);
        $this->turn()->assertStatus(503);
        foreach (Connection::OTHER_PAID_FLAGS as $flag) {
            $this->approve();
            config([$flag => true]);
            $this->turn()->assertStatus(503);
            config([$flag => false]);
        }
        $this->approve();
        config(['enterprise.conversation.enabled' => false]);
        $this->turn()->assertNotFound();
        Http::assertNothingSent();
        $this->assertSame(0, TeamOperationRecord::query()->where('kind', 'ent_trial_charge')->count());
    }

    public function test_only_server_owned_context_and_approved_current_actor_can_reach_the_model(): void
    {
        foreach ([['history' => [['role' => 'assistant', 'text' => 'Old secret']]], ['role' => 'owner'], ['actor' => ['id' => 1]],
            ['tools' => ['pay']], ['source_url' => 'https://outside.invalid'], ['role_preview' => 'COO']] as $delta) {
            $this->turn($delta)->assertUnprocessable();
        }
        $this->turn(['question' => str_repeat('x', 1501)])->assertUnprocessable();
        $this->approve(['user_ids' => ['999']]);
        $this->turn()->assertNotFound();
        $this->approve();
        foreach (['Send an email', 'Please pay the contractor', 'Could you delete that project'] as $question) {
            $this->turn(['question' => $question])->assertOk()->assertJsonPath('mode', 'deterministic_policy')->assertJsonPath('provider_dispatch', false);
        }
        $this->tenant->users()->updateExistingPivot($this->user->id, ['role' => 'member']);
        $this->turn(['intent' => 'costs'])->assertNotFound();
        $this->tenant->users()->updateExistingPivot($this->user->id, ['membership_active' => false]);
        $this->turn()->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_shared_campaign_prior_spend_and_other_channel_reservations_deny_the_actual_provider_route(): void
    {
        config(['enterprise.trial.prior_spend_microusd' => 4979701]);
        $this->approve();
        $before = app(TrialBudget::class)->reserve('email', 'synthetic-other-channel', hash('sha256', 'other'), 10000);
        $this->turn()->assertStatus(429);
        Http::assertNothingSent();
        $status = app(TrialBudget::class)->status();
        $this->assertSame(4989701, $status['used_or_reserved_microusd']);
        $this->assertSame(10299, $status['remaining_microusd']);
        $this->assertSame(1, TeamOperationRecord::query()->where('kind', 'ent_trial_charge')->count());
        $this->assertSame('reserved', TeamOperationRecord::query()->findOrFail($before['id'])->status);
    }

    public function test_timeout_and_provider_completed_but_unverifiable_usage_hold_the_maximum_and_pause_every_new_turn(): void
    {
        $attempts = 0;
        $this->fake(function () use (&$attempts) {
            $attempts++;
            throw new \Illuminate\Http\Client\ConnectionException('Synthetic timeout after submit');
        });
        $session = (string) Str::uuid();
        $key = (string) Str::uuid();
        $this->turn(['session_id' => $session, 'request_id' => $key])->assertStatus(503);
        $charge = TeamOperationRecord::query()->where('kind', 'ent_trial_charge')->firstOrFail();
        $this->assertSame('uncertain', $charge->status);
        $this->assertSame(20300, $charge->data['charged_microusd']);
        $this->assertTrue(app(TrialBudget::class)->status()['paused']);
        $this->turn(['session_id' => $session, 'request_id' => $key])->assertStatus(503);
        $this->turn()->assertStatus(503);
        $this->assertSame(1, $attempts, 'The timeout fake is invoked once; Laravel does not record a thrown fake as a completed HTTP response.');
        try {
            app(TrialBudget::class)->settle($charge->id, 'accepted', ['provider_id' => 'resp_late'], 0);
            $this->fail('A late unverified completion cannot release uncertainty.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $error) {
            $this->assertSame(409, $error->getStatusCode());
        }
        $this->assertSame(20300, app(TrialBudget::class)->status()['used_or_reserved_microusd']);
    }

    public static function invalidProviderResponses(): array
    {
        return [
            'wrong model' => ['model', 'other-model-2026-01-01'],
            'wrong tier' => ['service_tier', 'priority'],
            'incomplete' => ['status', 'incomplete'],
            'missing provider ID' => ['id', null],
            'input over context' => ['usage.input_tokens', 10001],
            'negative usage' => ['usage.input_tokens', -1],
            'missing usage' => ['usage.output_tokens', null],
            'output over bound' => ['usage.output_tokens', 101],
            'cache exceeds input' => ['usage.input_tokens_details.cached_tokens', 101],
            'cache plus write exceeds input' => ['usage.input_tokens_details.cache_write_tokens', 90],
            'unexpected tool' => ['output.0.type', 'function_call'],
            'refusal' => ['output.0.content.0.type', 'refusal'],
            'no text' => ['output.0.content.0.text', ''],
            'malformed JSON' => ['output.0.content.0.text', '{'],
            'foreign citation' => ['output.0.content.0.text', '{"answer":"Unavailable","refs":["project:foreign"]}'],
            'no citation' => ['output.0.content.0.text', '{"answer":"Unavailable","refs":[]}'],
            'action claim' => ['output.0.content.0.text', '{"answer":"I have paid the contractor.","refs":[]}'],
            'invented URL' => ['output.0.content.0.text', '{"answer":"Go to https://outside.invalid","refs":[]}'],
            'extra field' => ['output.0.content.0.text', '{"answer":"Unavailable","refs":[],"tool":"send"}'],
        ];
    }

    #[DataProvider('invalidProviderResponses')]
    public function test_completed_but_unverifiable_response_holds_maximum_without_showing_text(string $path, mixed $value): void
    {
        $this->fake(function ($request, $response) use ($path, $value) {
            data_set($response, $path, $value);

            return Http::response($response);
        });
        $this->turn()->assertStatus(503)->assertJsonMissingPath('reply');
        $this->assertTrue(app(TrialBudget::class)->status()['paused']);
        $this->assertSame(20300, app(TrialBudget::class)->status()['used_or_reserved_microusd']);
        Http::assertSentCount(1);
    }

    public function test_current_native_source_changes_discard_generated_text_and_prevent_history_replay(): void
    {
        $session = (string) Str::uuid();
        $this->fake(function ($request, $response) {
            $payload = json_decode(DB::table('team_projects')->where('id', $this->project)->value('payload'), true);
            $payload['name'] = 'Changed while provider worked';
            DB::table('team_projects')->where('id', $this->project)->update(['payload' => json_encode($payload)]);

            return Http::response($response);
        });
        $this->turn(['session_id' => $session])->assertConflict()->assertJsonMissingPath('reply');
        $this->assertSame(350, app(TrialBudget::class)->status()['used_or_reserved_microusd']);
        $this->turn(['session_id' => $session])->assertConflict();
        Http::assertSentCount(1);
        $this->assertArrayNotHasKey('last_result', TeamOperationRecord::query()->findOrFail($session)->data);
    }

    public function test_pending_exact_retry_and_changed_turn_cannot_dispatch_a_second_provider_request(): void
    {
        $session = (string) Str::uuid();
        $key = (string) Str::uuid();
        $this->fake(function ($request, $response) use ($session, $key) {
            foreach ([$key, (string) Str::uuid()] as $pending) {
                try {
                    app(\App\Enterprise\Conversation\Assistant::class)->turn($this->tenant, $this->user, $this->area, 'overview', null,
                        'What work needs attention?', $session, $pending);
                    $this->fail('A pending duplicate must not dispatch.');
                } catch (\Symfony\Component\HttpKernel\Exception\HttpException $error) {
                    $this->assertSame(409, $error->getStatusCode());
                }
            }

            return Http::response($response);
        });
        $this->turn(['session_id' => $session, 'request_id' => $key])->assertOk();
        $this->turn(['session_id' => $session, 'request_id' => $key])->assertOk()->assertJsonPath('replayed', true);
        Http::assertSentCount(1);
        $this->assertSame(1, TeamOperationRecord::query()->where('kind', 'ent_trial_charge')->count());
    }

    public function test_exact_activation_config_keeps_real_legacy_paid_endpoints_server_off_even_with_poisoned_flags(): void
    {
        $id = (string) Str::uuid();
        // These flags and synthetic legacy credentials would otherwise expose distinct authorities.
        // No real credential is read, copied or requested.
        config(['enterprise.ask_enabled' => true, 'enterprise.voice_jobs_enabled' => true, 'enterprise.voice_generation_enabled' => true,
            'enterprise.workflow_enabled' => true, 'enterprise.email_drafts_enabled' => true, 'enterprise.gmail.send_enabled' => true,
            'enterprise.mailbox.send_enabled' => true, 'enterprise.mailbox.enabled' => true, 'enterprise.mailbox.allowed_ids' => ['1'],
            'mailbox.direct_enabled' => true, 'mailbox.submission_host' => 'smtp.synthetic.invalid', 'mailbox.submission_port' => 587, 'enterprise.intelligence.enabled' => true,
            'enterprise.trial.canonical_origin' => 'https://client.example.invalid']);
        foreach (['ai', 'sms', 'email'] as $channel) {
            config(['enterprise.trial.channels.'.$channel.'.enabled' => true]);
        }
        foreach (['/enterprise/ask', '/enterprise/voice/turns', '/enterprise/briefing/refresh', '/enterprise/workflows/propose',
            '/enterprise/email-drafts/'.$id.'/confirm'] as $path) {
            $this->postJson($this->url($path), ['area_id' => $this->area, 'session_id' => $id, 'request_id' => $id,
                'question' => 'Summarize work', 'digest' => str_repeat('0', 64), 'confirmed' => true])->assertNotFound();
        }
        foreach (['start', 'previews', 'previews/'.$id.'/confirm'] as $path) {
            // Trial routes intentionally do not accept workspace query parameters.
            $this->postJson('https://client.example.invalid/enterprise-campus/enterprise/trial/'.$path,
                ['reviewer_code' => 'synthetic', 'channel' => 'ai', 'request_id' => $id])->assertNotFound();
        }
        $this->assertFalse(app(\App\Enterprise\Intelligence::class)->status($this->tenant)['ready']);
        $this->assertFalse(app(\App\Enterprise\GmailMailboxGateway::class)->ready());
        $this->assertFalse(app(\App\Enterprise\ConnectedMailboxGateway::class)->ready());
        foreach (['ai', 'sms', 'email'] as $channel) {
            $content = ['body' => 'Synthetic preview only', 'destination' => '+12025550123', 'sources' => [], 'fields' => []];
            $this->assertFalse(app(\App\Enterprise\Trial\TrialTransport::class)->quote($channel, $content)['ready']);
        }
        $this->turn()->assertStatus(503);
        Http::assertNothingSent();
        $this->assertSame(0, TeamOperationRecord::query()->whereIn('kind', ['ent_trial_charge', 'ent_ai_usage', 'ent_gmail_send'])->count());
    }

    public function test_forged_request_scope_and_connection_fields_do_not_reach_provider(): void
    {
        $this->turn(['area_id' => $this->manifest()['departments'][1]['id']])->assertNotFound();
        $this->postJson('https://client.example.invalid/enterprise-campus/enterprise/conversation/turns?tenant=foreign-workspace',
            ['area_id' => $this->area, 'intent' => 'overview', 'question' => 'Work', 'session_id' => (string) Str::uuid(), 'request_id' => (string) Str::uuid()])->assertNotFound();
        foreach (['tenant_id', 'user_id', 'silo_id', 'model', 'api_key', 'project_id', 'input_ceiling_microusd_per_million'] as $key) {
            $this->turn([$key => 'forged'])->assertUnprocessable();
        }
        Http::assertNothingSent();
    }

    public static function failedHttpStatuses(): array
    {
        return [[302], [429], [500]];
    }

    #[DataProvider('failedHttpStatuses')]
    public function test_redirect_and_provider_errors_keep_full_exposure_without_retry(int $status): void
    {
        $this->fake(fn ($request, $response) => Http::response($response, $status));
        $this->turn()->assertStatus(503)->assertJsonMissingPath('reply');
        Http::assertSentCount(1);
        $this->assertSame(20300, app(TrialBudget::class)->status()['used_or_reserved_microusd']);
        $this->assertTrue(app(TrialBudget::class)->status()['paused']);
    }

    public function test_model_only_receives_selected_authorized_inputs_and_server_integer_costs_with_missing_basis_distinct_from_zero(): void
    {
        foreach ([[0, 'estimate', 'USD'], [900, 'recorded_cost', 'USD'], [200, 'recorded_cost', 'EUR']] as [$amount, $basis, $currency]) {
            $this->postJson($this->url('/enterprise/planning'), ['kind' => 'cost', 'id' => (string) Str::uuid(), 'request_id' => (string) Str::uuid(),
                'area_id' => $this->area, 'expected_version' => 0, 'title' => 'Synthetic '.$basis, 'status' => 'recorded', 'project_id' => $this->project,
                'amount_minor' => $amount, 'basis' => $basis, 'currency' => $currency])->assertOk();
        }
        $this->fake(function ($request, $response, $payload) {
            $groups = array_column($payload['calculations'], null, 'currency');
            $this->assertSame(0, $groups['USD']['estimate_minor']);
            $this->assertSame(900, $groups['USD']['recorded_cost_minor']);
            $this->assertSame(900, $groups['USD']['difference_minor']);
            $this->assertNull($groups['EUR']['estimate_minor']);
            $this->assertNull($groups['EUR']['difference_minor']);
            $this->assertSame(200, $groups['EUR']['recorded_cost_minor']);
            foreach ($payload['sources'] as $source) {
                $this->assertArrayNotHasKey('url', $source, 'The model cannot choose or generate authority-bearing URLs.');
            }
            $this->assertStringContainsString('no approved budget', json_encode($payload));

            return Http::response($response);
        });
        $result = $this->turn(['intent' => 'costs'])->assertOk()->json();
        $this->assertCount(2, $result['calculations']);
        $this->assertCount(3, $result['sources'], 'Every selected calculation input retains an inspectable current source, even when the model cites one.');
        $this->assertCount(1, $result['cited_refs']);
        $this->assertEqualsCanonicalizing(array_column($result['sources'], 'ref'), array_merge(...array_column($result['calculations'], 'input_refs')));
        $this->assertStringContainsString('/enterprise-campus/enterprise/assistant-sources/cost/', $result['sources'][0]['url']);
        $this->assertStringNotContainsString('approved budget variance', json_encode($result));
        Http::assertSentCount(1);
    }

    public function test_role_or_source_change_during_provider_work_discards_old_text_after_metering(): void
    {
        $this->fake(function ($request, $response) {
            $this->tenant->users()->updateExistingPivot($this->user->id, ['membership_active' => false]);

            return Http::response($response);
        });
        $this->turn()->assertForbidden()->assertJsonMissingPath('reply');
        Http::assertSentCount(1);
        $this->assertSame(350, app(TrialBudget::class)->status()['used_or_reserved_microusd']);
    }

    public function test_expiry_and_explicit_end_scrub_session_content_without_deleting_the_lifetime_charge(): void
    {
        $this->fake();
        $session = (string) Str::uuid();
        $this->turn(['session_id' => $session])->assertOk();
        $this->postJson($this->url('/enterprise/conversation/sessions/'.$session.'/end'), ['area_id' => $this->area])->assertUnprocessable();
        $this->postJson($this->url('/enterprise/conversation/sessions/'.$session.'/end'), ['area_id' => $this->area, 'confirm_end' => true])->assertOk();
        $row = TeamOperationRecord::query()->findOrFail($session);
        $this->assertSame('ended', $row->status);
        $this->assertArrayNotHasKey('history', $row->data);
        $this->assertArrayNotHasKey('last_result', $row->data);
        $this->assertSame(350, app(TrialBudget::class)->status()['used_or_reserved_microusd']);
        $this->turn(['session_id' => $session])->assertConflict();
        $second = (string) Str::uuid();
        $this->turn(['session_id' => $second])->assertOk();
        $this->travel(11)->minutes();
        $this->turn(['session_id' => $second])->assertConflict();
        $expired = TeamOperationRecord::query()->findOrFail($second);
        $this->assertSame('expired', $expired->status);
        $this->assertArrayNotHasKey('history', $expired->data);
        Http::assertSentCount(2);
    }
}

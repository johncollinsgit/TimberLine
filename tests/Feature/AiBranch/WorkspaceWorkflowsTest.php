<?php

use App\Models\AiBranchAccount;
use App\Models\FieldServiceJob;
use App\Models\MarketingEmailDelivery;
use App\Models\MarketingProfile;
use App\Models\Tenant;
use App\Models\TenantAccessAddon;
use App\Models\TenantAccessProfile;
use App\Models\TenantBillingSubscription;
use App\Models\User;
use App\Services\AiBranch\AiBranchChat;
use App\Services\AiBranch\AiWorkspaceMemory;
use App\Services\AiBranch\AiWorkspaceWorkflows;
use App\Services\Marketing\Email\EmailProvider;
use App\Services\Marketing\Email\TenantEmailDispatchService;
use App\Services\Marketing\Email\TenantEmailProviderResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->withoutVite();
    Http::preventStrayRequests();
    config(['ai_branch.enabled' => true, 'ai_branch.workspace_context_enabled' => true, 'ai_branch.assistant_enabled' => true,
        'ai_branch.actions_enabled' => true, 'ai_branch.workspace_workflows_enabled' => true, 'ai_branch.api_key' => 'fake',
        'ai_branch.model' => 'test-model', 'ai_branch.input_micros_per_million' => 1000000, 'ai_branch.output_micros_per_million' => 4000000]);
});

function workflowFixture(string $slug = 'voice-workflows'): array
{
    $tenant = Tenant::create(['name' => 'Fictional Cedar Service', 'slug' => $slug]);
    $user = User::factory()->tenantAdmin()->create();
    $user->tenants()->attach($tenant->id, ['role' => 'owner', 'membership_active' => true]);
    TenantAccessProfile::create(['tenant_id' => $tenant->id, 'plan_key' => 'base', 'operating_mode' => 'direct', 'source' => 'test', 'metadata' => ['ai_branch' => ['timezone' => 'America/New_York']]]);
    TenantAccessAddon::create(['tenant_id' => $tenant->id, 'addon_key' => 'ai_branch_growth', 'enabled' => true, 'source' => 'stripe_fulfillment']);
    TenantBillingSubscription::create(['tenant_id' => $tenant->id, 'provider' => 'stripe', 'provider_subscription_reference' => 'sub_'.$slug,
        'purchase_key' => 'addon.ai_branch_growth', 'status' => 'active', 'current_period_ends_at' => now()->addMonths(2)]);
    AiBranchAccount::create(['tenant_id' => $tenant->id, 'enabled' => true, 'budget_cents' => 1500]);
    foreach (['tenant_ids', 'assistant_tenant_ids', 'workspace_context_tenant_ids'] as $key) {
        config(['ai_branch.'.$key => [...config('ai_branch.'.$key, []), $tenant->id]]);
    }
    $customer = MarketingProfile::create(['tenant_id' => $tenant->id, 'first_name' => 'Sarah', 'last_name' => 'Cedar', 'email' => 'fictional-sarah@example.test', 'accepts_email_marketing' => true]);

    return [$tenant, $user, $customer];
}

function workflowJob(MarketingProfile $customer): array
{
    return ['kind' => 'create_job', 'customer_id' => $customer->id, 'title' => 'Annual maintenance', 'description' => 'Inspect the fictional equipment.',
        'address' => '123 Fictional Lane', 'scheduled_local' => '2026-11-06T10:00'];
}

function workflowSender($test, Tenant $tenant): void
{
    $provider = Mockery::mock(EmailProvider::class);
    $provider->shouldReceive('key')->andReturn('sendgrid');
    $mock = Mockery::mock(TenantEmailProviderResolver::class);
    $mock->shouldReceive('getEmailProviderForTenant')->with($tenant->id)->andReturn([
        'provider' => $provider, 'configuration_issues' => [], 'settings' => ['source' => 'tenant_email_settings', 'email_enabled' => true,
            'from_email' => 'office@fictional-cedar.test', 'from_name' => 'Cedar Service', 'reply_to_email' => 'office@fictional-cedar.test'],
    ]);
    app()->instance(TenantEmailProviderResolver::class, $mock);
}

test('website and native resume the same private workspace conversation without another provider request', function () {
    [$tenant, $user] = workflowFixture();
    $chat = app(AiBranchChat::class)->start($tenant, $user);
    $this->actingAs($user)->getJson('/ai-branch/assistant?tenant='.$tenant->slug)->assertOk()->assertJsonPath('conversation.id', $chat->id);
    Sanctum::actingAs($user, ['mobile:read', 'mobile:write']);
    $this->getJson('/api/mobile/v1/workspaces/'.$tenant->slug.'/ai-assistant')->assertOk()->assertJsonPath('conversation.id', $chat->id);
    $this->getJson('/api/mobile/v1/workspaces/'.$tenant->slug.'/ai-assistant?new=1')->assertOk()->assertJsonPath('conversation', null);
    Http::assertNothingSent();
});

test('workspace knowledge is encrypted and shared only with authorized users of its workspace', function () {
    [$tenant, $user] = workflowFixture();
    [$other, $otherUser] = workflowFixture('other-workspace');
    $memory = app(AiWorkspaceMemory::class)->change($tenant, $user, 'Use short, friendly service emails.');
    expect(app(AiWorkspaceMemory::class)->listing($tenant, $user))->toBe($memory)
        ->and(app(AiWorkspaceMemory::class)->listing($other, $otherUser))->toBe([])
        ->and(DB::table('tenant_access_profiles')->where('tenant_id', $tenant->id)->value('metadata'))->not->toContain('friendly service emails');
    $this->actingAs($otherUser)->getJson('/ai-branch/assistant?tenant='.$tenant->slug)->assertForbidden();
    app(AiWorkspaceMemory::class)->change($tenant, $user, null, $memory[0]['id']);
    expect(app(AiWorkspaceMemory::class)->listing($tenant, $user))->toBe([]);
});

test('customer lookup is scoped, bounded and supplies current linked jobs without contact or secret fields', function () {
    [$tenant, $user, $customer] = workflowFixture();
    [$other] = workflowFixture('other-customer');
    MarketingProfile::create(['tenant_id' => $other->id, 'first_name' => 'Sarah', 'last_name' => 'Foreign']);
    FieldServiceJob::create(['tenant_id' => $tenant->id, 'marketing_profile_id' => $customer->id, 'title' => 'Current service visit', 'status' => 'open', 'lock_box_code' => 'NEVER-EXPOSE']);
    $rows = app(AiWorkspaceWorkflows::class)->customers($tenant, $user, 'Sarah');
    expect($rows)->toHaveCount(1)->and($rows[0]['jobs'][0]['title'])->toBe('Current service visit');
    $json = json_encode($rows);
    expect($json)->not->toContain('Foreign')->not->toContain('NEVER-EXPOSE')->not->toContain($customer->email);
});

test('reviewed job approval creates exactly one job across repeated web and mobile approvals', function () {
    [$tenant, $user, $customer] = workflowFixture();
    $service = app(AiWorkspaceWorkflows::class);
    $action = $service->propose($tenant, $user, workflowJob($customer), (string) Str::uuid());
    expect(FieldServiceJob::count())->toBe(0);
    $this->actingAs($user)->postJson('/ai-branch/workflows/'.$action->id.'/decision?tenant='.$tenant->slug,
        ['digest' => $action->digest, 'decision' => 'approve', 'confirmed' => true])->assertOk()->assertJsonPath('action.status', 'executed');
    Sanctum::actingAs($user, ['mobile:read', 'mobile:write']);
    $this->postJson('/api/mobile/v1/workspaces/'.$tenant->slug.'/ai-assistant/workflows/'.$action->id.'/decision',
        ['digest' => $action->digest, 'decision' => 'approve', 'confirmed' => true])->assertOk();
    expect(FieldServiceJob::count())->toBe(1)->and(FieldServiceJob::sole()->marketing_profile_id)->toBe($customer->id)
        ->and(FieldServiceJob::sole()->scheduled_for->utc()->format('H:i'))->toBe('15:00');
    Http::assertNothingSent();
});

test('changed customer and altered preview digest cannot approve an old job draft', function () {
    [$tenant, $user, $customer] = workflowFixture();
    $action = app(AiWorkspaceWorkflows::class)->propose($tenant, $user, workflowJob($customer), (string) Str::uuid());
    $base = '/ai-branch/workflows/'.$action->id.'/decision?tenant='.$tenant->slug;
    $this->actingAs($user)->postJson($base, ['digest' => str_repeat('a', 64), 'decision' => 'approve', 'confirmed' => true])->assertConflict();
    $customer->update(['email' => 'changed@example.test']);
    $this->postJson($base, ['digest' => $action->digest, 'decision' => 'approve', 'confirmed' => true])->assertConflict();
    expect(FieldServiceJob::count())->toBe(0);
});

test('email uses the reviewed workspace sender, escaped formatting and one durable delivery', function () {
    [$tenant, $user, $customer] = workflowFixture();
    workflowSender($this, $tenant);
    $service = app(AiWorkspaceWorkflows::class);
    $action = $service->propose($tenant, $user, ['kind' => 'customer_email', 'customer_id' => $customer->id, 'subject' => 'Your service options', 'body' => "Hello Sarah,\n<script>unsafe</script>"], (string) Str::uuid());
    $this->mock(TenantEmailDispatchService::class, function ($mock) use ($tenant, $customer) {
        $mock->shouldReceive('sendEmail')->once()->withArgs(fn ($to, $subject, $text, $options) => $to === $customer->email && $subject === 'Your service options'
            && $options['tenant_id'] === $tenant->id && $options['is_marketing'] === true
            && str_contains($options['html_body'], '&lt;script&gt;') && ! str_contains($options['html_body'], '<script>')
            && $options['from_email'] === 'office@fictional-cedar.test')->andReturn(['success' => true, 'message_id' => 'fictional-provider-receipt']);
    });
    $service->decide($tenant, $user, $action->id, $action->digest, true);
    $service->decide($tenant, $user, $action->id, $action->digest, true);
    expect(MarketingEmailDelivery::count())->toBe(1)->and(MarketingEmailDelivery::sole()->status)->toBe('sent')
        ->and(MarketingEmailDelivery::sole()->provider_message_id)->toBe('fictional-provider-receipt');
});

test('unknown email outcomes remain visible and approval replay never resends', function () {
    [$tenant, $user, $customer] = workflowFixture();
    workflowSender($this, $tenant);
    $service = app(AiWorkspaceWorkflows::class);
    $action = $service->propose($tenant, $user, ['kind' => 'customer_email', 'customer_id' => $customer->id, 'subject' => 'A question', 'body' => 'Hello Sarah'], (string) Str::uuid());
    $this->mock(TenantEmailDispatchService::class, fn ($mock) => $mock->shouldReceive('sendEmail')->once()->andThrow(new RuntimeException('timeout')));
    expect($service->decide($tenant, $user, $action->id, $action->digest, true)->status)->toBe('unknown');
    expect($service->decide($tenant, $user, $action->id, $action->digest, true)->status)->toBe('unknown');
    expect(MarketingEmailDelivery::count())->toBe(1);
});

test('a structured model response prepares a review card and settles usage without executing business work', function () {
    [$tenant, $user, $customer] = workflowFixture();
    $draft = workflowJob($customer);
    $reply = json_encode(['reply' => 'Please review the maintenance job.', 'draft' => $draft]);
    Http::fake(['api.openai.com/v1/responses' => Http::response(['id' => 'resp_workflow', 'status' => 'completed', 'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => $reply]]]], 'usage' => ['input_tokens' => 200, 'output_tokens' => 100]])]);
    $chat = app(AiBranchChat::class);
    $conversation = $chat->start($tenant, $user);
    $result = $chat->ask($tenant, $user, $conversation->id, 'Create a maintenance job for Sarah.', (string) Str::uuid());
    expect($result['text'])->toBe('Please review the maintenance job.')->and($result['workflow_action_id'])->toBeInt()->and(FieldServiceJob::count())->toBe(0);
    Http::assertSent(fn ($request) => $request['text']['format']['type'] === 'json_schema' && str_contains(json_encode($request['input']), 'Sarah'));
});

test('native read-only token cannot save workspace knowledge or approve a job', function () {
    [$tenant, $user] = workflowFixture();
    Sanctum::actingAs($user, ['mobile:read']);
    $this->postJson('/api/mobile/v1/workspaces/'.$tenant->slug.'/ai-assistant/workspace-memory', ['body' => 'Shared', 'confirmed' => true])->assertForbidden();
    Http::assertNothingSent();
});

test('customer follow-up agent creation is reviewed, encrypted and scheduled once per local day', function () {
    config(['ai_branch.workspace_agents_enabled' => true]);
    Illuminate\Support\Facades\Queue::fake();
    [$tenant, $user] = workflowFixture();
    $service = app(App\Services\AiBranch\AiWorkspaceAgents::class);
    $action = $service->propose($tenant, $user, ['agent_name' => 'Customer care', 'agent_instructions' => 'Prepare friendly service follow-ups.', 'agent_cadence' => 'daily', 'agent_time' => '09:00'], (string) Str::uuid());
    expect($service->listing($tenant, $user))->toBe([]);
    $service->decide($tenant, $user, $action->id, $action->digest, true);
    $service->decide($tenant, $user, $action->id, $action->digest, true);
    expect($service->listing($tenant, $user))->toHaveCount(1)
        ->and(DB::table('tenant_access_profiles')->where('tenant_id', $tenant->id)->value('metadata'))->not->toContain('Prepare friendly service follow-ups');
    $this->travelTo(Carbon\CarbonImmutable::parse('2026-10-23T10:00:00', 'America/New_York'));
    $service->scheduledRuns();
    $service->scheduledRuns();
    expect(App\Models\AiBranchAction::where('kind', 'workspace_agent_run')->count())->toBe(1);
    Illuminate\Support\Facades\Queue::assertPushed(App\Jobs\RunWorkspaceFollowupAgent::class, 1);
    $agent = $service->listing($tenant, $user)[0];
    $service->pause($tenant, $user, $agent['id']);
    $this->travel(1)->days();
    $service->scheduledRuns();
    expect(App\Models\AiBranchAction::where('kind', 'workspace_agent_run')->count())->toBe(1);
});

test('follow-up agent cannot create jobs and replaying its queued job never repeats provider inference', function () {
    config(['ai_branch.workspace_agents_enabled' => true]);
    Illuminate\Support\Facades\Queue::fake();
    [$tenant, $user, $customer] = workflowFixture();
    $service = app(App\Services\AiBranch\AiWorkspaceAgents::class);
    $action = $service->propose($tenant, $user, ['agent_name' => 'Customer care', 'agent_instructions' => 'Prepare one follow-up.', 'agent_cadence' => 'manual'], (string) Str::uuid());
    $service->decide($tenant, $user, $action->id, $action->digest, true);
    $agent = $service->listing($tenant, $user)[0];
    $uuid = (string) Str::uuid();
    $run = $service->run($tenant, $user, $agent['id'], $uuid);
    expect($service->run($tenant, $user, $agent['id'], $uuid)->id)->toBe($run->id);
    Http::fake(['api.openai.com/v1/responses' => Http::response(['id' => 'resp_agent', 'status' => 'completed', 'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode(['reply' => 'Review this work.', 'draft' => workflowJob($customer)])]]]], 'usage' => ['input_tokens' => 200, 'output_tokens' => 100]])]);
    $service->perform($tenant->id, $user->id, $run->id);
    $service->perform($tenant->id, $user->id, $run->id);
    expect($run->refresh()->status)->toBe('executed')->and(FieldServiceJob::count())->toBe(0)
        ->and(App\Models\AiBranchAction::where('kind', 'workspace_create_job')->count())->toBe(0);
    Http::assertSentCount(1);
});

test('revoked agent access blocks queued work before any provider request', function () {
    config(['ai_branch.workspace_agents_enabled' => true]);
    Illuminate\Support\Facades\Queue::fake();
    [$tenant, $user] = workflowFixture();
    $service = app(App\Services\AiBranch\AiWorkspaceAgents::class);
    $action = $service->propose($tenant, $user, ['agent_name' => 'Customer care', 'agent_instructions' => 'Prepare one follow-up.', 'agent_cadence' => 'manual'], (string) Str::uuid());
    $service->decide($tenant, $user, $action->id, $action->digest, true);
    $run = $service->run($tenant, $user, $service->listing($tenant, $user)[0]['id'], (string) Str::uuid());
    $user->tenants()->updateExistingPivot($tenant->id, ['membership_active' => false]);
    $service->perform($tenant->id, $user->id, $run->id);
    expect($run->refresh()->status)->toBe('needs_review');
    Http::assertNothingSent();
});

function workflowWav(int $seconds = 1): string
{
    $data = str_repeat("\0", 32000 * $seconds);

    return base64_encode('RIFF'.pack('V', strlen($data) + 36).'WAVEfmt '.pack('VvvVVvv', 16, 1, 1, 16000, 32000, 2, 16).'data'.pack('V', strlen($data)).$data);
}

test('API audio requires separate reviewed rates and verified wave duration, then replays without another charge', function () {
    config(['ai_branch.workspace_audio_enabled' => true]);
    [$tenant, $user] = workflowFixture();
    $audio = app(App\Services\AiBranch\AiWorkspaceAudio::class);
    $base = '/ai-branch/voice/';
    $this->actingAs($user)->postJson($base.'transcribe?tenant='.$tenant->slug, ['audio' => workflowWav(), 'request_id' => (string) Str::uuid()])->assertForbidden();
    $p = $audio->presentation($tenant, $user);
    $this->postJson($base.'settings?tenant='.$tenant->slug, ['enabled' => true, 'rates_digest' => $p['rates_digest'], 'confirmed' => true])->assertOk();
    $this->postJson($base.'transcribe?tenant='.$tenant->slug, ['audio' => workflowWav(31), 'request_id' => (string) Str::uuid()])->assertUnprocessable();
    Http::fake(['api.openai.com/v1/audio/transcriptions' => Http::response(['text' => 'What does Sarah need next?'], 200, ['x-request-id' => 'req_audio'])]);
    $uuid = (string) Str::uuid();
    expect($audio->transcribe($tenant, $user, $uuid, workflowWav()))->toBe(['text' => 'What does Sarah need next?']);
    expect($audio->transcribe($tenant, $user, $uuid, workflowWav()))->toBe(['text' => 'What does Sarah need next?']);
    expect(App\Models\TenantAiUsageEvent::where('feature', 'ai_branch_audio')->sole()->provider_cost_micros)->toBe(75);
    Http::assertSentCount(1);
});

test('API audio shares the text cap, preserves uncertain reservations and never retries a timed out request', function () {
    config(['ai_branch.workspace_audio_enabled' => true]);
    [$tenant, $user] = workflowFixture();
    $audio = app(App\Services\AiBranch\AiWorkspaceAudio::class);
    $audio->settings($tenant, $user, true, $audio->presentation($tenant, $user)['rates_digest']);
    Http::fake(['api.openai.com/v1/audio/transcriptions' => Http::response([], 503)]);
    $uuid = (string) Str::uuid();
    $this->actingAs($user)->postJson('/ai-branch/voice/transcribe?tenant='.$tenant->slug, ['audio' => workflowWav(), 'request_id' => $uuid])->assertStatus(503);
    $this->postJson('/ai-branch/voice/transcribe?tenant='.$tenant->slug, ['audio' => workflowWav(), 'request_id' => $uuid])->assertConflict();
    expect(app(AiBranchChat::class)->usage($tenant)['provider_micros'])->toBe(75);
    AiBranchAccount::where('tenant_id', $tenant->id)->update(['budget_cents' => 0]);
    $this->postJson('/ai-branch/voice/transcribe?tenant='.$tenant->slug, ['audio' => workflowWav(), 'request_id' => (string) Str::uuid()])->assertTooManyRequests();
    Http::assertSentCount(1);
});

test('API speech reads a saved answer only and cannot read another user conversation', function () {
    config(['ai_branch.workspace_audio_enabled' => true]);
    [$tenant, $user] = workflowFixture();
    $audio = app(App\Services\AiBranch\AiWorkspaceAudio::class);
    $audio->settings($tenant, $user, true, $audio->presentation($tenant, $user)['rates_digest']);
    $chat = app(AiBranchChat::class)->start($tenant, $user);
    $uuid = (string) Str::uuid();
    $chat->update(['messages' => [['role' => 'assistant', 'uuid' => $uuid, 'text' => 'Your saved workspace answer.']]]);
    Http::fake(['api.openai.com/v1/chat/completions' => Http::response(['id' => 'audio_result', 'choices' => [['finish_reason' => 'stop', 'message' => ['audio' => ['data' => base64_encode(str_repeat('mp3', 20))]]]], 'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 100, 'completion_tokens_details' => ['audio_tokens' => 80]]])]);
    $requestId = (string) Str::uuid();
    $result = $audio->speech($tenant, $user, $chat->id, $uuid, $requestId);
    expect($result['text'])->toBe('Your saved workspace answer.');
    $audio->speech($tenant, $user, $chat->id, $uuid, $requestId);
    Http::assertSentCount(1);
    Http::assertSent(fn ($request) => $request['messages'][1]['content'] === 'Your saved workspace answer.' && $request['store'] === false);
    $chat->update(['user_id' => User::factory()->create()->id]);
    $this->actingAs($user)->postJson('/ai-branch/voice/speech?tenant='.$tenant->slug, ['conversation_id' => $chat->id, 'message_uuid' => $uuid, 'request_id' => (string) Str::uuid()])->assertNotFound();
});

test('reviewed API audio settles prepaid credit at two times provider cost exactly once', function () {
    config(['ai_branch.workspace_audio_enabled' => true, 'ai_branch.standard_enabled' => true]);
    [$tenant, $user] = workflowFixture();
    config(['ai_branch.existing_tenants' => [$tenant->id => $tenant->slug]]);
    App\Models\TenantModuleEntitlement::create(['tenant_id' => $tenant->id, 'module_key' => 'ai_branch', 'enabled_status' => 'enabled',
        'availability_status' => 'available', 'billing_status' => 'included', 'entitlement_source' => App\Services\AiBranch\AiBranchOffer::INCLUDED_SOURCE]);
    app(App\Services\Marketing\Messaging\TenantMessagingUsageService::class)->fund($tenant->id, 10000, 'fictional-audio-credit');
    $audio = app(App\Services\AiBranch\AiWorkspaceAudio::class);
    expect($audio->presentation($tenant, $user)['buyer_multiplier'])->toBe(2);
    $audio->settings($tenant, $user, true, $audio->presentation($tenant, $user)['rates_digest']);
    Http::fake(['api.openai.com/v1/audio/transcriptions' => Http::response(['text' => 'Hello workspace'])]);
    $uuid = (string) Str::uuid();
    $audio->transcribe($tenant, $user, $uuid, workflowWav());
    $audio->transcribe($tenant, $user, $uuid, workflowWav());
    $credit = App\Models\TenantMessagingCreditAccount::forAllTenants()->where('tenant_id', $tenant->id)->sole();
    expect($credit->balance_micros)->toBe(9850)->and($credit->reserved_micros)->toBe(0)
        ->and(app(AiBranchChat::class)->usage($tenant)['buyer_micros'])->toBe(150);
    Http::assertSentCount(1);
});

test('reviewed workspace SendGrid sends disable transport retries on ambiguous provider failures', function () {
    Http::fake(['api.sendgrid.com/v3/mail/send' => Http::response([], 503)]);
    $provider = app(App\Services\Marketing\Email\Providers\SendGridEmailProvider::class);
    $result = $provider->sendEmail(['to_email' => 'fictional@example.test', 'subject' => 'Reviewed follow-up', 'text' => 'Hello customer', 'from_email' => 'office@example.test'], ['api_key' => 'fake-key', 'no_provider_retry' => true]);
    expect($result['success'])->toBeFalse();
    Http::assertSentCount(1);
});

test('workspace access changes hide prepared details and invalidate old approval cards', function () {
    [$tenant, $user, $customer] = workflowFixture();
    $service = app(AiWorkspaceWorkflows::class);
    $action = $service->propose($tenant, $user, workflowJob($customer), (string) Str::uuid());
    $user->update(['role' => 'marketing_manager']);
    expect($service->listing($tenant, $user))->toBe([]);
    $this->actingAs($user)->postJson('/ai-branch/workflows/'.$action->id.'/decision?tenant='.$tenant->slug,
        ['digest' => $action->digest, 'decision' => 'approve', 'confirmed' => true])->assertConflict();
    expect(FieldServiceJob::count())->toBe(0);
    Http::assertNothingSent();
});

<?php

use App\Models\IntegrationConnection;
use App\Models\MarketingAssistantTurn;
use App\Models\MarketingBrandKnowledgeVersion;
use App\Models\MarketingConversationControl;
use App\Models\MarketingProfile;
use App\Models\MarketingSocialPost;
use App\Models\MarketingSocialPublication;
use App\Models\Tenant;
use App\Models\TenantAiUsageEvent;
use App\Models\TenantBudSetting;
use App\Models\TenantModuleEntitlement;
use App\Models\User;
use App\Services\Automation\V2\WorkflowDraftService;
use App\Services\Marketing\Desk\MarketingAiService;
use App\Services\Marketing\Desk\MarketingWorkflowCatalog;
use App\Services\Marketing\Desk\SocialAccountOwnership;
use App\Services\Marketing\Desk\SocialPostService;
use App\Services\Marketing\Desk\SocialPublisher;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $this->withoutVite();
    Http::preventStrayRequests();
    Queue::fake();
    config(['marketing_desk.enabled' => true, 'marketing_desk.meta.execution_enabled' => true, 'marketing_desk.delivery_enabled' => true, 'marketing_desk.media_hosts' => ['media.example.test'],
        'bud.ai_enabled' => true, 'bud.ai_api_key' => 'test-only', 'marketing_desk.input_micros_per_million' => 1000000, 'marketing_desk.output_micros_per_million' => 1000000]);
});

function deskWorkspace(string $slug = 'pilot'): array
{
    $tenant = Tenant::create(['name' => ucfirst($slug), 'slug' => $slug]);
    $actor = User::factory()->create(['role' => 'admin', 'email_verified_at' => now()]);
    $actor->tenants()->attach($tenant->id, ['role' => 'admin', 'membership_active' => true]);
    foreach (['messaging', 'workflow_automations', 'ai'] as $module) {
        TenantModuleEntitlement::create(['tenant_id' => $tenant->id, 'module_key' => $module, 'availability_status' => 'available', 'enabled_status' => 'enabled', 'billing_status' => 'included_in_plan', 'entitlement_source' => 'test', 'price_source' => 'catalog']);
    }
    config(['marketing_desk.tenant_ids' => array_merge(config('marketing_desk.tenant_ids', []), [$tenant->id]), 'automation_workflows.v2.enabled' => true]);
    \App\Models\TenantAccessProfile::updateOrCreate(['tenant_id' => $tenant->id], ['plan_key' => 'growth', 'operating_mode' => 'hybrid', 'metadata' => ['marketing_desk' => ['ai_budget' => ['cap_cents' => 5000, 'approved_by' => $actor->id, 'approved_at' => now()->toIso8601String()]]]]);

    return [$tenant, $actor];
}
function deskKnowledge(int $tenantId, int $actorId): MarketingBrandKnowledgeVersion
{
    return MarketingBrandKnowledgeVersion::create(['tenant_id' => $tenantId, 'title' => 'Approved brand', 'audience' => 'general', 'content' => ['values' => 'Be useful and truthful', 'voice' => 'Warm and clear', 'facts' => 'We make candles.'], 'approved_by' => $actorId, 'approved_at' => now(), 'expires_at' => now()->addMonth()]);
}
function deskConnection(int $tenantId, string $platform): IntegrationConnection
{
    return IntegrationConnection::create(['tenant_id' => $tenantId, 'provider' => $platform, 'external_account_id' => hash_hmac('sha256', $platform.'-'.$tenantId, config('app.key')), 'external_account_secret' => $platform.'-'.$tenantId, 'external_account_label' => ucfirst($platform), 'status' => 'connected', 'access_token' => 'test-secret', 'scopes' => $platform === 'facebook' ? ['pages_manage_posts', 'read_insights', 'pages_messaging'] : ['instagram_business_content_publish', 'instagram_business_manage_messages', 'instagram_business_manage_insights']]);
}
function deskPost(int $tenantId, User $actor): MarketingSocialPost
{
    return app(SocialPostService::class)->save($tenantId, $actor, ['caption' => 'A thoughtful moment', 'media_type' => 'image', 'media_urls' => ['https://media.example.test/candle.jpg']]);
}

test('desk is default-off and tenant allowlist never expands when empty', function (): void {
    [$tenant,$actor] = deskWorkspace();
    config(['marketing_desk.tenant_ids' => []]);
    $this->actingAs($actor)->get(route('marketing.desk.index', ['tenant' => $tenant->slug]))->assertForbidden();
});

test('a marketing usage reservation cannot be replayed as a voice request', function (): void {
    [$tenant, $actor] = deskWorkspace();
    config(['bud.provider_configured' => true]);
    TenantBudSetting::create(['tenant_id' => $tenant->id, 'status' => 'approved', 'ai_status' => 'approved', 'ai_monthly_budget_cents' => 5000]);
    $uuid = (string) Str::uuid();
    $event = TenantAiUsageEvent::create(['tenant_id' => $tenant->id, 'user_id' => $actor->id, 'client_uuid' => $uuid, 'duration_seconds' => 0, 'feature' => 'marketing_owner', 'context' => 'marketing_owner', 'model' => 'test', 'status' => 'reserved', 'buyer_charge_micros' => 1000, 'provider_cost_micros' => 1000, 'occurred_at' => now()]);
    try {
        app(\App\Services\Billing\TenantAiUsageService::class)->reserveVoice($tenant, $actor, $uuid, 'job_note', 'voice-test', 90);
        $this->fail('A different AI action must not reuse a marketing reservation.');
    } catch (\Symfony\Component\HttpKernel\Exception\HttpException $exception) {
        expect($exception->getStatusCode())->toBe(409);
    }
    expect($event->fresh()->feature)->toBe('marketing_owner')->and($event->fresh()->buyer_charge_micros)->toBe(1000);
    Http::assertNothingSent();
});

test('all marketing desk destinations render from local data without provider requests', function (): void {
    [$tenant,$actor] = deskWorkspace();
    deskKnowledge($tenant->id, $actor->id);
    deskPost($tenant->id, $actor);
    foreach (['today', 'chat', 'calendar', 'insights', 'inbox', 'recipes', 'knowledge', 'settings'] as $view) {
        $this->actingAs($actor)->get(route('marketing.desk.index', ['tenant' => $tenant->slug, 'view' => $view]))->assertOk()->assertSee('Marketing desk');
    }
    Http::assertNothingSent();
});

test('a globally privileged user still needs an active role in this workspace', function (): void {
    [$tenant] = deskWorkspace();
    $outsider = User::factory()->create(['role' => 'admin', 'email_verified_at' => now()]);
    $this->actingAs($outsider)->getJson(route('marketing.desk.index', ['tenant' => $tenant->slug]))->assertRedirect();
});

test('knowledge records are encrypted and cannot be approved across workspaces', function (): void {
    [$tenant,$actor] = deskWorkspace();
    [$other] = deskWorkspace('other');
    $knowledge = deskKnowledge($other->id, $actor->id);
    expect(DB::table('marketing_brand_knowledge_versions')->where('id', $knowledge->id)->value('content'))->not->toContain('candles');
    $this->actingAs($actor)->postJson(route('marketing.desk.knowledge.approve', ['id' => $knowledge->id, 'tenant' => $tenant->slug]))->assertNotFound();
});

test('post approvals freeze an exact revision and reject foreign connections', function (): void {
    [$tenant,$actor] = deskWorkspace();
    [$other] = deskWorkspace('other');
    $post = deskPost($tenant->id, $actor);
    $foreign = deskConnection($other->id, 'facebook');
    $this->actingAs($actor)->postJson(route('marketing.desk.posts.approve', ['id' => $post->id, 'tenant' => $tenant->slug]), ['revision' => 1, 'connections' => ['facebook' => $foreign->id], 'scheduled_at' => now()->addHour()->toIso8601String(), 'confirmed' => true])->assertNotFound();
    $connection = deskConnection($tenant->id, 'facebook');
    app(SocialPostService::class)->approve($tenant->id, $post->id, $actor, 1, ['facebook' => $connection->id], now()->addHour()->toIso8601String());
    $edited = app(SocialPostService::class)->save($tenant->id, $actor, ['revision' => 1, 'caption' => 'An updated draft', 'media_type' => 'text', 'media_urls' => []], $post->id);
    expect($edited->approved_hash)->toBeNull()->and($edited->revision)->toBe(2)->and(MarketingSocialPublication::first()->status)->toBe('cancelled');
});

test('post media must use the approved host and proper format', function (): void {
    [$tenant,$actor] = deskWorkspace();
    $this->actingAs($actor)->postJson(route('marketing.desk.posts', ['tenant' => $tenant->slug]), ['caption' => 'Test', 'media_type' => 'image', 'media_urls' => ['https://unapproved.test/private']])->assertStatus(422);
});

test('publishing one platform does not replay another platform or an uncertain request', function (): void {
    [$tenant,$actor] = deskWorkspace();
    $post = deskPost($tenant->id, $actor);
    $connection = deskConnection($tenant->id, 'facebook');
    app(SocialPostService::class)->approve($tenant->id, $post->id, $actor, 1, ['facebook' => $connection->id], now()->subMinute()->toIso8601String());
    Http::fake(['graph.facebook.com/*' => Http::response(['id' => 'fb-post-1'])]);
    $publication = MarketingSocialPublication::first();
    app(SocialPublisher::class)->run($tenant->id, $publication->id);
    expect($publication->fresh()->status)->toBe('published');
    Http::assertSentCount(1);
});

test('uncertain publication is retained and never automatically resent', function (): void {
    [$tenant,$actor] = deskWorkspace();
    $post = deskPost($tenant->id, $actor);
    $connection = deskConnection($tenant->id, 'facebook');
    app(SocialPostService::class)->approve($tenant->id, $post->id, $actor, 1, ['facebook' => $connection->id], now()->subMinute()->toIso8601String());
    Http::fake(['graph.facebook.com/*' => Http::response([], 503)]);
    $publication = MarketingSocialPublication::first();
    try {
        app(SocialPublisher::class)->run($tenant->id, $publication->id);
    } catch (\Throwable) {
    }
    expect($publication->fresh()->status)->toBe('unknown');
    app(SocialPublisher::class)->run($tenant->id, $publication->id);
    Http::assertSentCount(1);
});

test('AI replies are structured, budgeted and idempotent', function (): void {
    [$tenant,$actor] = deskWorkspace();
    TenantBudSetting::create(['tenant_id' => $tenant->id, 'status' => 'approved', 'ai_status' => 'approved', 'ai_monthly_budget_cents' => 5000]);
    $knowledge = deskKnowledge($tenant->id, $actor->id);
    Http::fake(['api.openai.com/*' => Http::response(['id' => 'resp-test', 'status' => 'completed', 'usage' => ['input_tokens' => 200, 'output_tokens' => 50], 'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode(['answer' => 'A useful draft', 'handoff' => false, 'sources' => ['knowledge:'.$knowledge->id], 'suggested_caption' => 'A quiet moment', 'suggested_recipe' => ''])]]]]])]);
    $uuid = (string) Str::uuid();
    $thread = (string) Str::uuid();
    $evidence = ['question' => 'An idea?', 'knowledge' => [['source_id' => 'knowledge:'.$knowledge->id, ...$knowledge->content]]];
    $turn = app(MarketingAiService::class)->generate($tenant->id, $actor, $uuid, $thread, 'owner', $evidence);
    app(MarketingAiService::class)->generate($tenant->id, $actor, $uuid, $thread, 'owner', $evidence);
    expect($turn->status)->toBe('completed')->and(TenantAiUsageEvent::first()->status)->toBe('settled');
    Http::assertSentCount(1);
});

test('shared AI reservations enforce the workspace cap before calling OpenAI', function (): void {
    [$tenant,$actor] = deskWorkspace();
    TenantBudSetting::create(['tenant_id' => $tenant->id, 'status' => 'approved', 'ai_status' => 'approved', 'ai_monthly_budget_cents' => 5000]);
    TenantAiUsageEvent::create(['tenant_id' => $tenant->id, 'user_id' => $actor->id, 'client_uuid' => (string) Str::uuid(), 'duration_seconds' => 0, 'feature' => 'field_voice_transcription', 'context' => 'voice', 'model' => 'voice', 'status' => 'reserved', 'buyer_charge_micros' => 49999999, 'provider_cost_micros' => 49999999, 'occurred_at' => now()]);
    try {
        app(MarketingAiService::class)->generate($tenant->id, $actor, (string) Str::uuid(), (string) Str::uuid(), 'owner', ['question' => 'Hello']);
        $this->fail('Expected cap');
    } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
        expect($e->getStatusCode())->toBe(429);
    }
    Http::assertNothingSent();
});

test('account ownership is exclusive across workspaces', function (): void {
    [$tenant] = deskWorkspace();
    [$other] = deskWorkspace('other');
    $fingerprint = hash('sha256', 'page');
    app(SocialAccountOwnership::class)->claim($tenant->id, 'facebook', $fingerprint);
    expect(fn () => app(SocialAccountOwnership::class)->claim($other->id, 'facebook', $fingerprint))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
});

test('all starter recipes compile into inactive native workflow drafts', function (): void {
    [$tenant,$actor] = deskWorkspace();
    config(['automation_workflows.v2_enabled' => true, 'automation_workflows.v2_tenant_ids' => [$tenant->id]]);
    foreach (app(MarketingWorkflowCatalog::class)->recipes() as $key => $recipe) {
        $workflow = app(WorkflowDraftService::class)->createFromTemplate($tenant->id, $key, $actor);
        expect($workflow->status)->toBe('draft')->and($workflow->published_version_id)->toBeNull();
    }
});

function deskDeliveryContext(Tenant $tenant, User $actor): array
{
    app()->instance(\App\Services\Marketing\Desk\MarketingChannelReadiness::class, Mockery::mock(\App\Services\Marketing\Desk\MarketingChannelReadiness::class)->shouldReceive('ready')->andReturn(true)->getMock());
    $profile = MarketingProfile::create(['tenant_id' => $tenant->id, 'email' => 'customer@example.test', 'accepts_email_marketing' => true]);
    $workflow = \App\Models\AutomationWorkflow::create(['tenant_id' => $tenant->id, 'template_key' => 'marketing_retail_welcome', 'name' => 'Welcome', 'status' => 'active', 'draft_definition' => ['schema_version' => 2], 'definition_schema_version' => 2, 'draft_revision' => 1, 'created_by_user_id' => $actor->id, 'updated_by_user_id' => $actor->id]);
    $version = \App\Models\AutomationWorkflowVersion::create(['tenant_id' => $tenant->id, 'automation_workflow_id' => $workflow->id, 'version' => 1, 'definition_hash' => str_repeat('a', 64), 'definition' => ['schema_version' => 2], 'published_by_user_id' => $actor->id, 'published_at' => now()]);
    $workflow->update(['published_version_id' => $version->id]);
    $context = new \App\Services\Automation\V2\Data\ActionOperationContext(new \App\Services\Automation\V2\Data\WorkflowExecutionContext($tenant->id, $workflow->id, $version->id, 1, 1, ['store_key' => '', 'marketing_event_key' => str_repeat('b', 64)]), 'step', 'everbranch.marketing.email.send', null, ['marketing_profile_id' => $profile->id, 'audience' => 'retail', 'subject' => 'Welcome', 'body' => 'Thank you for joining us.'], [], 'first-message');

    return [$profile, $workflow, $version, $context];
}

test('marketing delivery cannot use operational email to bypass version approval', function (): void {
    [$tenant,$actor] = deskWorkspace();
    [,,$version,$context] = deskDeliveryContext($tenant, $actor);
    expect(fn () => app(\App\Services\Marketing\Desk\MarketingWorkflowDelivery::class)->send($context, false))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    expect(\App\Models\MarketingDeliveryAttempt::count())->toBe(0);
    Http::assertNothingSent();
});

test('approved workflow sends through existing campaign execution once and stops later steps', function (): void {
    [$tenant,$actor] = deskWorkspace();
    [$profile,$workflow,$version,$context] = deskDeliveryContext($tenant, $actor);
    $this->travelTo(now('UTC')->setTime(12, 0));
    \App\Models\MarketingDeliveryAuthorization::create(['tenant_id' => $tenant->id, 'workflow_id' => $workflow->id, 'definition_hash' => $version->definition_hash, 'audience' => 'retail', 'store_key' => '', 'approved_by' => $actor->id, 'approved_at' => now(), 'active' => true]);
    \App\Models\MarketingAudiencePermission::create(['tenant_id' => $tenant->id, 'marketing_profile_id' => $profile->id, 'audience' => 'retail', 'channel' => 'email', 'store_key' => '', 'proof_key' => 'verified-consent', 'granted_at' => now()]);
    $this->mock(\App\Services\Marketing\MarketingEmailExecutionService::class)->shouldReceive('sendRecipient')->once()->andReturn(['outcome' => 'sent']);
    $service = app(\App\Services\Marketing\Desk\MarketingWorkflowDelivery::class);
    expect($service->send($context, false)['status'])->toBe('sent');
    expect($service->send($context, false)['status'])->toBe('sent');
    $service->stop($tenant->id, $profile->id, 'customer_replied');
    $next = new \App\Services\Automation\V2\Data\ActionOperationContext($context->execution, 'step2', $context->componentKey, null, $context->config, [], 'second-message');
    expect($service->send($next, false))->toBe(['status' => 'suppressed', 'reason' => 'customer_replied']);
});

test('wholesale permission does not authorize a retail workflow', function (): void {
    [$tenant,$actor] = deskWorkspace();
    [$profile,$workflow,$version,$context] = deskDeliveryContext($tenant, $actor);
    $this->travelTo(now('UTC')->setTime(12, 0));
    \App\Models\MarketingDeliveryAuthorization::create(['tenant_id' => $tenant->id, 'workflow_id' => $workflow->id, 'definition_hash' => $version->definition_hash, 'audience' => 'retail', 'store_key' => '', 'approved_by' => $actor->id, 'approved_at' => now(), 'active' => true]);
    \App\Models\MarketingAudiencePermission::create(['tenant_id' => $tenant->id, 'marketing_profile_id' => $profile->id, 'audience' => 'wholesale', 'channel' => 'email', 'store_key' => '', 'proof_key' => 'verified-consent', 'granted_at' => now()]);
    expect(app(\App\Services\Marketing\Desk\MarketingWorkflowDelivery::class)->send($context, false)['reason'])->toBe('audience_consent_missing');
    Http::assertNothingSent();
});

test('a human takeover prevents customer AI from generating or sending', function (): void {
    [$tenant,$actor] = deskWorkspace();
    config(['marketing_desk.ai_replies_enabled' => true]);
    $knowledge = deskKnowledge($tenant->id, $actor->id);
    $policy = \App\Models\MarketingReplyPolicy::create(['tenant_id' => $tenant->id, 'channel' => 'email', 'audience' => 'general', 'store_key' => '', 'knowledge_version_id' => $knowledge->id, 'enabled' => true, 'approved_by' => $actor->id, 'approved_at' => now()]);
    $conversation = \App\Models\MessagingConversation::create(['tenant_id' => $tenant->id, 'channel' => 'email', 'email' => 'customer@example.test', 'status' => 'open', 'last_inbound_at' => now()]);
    $message = \App\Models\MessagingConversationMessage::create(['tenant_id' => $tenant->id, 'conversation_id' => $conversation->id, 'channel' => 'email', 'direction' => 'inbound', 'provider' => 'test', 'provider_message_id' => 'inbound-takeover', 'dedupe_hash' => hash('sha256', 'inbound-takeover'), 'body' => 'What do you make?', 'received_at' => now()]);
    app(\App\Services\Marketing\Desk\ReplyAutomationService::class)->takeover($tenant->id, $conversation->id);
    app(\App\Services\Marketing\Desk\ReplyAutomationService::class)->process($tenant->id, $message->id);
    expect(MarketingConversationControl::first()->mode)->toBe('human');
    Http::assertNothingSent();
});

test('customer questions without an approved source are handed to a person', function (): void {
    [$tenant,$actor] = deskWorkspace();
    config(['marketing_desk.ai_replies_enabled' => true]);
    TenantBudSetting::create(['tenant_id' => $tenant->id, 'status' => 'approved', 'ai_status' => 'approved', 'ai_monthly_budget_cents' => 5000]);
    $knowledge = deskKnowledge($tenant->id, $actor->id);
    \App\Models\MarketingReplyPolicy::create(['tenant_id' => $tenant->id, 'channel' => 'email', 'audience' => 'general', 'store_key' => '', 'knowledge_version_id' => $knowledge->id, 'enabled' => true, 'approved_by' => $actor->id, 'approved_at' => now()]);
    $conversation = \App\Models\MessagingConversation::create(['tenant_id' => $tenant->id, 'channel' => 'email', 'email' => 'customer@example.test', 'status' => 'open', 'last_inbound_at' => now()]);
    $message = \App\Models\MessagingConversationMessage::create(['tenant_id' => $tenant->id, 'conversation_id' => $conversation->id, 'channel' => 'email', 'direction' => 'inbound', 'provider' => 'test', 'provider_message_id' => 'inbound-unsupported', 'dedupe_hash' => hash('sha256', 'inbound-unsupported'), 'body' => 'Is this suitable for my situation?', 'received_at' => now()]);
    Http::fake(['api.openai.com/*' => Http::response(['id' => 'resp-test', 'status' => 'completed', 'usage' => ['input_tokens' => 200, 'output_tokens' => 50], 'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode(['answer' => 'An unsupported claim', 'handoff' => false, 'sources' => ['forged-source'], 'suggested_caption' => '', 'suggested_recipe' => ''])]]]]])]);
    $this->mock(\App\Services\Marketing\MessagingResponseInboxService::class)->shouldNotReceive('reply');
    app(\App\Services\Marketing\Desk\ReplyAutomationService::class)->process($tenant->id, $message->id);
    expect(MarketingConversationControl::first()->mode)->toBe('handoff');
});

test('facebook signed webhooks deduplicate and keep customer ownership within the page workspace', function (): void {
    [$tenant] = deskWorkspace();
    $connection = deskConnection($tenant->id, 'facebook');
    config(['marketing_desk.meta.client_secret' => 'meta-test']);
    $payload = ['object' => 'page', 'entry' => [['messaging' => [['sender' => ['id' => 'customer-42'], 'recipient' => ['id' => $connection->external_account_secret], 'timestamp' => now()->getTimestampMs(), 'message' => ['mid' => 'fb.mid.1', 'text' => 'Hello']]]]]];
    $body = json_encode($payload);
    $signature = 'sha256='.hash_hmac('sha256', $body, 'meta-test');
    for ($i = 0; $i < 2; $i++) {
        $this->call('POST', route('marketing.desk.facebook.webhook'), [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => $signature], $body)->assertOk();
    }
    expect(\App\Models\MessagingConversation::forTenantId($tenant->id)->where('channel', 'facebook')->count())->toBe(1)
        ->and(\App\Models\MessagingConversationMessage::where('provider_message_id', 'fb.mid.1')->count())->toBe(1);
    $this->call('POST', route('marketing.desk.facebook.webhook'), [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => 'invalid'], $body)->assertForbidden();
});

test('approved customer replies use only the originating conversation across every channel', function (string $channel): void {
    [$tenant,$actor] = deskWorkspace();
    config(['marketing_desk.ai_replies_enabled' => true]);
    TenantBudSetting::create(['tenant_id' => $tenant->id, 'status' => 'approved', 'ai_status' => 'approved', 'ai_monthly_budget_cents' => 5000]);
    $knowledge = deskKnowledge($tenant->id, $actor->id);
    \App\Models\MarketingReplyPolicy::create(['tenant_id' => $tenant->id, 'channel' => $channel, 'audience' => 'general', 'store_key' => '', 'knowledge_version_id' => $knowledge->id, 'enabled' => true, 'approved_by' => $actor->id, 'approved_at' => now()]);
    $conversation = \App\Models\MessagingConversation::create(['tenant_id' => $tenant->id, 'channel' => $channel, 'status' => 'open', 'last_inbound_at' => now()]);
    $message = \App\Models\MessagingConversationMessage::create(['tenant_id' => $tenant->id, 'conversation_id' => $conversation->id, 'channel' => $channel, 'direction' => 'inbound', 'provider' => 'test', 'provider_message_id' => 'supported-'.$channel, 'dedupe_hash' => hash('sha256', 'supported-'.$channel), 'body' => 'What do you make?', 'received_at' => now()]);
    Http::fake(['api.openai.com/*' => Http::response(['id' => 'resp-test', 'status' => 'completed', 'usage' => ['input_tokens' => 200, 'output_tokens' => 50], 'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode(['answer' => 'We make candles.', 'handoff' => false, 'sources' => ['knowledge:'.$knowledge->id], 'suggested_caption' => '', 'suggested_recipe' => ''])]]]]])]);
    $this->mock(\App\Services\Marketing\MessagingResponseInboxService::class)->shouldReceive('reply')->once()->with($tenant->id, null, $conversation->id, ['body' => 'We make candles.'], Mockery::type(User::class))->andReturn([]);
    app(\App\Services\Marketing\Desk\ReplyAutomationService::class)->process($tenant->id, $message->id);
    expect(MarketingConversationControl::first()->mode)->toBe('auto');
    Http::assertSentCount(1);
})->with(['email', 'sms', 'instagram', 'facebook']);

test('revoking operator access during AI generation prevents returning the answer', function (): void {
    [$tenant,$actor] = deskWorkspace();
    TenantBudSetting::create(['tenant_id' => $tenant->id, 'status' => 'approved', 'ai_status' => 'approved', 'ai_monthly_budget_cents' => 5000]);
    Http::fake(function () use ($tenant, $actor) {
        $actor->tenants()->updateExistingPivot($tenant->id, ['membership_active' => false]);

        return Http::response(['id' => 'resp-test', 'status' => 'completed', 'usage' => ['input_tokens' => 20, 'output_tokens' => 10], 'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode(['answer' => 'Private answer', 'handoff' => false, 'sources' => [], 'suggested_caption' => '', 'suggested_recipe' => ''])]]]]]);
    });
    expect(fn () => app(MarketingAiService::class)->generate($tenant->id, $actor, (string) Str::uuid(), (string) Str::uuid(), 'owner', ['question' => 'An idea']))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    expect(MarketingAssistantTurn::first()->status)->toBe('unknown')->and(MarketingAssistantTurn::first()->result)->toBeNull()->and(TenantAiUsageEvent::first()->status)->toBe('reserved');
});

test('scheduled audiences use workspace time, checkpoint once, and skip missed days', function (): void {
    [$tenant,$actor] = deskWorkspace();
    $settings = \App\Models\TenantAccessProfile::where('tenant_id', $tenant->id)->first();
    $metadata = $settings->metadata;
    data_set($metadata, 'marketing_desk.timezone', 'America/New_York');
    $settings->update(['metadata' => $metadata]);
    $profile = MarketingProfile::create(['tenant_id' => $tenant->id, 'email' => 'schedule@example.test', 'accepts_email_marketing' => true]);
    \App\Models\MarketingAudiencePermission::create(['tenant_id' => $tenant->id, 'marketing_profile_id' => $profile->id, 'audience' => 'retail', 'channel' => 'email', 'store_key' => '', 'proof_key' => 'verified-consent', 'granted_at' => now()]);
    $make = fn ($cursor) => new \App\Services\Automation\V2\Data\TriggerOperationContext($tenant->id, 1, 1, (string) Str::ulid(), 'everbranch.marketing.schedule', null, ['audience' => 'retail', 'channel' => 'email', 'time' => '09:00', 'days' => 'weekdays'], $cursor);
    $operation = app(\App\Services\Automation\V2\Operations\Native\MarketingScheduleTriggerOperation::class);
    $this->travelTo(\Carbon\CarbonImmutable::parse('2026-10-16T12:30:00Z'));
    expect($operation->poll($make(null))->events)->toBe([]);
    $this->travelTo(\Carbon\CarbonImmutable::parse('2026-10-16T13:30:00Z'));
    $first = $operation->poll($make(null));
    expect($first->events)->toHaveCount(1);
    expect($operation->poll($make($first->nextCursor))->events)->toBe([]);
    $this->travelTo(\Carbon\CarbonImmutable::parse('2026-10-19T13:30:00Z'));
    $next = $operation->poll($make($first->nextCursor));
    expect($next->events)->toHaveCount(1)->and($next->events[0]->eventKey)->not->toBe($first->events[0]->eventKey);
});

test('provider failure holds a workflow delivery for review and cannot be replayed', function (): void {
    [$tenant,$actor] = deskWorkspace();
    [$profile,$workflow,$version,$context] = deskDeliveryContext($tenant, $actor);
    $this->travelTo(now('UTC')->setTime(12, 0));
    \App\Models\MarketingDeliveryAuthorization::create(['tenant_id' => $tenant->id, 'workflow_id' => $workflow->id, 'definition_hash' => $version->definition_hash, 'audience' => 'retail', 'store_key' => '', 'approved_by' => $actor->id, 'approved_at' => now(), 'active' => true]);
    \App\Models\MarketingAudiencePermission::create(['tenant_id' => $tenant->id, 'marketing_profile_id' => $profile->id, 'audience' => 'retail', 'channel' => 'email', 'store_key' => '', 'proof_key' => 'verified-consent', 'granted_at' => now()]);
    $this->mock(\App\Services\Marketing\MarketingEmailExecutionService::class)->shouldReceive('sendRecipient')->once()->andReturn(['outcome' => 'failed', 'reason' => 'provider_failure']);
    $service = app(\App\Services\Marketing\Desk\MarketingWorkflowDelivery::class);
    for ($i = 0; $i < 2; $i++) {
        expect(fn () => $service->send($context, false))->toThrow(\App\Services\Automation\V2\WorkflowActionOutcomeUncertain::class);
    }
    expect(\App\Models\MarketingDeliveryAttempt::first()->status)->toBe('unknown');
});

test('marketing AI costs cannot enter the existing automatic voice invoicing path', function (): void {
    [$tenant,$actor] = deskWorkspace();
    TenantAiUsageEvent::create(['tenant_id' => $tenant->id, 'user_id' => $actor->id, 'client_uuid' => (string) Str::uuid(), 'feature' => 'marketing_owner', 'context' => 'marketing_owner', 'model' => 'test', 'status' => 'settled', 'duration_seconds' => 0, 'provider_cost_micros' => 1000, 'buyer_charge_micros' => 1000, 'occurred_at' => now()->subMonth()]);
    $result = app(\App\Services\Billing\TenantAiUsageInvoiceService::class)->invoiceClosedMonths($tenant->slug);
    expect($result['groups'])->toBe(0)->and($result['invoices_sent'])->toBe(0);
    Http::assertNothingSent();
});

test('batch approval rolls back every selected post when one account is foreign', function (): void {
    [$tenant,$actor] = deskWorkspace();
    [$other] = deskWorkspace('foreign');
    $a = deskPost($tenant->id, $actor);
    $b = deskPost($tenant->id, $actor);
    $own = deskConnection($tenant->id, 'facebook');
    $foreign = deskConnection($other->id, 'facebook');
    $payload = ['confirmed' => true, 'posts' => [
        ['id' => $a->id, 'revision' => 1, 'selected' => true, 'scheduled_at' => now()->addHour()->toIso8601String(), 'connections' => ['facebook' => $own->id]],
        ['id' => $b->id, 'revision' => 1, 'selected' => true, 'scheduled_at' => now()->addHours(2)->toIso8601String(), 'connections' => ['facebook' => $foreign->id]],
    ]];
    $this->actingAs($actor)->postJson(route('marketing.desk.posts.batch-approve', ['tenant' => $tenant->slug]), $payload)->assertNotFound();
    expect($a->fresh()->status)->toBe('draft')->and(MarketingSocialPublication::count())->toBe(0);
    $payload['posts'][1]['connections']['facebook'] = $own->id;
    $this->actingAs($actor)->postJson(route('marketing.desk.posts.batch-approve', ['tenant' => $tenant->slug]), $payload)->assertOk()->assertJsonPath('approved', 2);
    expect(MarketingSocialPublication::count())->toBe(2);
    Http::assertNothingSent();
});

test('owner suggestions receive measured post content with bounded metrics and no provider audit metadata', function (): void {
    [$tenant,$actor] = deskWorkspace();
    [$other,$otherActor] = deskWorkspace('other-brand');
    TenantBudSetting::create(['tenant_id' => $tenant->id, 'status' => 'approved', 'ai_status' => 'approved', 'ai_monthly_budget_cents' => 5000]);
    $knowledge = deskKnowledge($tenant->id, $actor->id);
    $post = deskPost($tenant->id, $actor);
    $foreign = deskPost($other->id, $otherActor);
    $foreign->update(['content' => ['caption' => 'FOREIGN-BRAND-PRIVATE-THEME', 'media_type' => 'text', 'media_urls' => []]]);
    foreach ([[$tenant, $post], [$other, $foreign]] as [$owner,$item]) {
        MarketingSocialPublication::create(['tenant_id' => $owner->id, 'post_id' => $item->id, 'revision' => 1, 'connection_id' => 999, 'platform' => 'facebook', 'status' => 'published', 'external_id' => 'fixture-'.$item->id, 'observed_at' => now(), 'insights' => ['post_impressions_unique' => 100, '_reconciliation' => ['verified_by' => 'PRIVATE-AUDIT-MARKER']]]);
    }
    Http::fake(['api.openai.com/*' => Http::response(['id' => 'resp-best-post', 'status' => 'completed', 'usage' => ['input_tokens' => 200, 'output_tokens' => 50], 'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode(['answer' => 'Build on your measured theme.', 'handoff' => false, 'sources' => ['post:'.$post->id], 'suggested_caption' => 'A thoughtful idea', 'suggested_recipe' => ''])]]]]])]);
    $this->actingAs($actor)->postJson(route('marketing.desk.chat', ['tenant' => $tenant->slug]), ['question' => 'What can we learn from our posts?', 'knowledge_id' => $knowledge->id, 'request_uuid' => (string) Str::uuid(), 'thread_uuid' => (string) Str::uuid()])->assertOk()->assertJsonPath('result.sources.0', 'post:'.$post->id);
    Http::assertSent(function ($request) {
        $body = json_encode($request->data());

        return str_contains($body, 'A thoughtful moment') && ! str_contains($body, 'PRIVATE-AUDIT-MARKER') && ! str_contains($body, 'FOREIGN-BRAND-PRIVATE-THEME');
    });
});

test('a Facebook disconnect clears only the selected workspace grant without contacting Meta', function (): void {
    [$tenant,$actor] = deskWorkspace();
    [$other] = deskWorkspace('another');
    $own = deskConnection($tenant->id, 'facebook');
    $foreign = deskConnection($other->id, 'facebook');
    $this->actingAs($actor)->post(route('marketing.desk.facebook.disconnect', ['id' => $own->id, 'tenant' => $tenant->slug]), ['confirmed' => true])->assertRedirect();
    expect($own->fresh()->status)->toBe('disconnected')->and($own->fresh()->access_token)->toBeNull()->and($foreign->fresh()->status)->toBe('connected');
    Http::assertNothingSent();
});

test('the scanner does not queue Meta delivery while review is disabled or the connection is disconnected', function (): void {
    [$tenant,$actor] = deskWorkspace();
    $post = deskPost($tenant->id, $actor);
    $connection = deskConnection($tenant->id, 'facebook');
    app(SocialPostService::class)->approve($tenant->id, $post->id, $actor, 1, ['facebook' => $connection->id], now()->subMinute()->toIso8601String());
    config(['marketing_desk.meta.execution_enabled' => false]);
    $this->artisan('marketing:dispatch-desk')->assertSuccessful();
    Queue::assertNotPushed(\App\Jobs\MarketingDesk\PublishSocialPost::class);
    config(['marketing_desk.meta.execution_enabled' => true]);
    $this->artisan('marketing:dispatch-desk')->assertSuccessful();
    Queue::assertPushed(\App\Jobs\MarketingDesk\PublishSocialPost::class, 1);
    Queue::fake();
    $connection->update(['status' => 'disconnected']);
    $this->artisan('marketing:dispatch-desk')->assertSuccessful();
    Queue::assertNotPushed(\App\Jobs\MarketingDesk\PublishSocialPost::class);
});

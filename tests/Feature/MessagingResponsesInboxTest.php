<?php

require_once __DIR__.'/ShopifyEmbeddedTestHelpers.php';

use App\Models\MarketingEmailDelivery;
use App\Models\MarketingMessageDelivery;
use App\Models\MarketingProfile;
use App\Models\MessagingContactChannelState;
use App\Models\MessagingConversation;
use App\Models\MessagingConversationMessage;
use App\Models\Tenant;
use App\Models\TenantModuleEntitlement;
use App\Services\Marketing\MessagingConversationService;
use App\Services\Marketing\MessagingEmailReplyAddressService;
use App\Services\Marketing\SendGridEmailService;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $this->withoutVite();
    config()->set('entitlements.default_plan', 'growth');
    config()->set('marketing.twilio.verify_signature', false);
});

function responsesApiHeaders(array $headers = []): array
{
    return array_merge([
        'Authorization' => 'Bearer '.retailShopifySessionToken(),
    ], $headers);
}

function grantResponsesMessagingEntitlement(Tenant $tenant): void
{
    TenantModuleEntitlement::query()->updateOrCreate(
        [
            'tenant_id' => $tenant->id,
            'module_key' => 'messaging',
        ],
        [
            'availability_status' => 'available',
            'enabled_status' => 'enabled',
            'billing_status' => 'add_on_comped',
            'price_override_cents' => 0,
            'currency' => 'USD',
            'entitlement_source' => 'test',
            'price_source' => 'test',
        ]
    );
}

/**
 * @param array<string,mixed> $overrides
 */
function responsesProfile(?int $tenantId, array $overrides = []): MarketingProfile
{
    $email = strtolower('responses-'.Str::random(8).'@example.com');

    return MarketingProfile::query()->create(array_merge([
        'tenant_id' => $tenantId,
        'first_name' => 'Inbox',
        'last_name' => 'Tester',
        'email' => $email,
        'normalized_email' => $email,
        'phone' => '5552223344',
        'normalized_phone' => '5552223344',
        'accepts_sms_marketing' => true,
        'accepts_email_marketing' => true,
    ], $overrides));
}

test('inbound sms reply creates conversation and message and appears in inbox', function () {
    $tenant = Tenant::query()->create([
        'name' => 'Responses SMS Tenant',
        'slug' => 'responses-sms-tenant',
    ]);
    grantResponsesMessagingEntitlement($tenant);
    configureEmbeddedRetailStore($tenant->id);

    $profile = responsesProfile($tenant->id, [
        'first_name' => 'Avery',
        'phone' => '5552223344',
        'normalized_phone' => '5552223344',
    ]);

    $delivery = MarketingMessageDelivery::query()->create([
        'marketing_profile_id' => $profile->id,
        'tenant_id' => $tenant->id,
        'store_key' => 'retail',
        'batch_id' => 'batch-sms',
        'source_label' => 'wishlist_offer',
        'channel' => 'sms',
        'provider' => 'twilio',
        'provider_message_id' => 'SM_OUTBOUND_001',
        'to_phone' => '+15552223344',
        'from_identifier' => '+18339625949',
        'attempt_number' => 1,
        'rendered_message' => 'Checking in about your wishlist.',
        'send_status' => 'delivered',
        'sent_at' => now()->subMinutes(10),
    ]);

    $this->post(route('marketing.webhooks.twilio-inbound'), [
        'From' => '+15552223344',
        'To' => '+18339625949',
        'Body' => 'Yes please, send details',
        'MessageSid' => 'SM_INBOUND_001',
    ])->assertOk();

    $conversation = MessagingConversation::query()->first();

    expect($conversation)->not->toBeNull()
        ->and((string) $conversation->channel)->toBe('sms')
        ->and((int) $conversation->tenant_id)->toBe((int) $tenant->id)
        ->and((int) $conversation->marketing_profile_id)->toBe((int) $profile->id)
        ->and((int) $conversation->source_id)->toBe((int) $delivery->id)
        ->and((int) $conversation->unread_count)->toBe(1);

    MessagingConversationMessage::query()
        ->where('conversation_id', $conversation->id)
        ->where('direction', 'inbound')
        ->where('provider_message_id', 'SM_INBOUND_001')
        ->exists();

    expect(MessagingConversationMessage::query()
        ->where('conversation_id', $conversation->id)
        ->where('direction', 'inbound')
        ->where('provider_message_id', 'SM_INBOUND_001')
        ->exists())->toBeTrue();

    $this->withHeaders(responsesApiHeaders())
        ->getJson(route('shopify.app.api.messaging.responses.index', ['channel' => 'sms']))
        ->assertOk()
        ->assertJsonPath('data.summary.sms_unread', 1)
        ->assertJsonPath('data.conversations.0.id', (int) $conversation->id)
        ->assertJsonPath('data.conversations.0.source_context.delivery_id', (int) $delivery->id);
});

test('stop reply marks contact unsubscribed, conversation opted out, and blocks outbound sms reply', function () {
    $tenant = Tenant::query()->create([
        'name' => 'Responses STOP Tenant',
        'slug' => 'responses-stop-tenant',
    ]);
    grantResponsesMessagingEntitlement($tenant);
    configureEmbeddedRetailStore($tenant->id);

    $profile = responsesProfile($tenant->id, [
        'phone' => '5553334444',
        'normalized_phone' => '5553334444',
    ]);

    MarketingMessageDelivery::query()->create([
        'marketing_profile_id' => $profile->id,
        'tenant_id' => $tenant->id,
        'store_key' => 'retail',
        'batch_id' => 'batch-stop',
        'source_label' => 'wishlist_offer',
        'channel' => 'sms',
        'provider' => 'twilio',
        'provider_message_id' => 'SM_OUTBOUND_STOP',
        'to_phone' => '+15553334444',
        'from_identifier' => '+18339625949',
        'attempt_number' => 1,
        'rendered_message' => 'We still saved your wishlist for later.',
        'send_status' => 'delivered',
        'sent_at' => now()->subMinutes(5),
    ]);

    $this->post(route('marketing.webhooks.twilio-inbound'), [
        'From' => '+15553334444',
        'To' => '+18339625949',
        'Body' => 'STOP',
        'MessageSid' => 'SM_STOP_001',
    ])->assertOk();

    $conversation = MessagingConversation::query()->firstOrFail();
    $profile->refresh();

    expect((string) $conversation->status)->toBe('opted_out')
        ->and((bool) $profile->accepts_sms_marketing)->toBeFalse();

    $channelState = MessagingContactChannelState::query()
        ->where('tenant_id', $tenant->id)
        ->where('phone', '+15553334444')
        ->first();

    expect($channelState)->not->toBeNull()
        ->and((string) $channelState?->sms_status)->toBe('unsubscribed');

    $this->withHeaders(responsesApiHeaders())
        ->postJson(route('shopify.app.api.messaging.responses.reply', ['conversation' => $conversation->id]), [
            'body' => 'Following up from Backstage',
        ])
        ->assertStatus(422)
        ->assertJsonPath('ok', false);
});

test('help reply is stored correctly and visible in conversation detail', function () {
    $tenant = Tenant::query()->create([
        'name' => 'Responses HELP Tenant',
        'slug' => 'responses-help-tenant',
    ]);
    grantResponsesMessagingEntitlement($tenant);
    configureEmbeddedRetailStore($tenant->id);

    $profile = responsesProfile($tenant->id, [
        'phone' => '5554445555',
        'normalized_phone' => '5554445555',
    ]);

    MarketingMessageDelivery::query()->create([
        'marketing_profile_id' => $profile->id,
        'tenant_id' => $tenant->id,
        'store_key' => 'retail',
        'batch_id' => 'batch-help',
        'source_label' => 'wishlist_offer',
        'channel' => 'sms',
        'provider' => 'twilio',
        'provider_message_id' => 'SM_OUTBOUND_HELP',
        'to_phone' => '+15554445555',
        'from_identifier' => '+18339625949',
        'attempt_number' => 1,
        'rendered_message' => 'Reply for more help.',
        'send_status' => 'delivered',
        'sent_at' => now()->subMinutes(5),
    ]);

    $this->post(route('marketing.webhooks.twilio-inbound'), [
        'From' => '+15554445555',
        'To' => '+18339625949',
        'Body' => 'HELP',
        'MessageSid' => 'SM_HELP_001',
    ])->assertOk();

    $conversation = MessagingConversation::query()->firstOrFail();

    $this->withHeaders(responsesApiHeaders())
        ->getJson(route('shopify.app.api.messaging.responses.show', ['conversation' => $conversation->id]))
        ->assertOk()
        ->assertJsonPath('data.conversation.id', (int) $conversation->id)
        ->assertJsonPath('data.messages.1.message_type', 'help');
});

test('duplicate sms webhook payload does not create duplicate messages', function () {
    $tenant = Tenant::query()->create([
        'name' => 'Responses Duplicate SMS Tenant',
        'slug' => 'responses-duplicate-sms-tenant',
    ]);
    grantResponsesMessagingEntitlement($tenant);
    configureEmbeddedRetailStore($tenant->id);

    $profile = responsesProfile($tenant->id, [
        'phone' => '5557778888',
        'normalized_phone' => '5557778888',
    ]);

    MarketingMessageDelivery::query()->create([
        'marketing_profile_id' => $profile->id,
        'tenant_id' => $tenant->id,
        'store_key' => 'retail',
        'batch_id' => 'batch-dedupe',
        'source_label' => 'wishlist_offer',
        'channel' => 'sms',
        'provider' => 'twilio',
        'provider_message_id' => 'SM_OUTBOUND_DEDUPE',
        'to_phone' => '+15557778888',
        'from_identifier' => '+18339625949',
        'attempt_number' => 1,
        'rendered_message' => 'Reply anytime.',
        'send_status' => 'delivered',
        'sent_at' => now()->subMinutes(2),
    ]);

    $payload = [
        'From' => '+15557778888',
        'To' => '+18339625949',
        'Body' => 'Need another link',
        'MessageSid' => 'SM_INBOUND_DEDUPE_001',
    ];

    $this->post(route('marketing.webhooks.twilio-inbound'), $payload)->assertOk();
    $this->post(route('marketing.webhooks.twilio-inbound'), $payload)->assertOk();

    expect(MessagingConversationMessage::query()->where('provider_message_id', 'SM_INBOUND_DEDUPE_001')->count())->toBe(1);
});

test('inbound email reply creates and threads email conversation and outbound reply appends to thread', function () {
    $tenant = Tenant::query()->create([
        'name' => 'Responses Email Tenant',
        'slug' => 'responses-email-tenant',
    ]);
    grantResponsesMessagingEntitlement($tenant);
    configureEmbeddedRetailStore($tenant->id);
    config()->set('marketing.messaging.responses.sendgrid_inbound_token', 'sg-inbound-token');
    config()->set('marketing.messaging.responses.email_inbound_domain', 'reply.example.test');
    config()->set('marketing.email.enabled', true);

    $profile = responsesProfile($tenant->id, [
        'email' => 'casey@example.com',
        'normalized_email' => 'casey@example.com',
    ]);

    $delivery = MarketingEmailDelivery::query()->create([
        'marketing_profile_id' => $profile->id,
        'tenant_id' => $tenant->id,
        'store_key' => 'retail',
        'batch_id' => 'email-batch',
        'source_label' => 'wishlist_offer',
        'message_subject' => 'Your wishlist update',
        'provider' => 'sendgrid',
        'provider_message_id' => '<outbound-1@example.test>',
        'sendgrid_message_id' => '<outbound-1@example.test>',
        'campaign_type' => 'direct_message',
        'template_key' => 'direct_message',
        'email' => 'casey@example.com',
        'status' => 'sent',
        'sent_at' => now()->subMinutes(20),
        'raw_payload' => ['body_text' => 'We saved your wishlist offer for later.'],
    ]);

    /** @var MessagingEmailReplyAddressService $replyAddressService */
    $replyAddressService = app(MessagingEmailReplyAddressService::class);
    $replyAddress = $replyAddressService->replyAddressForDelivery($tenant->id, $delivery->id);

    $this->post(route('marketing.webhooks.sendgrid-inbound', ['token' => 'sg-inbound-token']), [
        'from' => 'Casey Customer <casey@example.com>',
        'to' => $replyAddress,
        'subject' => 'Re: Your wishlist update',
        'text' => 'Can you send me the details again?',
        'headers' => "Message-ID: <inbound-1@example.test>\nIn-Reply-To: <outbound-1@example.test>\nReferences: <outbound-1@example.test>",
    ])->assertOk();

    $conversation = MessagingConversation::query()->where('channel', 'email')->firstOrFail();

    $this->withHeaders(responsesApiHeaders())
        ->getJson(route('shopify.app.api.messaging.responses.show', ['conversation' => $conversation->id]))
        ->assertOk()
        ->assertJsonPath('data.conversation.channel', 'email')
        ->assertJsonPath('data.messages.1.provider_message_id', '<inbound-1@example.test>');

    $sendGridMock = \Mockery::mock(SendGridEmailService::class);
    $sendGridMock->shouldReceive('sendEmail')
        ->once()
        ->andReturn([
            'success' => true,
            'provider' => 'sendgrid',
            'message_id' => '<reply-outbound@example.test>',
            'status' => 'sent',
            'error_code' => null,
            'error_message' => null,
            'payload' => [],
            'dry_run' => false,
            'retryable' => false,
            'tenant_id' => $tenant->id,
        ]);
    app()->instance(SendGridEmailService::class, $sendGridMock);

    $this->withHeaders(responsesApiHeaders())
        ->postJson(route('shopify.app.api.messaging.responses.reply', ['conversation' => $conversation->id]), [
            'subject' => 'Re: Your wishlist update',
            'body' => 'Absolutely. Here is the latest offer.',
        ])
        ->assertOk()
        ->assertJsonPath('data.messages.2.direction', 'outbound');

    expect(MarketingEmailDelivery::query()->where('source_label', 'responses_inbox_reply')->count())->toBe(1);
});

test('responses conversation list sorts by latest activity and unread counts update correctly', function () {
    $tenant = Tenant::query()->create([
        'name' => 'Responses Sort Tenant',
        'slug' => 'responses-sort-tenant',
    ]);
    grantResponsesMessagingEntitlement($tenant);
    configureEmbeddedRetailStore($tenant->id);

    /** @var MessagingConversationService $conversationService */
    $conversationService = app(MessagingConversationService::class);
    $profileA = responsesProfile($tenant->id, ['phone' => '5551010101', 'normalized_phone' => '5551010101']);
    $profileB = responsesProfile($tenant->id, ['phone' => '5552020202', 'normalized_phone' => '5552020202']);

    $older = $conversationService->findOrCreateSmsConversation($tenant->id, 'retail', $profileA, '+15551010101');
    $newer = $conversationService->findOrCreateSmsConversation($tenant->id, 'retail', $profileB, '+15552020202');

    $conversationService->appendMessage($older, [
        'channel' => 'sms',
        'direction' => 'inbound',
        'provider' => 'twilio',
        'provider_message_id' => 'older-inbound',
        'body' => 'Older inbound',
        'received_at' => now()->subHour(),
        'message_type' => 'normal',
    ]);

    $conversationService->appendMessage($newer, [
        'channel' => 'sms',
        'direction' => 'inbound',
        'provider' => 'twilio',
        'provider_message_id' => 'newer-inbound',
        'body' => 'Newest inbound',
        'received_at' => now()->subMinutes(5),
        'message_type' => 'normal',
    ]);

    $this->withHeaders(responsesApiHeaders())
        ->getJson(route('shopify.app.api.messaging.responses.index', ['channel' => 'sms']))
        ->assertOk()
        ->assertJsonPath('data.summary.sms_unread', 2)
        ->assertJsonPath('data.conversations.0.id', (int) $newer->id)
        ->assertJsonPath('data.conversations.1.id', (int) $older->id);

    $this->withHeaders(responsesApiHeaders())
        ->postJson(route('shopify.app.api.messaging.responses.update', ['conversation' => $newer->id]), [
            'action' => 'mark_read',
        ])
        ->assertOk()
        ->assertJsonPath('data.conversation.unread_count', 0);

    $this->withHeaders(responsesApiHeaders())
        ->getJson(route('shopify.app.api.messaging.responses.index', ['channel' => 'sms']))
        ->assertOk()
        ->assertJsonPath('data.summary.sms_unread', 1);
});

test('responses inbox stays tenant scoped for api results', function () {
    $tenantA = Tenant::query()->create([
        'name' => 'Responses Tenant A',
        'slug' => 'responses-tenant-a',
    ]);
    $tenantB = Tenant::query()->create([
        'name' => 'Responses Tenant B',
        'slug' => 'responses-tenant-b',
    ]);
    grantResponsesMessagingEntitlement($tenantA);
    grantResponsesMessagingEntitlement($tenantB);
    configureEmbeddedRetailStore($tenantA->id);

    /** @var MessagingConversationService $conversationService */
    $conversationService = app(MessagingConversationService::class);

    $conversationA = $conversationService->findOrCreateSmsConversation(
        $tenantA->id,
        'retail',
        responsesProfile($tenantA->id, ['phone' => '5559090901', 'normalized_phone' => '5559090901']),
        '+15559090901'
    );
    $conversationService->appendMessage($conversationA, [
        'channel' => 'sms',
        'direction' => 'inbound',
        'provider' => 'twilio',
        'provider_message_id' => 'tenant-a-msg',
        'body' => 'Tenant A',
        'received_at' => now()->subMinutes(4),
        'message_type' => 'normal',
    ]);

    $conversationB = $conversationService->findOrCreateSmsConversation(
        $tenantB->id,
        'retail',
        responsesProfile($tenantB->id, ['phone' => '5559090902', 'normalized_phone' => '5559090902']),
        '+15559090902'
    );
    $conversationService->appendMessage($conversationB, [
        'channel' => 'sms',
        'direction' => 'inbound',
        'provider' => 'twilio',
        'provider_message_id' => 'tenant-b-msg',
        'body' => 'Tenant B',
        'received_at' => now()->subMinutes(3),
        'message_type' => 'normal',
    ]);

    $this->withHeaders(responsesApiHeaders())
        ->getJson(route('shopify.app.api.messaging.responses.index', ['channel' => 'sms']))
        ->assertOk()
        ->assertJsonCount(1, 'data.conversations')
        ->assertJsonPath('data.conversations.0.id', (int) $conversationA->id);
});

test('responses tab renders next to analytics with text and email toggle labels', function () {
    $tenant = Tenant::query()->create([
        'name' => 'Responses Page Tenant',
        'slug' => 'responses-page-tenant',
    ]);
    grantResponsesMessagingEntitlement($tenant);
    configureEmbeddedRetailStore($tenant->id);

    $this->get(route('shopify.app.messaging.responses', retailEmbeddedSignedQuery()))
        ->assertOk()
        ->assertSeeText('Responses')
        ->assertSeeText('Text')
        ->assertSeeText('Email')
        ->assertViewHas('pageSubnav', function (array $subnav): bool {
            $keys = array_map(static fn (array $item): string => (string) ($item['key'] ?? ''), $subnav);

            return $keys === ['setup', 'workspace', 'analytics', 'responses'];
        });
});

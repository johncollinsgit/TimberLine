<?php

use App\Models\IntegrationConnection;
use App\Models\MessagingConversation;
use App\Models\Tenant;
use App\Models\TenantModuleEntitlement;
use App\Models\User;
use App\Services\Marketing\InstagramMessagingService;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

beforeEach(function (): void {
    $this->withoutVite();
    config()->set('services.instagram.enabled', true);
    config()->set('services.instagram.client_id', 'instagram-app-id');
    config()->set('services.instagram.client_secret', 'instagram-app-secret');
    config()->set('services.instagram.redirect_uri', 'https://app.test/integrations/instagram/callback');
    config()->set('services.instagram.webhook_verify_token', 'verify-token');
    config()->set('services.instagram.api_base', 'https://graph.instagram.com');
    config()->set('services.instagram.api_version', 'v24.0');
});

function enableInstagramIntegrations(Tenant $tenant): void
{
    TenantModuleEntitlement::query()->create([
        'tenant_id' => $tenant->id,
        'module_key' => 'integrations',
        'availability_status' => 'available',
        'enabled_status' => 'enabled',
        'billing_status' => 'included_in_plan',
        'entitlement_source' => 'test',
        'price_source' => 'catalog',
    ]);
}

test('instagram oauth connects a professional account to only the selected workspace', function (): void {
    $tenant = Tenant::query()->create(['name' => 'Modern Forestry', 'slug' => 'modern-forestry']);
    enableInstagramIntegrations($tenant);
    $user = User::factory()->create(['role' => 'admin', 'email_verified_at' => now()]);
    $user->tenants()->attach($tenant->id, ['role' => 'admin']);

    $redirect = $this->actingAs($user)
        ->get(route('integrations.instagram.connect', ['tenant' => $tenant->slug]))
        ->assertRedirect()
        ->headers->get('Location');
    parse_str((string) parse_url((string) $redirect, PHP_URL_QUERY), $query);

    expect($redirect)->toStartWith('https://www.instagram.com/oauth/authorize?')
        ->and($redirect)->toContain('instagram_business_manage_messages');

    Http::fake([
        'https://api.instagram.com/oauth/access_token' => Http::response([
            'access_token' => 'instagram-access-token',
            'expires_in' => 5184000,
        ]),
        'https://graph.instagram.com/v24.0/me*' => Http::response([
            'user_id' => 'ig-account-42',
            'username' => 'modernforestry',
        ]),
    ]);

    $this->actingAs($user)
        ->get(route('integrations.instagram.callback', ['state' => $query['state'], 'code' => 'oauth-code']))
        ->assertRedirect(route('integrations.instagram.index'));

    $connection = IntegrationConnection::query()->forTenantId($tenant->id)->where('provider', 'instagram')->sole();
    expect($connection->external_account_id)->toBe(hash_hmac('sha256', 'ig-account-42', (string) config('app.key')))
        ->and($connection->external_account_secret)->toBe('ig-account-42')
        ->and($connection->access_token)->toBe('instagram-access-token')
        ->and(data_get($connection->metadata, 'username'))->toBe('modernforestry');
});

test('instagram verifies signed inbound messages against the owning tenant connection', function (): void {
    $tenant = Tenant::query()->create(['name' => 'Evegrove', 'slug' => 'evegrove']);
    IntegrationConnection::query()->create([
        'tenant_id' => $tenant->id,
        'provider' => 'instagram',
        'external_account_id' => hash_hmac('sha256', 'ig-account-42', (string) config('app.key')),
        'external_account_secret' => 'ig-account-42',
        'external_account_label' => '@evergrove',
        'status' => IntegrationConnection::STATUS_CONNECTED,
        'access_token' => 'access-token',
        'metadata' => ['username' => 'evergrove'],
    ]);
    $payload = [
        'object' => 'instagram',
        'entry' => [[
            'id' => 'ig-account-42',
            'messaging' => [[
                'sender' => ['id' => 'instagram-customer-1'],
                'recipient' => ['id' => 'ig-account-42'],
                'timestamp' => now()->getTimestampMs(),
                'message' => ['mid' => 'mid.123', 'text' => 'Can you help me?'],
            ]],
        ]],
    ];
    $content = json_encode($payload, JSON_THROW_ON_ERROR);
    $signature = 'sha256='.hash_hmac('sha256', $content, 'instagram-app-secret');

    $this->call('POST', route('instagram.webhooks.handle'), [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_HUB_SIGNATURE_256' => $signature,
    ], $content)->assertOk();

    $conversation = MessagingConversation::query()->forTenantId($tenant->id)->where('channel', 'instagram')->sole();
    expect($conversation->source_type)->toBe('instagram')
        ->and(data_get($conversation->source_context, 'instagram_sender_id'))->toBe('instagram-customer-1')
        ->and($conversation->messages()->where('provider_message_id', 'mid.123')->count())->toBe(1);
});

test('instagram webhook challenge accepts only the configured verification token', function (): void {
    $this->get(route('instagram.webhooks.verify', [
        'hub.mode' => 'subscribe',
        'hub.verify_token' => 'verify-token',
        'hub.challenge' => 'challenge-response',
    ]))->assertOk()->assertSeeText('challenge-response');

    $this->get(route('instagram.webhooks.verify', [
        'hub.mode' => 'subscribe',
        'hub.verify_token' => 'wrong-token',
        'hub.challenge' => 'challenge-response',
    ]))->assertForbidden();
});

test('instagram replies are inbox-only and stay inside the recent inbound reply window', function (): void {
    $tenant = Tenant::query()->create(['name' => 'Modern Forestry', 'slug' => 'modern-forestry-replies']);
    $connection = IntegrationConnection::query()->create([
        'tenant_id' => $tenant->id,
        'provider' => 'instagram',
        'external_account_id' => hash_hmac('sha256', 'ig-account-42', (string) config('app.key')),
        'external_account_secret' => 'ig-account-42',
        'external_account_label' => '@modernforestry',
        'status' => IntegrationConnection::STATUS_CONNECTED,
        'access_token' => 'access-token',
        'metadata' => ['username' => 'modernforestry'],
    ]);
    $conversation = MessagingConversation::query()->create([
        'tenant_id' => $tenant->id,
        'channel' => 'instagram',
        'status' => 'open',
        'source_type' => 'instagram',
        'source_id' => 'conversation-fingerprint',
        'source_context' => [
            'instagram_connection_id' => $connection->id,
            'instagram_sender_id' => 'instagram-customer-1',
        ],
        'last_inbound_at' => now()->subHour(),
    ]);
    Http::fake([
        'https://graph.instagram.com/v24.0/ig-account-42/messages' => Http::response(['message_id' => 'mid.reply.1']),
    ]);

    app(InstagramMessagingService::class)->sendReply($conversation, 'Thanks for reaching out.');
    expect($conversation->fresh()->messages()->where('provider_message_id', 'mid.reply.1')->count())->toBe(1);

    $conversation->forceFill(['last_inbound_at' => now()->subHours(25)])->save();
    expect(fn () => app(InstagramMessagingService::class)->sendReply($conversation->fresh(), 'Late reply.'))
        ->toThrow(ValidationException::class);
});

<?php

use App\Models\MarketingEmailDelivery;
use App\Models\Tenant;
use App\Services\Marketing\Email\Providers\SesTenantEmailProvider;
use App\Services\Marketing\Email\SesDeliveryEvents;
use App\Services\Marketing\Email\SesSendSafety;
use App\Services\Marketing\Email\SesSnsVerifier;
use Aws\Result;
use Aws\SesV2\SesV2Client;
use Aws\Sns\Message;
use Aws\Sns\MessageValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SesMigrationSafetyTest extends TestCase
{
    use RefreshDatabase;

    // Production sends cannot be wrapped in a caller transaction. Isolate each
    // test with migrate:fresh instead of testing through a rollback transaction.
    protected function connectionsToTransact(): array
    {
        return [];
    }

    protected function setUp(): void
    {
        \Illuminate\Foundation\Testing\RefreshDatabaseState::$migrated = false;
        parent::setUp();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        \Illuminate\Foundation\Testing\RefreshDatabaseState::$migrated = false;
        \Illuminate\Foundation\Testing\RefreshDatabaseState::$inMemoryConnections = [];
    }

    private function fixture(): array
    {
        $tenant = Tenant::create(['name' => 'SES Fixture', 'slug' => 'ses-fixture']);
        $delivery = MarketingEmailDelivery::create(['tenant_id' => $tenant->id, 'provider' => 'ses_tenant', 'email' => 'fixture@example.test', 'status' => 'sending']);
        config(['ses_migration.tenant_enabled' => true, 'ses_migration.tenant_ids' => [$tenant->id], 'ses_migration.approved_attempt_cost_micros' => 200]);
        $config = ['region' => 'us-east-1', 'access_key' => 'fixture', 'secret_key' => 'fixture', 'tenant_name' => 'everbranch-tenant-'.$tenant->id, 'configuration_set' => 'fixture-marketing', 'from_email' => 'sender@example.test'];
        $message = ['to_email' => $delivery->email, 'subject' => 'Non-customer fixture', 'text' => 'Fixture only.', 'metadata' => ['tenant_id' => $tenant->id], 'custom_args' => ['marketing_email_delivery_id' => $delivery->id]];

        return [$delivery, $message, $config];
    }

    private function provider(callable $handler): SesTenantEmailProvider
    {
        $client = new SesV2Client(['region' => 'us-east-1', 'version' => 'latest', 'credentials' => false, 'retries' => 0, 'handler' => $handler]);

        return new class($client) extends SesTenantEmailProvider
        {
            public function __construct(private SesV2Client $fixtureClient) {}

            protected function client(array $config): SesV2Client
            {
                return $this->fixtureClient;
            }
        };
    }

    public function test_gate_blocks_before_provider_and_dry_runs_do_not_claim(): void
    {
        [$delivery, $message, $config] = $this->fixture();
        $provider = $this->provider(fn () => throw new RuntimeException('Must not send'));
        config(['ses_migration.tenant_enabled' => false]);
        $this->assertSame('ses_rollout_disabled', $provider->sendEmail($message, $config)['error_code']);
        $this->assertTrue($provider->sendEmail([...$message, 'dry_run' => true], $config)['success']);
        $this->assertNull(data_get($delivery->fresh()->metadata, 'ses_attempt'));
    }

    public function test_receipt_is_persisted_and_replays_do_not_send_again(): void
    {
        [$delivery, $message, $config] = $this->fixture();
        $calls = 0;
        $provider = $this->provider(function ($command) use (&$calls, $delivery) {
            $calls++;
            $tags = array_column($command['EmailTags'], 'Value', 'Name');
            $this->assertSame((string) $delivery->id, $tags['marketing_email_delivery_id']);
            $this->assertSame('fixture-marketing', $command['ConfigurationSetName']);

            return \GuzzleHttp\Promise\Create::promiseFor(new Result(['MessageId' => 'ses.opaque-id']));
        });
        $first = $provider->sendEmail($message, $config);
        $second = $provider->sendEmail($message, $config);
        $this->assertTrue($first['success']);
        $this->assertSame($first, $second);
        $this->assertSame(1, $calls);
        $this->assertSame('ses.opaque-id', $delivery->fresh()->provider_message_id);
    }

    public function test_ambiguous_response_is_held_without_retry_or_second_call(): void
    {
        [$delivery, $message, $config] = $this->fixture();
        $calls = 0;
        $provider = $this->provider(function () use (&$calls) {
            $calls++;
            throw new RuntimeException('timeout secret must not leak');
        });
        $first = $provider->sendEmail($message, $config);
        $this->assertSame('unknown', $first['status']);
        $this->assertFalse($first['retryable']);
        $this->assertStringNotContainsString('secret', $first['error_message']);
        $this->assertSame($first, $provider->sendEmail($message, $config));
        $this->assertSame(1, $calls);
    }

    public function test_cap_blocks_and_caller_transactions_cannot_wrap_provider_http(): void
    {
        [$delivery, $message, $config] = $this->fixture();
        $provider = $this->provider(fn () => throw new RuntimeException('Must not send'));
        config(['ses_migration.account_daily_attempts' => 0]);
        $this->assertSame('ses_rate_limited', $provider->sendEmail($message, $config)['error_code']);
        $this->assertNull(data_get($delivery->fresh()->metadata, 'ses_attempt'));
        config(['ses_migration.account_daily_attempts' => 100]);
        DB::beginTransaction();
        try {
            $this->assertSame('ses_delivery_not_ready', $provider->sendEmail($message, $config)['error_code']);
        } finally {
            DB::rollBack();
        }
    }

    public function test_native_tenant_binding_and_durable_delivery_are_required(): void
    {
        [$delivery, $message, $config] = $this->fixture();
        $provider = $this->provider(fn () => throw new RuntimeException('Must not send'));
        $this->assertSame('ses_delivery_context_required', $provider->sendEmail($message, [...$config, 'tenant_name' => 'foreign'])['error_code']);
        $this->assertSame('ses_delivery_not_ready', $provider->sendEmail([...$message, 'to_email' => 'other@example.test'], $config)['error_code']);
        $this->assertNull(data_get($delivery->fresh()->metadata, 'ses_attempt'));
    }

    public function test_stale_delivery_saves_cannot_erase_claim_receipt_or_callback(): void
    {
        [$delivery, $message, $config] = $this->fixture();
        $stale = MarketingEmailDelivery::findOrFail($delivery->id);
        $provider = $this->provider(fn () => \GuzzleHttp\Promise\Create::promiseFor(new Result(['MessageId' => 'ses.opaque-id'])));
        $this->assertTrue($provider->sendEmail($message, $config)['success']);
        $event = $this->event($delivery, 'Delivery');
        $event['delivery'] = ['timestamp' => now()->toIso8601String(), 'recipients' => [$delivery->email]];
        app(SesDeliveryEvents::class)->handle($event, 'delivery-1');
        $stale->forceFill(['status' => 'sent', 'provider_message_id' => null, 'metadata' => ['legacy' => true], 'raw_payload' => []])->save();
        $saved = $delivery->fresh();
        $this->assertSame('delivered', $saved->status);
        $this->assertSame('ses.opaque-id', $saved->provider_message_id);
        $this->assertTrue(data_get($saved->metadata, 'ses_attempt.result.success'));
        $this->assertCount(1, $saved->raw_payload['received_event_hashes']);
    }

    public function test_verified_event_resolves_uncertainty_and_blocks_replacement_before_resolution(): void
    {
        [$delivery, $message, $config] = $this->fixture();
        $provider = $this->provider(fn () => throw new RuntimeException('ambiguous'));
        $this->assertSame('unknown', $provider->sendEmail($message, $config)['status']);
        $replacement = MarketingEmailDelivery::create(['tenant_id' => $delivery->tenant_id, 'provider' => 'ses_tenant', 'email' => $delivery->email, 'status' => 'sending']);
        $copy = [...$message, 'custom_args' => ['marketing_email_delivery_id' => $replacement->id]];
        $this->assertSame('ses_delivery_not_ready', $provider->sendEmail($copy, $config)['error_code']);
        $event = $this->event($delivery, 'Send');
        $this->assertSame(1, app(SesDeliveryEvents::class)->handle($event, 'send-1')['matched']);
        $this->assertTrue($provider->sendEmail($message, $config)['success']);
        app(SesSendSafety::class)->complete($delivery->id, $delivery->tenant_id, ['success' => false, 'status' => 'unknown']);
        $this->assertTrue(data_get($delivery->fresh()->metadata, 'ses_attempt.result.success'));
    }

    public function test_ses_email_gate_does_not_enable_the_sms_platform(): void
    {
        [$delivery, $message, $config] = $this->fixture();
        config(['features.tenant_messaging_platform' => false]);
        \App\Models\TenantMessagingAccount::query()->forAllTenants()->create([
            'tenant_id' => $delivery->tenant_id, 'channel' => 'email', 'provider' => 'ses_tenant', 'status' => 'ready',
            'provider_account_id' => $config['tenant_name'], 'provider_config' => $config,
        ]);
        $resolver = app(\App\Services\Marketing\Messaging\TenantMessagingAccountResolver::class);
        $this->assertSame('ses_tenant', $resolver->resolve($delivery->tenant_id, 'email')['provider']);
        $this->assertSame('legacy', $resolver->resolve($delivery->tenant_id, 'sms')['mode']);
        $this->assertFalse(config('features.tenant_messaging_platform'));
    }

    public function test_gateway_and_resolver_use_ses_with_the_email_only_gate_and_hold_unknown_usage(): void
    {
        [$delivery, $message, $config] = $this->fixture();
        config(['features.tenant_messaging_platform' => false]);
        \App\Models\TenantAccessAddon::create(['tenant_id' => $delivery->tenant_id, 'addon_key' => 'messaging', 'enabled' => true]);
        $account = \App\Models\TenantMessagingAccount::query()->forAllTenants()->create([
            'tenant_id' => $delivery->tenant_id, 'channel' => 'email', 'provider' => 'ses_tenant', 'status' => 'ready',
            'provider_account_id' => $config['tenant_name'], 'authenticated_domain' => 'example.test', 'provider_config' => $config,
        ]);
        \App\Models\TenantMessagingSenderProfile::query()->forAllTenants()->create([
            'tenant_id' => $delivery->tenant_id, 'tenant_messaging_account_id' => $account->id, 'channel' => 'email',
            'label' => 'Fixture', 'display_name' => 'Fixture', 'from_email' => $config['from_email'],
            'reply_to_email' => 'replies@example.test', 'authenticated_domain' => 'example.test',
            'reply_mode' => 'direct_inbox', 'verification_status' => 'verified', 'is_default' => true,
        ]);
        $calls = 0;
        $this->app->instance(SesTenantEmailProvider::class, $this->provider(function () use (&$calls) {
            $calls++;
            throw new RuntimeException('ambiguous');
        }));
        $resolution = app(\App\Services\Marketing\Email\TenantEmailProviderResolver::class)->getEmailProviderForTenant($delivery->tenant_id);
        $this->assertSame('ses_tenant', $resolution['provider']->key());
        $this->assertTrue($resolution['settings']['email_enabled']);
        $options = ['tenant_id' => $delivery->tenant_id, 'delivery_id' => $delivery->id, 'idempotency_key' => 'fixture-delivery:'.$delivery->id];
        $sender = app(\App\Services\Marketing\SendGridEmailService::class);
        $result = $sender->sendEmail($delivery->email, 'Fixture', 'Fixture only', $options);
        $this->assertSame('unknown', $result['status']);
        $this->assertSame('unknown', $sender->sendEmail($delivery->email, 'Fixture', 'Fixture only', $options)['status']);
        $this->assertSame(1, $calls);
        $this->assertDatabaseCount('tenant_messaging_ledger_entries', 1);
        $this->assertDatabaseHas('tenant_messaging_ledger_entries', ['tenant_id' => $delivery->tenant_id, 'entry_type' => 'usage_reservation']);
        $this->assertFalse(config('features.tenant_messaging_platform'));
    }

    public function test_rollback_to_sendgrid_cannot_replay_an_unresolved_ses_attempt(): void
    {
        [$delivery, $message, $config] = $this->fixture();
        $provider = $this->provider(fn () => throw new RuntimeException('ambiguous'));
        $this->assertSame('unknown', $provider->sendEmail($message, $config)['status']);
        config(['ses_migration.tenant_enabled' => false, 'features.tenant_messaging_platform' => false]);
        Http::preventStrayRequests();
        $result = app(\App\Services\Marketing\SendGridEmailService::class)->sendEmail($delivery->email, 'Fixture', 'Fixture only', ['tenant_id' => $delivery->tenant_id, 'delivery_id' => $delivery->id]);
        $this->assertSame('unknown', $result['status']);
        $this->assertSame('ses_tenant', $result['provider']);
        $replacement = MarketingEmailDelivery::create(['tenant_id' => $delivery->tenant_id, 'provider' => 'sendgrid', 'email' => $delivery->email, 'status' => 'sending']);
        $result = app(\App\Services\Marketing\Email\TenantEmailDispatchService::class)->sendEmail($delivery->email, 'Fixture', 'Fixture only', ['tenant_id' => $delivery->tenant_id, 'delivery_id' => $replacement->id]);
        $this->assertSame('unknown', $result['status']);
        Http::assertNothingSent();
    }

    public function test_readiness_command_never_provisions_without_credentials(): void
    {
        config(['services.ses.key' => null, 'services.ses.secret' => null]);
        Http::preventStrayRequests();
        $this->artisan('email:ses-readiness', ['--identity' => ['theeverbranch.com']])->assertExitCode(1);
        Http::assertNothingSent();
    }

    private function event(MarketingEmailDelivery $delivery, string $type): array
    {
        return ['eventType' => $type, 'mail' => ['source' => 'sender@example.test', 'messageId' => 'ses.opaque-id', 'timestamp' => now()->toIso8601String(), 'destination' => [$delivery->email], 'tags' => [
            'tenant_id' => [(string) $delivery->tenant_id], 'marketing_email_delivery_id' => [(string) $delivery->id],
            'ses:tenant-name' => ['everbranch-tenant-'.$delivery->tenant_id], 'ses:configuration-set' => ['fixture-marketing'],
        ]], 'complaint' => ['timestamp' => now()->toIso8601String(), 'complainedRecipients' => [['emailAddress' => $delivery->email]]],
            'bounce' => ['timestamp' => now()->toIso8601String(), 'bounceType' => 'Permanent', 'bouncedRecipients' => [['emailAddress' => $delivery->email, 'status' => '5.1.1', 'diagnosticCode' => 'Unknown user']]]];
    }

    public function test_complaints_and_permanent_bounces_suppress_only_the_bound_tenant_and_deduplicate(): void
    {
        [$delivery, $message, $config] = $this->fixture();
        app(SesSendSafety::class)->claim($delivery->id, $delivery->tenant_id, $delivery->email, ['TenantName' => $config['tenant_name'], 'ConfigurationSetName' => $config['configuration_set'], 'FromEmailAddress' => $config['from_email']]);
        $service = app(SesDeliveryEvents::class);
        $event = $this->event($delivery, 'Complaint');
        $this->assertSame(1, $service->handle($event, 'complaint-1')['updated']);
        $this->assertSame(1, $service->handle($event, 'complaint-1')['duplicates']);
        $this->assertDatabaseHas('messaging_contact_channel_states', ['tenant_id' => $delivery->tenant_id, 'email' => $delivery->email, 'email_status' => 'suppressed']);
        $this->assertSame('ses.opaque-id', $delivery->fresh()->provider_message_id);
        $event['mail']['tags']['tenant_id'] = ['999'];
        $this->assertSame(0, $service->handle($event, 'foreign')['matched']);
        $event = $this->event($delivery, 'Bounce');
        $service->handle($event, 'bounce-1');
        $this->assertDatabaseHas('messaging_contact_channel_states', ['tenant_id' => $delivery->tenant_id, 'email_status' => 'suppressed']);
    }

    public function test_sns_rejects_unsigned_unknown_topics_and_signed_payload_tampering(): void
    {
        $topic = 'arn:aws:sns:us-east-1:123456789012:ses-fixture';
        config(['services.ses.account_id' => '123456789012', 'services.ses.region' => 'us-east-1', 'ses_migration.sns_topic_arns' => [$topic]]);
        $key = openssl_pkey_new(['private_key_bits' => 2048]);
        $cert = openssl_csr_sign(openssl_csr_new(['commonName' => 'sns.us-east-1.amazonaws.com'], $key), null, $key, 1);
        openssl_x509_export($cert, $pem);
        Http::fake(['https://sns.us-east-1.amazonaws.com/SimpleNotificationService-fixture.pem' => Http::response($pem)]);
        $data = ['Signature' => 'pending', 'Type' => 'Notification', 'MessageId' => 'fixture', 'TopicArn' => $topic, 'Message' => '{}', 'Timestamp' => now()->toIso8601String(), 'SignatureVersion' => '2', 'SigningCertURL' => 'https://sns.us-east-1.amazonaws.com/SimpleNotificationService-fixture.pem'];
        openssl_sign((new MessageValidator)->getStringToSign(new Message($data)), $signature, $key, OPENSSL_ALGO_SHA256);
        $data['Signature'] = base64_encode($signature);
        $this->assertTrue(app(SesSnsVerifier::class)->valid(new Message($data)));
        $this->assertFalse(app(SesSnsVerifier::class)->valid(new Message([...$data, 'Message' => '{"forged":true}'])));
        $this->assertFalse(app(SesSnsVerifier::class)->valid(new Message([...$data, 'TopicArn' => $topic.'-other'])));
        $this->postJson('/webhooks/ses/events', $data + ['Signature' => 'bad'])->assertOk();
        $this->postJson('/webhooks/ses/events', [...$data, 'Signature' => 'bad'])->assertForbidden();
    }
}

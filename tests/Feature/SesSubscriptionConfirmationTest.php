<?php

use App\Services\Marketing\Email\SesSnsVerifier;
use App\Services\Marketing\Email\SesSubscriptionConfirmation;
use Aws\Result;
use Aws\Sns\Message;
use Aws\Sns\SnsClient;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SesSubscriptionConfirmationTest extends TestCase
{
    use \Illuminate\Foundation\Testing\RefreshDatabase;

    private string $topic = 'arn:aws:sns:us-east-1:123456789012:approved-fixture';

    private function message(array $changes = []): Message
    {
        return new Message([...['Type' => 'SubscriptionConfirmation', 'TopicArn' => $this->topic,
            'MessageId' => 'fixture-confirmation', 'Message' => 'Fixture', 'Timestamp' => now()->toIso8601String(),
            'Signature' => 'fixture-signature', 'SignatureVersion' => '2', 'SigningCertURL' => 'https://sns.us-east-1.amazonaws.com/SimpleNotificationService-fixture.pem',
            'Token' => 'fixture-token-never-print', 'SubscribeURL' => 'https://untrusted.example/never-follow'], ...$changes]);
    }

    public function test_webhook_keeps_verified_token_encrypted_and_never_follows_subscribe_url(): void
    {
        Http::preventStrayRequests();
        $this->mock(SesSnsVerifier::class)->shouldReceive('valid')->andReturn(true);
        $this->postJson('/webhooks/ses/events', $this->message()->toArray())->assertStatus(202);
        $encrypted = Cache::get('ses:subscription-confirmation:'.hash('sha256', $this->topic));
        $this->assertIsString($encrypted);
        $this->assertStringNotContainsString('fixture-token', $encrypted);
        $this->assertNotNull(app(SesSubscriptionConfirmation::class)->pending($this->topic));
        $this->assertNull(app(SesSubscriptionConfirmation::class)->pending($this->topic.'-other'));
        Artisan::call('email:ses-confirm-subscription', ['topic' => $this->topic]);
        $this->assertStringContainsString('Preview only', Artisan::output());
        $this->assertStringNotContainsString('fixture-token', Artisan::output());
        Http::assertNothingSent();
    }

    public function test_confirmation_requires_current_authorization_and_fresh_confirmation_type(): void
    {
        $verifier = $this->mock(SesSnsVerifier::class);
        $verifier->shouldReceive('valid')->andReturn(true, false);
        $service = app(SesSubscriptionConfirmation::class);
        $service->remember($this->message());
        $this->assertNull($service->pending($this->topic));
        Cache::flush();
        $service->remember($this->message(['Timestamp' => now()->subHours(2)->toIso8601String()]));
        $service->remember($this->message(['Type' => 'UnsubscribeConfirmation']));
        $this->assertNull($service->pending($this->topic));
    }

    public function test_explicit_confirmation_uses_fixed_aws_api_and_records_no_token_in_output(): void
    {
        $this->mock(SesSnsVerifier::class)->shouldReceive('valid')->andReturn(true);
        config(['services.ses.region' => 'us-east-1']);
        $calls = 0;
        $topic = $this->topic;
        $client = new SnsClient(['region' => 'us-east-1', 'version' => 'latest', 'credentials' => false, 'retries' => 0,
            'handler' => function ($command, $request) use (&$calls, $topic) {
                $calls++;
                $this->assertSame('ConfirmSubscription', $command->getName());
                $this->assertSame($topic, $command['TopicArn']);
                $this->assertSame('fixture-token-never-print', $command['Token']);
                $this->assertSame('sns.us-east-1.amazonaws.com', $request->getUri()->getHost());

                return \GuzzleHttp\Promise\Create::promiseFor(new Result(['SubscriptionArn' => $topic.':11111111-2222-3333-4444-555555555555']));
            }]);
        $service = new class($client) extends SesSubscriptionConfirmation
        {
            public function __construct(private SnsClient $fixtureClient) {}

            protected function client(): SnsClient
            {
                return $this->fixtureClient;
            }
        };
        $this->app->instance(SesSubscriptionConfirmation::class, $service);
        $service->remember($this->message());
        $this->assertSame(0, Artisan::call('email:ses-confirm-subscription', ['topic' => $topic, '--confirm' => true]));
        $this->assertStringContainsString('Confirmed subscription', Artisan::output());
        $this->assertStringNotContainsString('fixture-token', Artisan::output());
        $this->assertSame(1, $calls);
        $this->assertNull($service->pending($topic));
    }
}

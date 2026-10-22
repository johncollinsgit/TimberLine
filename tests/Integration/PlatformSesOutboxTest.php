<?php

use App\Jobs\SendPlatformEmail;
use App\Models\PlatformEmailDelivery;
use App\Models\PlatformEmailMessage;
use App\Services\Mail\PlatformMailQueueContext;
use App\Services\Mail\PlatformSesDeliveryEvents;
use App\Services\Mail\PlatformSesOutbox;
use Aws\Exception\AwsException;
use Aws\Result;
use Aws\SesV2\SesV2Client;
use GuzzleHttp\Promise\FulfilledPromise;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mime\Email;

uses(Tests\TestCase::class);

beforeEach(function (): void {
    // No outer transaction: provider claims must commit before HTTP.
    $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
    config(['ses_migration.platform_enabled' => true, 'ses_migration.platform_key' => 'fixture-key',
        'ses_migration.platform_secret' => 'fixture-secret', 'ses_migration.platform_from' => 'no-reply@theeverbranch.com',
        'ses_migration.platform_configuration_set' => 'everbranch-platform-fixture',
        'ses_migration.platform_recipients' => ['fixture@example.test', 'cc@example.test', 'blind@example.test'],
        'ses_migration.approved_attempt_cost_micros' => 1000, 'ses_migration.max_per_second' => 100,
        'ses_migration.platform_daily_attempts' => 100, 'ses_migration.account_daily_attempts' => 100,
        'mail.from.address' => 'no-reply@theeverbranch.com', 'cache.default' => 'array']);
    Cache::flush();
});

function platformFixtureEmail(string $identity = 'platform-fixture-00000001'): Email
{
    $email = (new Email)->from('no-reply@theeverbranch.com')->to('fixture@example.test')->subject('Fixture only')->text('private-reset-token-fixture');
    $email->getHeaders()->addTextHeader('X-Everbranch-Logical-Message-ID', $identity);

    return $email;
}

function platformFixtureOutbox(callable $response): PlatformSesOutbox
{
    $client = new SesV2Client(['version' => 'latest', 'region' => 'us-east-1', 'retries' => 0,
        'credentials' => ['key' => 'fixture-key', 'secret' => 'fixture-secret'],
        'handler' => function ($command) use ($response) {
            return new FulfilledPromise($response($command));
        }]);
    $outbox = new class($client) extends PlatformSesOutbox
    {
        public function __construct(private SesV2Client $fixtureClient) {}

        protected function client(): SesV2Client
        {
            return $this->fixtureClient;
        }
    };
    app()->instance(PlatformSesOutbox::class, $outbox);

    return $outbox;
}

function platformFixtureEvent(PlatformEmailDelivery $delivery, string $type = 'Delivery'): array
{
    return ['eventType' => $type, 'mail' => ['messageId' => 'opaque-receipt.fixture', 'source' => 'no-reply@theeverbranch.com',
        'destination' => [$delivery->recipient], 'tags' => ['everbranch_lane' => ['platform'],
            'platform_delivery_id' => [(string) $delivery->id], 'ses:configuration-set' => ['everbranch-platform-fixture']]],
        'bounce' => ['bounceType' => 'Permanent', 'bouncedRecipients' => [['emailAddress' => $delivery->recipient]]],
        'complaint' => ['complainedRecipients' => [['emailAddress' => $delivery->recipient]]]];
}

it('freezes encrypted MIME once with one recipient per delivery and no tenant billing entry', function (): void {
    Bus::fake([SendPlatformEmail::class]);
    $email = platformFixtureEmail()->cc('cc@example.test')->bcc('blind@example.test');
    $email->getHeaders()->addTextHeader('X-SES-CONFIGURATION-SET', 'untrusted-override');
    $outbox = app(PlatformSesOutbox::class);
    $ids = $outbox->enqueue($email, Envelope::create($email));
    expect($outbox->enqueue($email, Envelope::create($email)))->toBe($ids);
    $message = PlatformEmailMessage::sole();
    expect(count($ids))->toBe(3)->and(PlatformEmailDelivery::count())->toBe(3)
        ->and($message->mime)->toContain('private-reset-token-fixture')
        ->and($message->mime)->not->toContain('Bcc:', 'blind@example.test', 'X-SES-', 'X-Everbranch-Logical')
        ->and(DB::table('platform_email_messages')->value('mime'))->not->toContain('private-reset-token-fixture')
        ->and(DB::table('platform_email_deliveries')->value('recipient'))->not->toContain('@example.test')
        ->and(DB::table('tenant_messaging_ledger_entries')->count())->toBe(0);
    Bus::assertDispatchedTimes(SendPlatformEmail::class, 3);
});

it('commits only scalar queued jobs after the caller commits and rolls back intents cleanly', function (): void {
    $email = platformFixtureEmail();
    DB::beginTransaction();
    $id = app(PlatformSesOutbox::class)->enqueue($email, Envelope::create($email))[0];
    expect(DB::table('jobs')->count())->toBe(0);
    DB::commit();
    $payload = DB::table('jobs')->sole()->payload;
    expect($payload)->toContain('SendPlatformEmail', 'deliveryId')->not->toContain('private-reset-token-fixture', 'fixture@example.test', 'fixture-secret');
    expect(PlatformEmailDelivery::findOrFail($id)->state)->toBe('queued');
    DB::beginTransaction();
    $other = platformFixtureEmail('platform-fixture-rollback');
    app(PlatformSesOutbox::class)->enqueue($other, Envelope::create($other));
    DB::rollBack();
    expect(PlatformEmailMessage::count())->toBe(1)->and(DB::table('jobs')->count())->toBe(1);
});

it('claims before SDK HTTP and never resends an accepted or ambiguous attempt', function (string $result): void {
    Bus::fake([SendPlatformEmail::class]);
    $calls = 0;
    $outbox = platformFixtureOutbox(function ($command) use (&$calls, $result) {
        $calls++;
        expect(PlatformEmailDelivery::sole()->state)->toBe('submitting')->and(DB::transactionLevel())->toBe(0)
            ->and($command['Destination']['ToAddresses'])->toBe(['fixture@example.test'])
            ->and($command['ConfigurationSetName'])->toBe('everbranch-platform-fixture')
            ->and($command['EmailTags'][0]['Value'])->toBe('platform')
            ->and($command->hasParam('TenantName'))->toBeFalse();
        if ($result === 'network') {
            throw new RuntimeException('ambiguous socket close');
        }
        if (in_array($result, ['400', '503'], true)) {
            throw new AwsException('fixture', $command, ['code' => 'FixtureError', 'response' => new \GuzzleHttp\Psr7\Response((int) $result)]);
        }

        return new Result(['MessageId' => $result === 'empty' ? '' : 'opaque-receipt.fixture']);
    });
    $email = platformFixtureEmail();
    $id = $outbox->enqueue($email, Envelope::create($email))[0];
    $expected = match ($result) {
        'accepted' => 'accepted', '400' => 'rejected', default => 'unknown'
    };
    expect($outbox->submit($id))->toBe($expected)->and($outbox->submit($id))->toBe($expected)->and($calls)->toBe(1);
    if ($expected === 'unknown') {
        config()->set('ses_migration.platform_enabled', false);
        expect(fn () => Mail::mailer('array')->raw('replacement', fn ($mail) => $mail->to('fixture@example.test')))->toThrow(RuntimeException::class, 'reconciliation');
    }
})->with(['accepted', 'network', '503', '400', 'empty']);

it('retains bounded AWS failure evidence without raw provider data or another send', function (int $status, string $code, string $expectedCode): void {
    Bus::fake([SendPlatformEmail::class]);
    $calls = 0;
    $outbox = platformFixtureOutbox(function ($command) use (&$calls, $status, $code) {
        $calls++;
        throw new AwsException('private-provider-body-and-credential-fixture', $command, [
            'code' => $code, 'response' => new \GuzzleHttp\Psr7\Response($status, [], 'private-provider-response-fixture'),
        ]);
    });
    $email = platformFixtureEmail();
    $id = $outbox->enqueue($email, Envelope::create($email))[0];
    $expectedState = $status < 500 ? 'rejected' : 'unknown';
    expect($outbox->submit($id))->toBe($expectedState)
        ->and(PlatformEmailDelivery::findOrFail($id)->hold_reason)->toBe('aws_http_'.$status.':'.$expectedCode)
        ->and(PlatformEmailDelivery::findOrFail($id)->provider_message_id)->toBeNull()
        ->and($outbox->submit($id))->toBe($expectedState)->and($calls)->toBe(1)
        ->and(json_encode(DB::table('platform_email_deliveries')->find($id)))->not->toContain('private-provider', 'fixture-secret')
        ->and(DB::table('tenant_messaging_ledger_entries')->count())->toBe(0);
})->with([
    'access denied' => [403, 'AccessDeniedException', 'AccessDeniedException'],
    'rate rejected' => [429, 'TooManyRequestsException', 'TooManyRequestsException'],
    'ambiguous service failure' => [503, 'ServiceUnavailableException', 'ServiceUnavailableException'],
    'untrusted code' => [400, 'private@example.test\nraw-provider-data', 'UnclassifiedAwsError'],
    'oversized code' => [400, str_repeat('X', 49), 'UnclassifiedAwsError'],
]);

it('rechecks pilot recipients and rollout before HTTP and shares the account cap', function (): void {
    Bus::fake([SendPlatformEmail::class]);
    $calls = 0;
    $outbox = platformFixtureOutbox(function () use (&$calls) {
        $calls++;

        return new Result(['MessageId' => 'receipt']);
    });
    $email = platformFixtureEmail();
    $id = $outbox->enqueue($email, Envelope::create($email))[0];
    config()->set('ses_migration.platform_enabled', false);
    expect($outbox->submit($id))->toBe('paused');
    config(['ses_migration.platform_enabled' => true, 'ses_migration.platform_recipients' => []]);
    expect($outbox->submit($id))->toBe('held');
    expect(PlatformEmailDelivery::find($id)->hold_reason)->toBe('recipient_not_approved');
    config(['ses_migration.platform_recipients' => ['fixture@example.test'], 'ses_migration.account_daily_attempts' => 1]);
    $other = platformFixtureEmail('platform-fixture-after-revoked');
    $id = $outbox->enqueue($other, Envelope::create($other))[0];
    expect(app(App\Services\Marketing\Email\SesSendSafety::class)->admit(123))->toBeTrue()
        ->and($outbox->submit($id))->toBe('rate_limited')->and($calls)->toBe(0)
        ->and(PlatformEmailDelivery::find($id)->state)->toBe('queued');
});

it('requires reviewed sender queue cost and configuration before enqueue', function (string $key, mixed $value): void {
    config()->set($key, $value);
    $email = platformFixtureEmail();
    expect(fn () => app(PlatformSesOutbox::class)->enqueue($email, Envelope::create($email)))->toThrow(RuntimeException::class);
    expect(PlatformEmailMessage::count())->toBe(0);
})->with([
    ['ses_migration.platform_enabled', false], ['ses_migration.platform_secret', null],
    ['ses_migration.platform_configuration_set', 'everbranch-marketing'], ['ses_migration.platform_queue_connection', 'sync'],
    ['ses_migration.approved_attempt_cost_micros', 175], ['ses_migration.platform_recipients', []],
    ['ses_migration.platform_from', 'other@example.test'], ['ses_migration.platform_max_bytes', 10],
    ['mail.default', 'ses_platform'],
]);

it('rejects a changed payload or recipient set under the original message identity', function (): void {
    Bus::fake([SendPlatformEmail::class]);
    $email = platformFixtureEmail();
    $outbox = app(PlatformSesOutbox::class);
    $outbox->enqueue($email, Envelope::create($email));
    $changed = clone $email;
    $changed->text('replacement-token');
    expect(fn () => $outbox->enqueue($changed, Envelope::create($changed)))->toThrow(RuntimeException::class, 'changed content');
    $changed = clone $email;
    $changed->to('cc@example.test');
    expect(fn () => $outbox->enqueue($changed, Envelope::create($changed)))->toThrow(RuntimeException::class, 'changed content');
    expect(PlatformEmailMessage::count())->toBe(1)->and(PlatformEmailDelivery::count())->toBe(1);
});

it('skips original queued mail before it can regenerate tokens and requires explicit business-job identities', function (): void {
    Bus::fake([SendPlatformEmail::class]);
    $context = app(PlatformMailQueueContext::class);
    $job = Mockery::mock(Illuminate\Contracts\Queue\Job::class);
    $job->shouldReceive('payload')->andReturn(['data' => ['commandName' => Illuminate\Mail\SendQueuedMailable::class]]);
    $job->shouldReceive('uuid')->andReturn('stable-mail-job-uuid');
    $context->start($job);
    $email = platformFixtureEmail();
    app(PlatformSesOutbox::class)->enqueue($email, Envelope::create($email));
    $job->shouldReceive('delete')->once();
    $context->start($job);
    $other = Mockery::mock(Illuminate\Contracts\Queue\Job::class);
    $other->shouldReceive('payload')->andReturn(['data' => ['commandName' => 'BusinessJob']]);
    $context->start($other);
    $email->getHeaders()->remove('X-Everbranch-Logical-Message-ID');
    expect(fn () => $context->group($email))->toThrow(RuntimeException::class, 'explicit stable');
    $context->reset();
});

it('binds signed event evidence to original recipient sender config and opaque receipt', function (): void {
    Bus::fake([SendPlatformEmail::class]);
    $outbox = platformFixtureOutbox(fn () => throw new RuntimeException('unknown result'));
    $email = platformFixtureEmail();
    $id = $outbox->enqueue($email, Envelope::create($email))[0];
    expect($outbox->submit($id))->toBe('unknown');
    $row = PlatformEmailDelivery::findOrFail($id);
    $events = app(PlatformSesDeliveryEvents::class);
    $event = platformFixtureEvent($row);
    $event['mail']['source'] = '"Everbranch, Inc." <NO-REPLY@theeverbranch.com>';
    foreach (['mail.destination.0' => 'other@example.test', 'mail.source' => 'other@example.test', 'mail.tags.ses:configuration-set.0' => 'wrong-config'] as $path => $value) {
        $invalid = $event;
        data_set($invalid, $path, $value);
        expect($events->handle($invalid, 'bad-event')['matched'])->toBe(0);
    }
    expect($events->handle($event, 'delivery-event')['updated'])->toBe(1)
        ->and($events->handle($event, 'delivery-event')['updated'])->toBe(0)
        ->and($row->fresh()->state)->toBe('accepted')
        ->and($row->fresh()->event_state)->toBe('delivered');
    $wrong = $event;
    $wrong['mail']['messageId'] = 'another-receipt';
    expect($events->handle($wrong, 'wrong-receipt')['matched'])->toBe(0);
    $events->handle(platformFixtureEvent($row, 'Complaint'), 'complaint-event');
    $events->handle($event, 'late-delivery');
    expect($row->fresh()->event_state)->toBe('complaint')->and($outbox->heldAddress($row->recipient))->toBeTrue()
        ->and($outbox->heldAddress('cc@example.test'))->toBeFalse()
        ->and(DB::table('tenant_messaging_ledger_entries')->count())->toBe(0);
});

it('retains unknown and suppression holds across APP_KEY rotation', function (): void {
    Bus::fake([SendPlatformEmail::class]);
    $outbox = platformFixtureOutbox(fn () => throw new RuntimeException('unknown result'));
    $email = platformFixtureEmail();
    $id = $outbox->enqueue($email, Envelope::create($email))[0];
    $outbox->submit($id);
    $oldKey = config('app.key');
    config(['app.key' => 'new-hash-key-fixture', 'app.previous_keys' => [$oldKey]]);
    expect($outbox->heldAddress('fixture@example.test'))->toBeTrue();
    $row = PlatformEmailDelivery::findOrFail($id);
    app(PlatformSesDeliveryEvents::class)->handle(platformFixtureEvent($row, 'Bounce'), 'rotated-key-bounce');
    expect($row->fresh()->event_state)->toBe('hard_bounce')->and($outbox->heldAddress($row->recipient))->toBeTrue();
});

it('rejects tenant-tagged evidence from the platform lane without releasing its hold', function (): void {
    Bus::fake([SendPlatformEmail::class]);
    $outbox = platformFixtureOutbox(fn () => throw new RuntimeException('unknown result'));
    $email = platformFixtureEmail();
    $id = $outbox->enqueue($email, Envelope::create($email))[0];
    expect($outbox->submit($id))->toBe('unknown');
    $row = PlatformEmailDelivery::findOrFail($id);
    $event = platformFixtureEvent($row);
    foreach (['ses:tenant', 'tenant_id', 'marketing_email_delivery_id'] as $tag) {
        $invalid = $event;
        $invalid['mail']['tags'][$tag] = ['foreign-tenant'];
        expect(app(PlatformSesDeliveryEvents::class)->handle($invalid, 'foreign-'.$tag)['matched'])->toBe(0);
    }
    expect($row->fresh()->state)->toBe('unknown')->and($row->fresh()->provider_message_id)->toBeNull()
        ->and($row->fresh()->event_hashes)->toBeEmpty();
    expect(app(PlatformSesDeliveryEvents::class)->handle($event, 'valid-platform')['updated'])->toBe(1);
});

it('never regresses durable claims through stale or edited model saves', function (): void {
    Bus::fake([SendPlatformEmail::class]);
    $outbox = platformFixtureOutbox(fn () => new Result(['MessageId' => 'receipt']));
    $email = platformFixtureEmail();
    $id = $outbox->enqueue($email, Envelope::create($email))[0];
    $stale = PlatformEmailDelivery::findOrFail($id);
    $outbox->submit($id);
    $stale->state = 'submitting';
    expect(fn () => $stale->save())->toThrow(LogicException::class, 'Stale');
    $fresh = $stale->fresh();
    $fresh->state = 'queued';
    expect(fn () => $fresh->save())->toThrow(LogicException::class, 'cannot regress');
    expect(fn () => $stale->delete())->toThrow(LogicException::class);
});

it('rejects nested Laravel failover before an outbox side effect', function (): void {
    config(['mail.mailers.nested' => ['transport' => 'failover', 'mailers' => ['ses_platform', 'smtp']],
        'mail.mailers.outer' => ['transport' => 'roundrobin', 'mailers' => ['nested', 'array']]]);
    expect(fn () => Mail::mailer('ses_platform'))->toThrow(RuntimeException::class, 'failover');
    expect(PlatformEmailMessage::count())->toBe(0);
});

it('reports explicit local outbox acceptance without inventing a provider receipt', function (): void {
    Bus::fake([SendPlatformEmail::class]);
    $sent = Mail::mailer('ses_platform')->raw('private-reset-token-fixture', fn ($mail) => $mail->to('fixture@example.test'));
    expect($sent->getMessageId())->toBe('outbox:'.PlatformEmailDelivery::sole()->id)
        ->and($sent->getSymfonySentMessage()->getOriginalMessage()->getHeaders()->get('X-Everbranch-Mail-State')->getBodyAsString())->toBe('queued')
        ->and(PlatformEmailDelivery::sole()->provider_message_id)->toBeNull()
        ->and(PlatformEmailDelivery::sole()->state)->toBe('queued');
});

it('requires the shared SNS signature boundary before routing platform evidence', function (): void {
    Bus::fake([SendPlatformEmail::class]);
    $outbox = platformFixtureOutbox(fn () => throw new RuntimeException('fixture unknown'));
    $email = platformFixtureEmail();
    $id = $outbox->enqueue($email, Envelope::create($email))[0];
    $outbox->submit($id);
    $event = platformFixtureEvent(PlatformEmailDelivery::findOrFail($id));
    $envelope = ['Type' => 'Notification', 'MessageId' => 'platform-route-fixture', 'Message' => json_encode($event),
        'TopicArn' => 'arn:aws:sns:us-east-1:123456789012:platform-fixture', 'Timestamp' => now()->toIso8601String(),
        'Signature' => 'invalid-fixture', 'SignatureVersion' => '2', 'SigningCertURL' => 'https://sns.us-east-1.amazonaws.com/SimpleNotificationService-fixture.pem'];
    $this->postJson('/webhooks/ses/events', $envelope)->assertStatus(403);
    expect(PlatformEmailDelivery::findOrFail($id)->state)->toBe('unknown');
    $this->mock(App\Services\Marketing\Email\SesSnsVerifier::class)->shouldReceive('valid')->once()->andReturn(true);
    $this->postJson('/webhooks/ses/events', $envelope)->assertOk()->assertJson(['matched' => 1, 'updated' => 1]);
    expect(PlatformEmailDelivery::findOrFail($id)->event_state)->toBe('delivered');
});

it('acknowledges held jobs and retries only pre HTTP rate admission while recovering only queued intents', function (): void {
    Bus::fake([SendPlatformEmail::class]);
    $email = platformFixtureEmail();
    $id = app(PlatformSesOutbox::class)->enqueue($email, Envelope::create($email))[0];
    $job = new SendPlatformEmail($id);
    $queueJob = Mockery::mock(Illuminate\Contracts\Queue\Job::class);
    $queueJob->shouldReceive('release')->once()->with(30);
    $job->setJob($queueJob);
    $outbox = Mockery::mock(PlatformSesOutbox::class);
    $outbox->shouldReceive('submit')->once()->with($id)->andReturn('rate_limited');
    $job->handle($outbox);
    $queueJob->shouldReceive('fail')->once()->with(Mockery::type(RuntimeException::class));
    $outbox->shouldReceive('submit')->once()->with($id)->andReturn('unknown');
    $job->handle($outbox);
    Bus::fake([SendPlatformEmail::class]);
    $row = PlatformEmailDelivery::findOrFail($id);
    $row->update(['state' => 'submitting', 'submitted_at' => now()]);
    $row->update(['state' => 'unknown']);
    $other = platformFixtureEmail('platform-fixture-recovery-00002')->to('cc@example.test');
    $queued = app(PlatformSesOutbox::class)->enqueue($other, Envelope::create($other))[0];
    $this->artisan('email:ses-platform-dispatch')->assertSuccessful();
    Bus::assertDispatched(SendPlatformEmail::class, fn ($dispatched) => $dispatched->deliveryId === $queued);
    Bus::assertNotDispatched(SendPlatformEmail::class, fn ($dispatched) => $dispatched->deliveryId === $id);
});

it('preserves provider receipt semantics for confirmed transactional mail without a second queue send', function (): void {
    Bus::fake([SendPlatformEmail::class]);
    $calls = 0;
    platformFixtureOutbox(function () use (&$calls) {
        $calls++;

        return new Result(['MessageId' => 'actual-ses-fixture-receipt']);
    });
    $sent = Mail::mailer('ses_platform_confirmed')->raw('Fixture confirmed acceptance', fn ($mail) => $mail->to('fixture@example.test'));
    expect($sent->getMessageId())->toBe('actual-ses-fixture-receipt')
        ->and(PlatformEmailDelivery::sole()->provider_message_id)->toBe('actual-ses-fixture-receipt')
        ->and(PlatformEmailDelivery::sole()->state)->toBe('accepted')->and($calls)->toBe(1);
    Bus::assertNothingDispatched();
});

it('never recovers a confirmed intent that stopped before provider submission', function (): void {
    Bus::fake([SendPlatformEmail::class]);
    $calls = 0;
    platformFixtureOutbox(function () use (&$calls) {
        $calls++;

        return new Result(['MessageId' => 'must-not-send']);
    });
    config()->set('ses_migration.account_daily_attempts', 0);
    expect(fn () => Mail::mailer('ses_platform_confirmed')->raw('No automatic stale business replay', fn ($mail) => $mail->to('fixture@example.test')))
        ->toThrow(Symfony\Component\Mailer\Exception\TransportException::class);
    $row = PlatformEmailDelivery::sole();
    expect($row->state)->toBe('prepared')->and($row->submitted_at)->toBeNull()->and($calls)->toBe(0);
    $this->artisan('email:ses-platform-dispatch')->assertSuccessful();
    Bus::assertNothingDispatched();
    expect(app(PlatformSesOutbox::class)->submit($row->id))->toBe('prepared');
});

it('requires a single recipient and committed caller context for provider acceptance', function (): void {
    $mailer = Mail::mailer('ses_platform_confirmed');
    expect(fn () => $mailer->raw('Multiple recipients', fn ($mail) => $mail->to('fixture@example.test')->cc('cc@example.test')))
        ->toThrow(Symfony\Component\Mailer\Exception\TransportException::class);
    DB::beginTransaction();
    try {
        expect(fn () => $mailer->raw('Caller transaction', fn ($mail) => $mail->to('fixture@example.test')))
            ->toThrow(Symfony\Component\Mailer\Exception\TransportException::class);
    } finally {
        DB::rollBack();
    }
    expect(PlatformEmailMessage::count())->toBe(0);
});

it('reconciles frozen wholesale notifications before regenerating activation links or tokens', function (string $state): void {
    Bus::fake([SendPlatformEmail::class]);
    config()->set('mail.default', 'ses_platform_confirmed');
    $request = App\Models\CustomerAccessRequest::create(['name' => 'Fictional applicant', 'email' => 'fixture@example.test',
        'status' => 'approved', 'application_kind' => 'wholesale_application', 'metadata' => []]);
    $service = app(App\Services\Onboarding\WholesaleApplicationDeliveryService::class);
    $service->enqueue($request, 'decision');
    $identity = App\Support\Mail\PlatformMailIdentity::wholesale($request, 'decision');
    $email = platformFixtureEmail($identity);
    $id = app(PlatformSesOutbox::class)->enqueue($email, Envelope::create($email), false)[0];
    $row = PlatformEmailDelivery::findOrFail($id);
    $row->update(['state' => 'submitting', 'submitted_at' => now()]);
    $row->update(['state' => $state, 'provider_message_id' => $state === 'accepted' ? 'actual-receipt' : null, 'accepted_at' => $state === 'accepted' ? now() : null]);
    Mail::fake();
    Illuminate\Support\Facades\Notification::fake();
    Illuminate\Support\Facades\Http::preventStrayRequests();
    $service->deliver($request->id);
    expect(data_get($request->fresh()->metadata, 'delivery.decision.status'))->toBe($state === 'accepted' ? 'sent' : 'held');
    Illuminate\Support\Facades\Notification::assertNothingSent();
    Illuminate\Support\Facades\Http::assertNothingSent();
})->with(['accepted', 'unknown']);

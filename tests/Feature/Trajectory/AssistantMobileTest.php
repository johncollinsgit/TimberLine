<?php

use App\Jobs\Trajectory\CaptureExpenseNotice;
use App\Jobs\Trajectory\SendExpensePush;
use App\Models\Trajectory\Account;
use App\Models\Trajectory\AlertPreference;
use App\Models\Trajectory\AssistantTurn;
use App\Models\Trajectory\Connection;
use App\Models\Trajectory\ExpenseNotice;
use App\Models\Trajectory\PushSubscription;
use App\Models\Trajectory\Record;
use App\Models\Trajectory\Transaction;
use App\Models\User;
use App\Services\Trajectory\AssistantService;
use App\Services\Trajectory\ExpenseNotificationService;
use App\Services\Trajectory\LedgerService;
use App\Services\Trajectory\PilotService;
use App\Services\Trajectory\WebPushService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $this->withoutVite();
    $this->travelTo(\Carbon\CarbonImmutable::parse('2027-02-12T12:00:00Z'));
    config(['trajectory.enabled' => true, 'trajectory_mobile.push_enabled' => false, 'bud.core_enabled' => true, 'bud.ai_enabled' => false]);
    $this->owner = User::factory()->create(['role' => 'admin', 'is_active' => true, 'email_verified_at' => now()]);
    [$this->space] = app(PilotService::class)->prepare($this->owner, null, true);
    $this->space->update(['timezone' => 'UTC']);
    $this->cash = Account::create(['space_id' => $this->space->id, 'source_key' => 'assistant-fixture:cash', 'name' => 'Example cash', 'kind' => 'cash', 'currency' => 'USD', 'balance_cents' => 100000, 'observed_at' => now(), 'history_start' => '2026-01-01']);
    $this->entry = function (string $key, int $amount = -2500, bool $pending = false): Transaction {
        app(LedgerService::class)->ingest($this->cash, [['id' => $key, 'date' => '2027-02-12', 'merchant' => 'Example '.$key, 'amount_cents' => $amount, 'pending' => $pending]]);
        $transaction = Transaction::where('source_key', $this->cash->source_key.':'.$key)->firstOrFail();
        $transaction->update(['category' => 'shopping', 'flow' => 'expense', 'reviewed' => true]);

        return $transaction->fresh();
    };
    $this->context = fn ($tx) => ['transaction_ids' => [$tx->id], 'transaction_versions' => [(string) $tx->id => $tx->version]];
});

test('an explicit assistant category command saves once and supports versioned undo', function (): void {
    $transaction = ($this->entry)('category');
    $original = $transaction->only(['amount_cents', 'source_key', 'source', 'account_id', 'posted_on']);
    $id = (string) Str::uuid();
    $service = app(AssistantService::class);
    $reply = $service->respond($this->owner, $this->space, 'Move this to groceries', ($this->context)($transaction), $id);
    expect($reply['applied'][0]['transaction_id'])->toBe($transaction->id)
        ->and($transaction->fresh()->category)->toBe('groceries')
        ->and($transaction->fresh()->only(array_keys($original)))->toEqual($original);
    $version = $transaction->fresh()->version;
    expect($service->respond($this->owner, $this->space, 'Move this to groceries', ($this->context)($transaction), $id))->toBe($reply)
        ->and($transaction->fresh()->version)->toBe($version);
    $service->undo($this->owner, $this->space, AssistantTurn::findOrFail($reply['id']));
    expect($transaction->fresh()->category)->toBe('shopping')
        ->and($transaction->fresh()->amount_cents)->toBe(-2500);
    expect(DB::table('trajectory_assistant_turns')->value('question'))->not->toContain('groceries');
});

test('assistant clarifies ambiguous category and program rather than changing a purchase', function (): void {
    $transaction = ($this->entry)('ambiguous');
    Record::create(['space_id' => $this->space->id, 'kind' => 'program', 'name' => 'Shopping', 'data' => ['key' => 'shopping'], 'active' => true, 'version' => 1]);
    $reply = app(AssistantService::class)->respond($this->owner, $this->space, 'Shopping', ($this->context)($transaction), (string) Str::uuid());
    expect($reply['clarification'])->toBeTrue()->and($transaction->fresh()->version)->toBe($transaction->version);
});

test('assistant batch changes require a preview and fail atomically when one source version changes', function (): void {
    $a = ($this->entry)('batch-a');
    $b = ($this->entry)('batch-b');
    $context = ['transaction_ids' => [$a->id, $b->id], 'transaction_versions' => [(string) $a->id => $a->version, (string) $b->id => $b->version]];
    $service = app(AssistantService::class);
    $reply = $service->respond($this->owner, $this->space, 'groceries', $context, (string) Str::uuid());
    expect($reply['proposal']['requires_review'])->toBeTrue()->and($a->fresh()->category)->toBe('shopping');
    $b->increment('version');
    expect(fn () => $service->apply($this->owner, $this->space, AssistantTurn::findOrFail($reply['id'])))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    expect($a->fresh()->category)->toBe('shopping');
});

test('assistant cannot select another household transaction or replay a request across spaces', function (): void {
    $transaction = ($this->entry)('private');
    $other = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
    [$otherSpace] = app(PilotService::class)->prepare($other, null, true);
    expect(fn () => app(AssistantService::class)->respond($other, $otherSpace, 'groceries', ($this->context)($transaction), (string) Str::uuid()))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    $this->actingAs($other)->getJson('/trajectory/spaces/'.$this->space->id.'/assistant')->assertForbidden();
});

test('expense inbox deduplicates pending posting and retries and does not need paid AI or push', function (): void {
    Queue::fake();
    AlertPreference::create(['space_id' => $this->space->id, 'user_id' => $this->owner->id, 'enabled' => true, 'include_pending' => true, 'show_details' => true, 'enabled_at' => now()->subMinute()]);
    $transaction = ($this->entry)('pending', -1234, true);
    $service = app(ExpenseNotificationService::class);
    $service->capture($transaction);
    $service->capture($transaction);
    expect(ExpenseNotice::count())->toBe(1);
    Queue::assertPushed(SendExpensePush::class, 1);
    $transaction->update(['pending' => false]);
    $service->capture($transaction->fresh());
    expect(ExpenseNotice::count())->toBe(1)->and(ExpenseNotice::first()->payload['pending'])->toBeFalse()
        ->and(ExpenseNotice::first()->payload['remaining_cents'])->toBeNull();
    Queue::assertPushed(SendExpensePush::class, 1);
    app(WebPushService::class)->send(ExpenseNotice::first());
    expect(ExpenseNotice::first()->delivery_status)->toBe('inbox_only');
});

test('initial connected imports and edits do not dispatch expense notification jobs', function (): void {
    Queue::fake();
    $connection = Connection::create(['space_id' => $this->space->id, 'provider' => 'plaid', 'external_id' => 'example-bootstrapping', 'access_token' => 'fictional-private-token', 'cursor' => null]);
    $this->cash->update(['connection_id' => $connection->id]);
    $transaction = ($this->entry)('historical');
    Queue::assertNotPushed(CaptureExpenseNotice::class);
    $connection->update(['cursor' => 'fictional-cursor']);
    $transaction->update(['category' => 'groceries', 'version' => $transaction->version + 1]);
    Queue::assertNotPushed(CaptureExpenseNotice::class);
});

test('expense inbox excludes transfers debt repayments and people without household access', function (): void {
    Queue::fake();
    $preference = AlertPreference::create(['space_id' => $this->space->id, 'user_id' => $this->owner->id, 'enabled' => true, 'include_pending' => true, 'enabled_at' => now()->subMinute()]);
    $transaction = ($this->entry)('repayment');
    $transaction->update(['flow' => 'card_payment']);
    app(ExpenseNotificationService::class)->capture($transaction->fresh());
    expect(ExpenseNotice::count())->toBe(0);
    $this->owner->update(['is_active' => false]);
    $transaction->update(['flow' => 'expense']);
    app(ExpenseNotificationService::class)->capture($transaction->fresh());
    expect(ExpenseNotice::count())->toBe(0);
});

test('personal PWA manifests are distinct and financial pages are never cached offline', function (): void {
    $personal = $this->getJson('/trajectory/personal/manifest.webmanifest')->assertOk()->json();
    $business = $this->getJson('/trajectory/business/manifest.webmanifest')->assertOk()->json();
    expect($personal['id'])->not->toBe($business['id'])->and($personal['scope'])->toBe('/trajectory/personal/');
    $response = $this->actingAs($this->owner)->get('/trajectory/personal/')->assertOk();
    expect($response->headers->get('Cache-Control'))->toContain('no-store');
    $worker = file_get_contents($this->get('/trajectory/personal/sw.js')->assertOk()->baseResponse->getFile()->getPathname());
    expect($worker)->not->toContain('caches.open', 'cache.put')->and($worker)->toContain('notificationclick');
});

test('closed expense badge counts private notices without rebuilding purchase budgets', function (): void {
    $transaction = ($this->entry)('badge');
    $other = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
    foreach ([$this->owner, $other] as $recipient) {
        ExpenseNotice::create(['space_id' => $this->space->id, 'user_id' => $recipient->id, 'transaction_id' => $transaction->id,
            'payload' => ['merchant' => 'Private purchase details'], 'delivery_status' => 'inbox_only']);
    }
    $this->mock(ExpenseNotificationService::class)->shouldNotReceive('payload');
    $url = '/trajectory/spaces/'.$this->space->id.'/notifications?summary=1';
    $response = $this->actingAs($this->owner)->getJson($url)->assertOk()->assertExactJson(['unread_count' => 1]);
    expect($response->headers->get('Cache-Control'))->toContain('no-store');
    $this->actingAs($other)->getJson($url)->assertForbidden();
    ExpenseNotice::where('user_id', $this->owner->id)->update(['read_at' => now()]);
    $this->actingAs($this->owner)->getJson($url)->assertOk()->assertExactJson(['unread_count' => 0]);
});

test('push subscriptions reject arbitrary network URLs and stay user-private and encrypted', function (): void {
    $base = '/trajectory/spaces/'.$this->space->id;
    $keys = ['p256dh' => rtrim(strtr(base64_encode(str_repeat('a', 65)), '+/', '-_'), '='), 'auth' => rtrim(strtr(base64_encode(str_repeat('b', 16)), '+/', '-_'), '=')];
    $this->actingAs($this->owner)->postJson($base.'/push-subscriptions', ['endpoint' => 'https://127.0.0.1/private', 'keys' => $keys])->assertUnprocessable();
    $this->postJson($base.'/push-subscriptions', ['endpoint' => 'https://fcm.googleapis.com/fcm/send/fictional', 'keys' => $keys])->assertCreated();
    expect(DB::table('trajectory_push_subscriptions')->value('endpoint'))->not->toContain('fcm.googleapis.com')
        ->and(PushSubscription::first()->toArray())->not->toHaveKeys(['endpoint', 'keys', 'endpoint_hash']);
    $other = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
    $this->actingAs($other)->getJson($base.'/notifications')->assertForbidden();
});

test('device subscription status is read only and private to the exact active member household and endpoint', function (): void {
    $base = '/trajectory/spaces/'.$this->space->id;
    $endpoint = 'https://fcm.googleapis.com/fcm/send/fictional-device-status';
    $keys = ['p256dh' => rtrim(strtr(base64_encode(str_repeat('a', 65)), '+/', '-_'), '='), 'auth' => rtrim(strtr(base64_encode(str_repeat('b', 16)), '+/', '-_'), '=')];
    $this->actingAs($this->owner)->postJson($base.'/push-subscriptions', ['endpoint' => $endpoint, 'keys' => $keys])->assertCreated();
    $statusUrl = $base.'/push-subscriptions?endpoint_hash='.hash('sha256', $endpoint);
    $before = DB::table('trajectory_push_subscriptions')->first();
    $response = $this->getJson($statusUrl)->assertOk()->assertExactJson(['registered' => true]);
    expect($response->headers->get('Cache-Control'))->toContain('no-store');
    $this->getJson($base.'/push-subscriptions?endpoint_hash='.hash('sha256', $endpoint.'-other-device'))->assertOk()->assertExactJson(['registered' => false]);
    $this->getJson($base.'/push-subscriptions?endpoint_hash=not-a-hash')->assertUnprocessable();

    $member = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
    DB::table('trajectory_members')->insert(['space_id' => $this->space->id, 'user_id' => $member->id, 'created_at' => now(), 'updated_at' => now()]);
    $this->actingAs($member)->getJson($statusUrl)->assertOk()->assertExactJson(['registered' => false]);
    DB::table('trajectory_members')->where('user_id', $member->id)->delete();
    $this->getJson($statusUrl)->assertForbidden();

    [$otherHousehold] = app(PilotService::class)->prepare($member, null, true);
    DB::table('trajectory_members')->insert(['space_id' => $otherHousehold->id, 'user_id' => $this->owner->id, 'created_at' => now(), 'updated_at' => now()]);
    $this->actingAs($this->owner)->getJson('/trajectory/spaces/'.$otherHousehold->id.'/push-subscriptions?endpoint_hash='.hash('sha256', $endpoint))
        ->assertOk()->assertExactJson(['registered' => false]);
    expect(DB::table('trajectory_push_subscriptions')->first())->toEqual($before);

    PushSubscription::first()->update(['disabled_at' => now()]);
    $this->getJson($statusUrl)->assertOk()->assertExactJson(['registered' => false]);
    expect(PushSubscription::count())->toBe(1)->and(PushSubscription::first()->disabled_at)->not->toBeNull();
});

test('Ask answers the selected category and basis rather than the whole household', function (): void {
    $food = ($this->entry)('scoped-food', -1234);
    $food->update(['category' => 'groceries']);
    ($this->entry)('other-shopping', -9876);
    $response = app(AssistantService::class)->respond($this->owner, $this->space, 'How much did this category cost?', ['from' => '2027-02-01', 'through' => '2027-02-12', 'level' => 'category', 'category' => 'food', 'direction' => 'out', 'basis' => 'spending'], (string) Str::uuid());
    expect($response['context_summary']['amount_cents'])->toBe(1234)->and($response['context_summary']['count'])->toBe(1)
        ->and($response['reply'])->toContain('scoped-food')->not->toContain('other-shopping');
});

test('clear selected batch commands apply directly while a named purchase never changes its siblings', function (): void {
    $a = ($this->entry)('named-a');
    $b = ($this->entry)('named-b');
    $context = ['transaction_ids' => [$a->id, $b->id], 'transaction_versions' => [(string) $a->id => $a->version, (string) $b->id => $b->version]];
    $service = app(AssistantService::class);
    $reply = $service->respond($this->owner, $this->space, 'Move Example named-a to groceries', $context, (string) Str::uuid());
    expect($reply['applied'])->toHaveCount(1)->and($a->fresh()->category)->toBe('groceries')->and($b->fresh()->category)->toBe('shopping');
    $context['transaction_versions'][(string) $a->id] = $a->fresh()->version;
    $reply = $service->respond($this->owner, $this->space, 'Move all selected purchases to dining', $context, (string) Str::uuid());
    expect($reply['applied'])->toHaveCount(2)->and($a->fresh()->category)->toBe('dining')->and($b->fresh()->category)->toBe('dining');
});

test('an uncertain merchant reference asks which row rather than applying a batch', function (): void {
    $a = ($this->entry)('one');
    $b = ($this->entry)('two');
    $context = ['transaction_ids' => [$a->id, $b->id], 'transaction_versions' => [(string) $a->id => $a->version, (string) $b->id => $b->version]];
    $response = app(AssistantService::class)->respond($this->owner, $this->space, 'Move Missing merchant to groceries', $context, (string) Str::uuid());
    expect($response['clarification'])->toBeTrue()->and($a->fresh()->category)->toBe('shopping')->and($b->fresh()->category)->toBe('shopping');
});

test('half household half linked business produces an exact conserving split with reversible attribution', function (): void {
    $tenant = \App\Models\Tenant::create(['slug' => 'assistant-example-business', 'name' => 'Example business']);
    \App\Models\TenantAccessProfile::create(['tenant_id' => $tenant->id, 'plan_key' => 'base', 'operating_mode' => 'direct']);
    $this->owner->tenants()->attach($tenant->id, ['role' => 'owner', 'membership_active' => true]);
    [, $business] = app(PilotService::class)->prepare($this->owner, $tenant, true);
    app()->forgetInstance(\App\Services\Tenancy\TenantModuleAccessResolver::class);
    $transaction = ($this->entry)('split', -10001);
    $service = app(AssistantService::class);
    $reply = $service->respond($this->owner, $this->space, 'half household and half Example business', ($this->context)($transaction), (string) Str::uuid());
    expect($reply['proposal']['actions'][0]['destination']['lines'])->toHaveCount(2)
        ->and(array_sum(array_column($reply['proposal']['actions'][0]['destination']['lines'], 'amount_cents')))->toBe(-10001);
    $applied = $service->apply($this->owner, $this->space, AssistantTurn::findOrFail($reply['id']));
    expect(\App\Models\Trajectory\Allocation::where('transaction_id', $transaction->id)->where('space_id', $business->id)->value('amount_cents'))->toBe(-5001)
        ->and(Transaction::count())->toBe(1)->and($transaction->fresh()->amount_cents)->toBe(-10001);
    $service->undo($this->owner, $this->space, AssistantTurn::findOrFail($reply['id']));
    expect(\App\Models\Trajectory\Allocation::where('transaction_id', $transaction->id)->where('space_id', $this->space->id)->value('amount_cents'))->toBe(-10001);
});

test('incremental historical backfills do not alert while a newly observed pending purchase can', function (): void {
    Queue::fake();
    AlertPreference::create(['space_id' => $this->space->id, 'user_id' => $this->owner->id, 'enabled' => true, 'include_pending' => true, 'show_details' => true, 'enabled_at' => now()->subMinute()]);
    $old = ($this->entry)('backfill');
    $old->update(['posted_on' => '2026-12-01']);
    app(ExpenseNotificationService::class)->capture($old->fresh());
    expect(ExpenseNotice::count())->toBe(0);
    $pending = ($this->entry)('just-seen-pending', -100, true);
    $pending->update(['posted_on' => '2027-02-11']);
    app(ExpenseNotificationService::class)->capture($pending->fresh());
    expect(ExpenseNotice::count())->toBe(1);
    $pending->update(['pending' => false]);
    app(ExpenseNotificationService::class)->capture($pending->fresh());
    expect(ExpenseNotice::first()->payload['pending'])->toBeFalse();
    Queue::assertPushed(SendExpensePush::class, 1);
});

test('queued push and inbox recheck current ownership and never expose a moved purchase snapshot', function (): void {
    Queue::fake();
    AlertPreference::create(['space_id' => $this->space->id, 'user_id' => $this->owner->id, 'enabled' => true, 'include_pending' => true, 'enabled_at' => now()->subMinute()]);
    $transaction = ($this->entry)('private-moved-merchant');
    app(ExpenseNotificationService::class)->capture($transaction);
    $notice = ExpenseNotice::firstOrFail();
    $other = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
    [$otherSpace] = app(PilotService::class)->prepare($other, null, true);
    $transaction->update(['space_id' => $otherSpace->id]);
    app(WebPushService::class)->send($notice);
    expect($notice->fresh()->delivery_status)->toBe('suppressed');
    $response = $this->actingAs($this->owner)->getJson('/trajectory/spaces/'.$this->space->id.'/notifications')->assertOk();
    expect($response->json('rows.0.payload'))->toBe(['removed' => true, 'unavailable' => true])
        ->and($response->getContent())->not->toContain('private-moved-merchant', '2500');
});

test('a queued expense reclassified as a transfer is suppressed before external delivery', function (): void {
    Queue::fake();
    AlertPreference::create(['space_id' => $this->space->id, 'user_id' => $this->owner->id, 'enabled' => true, 'include_pending' => true, 'enabled_at' => now()->subMinute()]);
    $transaction = ($this->entry)('now-transfer');
    app(ExpenseNotificationService::class)->capture($transaction);
    $transaction->update(['flow' => 'transfer']);
    app(WebPushService::class)->send(ExpenseNotice::firstOrFail());
    expect(ExpenseNotice::first()->delivery_status)->toBe('suppressed');
});

test('a truncated selected batch is clarified instead of applying only part of a category', function (): void {
    $transaction = ($this->entry)('truncated');
    $context = [...($this->context)($transaction), 'selection_truncated' => true];
    $reply = app(AssistantService::class)->respond($this->owner, $this->space, 'Move all selected purchases to groceries', $context, (string) Str::uuid());
    expect($reply['clarification'])->toBeTrue()->and($transaction->fresh()->category)->toBe('shopping');
});

test('a pending purchase can receive a program and remains pending with source cents intact', function (): void {
    $transaction = ($this->entry)('program-pending', -1700, true);
    $program = app(\App\Services\Trajectory\AttributionService::class)->saveDefinition($this->owner, $this->space, 'program', 'Camping', ['key' => 'camping']);
    $reply = app(AssistantService::class)->respond($this->owner, $this->space, 'Move this to program Camping', ($this->context)($transaction), (string) Str::uuid());
    expect($reply['applied'])->toHaveCount(1)->and($transaction->fresh()->pending)->toBeTrue()->and($transaction->fresh()->amount_cents)->toBe(-1700);
    $payload = app(ExpenseNotificationService::class)->payload($this->owner, $this->space, $transaction->fresh());
    expect($payload['program_id'])->toBe($program->id)->and($payload['program_label'])->toBe('Camping');
    $response = app(AssistantService::class)->respond($this->owner, $this->space, 'Explain this purchase', [...($this->context)($transaction->fresh()), 'focused_transaction' => true, 'level' => 'transaction'], (string) Str::uuid());
    expect($response['context_summary']['pending'])->toBeTrue()->and($response['reply'])->toContain('pending');
});

test('configured Web Push requires member opt in and submits one current Ask purchase message without claiming handset delivery', function (): void {
    Queue::fake();
    $keys = \Minishlink\WebPush\VAPID::createVapidKeys();
    config(['trajectory_mobile.push_enabled' => true, 'trajectory_mobile.public_key' => $keys['publicKey'], 'trajectory_mobile.private_key' => $keys['privateKey'], 'trajectory_mobile.subject' => 'mailto:fixture@example.test']);
    $preference = AlertPreference::create(['space_id' => $this->space->id, 'user_id' => $this->owner->id, 'enabled' => true, 'include_pending' => true, 'show_details' => true, 'enabled_at' => now()->subMinute()]);
    $transaction = ($this->entry)('configured-push');
    app(ExpenseNotificationService::class)->capture($transaction);
    $notice = ExpenseNotice::firstOrFail();
    $endpoint = 'https://fcm.googleapis.com/fcm/send/fictional-member';
    PushSubscription::create(['space_id' => $this->space->id, 'user_id' => $this->owner->id, 'endpoint' => $endpoint, 'endpoint_hash' => hash('sha256', $endpoint), 'keys' => ['p256dh' => $keys['publicKey'], 'auth' => rtrim(strtr(base64_encode(str_repeat('b', 16)), '+/', '-_'), '=')]]);
    $transport = Mockery::mock(\Minishlink\WebPush\WebPush::class);
    $transport->shouldReceive('queueNotification')->once()->withArgs(function ($subscription, $json) use ($endpoint): bool {
        $payload = json_decode($json, true);
        expect($subscription->getEndpoint())->toBe($endpoint)->and($payload['title'])->toBe('Ask Everbranch · New purchase')
            ->and($payload['body'])->toContain('configured-push', '$25.00', 'Shopping')
            ->and($payload['body'])->not->toContain('$0.00 budget left');

        return true;
    });
    $transport->shouldReceive('flush')->once()->andReturnUsing(function () use ($endpoint): Generator {
        yield new \Minishlink\WebPush\MessageSentReport(new \GuzzleHttp\Psr7\Request('POST', $endpoint), new \GuzzleHttp\Psr7\Response(201), true);
    });
    $service = new class($transport) extends WebPushService
    {
        public function __construct(private \Minishlink\WebPush\WebPush $fake) {}

        protected function transport(): \Minishlink\WebPush\WebPush
        {
            return $this->fake;
        }
    };
    expect($service->ready())->toBeTrue();
    $service->send($notice);
    $service->send($notice->fresh());
    expect($notice->fresh()->delivery_status)->toBe('accepted');
    $preference->update(['enabled' => false]);
    $notice->update(['delivery_status' => 'pending']);
    $service->send($notice);
    expect($notice->fresh()->delivery_status)->toBe('suppressed');
});

<?php

use App\Models\User;
use App\Services\Retail\RetailDemoDelivery;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

beforeEach(function () {
    Cache::flush();
    Http::preventStrayRequests();
    $this->owner = User::factory()->create(['email' => 'presenter@demo.invalid', 'is_active' => true, 'email_verified_at' => now()]);
    config()->set('retail_showroom.enabled', true);
    config()->set('retail_showroom.owner_email', $this->owner->email);
    $this->actingAs($this->owner);
    $this->dir = storage_path('framework/testing/demo-delivery-'.Str::uuid());
    $dir = $this->dir;
    app()->instance(RetailDemoDelivery::class, new class($dir) extends RetailDemoDelivery
    {
        public function __construct(private string $path) {}

        protected function directory(): string
        {
            return $this->path;
        }

        protected function settings(): array
        {
            return ['email' => ['enabled' => true, 'provider' => 'sendgrid', 'api_key' => 'synthetic-sendgrid-key', 'from' => ['address' => 'no-reply@theeverbranch.com']], 'sms' => ['enabled' => true, 'verification_status' => 'approved', 'account_sid' => 'AC'.str_repeat('1', 32), 'auth_token' => 'synthetic-twilio-token', 'from' => '+15555550100', 'allowed_recipients' => ['+15555550101'], 'presenter_confirmed_recipients' => config('retail_showroom.testing_presenter_confirmation', false)]];
        }
    });
    $catalog = json_decode(file_get_contents(resource_path('data/avery-catalog-demo.json')), true);
    $this->input = ['channel' => 'email', 'recipient' => 'presenter@example.test', 'customer_id' => 'ak-demo-02', 'product_id' => $catalog['products'][0]['id'], 'variant_id' => $catalog['products'][0]['variants'][0]['id'], 'consent' => true, 'idempotency_key' => (string) Str::uuid()];
    $this->url = 'http://'.parse_url(route('retail.showroom'), PHP_URL_HOST).'/retail/showroom/api/demo/delivery';
});
afterEach(function () {
    File::deleteDirectory($this->dir);
});

test('live demo delivery requires the configured owner and explicit recipient consent', function () {
    $this->postJson($this->url, [...$this->input, 'consent' => false])->assertUnprocessable();
    $this->actingAs(User::factory()->create(['email_verified_at' => now(), 'is_active' => true]));
    $this->postJson($this->url, $this->input)->assertForbidden();
    Http::assertNothingSent();
});

test('reviewed SendGrid email uses exact catalog data and a durable replay guard without storing the recipient', function () {
    Http::fake(['api.sendgrid.com/v3/mail/send' => Http::response('', 202, ['X-Message-Id' => 'synthetic-message'])]);
    $first = $this->postJson($this->url, $this->input)->assertOk()->assertJsonPath('status', 'accepted')->assertJsonPath('reused', false)->json();
    $this->postJson($this->url, $this->input)->assertOk()->assertJsonPath('reference', $first['reference'])->assertJsonPath('reused', true);
    Http::assertSentCount(1);
    $raw = file_get_contents(glob($this->dir.'/*.json')[0]);
    expect($raw)->toContain('One presenter-requested demonstration message', 'synthetic-message')->not->toContain('presenter@example.test', 'synthetic-sendgrid-key', 'saved an interest');
    Http::assertSent(fn ($r) => $r['personalizations'][0]['to'][0]['email'] === 'presenter@example.test' && str_contains($r['content'][1]['value'], 'exact knife'));
});

test('temporary SMS sender rejects other mobiles and reports actual provider delivery separately from acceptance', function () {
    Http::fake(['api.twilio.com/*/Messages.json' => Http::response(['sid' => 'SM'.str_repeat('2', 32), 'status' => 'queued'], 201), 'api.twilio.com/*/Messages/SM*.json' => Http::response(['status' => 'delivered', 'error_code' => null])]);
    $this->postJson($this->url, [...$this->input, 'channel' => 'sms', 'recipient' => '+15555550102'])->assertUnprocessable();
    Http::assertNothingSent();
    $sent = $this->postJson($this->url, [...$this->input, 'channel' => 'sms', 'recipient' => '+15555550101'])->assertOk()->assertJsonPath('status', 'queued')->json();
    $this->getJson($this->url.'/'.$sent['reference'])->assertOk()->assertJsonPath('status', 'delivered');
    Http::assertSentCount(2);
});

test('unknown customer or product variant never reaches a provider', function () {
    $this->postJson($this->url, [...$this->input, 'customer_id' => 'real-customer-42'])->assertUnprocessable();
    $this->postJson($this->url, [...$this->input, 'variant_id' => 'foreign-variant'])->assertUnprocessable();
    Http::assertNothingSent();
});

test('ambiguous provider failure stays unconfirmed and cannot automatically send again', function () {
    Http::fake(['*' => Http::failedConnection()]);
    $this->postJson($this->url, $this->input)->assertOk()->assertJsonPath('status', 'unconfirmed');
    $this->postJson($this->url, $this->input)->assertOk()->assertJsonPath('status', 'unconfirmed')->assertJsonPath('reused', true);
    $this->postJson($this->url, [...$this->input, 'recipient' => 'other@example.test'])->assertUnprocessable();
    Http::assertSentCount(1);
});

test('a separately enabled presenter session can send one recipient-confirmed demo without enrolling a customer', function () {
    config(['retail_showroom.testing_presenter_confirmation' => true]);
    Http::fake(['api.twilio.com/*/Messages.json' => Http::response(['sid' => 'SM'.str_repeat('3', 32), 'status' => 'queued'], 201)]);
    $input = [...$this->input, 'channel' => 'sms', 'recipient' => '+15555550102'];
    $this->postJson($this->url, [...$input, 'consent' => false])->assertUnprocessable();
    Http::assertNothingSent();
    $first = $this->postJson($this->url, $input)->assertOk()->assertJsonPath('status', 'queued')->json();
    $this->postJson($this->url, $input)->assertOk()->assertJsonPath('reference', $first['reference'])->assertJsonPath('reused', true);
    Http::assertSentCount(1);
    Http::assertSent(fn ($request) => $request['To'] === '+15555550102' && str_starts_with($request['Body'], 'Modern Forestry: Owner-authorized Everbranch demo.') && str_contains($request['Body'], 'Reply STOP'));
    expect(file_get_contents(glob($this->dir.'/*.json')[0]))->not->toContain('+15555550102');
});

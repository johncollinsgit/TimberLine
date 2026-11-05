<?php

require_once dirname(__DIR__).'/ShopifyEmbeddedTestHelpers.php';

use App\Models\Order;
use App\Models\TeamOperationRecord;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WholesaleProspect;
use App\Services\Wholesale\Gmail\WholesaleGmailConnection;
use App\Services\Wholesale\Gmail\WholesaleGmailJournal;
use App\Services\Wholesale\Gmail\WholesaleGmailSender;
use App\Services\Wholesale\WholesaleOutreachDigest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    $this->withoutVite();
    $this->tenant = Tenant::query()->create(['name' => 'Example Candles', 'mailing_address' => '123 Example Lane, Fictional, GA 30000', 'slug' => 'example-candles']);
    configureEmbeddedWholesaleStore($this->tenant->id);
    configureWholesaleReportingFixture();
    $this->actor = User::factory()->create(['role' => 'admin', 'is_active' => true]);
    $this->actor->tenants()->attach($this->tenant);
    $this->actingAs($this->actor);
    $this->shop = 's2vscq-rf.myshopify.com';
    config(['app.url' => 'https://backstage.example.test', 'wholesale_gmail' => [
        'connection_enabled' => true, 'send_enabled' => true,
        'client_id' => 'fictional-client', 'client_secret' => 'fictional-secret',
        'redirect_uri' => 'https://backstage.example.test/wholesale/gmail/callback',
        'tenants' => [(string) $this->tenant->id => ['shop' => $this->shop, 'mailbox' => 'owner@example.test',
            'from' => 'info@example.test', 'reply_to' => 'info@example.test', 'name' => 'Example Candles', 'mailing_address' => '123 Example Lane, Fictional, GA 30000']],
    ]]);
    $this->journal = app(WholesaleGmailJournal::class);
    $this->gmail = app(WholesaleGmailConnection::class);
    $this->sender = app(WholesaleGmailSender::class);
    $this->order = Order::factory()->create([
        'tenant_id' => $this->tenant->id, 'shopify_store_key' => 'wholesale', 'shopify_store' => 'wholesale',
        'source' => 'shopify_wholesale', 'order_type' => 'wholesale', 'shopify_customer_id' => 'fictional-buyer',
        'customer_name' => 'Robin Example', 'shipping_company' => 'Example Shop', 'customer_email' => 'buyer@example.test',
        'total_price' => 300, 'refund_total' => 0, 'ordered_at' => now()->subDays(160),
    ]);
    $this->account = app(WholesaleOutreachDigest::class)->snapshot($this->tenant)['rows'][0]['account_key'];
    Http::preventStrayRequests();
    $this->googleEmail = 'owner@example.test';
    $this->aliasStatus = 'accepted';
    $this->sendAttempts = 0;
    $this->loseResponse = false;
    Http::fake(function ($request) {
        return match ($request->url()) {
            WholesaleGmailConnection::IDENTITY => Http::response(['email' => $this->googleEmail, 'email_verified' => true, 'sub' => 'owner-sub']),
            WholesaleGmailConnection::ALIASES => Http::response(['sendAs' => [['sendAsEmail' => 'info@example.test', 'verificationStatus' => $this->aliasStatus]]]),
            WholesaleGmailConnection::TOKEN => Http::response(['access_token' => 'new-access', 'refresh_token' => 'refresh', 'token_type' => 'Bearer', 'expires_in' => 3600, 'scope' => $this->scopes ?? implode(' ', WholesaleGmailConnection::SCOPES)]),
            WholesaleGmailSender::SEND => (function () {
                $this->sendAttempts++;
                expect($this->journal->rows($this->tenant->id, $this->internalMode ?? 'send')->first()->status)->toBe('sending');
                if (isset($this->nestedReview)) {
                    expect(($this->send)($this->nestedReview)['status'])->toBe('sending');
                }
                if ($this->loseResponse) {
                    throw new \Illuminate\Http\Client\ConnectionException('Fictional lost response');
                }

                return Http::response(['id' => 'message123', 'threadId' => 'thread123']);
            })(),
            default => Http::response(['data' => ['shop' => ['primaryDomain' => ['url' => 'https://wholesale.example.test']], 'nodes' => []]]),
        };
    });
    $config = $this->gmail->settings($this->tenant->id, $this->shop);
    $this->connection = $this->journal->create($this->tenant->id, 'connection', hash('sha256', $this->shop), [
        'shop' => $this->shop, 'fingerprint' => $config['fingerprint'], 'generation' => 'generation-1',
        'access' => 'access-secret', 'refresh' => 'refresh-secret', 'expires' => now()->addHour()->timestamp, 'subject' => 'owner-sub',
    ], 'connected');
    $this->review = fn () => $this->sender->review($this->tenant->id, $this->actor->id, $this->shop, $this->account, 'A personal note', "Hello Robin.\n\nHow are your candles selling?");
    $this->send = fn ($id) => $this->sender->send($this->tenant->id, $this->actor->id, $this->shop, $id);
});

test('native sends preserve the reviewed HTML sender and reply address and replay only the receipt', function (): void {
    $review = ($this->review)();
    expect($review['html'])->toContain('Hello Robin.', 'Example Candles');
    Http::assertNotSent(fn ($r) => $r->url() === WholesaleGmailSender::SEND);
    $result = ($this->send)($review['id']);
    expect($result)->toMatchArray(['status' => 'accepted', 'message_id' => 'message123', 'thread_id' => 'thread123']);
    Http::assertSent(function ($r) {
        if ($r->url() !== WholesaleGmailSender::SEND) {
            return false;
        }
        $raw = base64_decode(strtr($r['raw'], '-_', '+/'));
        expect($raw)->toContain('From: Example Candles <info@example.test>', 'Reply-To: info@example.test', 'To: buyer@example.test', 'text/html', 'text/plain')
            ->not->toContain('owner@example.test', 'Bcc:');
        $row = $this->journal->rows($this->tenant->id, 'send')->first();
        expect($row->status)->toBe('accepted');

        return true;
    });
    expect(($this->send)($review['id']))->toBe($result);
    expect(app(WholesaleOutreachDigest::class)->snapshot($this->tenant)['rows'])->toBe([]);
    expect(Order::query()->count())->toBe(1);
    expect($this->journal->rows($this->tenant->id, 'send')->count())->toBe(1);
    expect(TeamOperationRecord::query()->whereKey($this->connection->id)->toBase()->value('data'))->not->toContain('access-secret', 'refresh-secret');
    Http::assertSentCount(4); // identity, alias, source catalog, and one Gmail send
});

test('two reviewed drafts for one order share one send claim even when copy differs', function (): void {
    $a = ($this->review)();
    $b = $this->sender->review($this->tenant->id, $this->actor->id, $this->shop, $this->account, 'Edited subject', 'Different reviewed words.');
    ($this->send)($a['id']);
    expect(($this->send)($b['id'])['message_id'])->toBe('message123');
    expect($this->journal->rows($this->tenant->id, 'send')->count())->toBe(1);
});

test('an ambiguous provider result is retained and cannot create a replacement send', function (): void {
    $this->loseResponse = true;
    $review = ($this->review)();
    expect(($this->send)($review['id'])['status'])->toBe('uncertain');
    expect(($this->send)($review['id'])['status'])->toBe('uncertain');
    expect($this->sendAttempts)->toBe(1);
    expect($this->journal->rows($this->tenant->id, 'send')->count())->toBe(1);
    $this->travel(30)->days();
    expect(app(WholesaleOutreachDigest::class)->snapshot($this->tenant)['rows'])->toBe([]);
});

test('new opt outs and changed recipient or order content stop an already reviewed send', function (): void {
    $review = ($this->review)();
    WholesaleProspect::query()->create(['tenant_id' => $this->tenant->id, 'public_id' => (string) \Illuminate\Support\Str::uuid(), 'business_name' => 'Example Shop', 'discovery_source' => 'manual', 'do_not_contact' => true, 'public_business_email' => 'buyer@example.test']);
    expect(fn () => ($this->send)($review['id']))->toThrow(DomainException::class);
    Http::assertNotSent(fn ($r) => $r->url() === WholesaleGmailSender::SEND);
});

test('expired review mailbox changes paused sending and revoked staff prevent any send', function (): void {
    $review = ($this->review)();
    $this->travel(16)->minutes();
    expect(fn () => ($this->send)($review['id']))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    $this->travelBack();
    $this->gmail->disconnect($this->tenant->id, $this->actor->id, $this->shop);
    expect(fn () => ($this->send)($review['id']))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    config(['wholesale_gmail.send_enabled' => false]);
    expect(fn () => ($this->send)($review['id']))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    $this->actor->update(['is_active' => false]);
    expect(fn () => ($this->send)($review['id']))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    Http::assertNotSent(fn ($r) => $r->url() === WholesaleGmailSender::SEND);
});

test('wrong Google account or unverified From alias cannot silently substitute the primary Gmail sender', function (): void {
    $review = ($this->review)();
    $this->googleEmail = 'other@example.test';
    expect(fn () => ($this->send)($review['id']))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    $this->googleEmail = 'owner@example.test';
    $this->aliasStatus = 'pending';
    expect(fn () => ($this->send)($review['id']))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    Http::assertNotSent(fn ($r) => $r->url() === WholesaleGmailSender::SEND);
});

test('fresh Shopify authentication tenant membership and operator role protect native endpoints', function (): void {
    $url = route('shopify.app.wholesale.gmail.status');
    $this->postJson($url)->assertUnauthorized();
    $this->withHeader('Authorization', 'Bearer '.wholesaleShopifySessionToken())->postJson($url)->assertOk()->assertJsonPath('can_send', true)->assertDontSee('access-secret');
    $this->actor->update(['role' => 'employee']);
    $this->postJson($url)->assertForbidden();
    $this->actor->update(['role' => 'admin']);
    $this->actor->tenants()->detach();
    $this->postJson($url)->assertForbidden();
});

test('retail sessions and another tenants review cannot select the wholesale sender', function (): void {
    $review = ($this->review)();
    config(['services.shopify.stores.retail.shop' => 'modernforestry.myshopify.com', 'services.shopify.stores.retail.client_id' => 'retail-client', 'services.shopify.stores.retail.client_secret' => 'retail-secret']);
    $this->withHeader('Authorization', 'Bearer '.retailShopifySessionToken())->postJson(route('shopify.app.wholesale.gmail.status'))->assertForbidden();
    $other = Tenant::query()->create(['name' => 'Other', 'slug' => 'other']);
    expect(fn () => $this->sender->send($other->id, $this->actor->id, $this->shop, $review['id']))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    Http::assertNotSent(fn ($r) => $r->url() === WholesaleGmailSender::SEND);
});

test('OAuth uses a single use browser bound PKCE handoff and refuses callback replay', function (): void {
    $url = $this->gmail->begin($this->tenant->id, $this->actor->id, $this->shop);
    parse_str(parse_url($url, PHP_URL_QUERY), $query);
    $id = basename(parse_url($url, PHP_URL_PATH));
    $response = $this->gmail->launch(Request::create($url), $id);
    parse_str(parse_url($response->getTargetUrl(), PHP_URL_QUERY), $oauth);
    expect($oauth)->toMatchArray(['code_challenge_method' => 'S256', 'include_granted_scopes' => 'false', 'prompt' => 'consent select_account']);
    $state = $this->journal->rows($this->tenant->id, 'state')->findOrFail($id);
    expect($oauth['code_challenge'])->toBe(WholesaleGmailConnection::encode(hash('sha256', $state->data['verifier'], true)));
    expect(fn () => $this->gmail->launch(Request::create($url), $id))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    $request = Request::create('https://backstage.example.test/wholesale/gmail/callback', 'GET', ['state' => $oauth['state'], 'code' => 'fictional-code'], [$this->gmail->cookieName($id) => $state->data['cookie']]);
    $this->gmail->callback($request);
    expect($this->gmail->status($this->tenant->id, $this->shop)['connected'])->toBeTrue();
    expect(fn () => $this->gmail->callback($request))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    expect($state->fresh()->data)->not->toHaveKeys(['verifier', 'cookie', 'oauth_state']);
});

test('OAuth rejects the wrong browser wrong callback and superseded consent without exchanging a code', function (): void {
    $url = $this->gmail->begin($this->tenant->id, $this->actor->id, $this->shop);
    $id = basename(parse_url($url, PHP_URL_PATH));
    $r = $this->gmail->launch(Request::create($url), $id);
    parse_str(parse_url($r->getTargetUrl(), PHP_URL_QUERY), $oauth);
    $request = Request::create('https://backstage.example.test/wholesale/gmail/callback', 'GET', ['state' => $oauth['state'], 'code' => 'fictional-code']);
    expect(fn () => $this->gmail->callback($request))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    $this->gmail->begin($this->tenant->id, $this->actor->id, $this->shop);
    expect(fn () => $this->gmail->callback($request))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    config(['wholesale_gmail.redirect_uri' => 'https://other.example.test/callback']);
    expect($this->gmail->status($this->tenant->id, $this->shop)['configured'])->toBeFalse();
    Http::assertNotSent(fn ($r) => $r->url() === WholesaleGmailSender::SEND);
});

test('expired token refresh keeps the reviewed generation while verifying Google identity again', function (): void {
    $review = ($this->review)();
    $this->connection->update(['data' => [...$this->connection->data, 'expires' => now()->subMinute()->timestamp]]);
    expect(($this->send)($review['id'])['status'])->toBe('accepted');
    expect($this->connection->fresh()->data['generation'])->toBe('generation-1');
    Http::assertSent(fn ($r) => $r->url() === WholesaleGmailConnection::TOKEN && $r['grant_type'] === 'refresh_token');
});

test('address level unsubscribes apply even when the wholesale prospect ledger has no match', function (): void {
    $review = ($this->review)();
    \App\Models\MessagingContactChannelState::query()->create(['tenant_id' => $this->tenant->id, 'email' => 'buyer@example.test', 'email_status' => 'unsubscribed']);
    expect(fn () => ($this->send)($review['id']))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    expect($this->sendAttempts)->toBe(0);
});

test('changed source order facts force a fresh preview before Gmail can receive a message', function (): void {
    $review = ($this->review)();
    $this->order->update(['customer_email' => 'different-buyer@example.test']);
    expect(fn () => ($this->send)($review['id']))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    expect($this->sendAttempts)->toBe(0);
});

test('the native review shows a mailing address opt out and escaped edits without a provider send', function (): void {
    $review = $this->sender->review($this->tenant->id, $this->actor->id, $this->shop, $this->account, 'A personal note', '<script>bad()</script>');
    expect($review['html'])->toContain('123 Example Lane', '/email/unsubscribe?', '&lt;script&gt;')->not->toContain('<script>bad()');
    $page = $this->get(route('shopify.app.wholesale.customers.outreach', [...wholesaleEmbeddedSignedQuery(), 'accountKey' => $this->account]))->assertOk()
        ->assertSeeText('Send from Backstage')->assertSeeText('Review and send')->assertSee('sandbox=""', false);
    if ($file = getenv('WHOLESALE_GMAIL_BROWSER_FIXTURE')) {
        $browserReview = $this->sender->review($this->tenant->id, $this->actor->id, $this->shop, $this->account, 'A personal note', "Hello Robin.\n\nHow has your last selection been selling?");
        file_put_contents($file, json_encode(['html' => $page->getContent(), 'review' => $browserReview], JSON_THROW_ON_ERROR));
    }
    expect($this->sendAttempts)->toBe(0);
});

test('a competing confirmation cannot start HTTP while the original intent is in flight', function (): void {
    $first = ($this->review)();
    $this->nestedReview = ($this->review)()['id'];
    expect(($this->send)($first['id'])['status'])->toBe('accepted');
    expect($this->sendAttempts)->toBe(1);
});

test('unapproved OAuth scopes fail closed and a stuck refresh can be disconnected while sends are disabled', function (): void {
    $review = ($this->review)();
    $this->scopes = implode(' ', WholesaleGmailConnection::SCOPES).' https://www.googleapis.com/auth/gmail.readonly';
    $this->connection->update(['data' => [...$this->connection->data, 'expires' => now()->subMinute()->timestamp]]);
    expect(fn () => ($this->send)($review['id']))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    expect($this->sendAttempts)->toBe(0);
    $this->connection->refresh()->update(['status' => 'refreshing']);
    config(['wholesale_gmail.connection_enabled' => false, 'wholesale_gmail.send_enabled' => false]);
    expect($this->gmail->status($this->tenant->id, $this->shop)['can_disconnect'])->toBeTrue();
    $this->gmail->disconnect($this->tenant->id, $this->actor->id, $this->shop);
    expect($this->connection->fresh()->status)->toBe('disconnected');
});

test('internal acceptance can send only to the configured team inbox while customer sending is disabled', function (): void {
    config(['wholesale_gmail.send_enabled' => false, 'wholesale_gmail.test_enabled' => true,
        'wholesale_gmail.tenants.'.$this->tenant->id.'.test_recipient' => 'team@example.test']);
    $c = $this->gmail->settings($this->tenant->id, $this->shop);
    $this->connection->update(['data' => [...$this->connection->data, 'fingerprint' => $c['fingerprint']]]);
    $this->internalMode = 'test';
    $review = ($this->review)();
    expect($review)->toMatchArray(['can_send' => false, 'can_test' => true, 'test_recipient' => 'team@example.test']);
    expect(fn () => ($this->send)($review['id']))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    $result = $this->sender->send($this->tenant->id, $this->actor->id, $this->shop, $review['id'], true);
    expect($result)->toMatchArray(['status' => 'accepted', 'internal_test' => true, 'to' => 'team@example.test']);
    Http::assertSent(function ($r) {
        if ($r->url() !== WholesaleGmailSender::SEND) {
            return false;
        }
        $raw = base64_decode(strtr($r['raw'], '-_', '+/'));
        expect($raw)->toContain('To: team@example.test', 'From: Example Candles <info@example.test>')->not->toContain('To: buyer@example.test');

        return true;
    });
    expect(app(WholesaleOutreachDigest::class)->snapshot($this->tenant)['rows'])->toHaveCount(1);
    expect($this->journal->rows($this->tenant->id, 'send')->count())->toBe(0);
});

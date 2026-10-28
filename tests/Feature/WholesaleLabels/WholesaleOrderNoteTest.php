<?php

require_once dirname(__DIR__).'/ShopifyEmbeddedTestHelpers.php';

use App\Models\Tenant;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $tenant = Tenant::query()->create(['name' => 'Fictional order-note test', 'slug' => 'fictional-order-note-test']);
    configureEmbeddedWholesaleStore($tenant->id);
    config(['services.shopify.stores.wholesale.client_secret' => 'fictional-wholesale-client-secret-for-tests']);
    config(['wholesale_labels.order_notes_enabled' => true, 'services.shopify.stores.wholesale.embedded_client_id' => null, 'services.shopify.stores.wholesale.embedded_client_secret' => null]);
});
function buyerNoteToken(array $claims = [], string $secret = 'fictional-wholesale-client-secret-for-tests'): string
{
    return \Firebase\JWT\JWT::encode($claims + ['dest' => 's2vscq-rf.myshopify.com', 'aud' => 'wholesale-client-id', 'sub' => 'gid://shopify/Customer/101', 'exp' => time() + 300, 'nbf' => time() - 1, 'iat' => time(), 'jti' => 'fictional-note-request'], $secret, 'HS256');
}
it('allows CORS preflight but requires a verified signed-in buyer to write', function () {
    $this->options('/shopify/wholesale/order-note', [], ['Origin' => 'https://extensions.shopifycdn.com'])->assertNoContent()->assertHeader('Access-Control-Allow-Origin', '*');
    $this->postJson('/shopify/wholesale/order-note', ['order_id' => 'gid://shopify/Order/9001', 'note' => 'Test'])->assertUnauthorized();
    $this->withToken(buyerNoteToken([], 'forged-wholesale-client-secret-for-tests'))->postJson('/shopify/wholesale/order-note', [])->assertUnauthorized();
});
it('checks ownership with Shopify and does not trust a customer supplied in the body', function () {
    Http::fake(['*' => Http::response(['data' => ['order' => ['id' => 'gid://shopify/Order/9001', 'customer' => ['id' => 'gid://shopify/Customer/202'], 'note' => 'Private', 'cancelledAt' => null, 'createdAt' => now()->toIso8601String(), 'buyerNote' => null]]])]);
    $this->withHeader('Origin', 'https://extensions.shopifycdn.com')->withToken(buyerNoteToken())->postJson('/shopify/wholesale/order-note', ['order_id' => 'gid://shopify/Order/9001', 'customer_id' => '202', 'note' => 'Test'])->assertNotFound()->assertHeader('Access-Control-Allow-Origin', '*');
    Http::assertSentCount(1);
});
it('preserves existing staff notes and safely deduplicates a retry', function (string $orderId) {
    $saved = false;
    $calls = 0;
    Http::fake(function ($request) use (&$saved, &$calls) {
        $calls++;
        expect($request['variables']['id'] ?? $request['variables']['input']['id'])->toBe('gid://shopify/Order/9001');
        if (str_starts_with($request['query'], 'mutation')) {
            expect($request['variables']['input']['note'])->toBe("Existing staff instructions\n\n[Wholesale order request]\nPlease include a display card.");
            $saved = true;

            return Http::response(['data' => ['orderUpdate' => ['order' => ['id' => 'gid://shopify/Order/9001'], 'userErrors' => []]]]);
        }

        return Http::response(['data' => ['order' => ['id' => 'gid://shopify/Order/9001', 'customer' => ['id' => 'gid://shopify/Customer/101'], 'note' => 'Existing staff instructions', 'cancelledAt' => null, 'createdAt' => now()->toIso8601String(), 'buyerNote' => $saved ? ['value' => 'Please include a display card.'] : null]]]);
    });
    $data = ['order_id' => $orderId, 'note' => 'Please include a display card.'];
    $this->withToken(buyerNoteToken())->postJson('/shopify/wholesale/order-note', $data)->assertOk()->assertJsonPath('ok', true);
    $this->postJson('/shopify/wholesale/order-note', $data)->assertOk();
    expect($calls)->toBe(3);
})->with(['gid://shopify/Order/9001', 'gid://shopify/OrderIdentity/9001']);
it('retains buyer text through recoverable order creation delays and rejects old requests', function () {
    Http::fake(['*' => Http::response(['data' => ['order' => null]])]);
    $this->withToken(buyerNoteToken())->postJson('/shopify/wholesale/order-note', ['order_id' => 'gid://shopify/Order/9001', 'note' => 'A saved browser draft'])->assertStatus(409)->assertHeader('Retry-After', '3');
    $this->withToken(buyerNoteToken(['exp' => time() - 30]))->postJson('/shopify/wholesale/order-note', [])->assertUnauthorized();
});

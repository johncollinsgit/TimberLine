<?php

use App\Models\SubscriptionBillingAction;
use App\Models\SubscriptionBillingCycle;
use App\Models\User;
use App\Services\Subscriptions\SubscriptionShopifyMethodService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\Subscriptions\ShopifyReuseScenario;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2027-01-15T15:00:00Z'));
    $this->scenario = new ShopifyReuseScenario;
});

test('original Shop Pay mapping can be previewed without storing proof or creating contracts or charges', function () {
    $s = $this->scenario;
    $proof = app(SubscriptionShopifyMethodService::class)->verify($s->tenant->id, $s->actor, $s->agreement->id, '111', false);
    expect($proof)->toMatchArray(['method_type' => 'shop_pay', 'last_four' => '4242', 'contract_adopted' => false, 'collection_enabled' => false,
        'original_method_accessible' => true, 'reauthorization_required' => null])
        ->and($s->agreement->fresh()->method_verified_at)->toBeNull()
        ->and(SubscriptionBillingAction::where('kind', 'method_verified')->count())->toBe(0)
        ->and(SubscriptionBillingCycle::count())->toBe(0)
        ->and(\App\Models\SubscriptionPilotMember::findOrFail($s->agreement->member_id)->billing_owner)->toBe('recharge');
    Http::assertSentCount(7);
});

test('exact original Shopify card proof stores masked evidence and leaves Recharge primary', function () {
    $s = $this->scenario;
    $s->card = true;
    app(SubscriptionShopifyMethodService::class)->verify($s->tenant->id, $s->actor, $s->agreement->id, '111');
    $row = $s->agreement->fresh();
    expect($row->method_verified_at)->not->toBeNull()
        ->and(data_get($row->authorization_evidence, 'provider_proof.method_type'))->toBe('card')
        ->and(json_encode($row->authorization_evidence))->not->toContain('original_method', 'ORIGINAL_SUBSCRIPTION_TOKEN', 'ORIGINAL_RECHARGE_TOKEN')
        ->and($row->status)->toBe('prepared')->and($row->activated_at)->toBeNull()
        ->and(\App\Models\SubscriptionPilotMember::findOrFail($row->member_id)->billing_owner)->toBe('recharge')->and($row->terms['candle_cash_allowed'])->toBeFalse()
        ->and(SubscriptionBillingCycle::count())->toBe(0);
});

test('Shopify saved method verification rejects changed source identity ownership economics or permissions', function (string $defect) {
    $s = $this->scenario;
    $s->defect = $defect;
    expect(fn () => app(SubscriptionShopifyMethodService::class)->verify($s->tenant->id, $s->actor, $s->agreement->id, '111'))
        ->toThrow($defect === 'rotated' ? \Illuminate\Database\Eloquent\ModelNotFoundException::class : HttpException::class);
    expect($s->agreement->fresh()->method_verified_at)->toBeNull()->and(SubscriptionBillingCycle::count())->toBe(0);
})->with(['customer', 'revoked', 'inactive', 'expired', 'scope', 'app', 'production_store', 'refunded_order', 'rotated', 'quantity', 'price', 'date', 'address_method', 'token']);

test('source permissions and rollout fail before requests and unrelated owners cannot inspect methods', function () {
    $s = $this->scenario;
    $stranger = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
    expect(fn () => app(SubscriptionShopifyMethodService::class)->verify($s->tenant->id, $stranger, $s->agreement->id, '111'))->toThrow(HttpException::class);
    $s->sourceRevision->update(['scopes' => ['read_store']]);
    expect(fn () => app(SubscriptionShopifyMethodService::class)->verify($s->tenant->id, $s->actor, $s->agreement->id, '111'))->toThrow(HttpException::class);
    Http::assertNothingSent();
});

test('Shopify mapping summary is offline and masked', function () {
    $s = $this->scenario;
    $this->artisan('subscriptions:shopify', ['action' => 'summary', '--tenant' => $s->tenant->slug, '--actor' => $s->actor->id, '--agreement' => $s->agreement->id])
        ->expectsOutputToContain('"provider_requests": false')->assertExitCode(0);
    Http::assertNothingSent();
});

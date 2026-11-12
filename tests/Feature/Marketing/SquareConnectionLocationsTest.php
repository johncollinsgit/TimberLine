<?php

use App\Models\SquarePayment;
use App\Models\Tenant;
use App\Models\TenantMarketingSetting;
use App\Models\User;
use App\Services\Marketing\SquareConnectionContext;
use App\Services\Marketing\SquareSourceRefresh;
use App\Services\Pos\PosStore;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function (): void {
    $this->withoutVite();
    Http::preventStrayRequests();
    config(['pos_live.enabled' => false, 'pos_live.runtime_id' => 'source_locations_fixture',
        'marketing.square.enabled' => false, 'marketing.square.environment' => 'sandbox', 'marketing.square.access_token' => 'fixture-legacy-reader']);
    $this->tenant = Tenant::create(['name' => 'Fictional location merchant', 'slug' => 'source-locations-'.Str::lower(Str::random(8))]);
    $this->owner = User::factory()->create(['role' => 'member', 'is_active' => true, 'email_verified_at' => now()]);
    $this->owner->tenants()->attach($this->tenant, ['role' => 'owner', 'membership_active' => true]);
    $this->store = app(PosStore::class);
    $this->first = str_repeat('a', 32);
    $this->primary = str_repeat('z', 32);
    foreach ([$this->first => ['FIRSTLOCATION', 'Downtown booth'], $this->primary => ['PRIMARYLOCATION', 'Weekend event']] as $id => [$location, $label]) {
        $this->store->rows('connections', $this->tenant->id)->insert($this->store->values($this->tenant->id, ['id' => $id,
            'merchant_id' => 'LOCATIONMERCHANT', 'environment' => 'sandbox', 'provider_location_id' => $location,
            'credentials' => $this->store->encrypt(['access_token' => 'fictional-native-'.$id, 'expires_at' => now()->addDay()->toIso8601String()]), 'active' => true]));
        $this->store->rows('registers', $this->tenant->id)->insert($this->store->values($this->tenant->id, ['id' => bin2hex(random_bytes(16)),
            'name' => $label, 'location_id' => 'native-'.$location, 'location_name' => $label, 'connection_id' => $id, 'catalog_source' => 'native',
            'configuration' => $this->store->encrypt([]), 'active' => true]));
    }
});

it('lists every original location reader without merging locations and labels the owner controls', function (): void {
    $rows = collect(app(SquareConnectionContext::class)->available($this->tenant->id));
    $legacy = $rows->where('kind', 'original_merchant_verified_legacy_reader')->keyBy('id');
    expect($legacy)->toHaveCount(2)
        ->and($legacy['legacy:'.$this->first]['location_ids'])->toBe(['FIRSTLOCATION'])
        ->and($legacy['legacy:'.$this->primary]['location_ids'])->toBe(['PRIMARYLOCATION'])
        ->and($legacy['legacy:'.$this->primary]['location_label'])->toBe('Weekend event · PRIMARYLOCATION');
    $this->actingAs($this->owner)->get('/workspace/customers/square?tenant='.$this->tenant->slug)->assertOk()
        ->assertSee('Downtown booth · FIRSTLOCATION')->assertSee('Weekend event · PRIMARYLOCATION')
        ->assertSee('legacy:'.$this->first)->assertSee('legacy:'.$this->primary);
    Http::assertNothingSent();
});

it('keeps a later location policy valid and replays only its exact original payment', function (): void {
    $rewardsBefore = \App\Models\CandleCashTransaction::count();
    Http::fake(function ($request) {
        expect($request->hasHeader('Authorization', 'Bearer fixture-legacy-reader'))->toBeTrue();

        return Http::response(match (parse_url($request->url(), PHP_URL_PATH)) {
            '/v2/merchants/LOCATIONMERCHANT' => ['merchant' => ['id' => 'LOCATIONMERCHANT']],
            '/v2/payments/LOCATIONPAYMENT' => ['payment' => ['id' => 'LOCATIONPAYMENT', 'location_id' => 'PRIMARYLOCATION',
                'created_at' => now()->subHour()->toIso8601String(), 'updated_at' => now()->subHour()->toIso8601String(), 'status' => 'COMPLETED',
                'amount_money' => ['amount' => 1080, 'currency' => 'USD'], 'refunded_money' => ['amount' => 0, 'currency' => 'USD']]],
            default => throw new LogicException('Unexpected original location read'),
        });
    });
    $service = app(SquareSourceRefresh::class);
    $policy = $service->configure($this->tenant->id, $this->owner, ['enabled' => true, 'expected_version' => $service->status($this->tenant->id)['version'],
        'connection_id' => 'legacy:'.$this->primary, 'lookback_days' => 14, 'create_unmatched' => false]);
    expect($service->assertPolicyCurrent($this->tenant->id, $policy['version'])['binding']['location_ids'])->toBe(['PRIMARYLOCATION'])
        ->and($service->webhookTargets('LOCATIONMERCHANT', 'sandbox', 'FIRSTLOCATION'))->toBe([]);
    $target = $service->webhookTargets('LOCATIONMERCHANT', 'sandbox', 'PRIMARYLOCATION')[0];
    $uuid = (string) Str::uuid();
    expect($service->refreshObject($this->tenant->id, $target['binding'], 'payment', 'LOCATIONPAYMENT', $uuid)['status'])->toBe('completed');
    $calls = Http::recorded()->count();
    expect($service->refreshObject($this->tenant->id, $target['binding'], 'payment', 'LOCATIONPAYMENT', $uuid)['replayed'])->toBeTrue()
        ->and(SquarePayment::forTenantId($this->tenant->id)->sole()->location_id)->toBe('PRIMARYLOCATION')
        ->and(\App\Models\CandleCashTransaction::count())->toBe($rewardsBefore);
    Http::assertSentCount($calls);
    $wrong = $target['binding'];
    $wrong['location_ids'] = ['FIRSTLOCATION'];
    expect(fn () => $service->refreshObject($this->tenant->id, $wrong, 'payment', 'LOCATIONPAYMENT', $uuid))->toThrow(HttpException::class);
    Http::assertSentCount($calls);
    $this->store->rows('connections', $this->tenant->id)->where('id', $this->primary)->update(['provider_location_id' => 'CHANGEDLOCATION']);
    expect($service->webhookTargets('LOCATIONMERCHANT', 'sandbox', 'PRIMARYLOCATION'))->toBe([]);
    Http::assertSentCount($calls);
});

it('preserves original tenant merchant environment and generation admission for every location', function (): void {
    $foreign = Tenant::create(['name' => 'Other location merchant', 'slug' => 'foreign-locations-'.Str::lower(Str::random(8))]);
    $foreignId = str_repeat('f', 32);
    $this->store->rows('connections', $foreign->id)->insert($this->store->values($foreign->id, ['id' => $foreignId,
        'merchant_id' => 'FOREIGNMERCHANT', 'environment' => 'sandbox', 'provider_location_id' => 'FOREIGNLOCATION',
        'credentials' => $this->store->encrypt(['access_token' => 'fictional-foreign-token', 'expires_at' => now()->addDay()->toIso8601String()]), 'active' => true]));
    $context = app(SquareConnectionContext::class);
    expect(array_column($context->available($this->tenant->id), 'id'))->not->toContain($foreignId, 'legacy:'.$foreignId);
    expect(fn () => $context->resolve($this->tenant->id, 'legacy:'.$foreignId))->toThrow(HttpException::class);
    Http::assertNothingSent();
    Http::fake(fn () => Http::response(['merchant' => ['id' => 'LOCATIONMERCHANT']]));
    expect(fn () => $context->resolve($foreign->id, 'legacy:'.$foreignId))->toThrow(HttpException::class);
    $binding = $context->publicBinding($context->resolve($this->tenant->id, 'legacy:'.$this->primary));
    $this->store->rows('connections', $this->tenant->id)->where('id', $this->primary)->update(['credentials' => $this->store->encrypt(['access_token' => 'changed-native-credential'])]);
    expect(fn () => $context->assertCurrent($this->tenant->id, $binding))->toThrow(HttpException::class)
        ->and(TenantMarketingSetting::where('tenant_id', $foreign->id)->where('key', 'square_source_refresh')->exists())->toBeFalse();
    config(['marketing.square.environment' => 'production']);
    expect(collect($context->available($this->tenant->id))->where('kind', 'original_merchant_verified_legacy_reader'))->toHaveCount(0);
});

<?php

use App\Models\MarketingProfile;
use App\Models\MarketingProfileLink;
use App\Models\SquareOrder;
use App\Models\SquarePayment;
use App\Models\TeamOperationRecord;
use App\Models\User;
use App\Services\Inventory\StockLedger;
use App\Services\Marketing\ConfirmedCustomerPurchaseProjection;
use App\Services\Marketing\MessagingContactChannelStateService;
use Illuminate\Http\Request;
use Illuminate\Routing\Route as MatchedRoute;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

require_once __DIR__.'/../../Support/TeamStudioFixtures.php';

function lifetimeHistoryQueries(): int
{
    return collect(DB::getQueryLog())->filter(function ($query) {
        $sql = str_replace(['"', '`'], '', $query['query']);

        return str_contains($sql, 'from square_orders') && str_contains($sql, 'order by closed_at desc, id desc');
    })->count();
}

function lifetimeCustomer($test): MarketingProfile
{
    $id = (string) Str::uuid();
    $profile = MarketingProfile::create(['tenant_id' => $test->tenant->id, 'first_name' => 'Fictional buyer', 'email' => $id.'@example.invalid',
        'normalized_email' => $id.'@example.invalid', 'accepts_email_marketing' => true]);
    $orderId = 'lifetime-order-'.$profile->id;
    SquareOrder::create(['tenant_id' => $test->tenant->id, 'square_order_id' => $orderId, 'state' => 'COMPLETED', 'closed_at' => now()->subHour(),
        'location_id' => 'lifetime-location', 'total_money_amount' => 1080, 'total_money_currency' => 'USD', 'raw_payload' => ['total_tax_money' => ['amount' => 80, 'currency' => 'USD']]]);
    SquarePayment::create(['tenant_id' => $test->tenant->id, 'square_payment_id' => 'payment-'.$orderId, 'square_order_id' => $orderId,
        'status' => 'COMPLETED', 'amount_money' => 1080, 'currency' => 'USD', 'location_id' => 'lifetime-location', 'source_type' => 'CARD', 'raw_payload' => []]);
    MarketingProfileLink::create(['tenant_id' => $test->tenant->id, 'marketing_profile_id' => $profile->id, 'source_type' => 'square_order', 'source_id' => $orderId]);
    MarketingProfileLink::create(['tenant_id' => $test->tenant->id, 'marketing_profile_id' => $profile->id, 'source_type' => 'pos_event_capture',
        'source_id' => 'lifetime-capture-'.$profile->id, 'source_meta' => ['event_id' => 123, 'captured_at' => now()->subHour()->toIso8601String(), 'capture_evidence_version' => 1, 'record_origin' => 'selected']]);
    $data = $test->event->fresh()->data;
    $data['_teams_enrichment']['square_orders']['ids'][] = $orderId;
    $test->event->update(['data' => $data]);

    return $profile;
}

beforeEach(function (): void {
    $this->withoutVite();
    $this->freezeTime();
    Http::preventStrayRequests();
    config(['team_work.enabled' => true, 'team_work.premium_enabled' => true, 'team_operations.enabled' => true, 'pos_live.runtime_id' => 'projection_lifetime_fixture']);
    [$this->tenant, $this->owner] = teamWorkspace();
    $this->area = studioArea($this->tenant);
    $this->reviewer = User::factory()->create(['email' => 'lifetime-reviewer-'.Str::uuid().'@example.invalid', 'is_active' => true]);
    $this->tenant->users()->attach($this->reviewer->id, ['role' => 'admin', 'membership_active' => true]);
    app(StockLedger::class)->rows('event_links', $this->tenant->id)->insert(['tenant_id' => $this->tenant->id, 'event_id' => 123, 'provider' => 'manual', 'external_id' => 'lifetime-show',
        'metadata' => json_encode(['name' => 'Reviewed show', 'timezone' => 'UTC']), 'reviewed_by' => $this->owner->id]);
    $this->event = TeamOperationRecord::create(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'area_id' => $this->area->id,
        'kind' => 'event', 'title' => 'Reviewed show', 'source_key' => hash('sha256', Str::uuid()), 'status' => 'accepted', 'version' => 1,
        'data' => ['_teams_enrichment' => ['commerce_event' => ['id' => 123], 'square_orders' => ['ids' => [], 'reviewed_by' => $this->reviewer->id, 'reviewed_at' => now()->toIso8601String()]]]]);
    $this->buyer = lifetimeCustomer($this);
});

it('builds each original history once when shared cache expires between cohort admission and rule evaluation while suppression stays fresh', function (): void {
    for ($i = 1; $i < 12; $i++) {
        lifetimeCustomer($this);
    }
    $advanced = false;
    DB::listen(function ($query) use (&$advanced): void {
        $sql = str_replace(['"', '`'], '', $query->sql);
        if (! $advanced && str_starts_with($sql, 'select * from marketing_profiles') && str_contains($sql, 'id in (')
            && str_contains($sql, 'archived_at is null') && str_contains($sql, 'merged_into_profile_id is null')) {
            $advanced = true;
            $this->travel(6)->seconds();
            app(MessagingContactChannelStateService::class)->markEmailStatus($this->tenant->id, $this->buyer, $this->buyer->email, 'suppressed', 'fictional_source_review');
        }
    });
    DB::enableQueryLog();
    DB::flushQueryLog();
    $this->actingAs($this->owner)->postJson('/marketing/event-audiences/preview?tenant='.$this->tenant->slug,
        ['kind' => 'event_buyers', 'event_id' => 123, 'preview_id' => (string) Str::uuid()])->assertOk()
        ->assertJsonPath('matched', 12)->assertJsonPath('email_eligible', 11)->assertJsonPath('complete', true);
    $reads = lifetimeHistoryQueries();
    DB::disableQueryLog();
    $key = 'customer-confirmed-purchases-v1:'.hash('sha256', (string) config('pos_live.runtime_id')).':'.$this->tenant->id.':'.$this->buyer->id;
    expect($advanced)->toBeTrue()->and($reads)->toBe(12)->and(Cache::has($key))->toBeFalse();
    Http::assertNothingSent();
});

it('uses new request data after six seconds for occurrence changes source attribution and reviewer revocation', function (string $change): void {
    $url = '/marketing/event-audiences/preview?tenant='.$this->tenant->slug;
    $input = ['kind' => 'event_buyers', 'event_id' => 123];
    $this->actingAs($this->owner)->postJson($url, [...$input, 'preview_id' => (string) Str::uuid()])->assertOk()->assertJsonPath('matched', 1);
    if ($change === 'occurrence') {
        $this->event->update(['status' => 'withdrawn']);
    } elseif ($change === 'source_attribution') {
        $new = MarketingProfile::create(['tenant_id' => $this->tenant->id, 'first_name' => 'New explicitly confirmed buyer']);
        MarketingProfileLink::forTenantId($this->tenant->id)->where('source_type', 'square_order')->update(['marketing_profile_id' => $new->id]);
    } elseif ($change === 'pay_mirror_conflict') {
        $store = app(\App\Services\Pos\PosStore::class);
        $store->rows('sales', $this->tenant->id)->insert($store->values($this->tenant->id, ['id' => bin2hex(random_bytes(16)), 'register_id' => bin2hex(random_bytes(16)),
            'location_id' => 'lifetime-location', 'actor_id' => $this->owner->id, 'request_id' => (string) Str::uuid(), 'payment_request_id' => (string) Str::uuid(),
            'sdk_attempt_id' => (string) Str::uuid(), 'attempt_id' => bin2hex(random_bytes(16)), 'snapshot' => $store->encrypt([]),
            'snapshot_hash' => \App\Services\PaymentsCore\Contract::hash([]), 'amount_minor' => 1080, 'currency' => 'usd', 'commerce_source' => \App\Services\Pos\StockPosCommerce::VERSION,
            'provider_order_id' => 'lifetime-order-'.$this->buyer->id, 'state' => 'unknown', 'stock_state' => 'pending', 'created_at' => now(), 'expires_at' => now()->addHour()]));
    } else {
        DB::table('tenant_user')->where(['tenant_id' => $this->tenant->id, 'user_id' => $this->reviewer->id])->update(['membership_active' => false]);
    }
    $this->travel(6)->seconds();
    $fresh = $this->postJson($url, [...$input, 'preview_id' => (string) Str::uuid()])->assertOk()->assertJsonPath('complete', true);
    if ($change === 'source_attribution') {
        $fresh->assertJsonPath('matched', 1);
        expect(array_column($fresh->json('samples'), 'id'))->toBe([$new->id]);
    } else {
        $fresh->assertJsonPath('matched', 0);
    }
    Http::assertNothingSent();
})->with(['occurrence', 'source_attribution', 'reviewer_revocation', 'pay_mirror_conflict']);

it('freshly checks archive tenant and current canonical root before reading an existing request memo', function (): void {
    [$foreign] = teamWorkspace();
    $other = MarketingProfile::create(['tenant_id' => $this->tenant->id, 'first_name' => 'Current canonical survivor']);
    Route::get('/_fixture/projection-current-root', function () use ($foreign, $other) {
        $reader = app(ConfirmedCustomerPurchaseProjection::class);
        $first = $reader->read($this->buyer, $this->tenant->id);
        $statuses = [];
        $this->buyer->update(['archived_at' => now()]);
        try {
            $reader->read($this->buyer, $this->tenant->id);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $error) {
            $statuses['archived'] = $error->getStatusCode();
        }
        $this->buyer->update(['merged_into_profile_id' => $other->id]);
        $current = $reader->read($this->buyer, $this->tenant->id);
        try {
            $reader->read($this->buyer, $foreign->id);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $error) {
            $statuses['foreign'] = $error->getStatusCode();
        }

        return ['first' => $first['customer_id'], 'current' => $current['customer_id'], 'statuses' => $statuses];
    });
    $this->getJson('/_fixture/projection-current-root')->assertOk()->assertJsonPath('first', $this->buyer->id)
        ->assertJsonPath('current', $other->id)->assertJsonPath('statuses.archived', 404)->assertJsonPath('statuses.foreign', 404);
    Http::assertNothingSent();
});

it('does not retain a projection on console requests or fake unmatched route objects', function (string $context): void {
    $request = Request::create('/console-job', 'GET');
    if ($context === 'unmatched_route') {
        $request->setRouteResolver(fn () => new MatchedRoute('GET', '/different-uri', fn () => null));
    } elseif ($context === 'route_name') {
        $request->setRouteResolver(fn () => 'marketing.event-audiences.preview');
    }
    app()->instance('request', $request);
    $reader = app(ConfirmedCustomerPurchaseProjection::class);
    expect($reader->read($this->buyer, $this->tenant->id)['confirmed_orders'])->toBe(1);
    SquarePayment::forTenantId($this->tenant->id)->update(['status' => 'FAILED']);
    $this->travel(6)->seconds();
    expect($reader->read($this->buyer, $this->tenant->id)['confirmed_orders'])->toBe(0);
    Http::assertNothingSent();
})->with(['console', 'unmatched_route', 'route_name']);

it('separates runtime keys within an actual request without changing the shared cache lifetime', function (): void {
    Route::get('/_fixture/projection-runtime', function () {
        $reader = app(ConfirmedCustomerPurchaseProjection::class);
        $first = $reader->read($this->buyer, $this->tenant->id);
        $this->travel(6)->seconds();
        config(['pos_live.runtime_id' => 'different_lifetime_fixture']);
        $second = $reader->read($this->buyer, $this->tenant->id);

        return ['different_as_of' => $first['as_of'] !== $second['as_of'], 'same_current_customer' => $first['customer_id'] === $second['customer_id']];
    });
    $this->getJson('/_fixture/projection-runtime')->assertOk()->assertJsonPath('different_as_of', true)->assertJsonPath('same_current_customer', true);
    Http::assertNothingSent();
});

<?php

require_once dirname(__DIR__).'/ShopifyEmbeddedTestHelpers.php';

use App\Models\LandlordOperatorAction;
use App\Models\Order;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WholesaleFollowUp;
use App\Services\Wholesale\WholesaleOperationsService;
use App\Services\Wholesale\WholesaleOutreachDigest;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $this->withoutVite();
    $this->tenant = Tenant::query()->create(['name' => 'Fictional Wholesale', 'slug' => 'modern-forestry']);
    configureEmbeddedWholesaleStore((int) $this->tenant->id);
    configureWholesaleReportingFixture();
    $this->actor = User::factory()->create(['email' => 'followup-operator@example.com', 'role' => 'admin', 'is_active' => true]);
    $this->actor->tenants()->attach($this->tenant->id, ['role' => 'admin']);
    Order::factory()->create([
        'tenant_id' => $this->tenant->id, 'shopify_store_key' => 'wholesale', 'source' => 'shopify_wholesale', 'order_type' => 'wholesale',
        'shopify_customer_id' => 'fixture-buyer', 'customer_name' => 'Fictional Buyer', 'customer_email' => 'buyer@example.com',
        'total_price' => 250, 'refund_total' => 0, 'ordered_at' => now()->subDays(130),
    ]);
    $customer = app(WholesaleOperationsService::class)->customers((int) $this->tenant->id)->first();
    $this->followUp = WholesaleFollowUp::query()->create([
        'tenant_id' => $this->tenant->id, 'public_id' => (string) Str::uuid(), 'target_type' => 'customer',
        'target_key' => $customer['public_key'], 'follow_up_type' => 'sales_review', 'title' => 'Reviewed check-in',
        'status' => 'open', 'due_at' => now()->subDay(), 'created_by_user_id' => $this->actor->id,
    ]);
    $this->headers = [
        'Authorization' => 'Bearer '.wholesaleShopifySessionToken(['email' => $this->actor->email]),
        'Accept' => 'application/json',
    ];
});

test('operators can complete follow ups once and the outreach list observes the completion', function (): void {
    expect(app(WholesaleOutreachDigest::class)->snapshot($this->tenant)['rows'])->toHaveCount(1);
    $this->get(route('shopify.app.wholesale.follow-ups', wholesaleEmbeddedSignedQuery()))
        ->assertOk()->assertSeeText('Mark completed')->assertSee('data-wholesale-session-token', false);
    $url = route('shopify.app.wholesale.follow-ups.update', ['followUpPublicId' => $this->followUp->public_id]);
    $this->withHeaders($this->headers)->post($url, ['action' => 'complete'])->assertOk()->assertJsonPath('ok', true);
    $completedAt = $this->followUp->fresh()->completed_at->toIso8601String();
    $this->travel(1)->minutes();
    $this->headers['Authorization'] = 'Bearer '.wholesaleShopifySessionToken(['email' => $this->actor->email]);
    $this->withHeaders($this->headers)->post($url, ['action' => 'complete'])->assertOk();
    expect($this->followUp->fresh()->status)->toBe('completed')
        ->and($this->followUp->fresh()->completed_at->toIso8601String())->toBe($completedAt)
        ->and(LandlordOperatorAction::query()->where('action_type', 'wholesale.follow_up.complete')->count())->toBe(1)
        ->and(app(WholesaleOutreachDigest::class)->snapshot($this->tenant)['rows'])->toBe([]);
});

test('rescheduling uses the chosen Eastern date and suppresses outreach until it is due', function (int $month): void {
    $date = CarbonImmutable::create(now('America/New_York')->year + 1, $month, 15, 9, 30, 0, 'America/New_York');
    $this->withHeaders($this->headers)->post(route('shopify.app.wholesale.follow-ups.update', ['followUpPublicId' => $this->followUp->public_id]), [
        'action' => 'reschedule', 'due_local_at' => $date->format('Y-m-d\TH:i'),
    ])->assertOk();
    expect($this->followUp->fresh()->due_at->utc()->toIso8601String())->toBe($date->utc()->toIso8601String())
        ->and(app(WholesaleOutreachDigest::class)->snapshot($this->tenant)['rows'])->toBe([]);
})->with(['winter' => [1], 'summer' => [7]]);

test('rescheduling rejects missing and past dates without changing the follow up', function (?string $date): void {
    $before = $this->followUp->due_at->toIso8601String();
    $this->withHeaders($this->headers)->post(route('shopify.app.wholesale.follow-ups.update', ['followUpPublicId' => $this->followUp->public_id]), [
        'action' => 'reschedule', 'due_local_at' => $date,
    ])->assertUnprocessable();
    expect($this->followUp->fresh()->due_at->toIso8601String())->toBe($before)
        ->and(LandlordOperatorAction::query()->where('action_type', 'wholesale.follow_up.reschedule')->count())->toBe(0);
})->with(['missing' => [null], 'past' => ['2020-01-15T09:30']]);

test('follow up updates cannot cross tenant or retail installation boundaries', function (): void {
    $other = Tenant::query()->create(['name' => 'Other workspace', 'slug' => 'other-workspace']);
    $this->followUp->update(['tenant_id' => $other->id]);
    $url = route('shopify.app.wholesale.follow-ups.update', ['followUpPublicId' => $this->followUp->public_id]);
    $this->withHeaders($this->headers)->post($url, ['action' => 'complete'])->assertNotFound();
    configureEmbeddedRetailStore((int) $this->tenant->id);
    $this->withHeaders([
        'Authorization' => 'Bearer '.retailShopifySessionToken(['email' => $this->actor->email]),
        'Accept' => 'application/json',
    ])->post($url, ['action' => 'complete'])->assertForbidden();
    expect($this->followUp->fresh()->status)->toBe('open');
});

test('completed follow up history cannot be silently reopened by rescheduling', function (): void {
    $this->followUp->update(['status' => 'completed', 'completed_at' => now()]);
    $this->withHeaders($this->headers)->post(route('shopify.app.wholesale.follow-ups.update', ['followUpPublicId' => $this->followUp->public_id]), [
        'action' => 'reschedule', 'due_local_at' => now()->addMonth()->format('Y-m-d\TH:i'),
    ])->assertUnprocessable();
    expect($this->followUp->fresh()->status)->toBe('completed');
});

test('a verified Shopify user without operator authority cannot update follow ups', function (): void {
    $this->actor->update(['role' => 'employee']);
    $this->withHeaders($this->headers)->post(route('shopify.app.wholesale.follow-ups.update', ['followUpPublicId' => $this->followUp->public_id]), [
        'action' => 'complete',
    ])->assertForbidden();
    expect($this->followUp->fresh()->status)->toBe('open');
});

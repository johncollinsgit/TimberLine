<?php

use App\Services\Inventory\StockEventProduction;
use App\Services\Inventory\StockException;
use App\Services\Inventory\StockLedger;

uses(Tests\Support\PosLiveTestCase::class);

beforeEach(function (): void {
    config()->set('pos_live.runtime_id', 'production_fixture_runtime');
    $this->tenant = \App\Models\Tenant::create(['name' => 'Fictional maker', 'slug' => 'production-'.bin2hex(random_bytes(5)), 'status' => 'active']);
    $this->event = \App\Models\Event::create(['name' => 'Fictional future event', 'year' => 2026, 'status' => 'planned']);
    $this->order = \App\Models\Order::create(['tenant_id' => $this->tenant->id, 'order_number' => 'TEST-PRODUCTION-'.bin2hex(random_bytes(6)),
        'event_id' => $this->event->id, 'order_type' => 'event', 'source' => 'internal', 'status' => 'submitted_to_pouring']);
    $this->line = \App\Models\OrderLine::create(['order_id' => $this->order->id, 'sku' => 'TEST-MADE-ITEM', 'ordered_qty' => 10, 'quantity' => 10, 'extra_qty' => 0, 'wick_type' => 'cotton']);
    $this->stock = app(StockLedger::class);
    $this->production = app(StockEventProduction::class);
    $this->item = bin2hex(random_bytes(16));
    $this->stock->rows('items', $this->tenant->id)->insert(['id' => $this->item, 'tenant_id' => $this->tenant->id,
        'sku' => 'TEST-MADE-ITEM', 'title' => 'Fictional finished item', 'product_key' => 'fixture', 'revision' => str_repeat('a', 64)]);
    $this->pool = $this->stock->createPool($this->tenant->id, 'show', 1, 'Fictional event stock', 'show', 'event:'.$this->event->id)['pool_id'];
    $this->source = $this->production->source($this->tenant->id, $this->line->id);
    $this->production->map($this->tenant->id, 'review', 1, $this->line->id, $this->item, $this->source['revision']);
});

it('keeps published and completed production plans separate from confirmed sellable event stock', function (): void {
    expect($this->stock->listing($this->tenant->id, $this->pool)[0]['available_quantity'])->toBe(0);
    $this->order->update(['status' => 'brought_down']);
    $this->source = $this->production->source($this->tenant->id, $this->line->id);
    expect($this->stock->listing($this->tenant->id, $this->pool)[0]['available_quantity'])->toBe(0);
    $input = [$this->tenant->id, 'actually-ready', 1, $this->line->id, $this->pool, 4, $this->source['revision'], true];
    $this->production->confirm(...$input);
    $this->production->confirm(...$input);
    expect($this->stock->listing($this->tenant->id, $this->pool)[0]['available_quantity'])->toBe(4)
        ->and($this->production->demand($this->tenant->id)[0]['planned_quantity'])->toBe(10)
        ->and($this->production->demand($this->tenant->id)[0]['received_quantity'])->toBe(4);
    $this->stock->reserve($this->tenant->id, 'event-sale', 1, $this->pool, [$this->item => 1], now()->addMinutes(10));
    $this->stock->settle($this->tenant->id, 'event-sale', true, 'square-synthetic');
    expect($this->production->demand($this->tenant->id)[0]['planned_quantity'])->toBe(10)
        ->and($this->stock->listing($this->tenant->id, $this->pool)[0]['available_quantity'])->toBe(3);
});

it('requires physical confirmation and an exact tenant and event mapping', function (): void {
    expect(fn () => $this->production->confirm($this->tenant->id, 'no-confirmation', 1, $this->line->id, $this->pool, 4, $this->source['revision'], false))->toThrow(StockException::class, 'production_physical_confirmation_required');
    expect(fn () => $this->production->source($this->tenant->id + 1, $this->line->id))->toThrow(StockException::class, 'production_source_unavailable');
    $other = $this->stock->createPool($this->tenant->id, 'other', 1, 'Other event', 'show', 'event:other')['pool_id'];
    expect(fn () => $this->production->confirm($this->tenant->id, 'wrong-event', 1, $this->line->id, $other, 4, $this->source['revision'], true))->toThrow(StockException::class, 'production_event_pool_mismatch');
    expect($this->stock->rows('movements', $this->tenant->id)->count())->toBe(0);
});

it('rejects changed plans, over-receipts and remapping already received physical units', function (): void {
    $this->production->confirm($this->tenant->id, 'first', 1, $this->line->id, $this->pool, 4, $this->source['revision'], true);
    expect(fn () => $this->production->confirm($this->tenant->id, 'too-many', 1, $this->line->id, $this->pool, 7, $this->source['revision'], true))->toThrow(StockException::class, 'production_exceeds_plan_review_required');
    $this->line->update(['ordered_qty' => 11]);
    expect(fn () => $this->production->confirm($this->tenant->id, 'stale', 1, $this->line->id, $this->pool, 1, $this->source['revision'], true))->toThrow(StockException::class, 'production_plan_changed');
    $this->line->update(['wick_type' => 'wood']);
    $current = $this->production->source($this->tenant->id, $this->line->id);
    expect(fn () => $this->production->map($this->tenant->id, 'remap', 1, $this->line->id, $this->item, $current['revision']))->toThrow(StockException::class, 'production_mapping_has_receipts');
    expect($this->stock->listing($this->tenant->id, $this->pool)[0]['available_quantity'])->toBe(4);
});

it('shows event demand and actual stock separately to owners and blocks ordinary members', function (): void {
    config()->set('pos_live.enabled', true);
    $owner = \App\Models\User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
    $owner->tenants()->attach($this->tenant, ['role' => 'owner', 'membership_active' => true]);
    $url = '/pos/admin/stock?tenant='.$this->tenant->slug.'&pool='.$this->pool;
    $this->actingAs($owner)->get($url)->assertOk()->assertSee('Produced &amp; ready to sell', false)->assertSee('To produce')->assertSee('Confirm new sellable units');
    $member = \App\Models\User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
    $member->tenants()->attach($this->tenant, ['role' => 'member', 'membership_active' => true]);
    $this->actingAs($member)->get($url)->assertForbidden();
});

it('does not expose another tenant’s production plan or stock through owner forms', function (): void {
    config()->set('pos_live.enabled', true);
    $other = \App\Models\Tenant::create(['name' => 'Other fictional maker', 'slug' => 'other-maker-'.bin2hex(random_bytes(5)), 'status' => 'active']);
    $owner = \App\Models\User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
    $owner->tenants()->attach($other, ['role' => 'owner', 'membership_active' => true]);
    $url = '/pos/admin/stock?tenant='.$other->slug;
    $this->actingAs($owner)->get($url)->assertOk()->assertDontSee($this->order->order_number);
    $this->actingAs($owner)->from($url)->post($url, ['request_id' => (string) \Illuminate\Support\Str::uuid(), 'action' => 'count',
        'pool_id' => $this->pool, 'item_id' => $this->item, 'quantity' => 100, 'reason' => 'opening_count', 'physically_confirmed' => '1', 'expected_version' => 0])
        ->assertRedirect($url)->assertSessionHasErrors('stock');
    expect($this->stock->listing($this->tenant->id, $this->pool)[0]['available_quantity'])->toBe(0);
});

it('requires reviewed ownership for legacy global plans and never changes original order ownership', function (): void {
    $owner = \App\Models\User::factory()->create(['is_active' => true]);
    $owner->tenants()->attach($this->tenant, ['role' => 'owner', 'membership_active' => true]);
    $this->order->update(['tenant_id' => null]);
    expect(fn () => $this->production->source($this->tenant->id, $this->line->id))->toThrow(StockException::class);
    expect(fn () => $this->production->adopt($this->tenant->id, 'unowned', $owner->id, $this->order->id))->toThrow(StockException::class, 'production_source_ownership_review_required');
    $plan = \App\Models\RetailPlan::create(['name' => 'Fictional legacy event plan', 'status' => 'published', 'queue_type' => 'event', 'created_by' => $owner->id]);
    \App\Models\RetailPlanItem::create(['retail_plan_id' => $plan->id, 'order_id' => $this->order->id, 'quantity' => 1, 'source' => 'market_box_template', 'status' => 'published']);
    $this->production->adopt($this->tenant->id, 'reviewed', $owner->id, $this->order->id);
    expect($this->production->source($this->tenant->id, $this->line->id)['planned_quantity'])->toBe(10)
        ->and($this->order->fresh()->tenant_id)->toBeNull()
        ->and(fn () => $this->production->source($this->tenant->id + 1, $this->line->id))->toThrow(StockException::class);
});

it('links Asana and later Teams to one event without turning either event reference into stock', function (): void {
    $events = app(\App\Services\Inventory\StockEvents::class);
    $input = ['provider' => 'asana', 'external_id' => '123456789', 'name' => 'Fictional Columbus-like show',
        'start_on' => '2026-10-30', 'end_on' => '2026-11-01', 'city' => 'Fictional city', 'state' => 'GA'];
    $id = $events->review($this->tenant->id, 'event-asana', 1, $input)['event_id'];
    $pool = $this->stock->createPool($this->tenant->id, 'second-show', 1, 'Fictional second show', 'show', 'event:'.$id)['pool_id'];
    $linked = $events->review($this->tenant->id, 'event-teams', 1, [...$input, 'provider' => 'teams', 'external_id' => 'native-team-task', 'event_id' => $id]);
    expect($linked['event_id'])->toBe($id)->and($events->owns($this->tenant->id, $id))->toBeTrue()
        ->and($events->owns($this->tenant->id + 1, $id))->toBeFalse()
        ->and($this->stock->listing($this->tenant->id, $pool)[0]['availability'])->toBe('count_required')
        ->and($this->stock->rows('event_links', $this->tenant->id)->where('event_id', $id)->count())->toBe(2);
});

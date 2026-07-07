<?php

use App\Livewire\PouringRoom\StackOrders;
use App\Livewire\Shipping\Orders as ShippingOrders;
use App\Models\MarketingIdentityReview;
use App\Models\MarketingProfile;
use App\Models\Order;
use App\Models\Tenant;
use App\Models\User;
use Livewire\Livewire;

/**
 * Locks in the surgical fixes for the Tier-1 cross-tenant IDOR sites: each proves
 * BOTH that a member of tenant A cannot read/mutate tenant B's records AND that the
 * member's own (flagship) records still work unchanged — the behavior-preserving
 * guarantee that made these safe to ship on the live candle-ops surface.
 */
function idorTenant(string $slug): Tenant
{
    return Tenant::query()->create(['name' => ucfirst($slug), 'slug' => $slug]);
}

function idorMember(Tenant $tenant, string $role = 'admin'): User
{
    $user = User::factory()->create([
        'role' => $role,
        'is_active' => true,
        'email_verified_at' => now(),
        'approved_at' => now(),
    ]);
    $user->tenants()->attach($tenant->id, ['role' => $role]);

    return $user;
}

test('pouring stack cannot start another tenant order, but starts its own', function (): void {
    $mine = idorTenant('mine');
    $other = idorTenant('other');
    $user = idorMember($mine);

    $myOrder = Order::factory()->create(['tenant_id' => $mine->id, 'status' => 'submitted_to_pouring']);
    $theirOrder = Order::factory()->create(['tenant_id' => $other->id, 'status' => 'submitted_to_pouring']);

    Livewire::actingAs($user)->test(StackOrders::class, ['channel' => 'retail'])
        ->call('startOrder', $theirOrder->id)
        ->call('startOrder', $myOrder->id);

    expect($theirOrder->fresh()->status)->toBe('submitted_to_pouring'); // cross-tenant write blocked
    expect($myOrder->fresh()->status)->toBe('pouring');                 // own write preserved
});

test('pouring stack bulk submit ignores another tenant order', function (): void {
    $mine = idorTenant('mine');
    $other = idorTenant('other');
    $user = idorMember($mine);

    $myOrder = Order::factory()->create(['tenant_id' => $mine->id, 'status' => 'pouring']);
    $theirOrder = Order::factory()->create(['tenant_id' => $other->id, 'status' => 'pouring']);

    Livewire::actingAs($user)->test(StackOrders::class, ['channel' => 'retail'])
        ->set('selected', [$myOrder->id => true, $theirOrder->id => true])
        ->call('submitSelected');

    expect($theirOrder->fresh()->status)->toBe('pouring');        // untouched
    expect($myOrder->fresh()->status)->toBe('brought_down');      // submitted
});

test('shipping editor 404s another tenant order and edits its own', function (): void {
    $mine = idorTenant('mine');
    $other = idorTenant('other');
    $user = idorMember($mine);

    $myOrder = Order::factory()->create(['tenant_id' => $mine->id, 'status' => 'new']);
    $theirOrder = Order::factory()->create(['tenant_id' => $other->id, 'status' => 'new']);

    // Own order opens for editing.
    Livewire::actingAs($user)->test(ShippingOrders::class)
        ->call('startEditing', $myOrder->id)
        ->assertSet("orderEditing.{$myOrder->id}", true);

    // Another tenant's order cannot be opened — the scoped findOrFail raises the
    // exception Laravel renders as 404 in HTTP (it bubbles raw in a Livewire unit test).
    expect(fn () => Livewire::actingAs($user)->test(ShippingOrders::class)->call('startEditing', $theirOrder->id))
        ->toThrow(Illuminate\Database\Eloquent\ModelNotFoundException::class);
});

test('candle-cash adjust cannot credit another tenant customer, but adjusts its own', function (): void {
    $mine = idorTenant('mine');
    $other = idorTenant('other');
    $user = idorMember($mine);

    $myProfile = MarketingProfile::factory()->create(['tenant_id' => $mine->id]);
    $theirProfile = MarketingProfile::factory()->create(['tenant_id' => $other->id]);

    // Cross-tenant balance adjustment is refused.
    $this->actingAs($user)
        ->post(route('marketing.candle-cash.customers.adjust', $theirProfile), [
            'adjustment_type' => 'add',
            'amount' => 25,
        ])
        ->assertNotFound();

    // Own customer adjusts fine (redirects back, not 404).
    $this->actingAs($user)
        ->post(route('marketing.candle-cash.customers.adjust', $myProfile), [
            'adjustment_type' => 'add',
            'amount' => 25,
        ])
        ->assertRedirect();
});

test('identity review cannot be merged into another tenant profile', function (): void {
    $mine = idorTenant('mine');
    $other = idorTenant('other');
    $user = idorMember($mine);

    $theirProfile = MarketingProfile::factory()->create(['tenant_id' => $other->id]);

    $review = MarketingIdentityReview::query()->create([
        'status' => 'pending',
        'source_type' => 'shopify',
        'source_id' => 'test-1',
    ]);

    // The target profile exists (passes exists: validation) but belongs to another
    // tenant, so the scoped findOrFail 404s rather than merging cross-tenant.
    $this->actingAs($user)
        ->post(route('marketing.identity-review.resolve-existing', $review), [
            'profile_id' => $theirProfile->id,
        ])
        ->assertNotFound();
});

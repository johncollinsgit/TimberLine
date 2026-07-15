<?php

require_once dirname(__DIR__).'/ShopifyEmbeddedTestHelpers.php';

use App\Models\LandlordOperatorAction;
use App\Models\Order;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WholesaleFollowUp;
use App\Models\WholesaleSuggestion;
use App\Models\WholesaleSuggestionDecision;
use App\Services\Wholesale\WholesaleSuggestionGenerator;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $this->withoutVite();
    $this->tenant = Tenant::query()->create(['name' => 'Modern Forestry', 'slug' => 'modern-forestry']);
    configureEmbeddedWholesaleStore((int) $this->tenant->id);
    $this->actor = User::factory()->create(['email' => 'wholesale-decisions@example.com', 'role' => 'admin', 'is_active' => true]);
    $this->actor->tenants()->attach($this->tenant->id, ['role' => 'admin']);
});

test('timing suggestion generator uses only qualified wholesale evidence', function (): void {
    foreach ([150, 90] as $daysAgo) {
        Order::factory()->create([
            'tenant_id' => $this->tenant->id,
            'shopify_store_key' => 'wholesale',
            'source' => 'shopify_wholesale',
            'order_type' => 'wholesale',
            'shopify_customer_id' => 'qualified-1',
            'customer_name' => 'Qualified Reorder Account',
            'total_price' => 300,
            'ordered_at' => now()->subDays($daysAgo),
        ]);
    }
    Order::factory()->create([
        'tenant_id' => $this->tenant->id,
        'shopify_store_key' => 'retail',
        'source' => 'shopify_retail',
        'order_type' => 'retail',
        'shopify_customer_id' => 'retail-1',
        'customer_name' => 'Private Retail Account',
        'total_price' => 5000,
        'ordered_at' => now()->subYear(),
    ]);

    $result = app(WholesaleSuggestionGenerator::class)->refresh((int) $this->tenant->id);

    expect($result['created'])->toBe(1)
        ->and(WholesaleSuggestion::query()->forAllTenants()->count())->toBe(1);

    $suggestion = WholesaleSuggestion::query()->forAllTenants()->firstOrFail();
    expect($suggestion->title)->toContain('Qualified Reorder Account')
        ->and(json_encode($suggestion->supporting_evidence))->not->toContain('Private Retail Account');

    $this->get(route('shopify.app.wholesale.suggestions', wholesaleEmbeddedSignedQuery()))
        ->assertOk()
        ->assertSeeText('Qualified Reorder Account')
        ->assertSeeText('No retail behavior was used')
        ->assertDontSeeText('Private Retail Account');
});

test('suggestion decisions are audited and follow up creation is idempotent', function (): void {
    $suggestion = WholesaleSuggestion::query()->create([
        'tenant_id' => $this->tenant->id,
        'public_id' => (string) Str::uuid(),
        'target_type' => 'customer',
        'target_key' => str_repeat('a', 64),
        'suggestion_type' => 'customer_due_for_reorder',
        'title' => 'Account due for reorder',
        'recommended_action' => 'Review and follow up.',
        'priority' => 'high',
        'confidence' => 85,
        'supporting_evidence' => ['wholesale_order_count' => 4],
        'reason' => 'Wholesale timing evidence.',
        'evidence_fingerprint' => hash('sha256', 'test-suggestion'),
        'status' => 'pending',
        'last_evaluated_at' => now(),
    ]);
    $headers = [
        'Authorization' => 'Bearer '.wholesaleShopifySessionToken(['email' => $this->actor->email]),
        'Accept' => 'application/json',
    ];

    foreach (['First review', 'Second replay'] as $note) {
        $this->withHeaders($headers)->post(route('shopify.app.wholesale.suggestions.decide', [
            'suggestionPublicId' => $suggestion->public_id,
        ]), [
            'action' => 'create_follow_up',
            'note' => $note,
            'due_at' => now()->addWeek()->toIso8601String(),
        ])->assertOk()->assertJsonPath('ok', true);
    }

    expect(WholesaleFollowUp::query()->forAllTenants()->count())->toBe(1)
        ->and(WholesaleSuggestionDecision::query()->forAllTenants()->count())->toBe(2)
        ->and(LandlordOperatorAction::query()->where('action_type', 'wholesale.suggestion.create_follow_up')->count())->toBe(2)
        ->and($suggestion->fresh()->status)->toBe('accepted');
});

test('dismissal requires a recorded reason', function (): void {
    $suggestion = WholesaleSuggestion::query()->create([
        'tenant_id' => $this->tenant->id,
        'public_id' => (string) Str::uuid(),
        'target_type' => 'customer',
        'target_key' => str_repeat('b', 64),
        'suggestion_type' => 'customer_due_for_reorder',
        'title' => 'Review timing',
        'recommended_action' => 'Review.',
        'confidence' => 70,
        'supporting_evidence' => [],
        'reason' => 'Wholesale timing evidence.',
        'evidence_fingerprint' => hash('sha256', 'dismiss-test'),
        'last_evaluated_at' => now(),
    ]);

    $this->withHeaders([
        'Authorization' => 'Bearer '.wholesaleShopifySessionToken(['email' => $this->actor->email]),
        'Accept' => 'application/json',
    ])->post(route('shopify.app.wholesale.suggestions.decide', ['suggestionPublicId' => $suggestion->public_id]), [
        'action' => 'dismiss',
    ])->assertUnprocessable()->assertJsonPath('ok', false);

    expect($suggestion->fresh()->status)->toBe('pending')
        ->and(WholesaleSuggestionDecision::query()->forAllTenants()->count())->toBe(0);
});

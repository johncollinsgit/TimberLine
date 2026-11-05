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
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

function createWholesaleDecisionTestSuggestion(int $tenantId, array $overrides = []): WholesaleSuggestion
{
    return WholesaleSuggestion::query()->create(array_merge([
        'tenant_id' => $tenantId,
        'public_id' => (string) Str::uuid(),
        'target_type' => 'customer',
        'target_key' => hash('sha256', (string) Str::uuid()),
        'suggestion_type' => 'customer_due_for_reorder',
        'title' => 'Current reorder recommendation',
        'recommended_action' => 'Review the account before contacting.',
        'supporting_evidence' => [],
        'reason' => 'Qualified wholesale timing evidence.',
        'evidence_fingerprint' => hash('sha256', (string) Str::uuid()),
        'status' => 'pending',
        'last_evaluated_at' => now(),
    ], $overrides));
}

beforeEach(function (): void {
    $this->withoutVite();
    $this->tenant = Tenant::query()->create(['name' => 'Modern Forestry', 'slug' => 'modern-forestry']);
    configureEmbeddedWholesaleStore((int) $this->tenant->id);
    configureWholesaleReportingFixture();
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

test('the current queue separates retired recommendations and its history link works', function (): void {
    createWholesaleDecisionTestSuggestion((int) $this->tenant->id);
    $retired = createWholesaleDecisionTestSuggestion((int) $this->tenant->id, [
        'title' => 'Retired prior recommendation',
        'status' => 'superseded',
    ]);
    createWholesaleDecisionTestSuggestion((int) $this->tenant->id, [
        'title' => 'Previously handled recommendation',
        'status' => 'already_handled',
    ]);

    $current = $this->get(route('shopify.app.wholesale.suggestions', wholesaleEmbeddedSignedQuery()))
        ->assertOk()
        ->assertSeeText('Current reorder recommendation')
        ->assertDontSeeText('Retired prior recommendation')
        ->assertDontSeeText('Previously handled recommendation');

    $dom = new DOMDocument;
    @$dom->loadHTML($current->getContent());
    $historyLink = (new DOMXPath($dom))->query('//a[@data-wholesale-suggestion-history]')->item(0);
    expect($historyLink)->not->toBeNull();

    $this->get($historyLink->getAttribute('href'))
        ->assertOk()
        ->assertSeeText('Retired prior recommendation')
        ->assertSeeText('Previously handled recommendation')
        ->assertDontSeeText('Current reorder recommendation')
        ->assertSeeText('Historical entry retained with its decisions.')
        ->assertDontSee(route('shopify.app.wholesale.suggestions.decide', ['suggestionPublicId' => $retired->public_id], false), false);
});

test('selected Eastern follow up and snooze dates are stored at the correct UTC instant', function (string $action, int $month): void {
    $suggestion = createWholesaleDecisionTestSuggestion((int) $this->tenant->id);
    $chosen = CarbonImmutable::create(now('America/New_York')->year + 1, $month, 15, 9, 30, 0, 'America/New_York');

    $this->withHeaders([
        'Authorization' => 'Bearer '.wholesaleShopifySessionToken(['email' => $this->actor->email]),
        'Accept' => 'application/json',
    ])->post(route('shopify.app.wholesale.suggestions.decide', ['suggestionPublicId' => $suggestion->public_id]), [
        'action' => $action,
        'decision_local_at' => $chosen->format('Y-m-d\TH:i'),
    ])->assertOk()->assertJsonPath('ok', true);

    $savedAt = $action === 'snooze'
        ? $suggestion->fresh()->snoozed_until
        : WholesaleFollowUp::query()->forAllTenants()->firstOrFail()->due_at;

    expect($savedAt->utc()->format('Y-m-d H:i:s'))->toBe($chosen->utc()->format('Y-m-d H:i:s'))
        ->and($savedAt->timezone('America/New_York')->format('H:i'))->toBe('09:30');
})->with([
    'winter snooze' => ['snooze', 1],
    'summer snooze' => ['snooze', 7],
    'winter follow up' => ['create_follow_up', 1],
    'summer follow up' => ['create_follow_up', 7],
]);

test('snooze requires an explicit future date', function (array $dateInput): void {
    $suggestion = createWholesaleDecisionTestSuggestion((int) $this->tenant->id);

    $this->withHeaders([
        'Authorization' => 'Bearer '.wholesaleShopifySessionToken(['email' => $this->actor->email]),
        'Accept' => 'application/json',
    ])->post(route('shopify.app.wholesale.suggestions.decide', ['suggestionPublicId' => $suggestion->public_id]), array_merge([
        'action' => 'snooze',
    ], $dateInput))->assertUnprocessable();

    expect($suggestion->fresh()->status)->toBe('pending')
        ->and(WholesaleSuggestionDecision::query()->forAllTenants()->count())->toBe(0);
})->with([
    'missing' => [[]],
    'invalid' => [['decision_local_at' => 'not-a-date']],
    'past' => [['decision_local_at' => '2020-01-15T09:30']],
]);

test('a stale superseded recommendation cannot create another follow up', function (): void {
    $suggestion = createWholesaleDecisionTestSuggestion((int) $this->tenant->id, ['status' => 'superseded']);

    $this->withHeaders([
        'Authorization' => 'Bearer '.wholesaleShopifySessionToken(['email' => $this->actor->email]),
        'Accept' => 'application/json',
    ])->post(route('shopify.app.wholesale.suggestions.decide', ['suggestionPublicId' => $suggestion->public_id]), [
        'action' => 'create_follow_up',
    ])->assertUnprocessable()->assertJsonPath('ok', false);

    expect($suggestion->fresh()->status)->toBe('superseded')
        ->and(WholesaleFollowUp::query()->forAllTenants()->count())->toBe(0);
});

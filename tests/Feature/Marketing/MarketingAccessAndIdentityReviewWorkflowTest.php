<?php

use App\Models\MarketingIdentityReview;
use App\Models\MarketingProfile;
use App\Models\MarketingProfileLink;
use App\Models\Order;
use App\Models\SquareCustomer;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;

beforeEach(function () {
    $this->withoutMiddleware(ValidateCsrfToken::class);
});

test('admin and marketing manager can access customers and identity review pages', function () {
    $profile = MarketingProfile::query()->create([
        'first_name' => 'Casey',
        'last_name' => 'Bloom',
        'email' => 'casey@example.com',
        'normalized_email' => 'casey@example.com',
    ]);

    $review = MarketingIdentityReview::query()->create([
        'status' => 'pending',
        'raw_first_name' => 'Casey',
        'raw_last_name' => 'Bloom',
        'raw_email' => 'casey@example.com',
        'source_type' => 'order',
        'source_id' => '1001',
        'conflict_reasons' => ['email_phone_conflict'],
    ]);

    $admin = User::factory()->create([
        'role' => 'admin',
        'email_verified_at' => now(),
    ]);
    $marketingManager = User::factory()->create([
        'role' => 'marketing_manager',
        'email_verified_at' => now(),
    ]);

    foreach ([$admin, $marketingManager] as $user) {
        $this->actingAs($user)
            ->get(route('marketing.customers'))
            ->assertOk()
            ->assertSeeText('Customers');

        $this->actingAs($user)
            ->get(route('marketing.customers.show', $profile))
            ->assertOk()
            ->assertSeeText('Customer');

        $this->actingAs($user)
            ->get(route('marketing.identity-review'))
            ->assertOk()
            ->assertSeeText('Fix Matches');

        $this->actingAs($user)
            ->get(route('marketing.identity-review.show', $review))
            ->assertOk()
            ->assertSeeText('Review Match');
    }

    $manager = User::factory()->create([
        'role' => 'manager',
        'email_verified_at' => now(),
    ]);

    $this->actingAs($manager)
        ->get(route('marketing.customers'))
        ->assertOk()
        ->assertSeeText('Customers');

    $this->actingAs($manager)
        ->get(route('marketing.customers.show', $profile))
        ->assertOk()
        ->assertSeeText('Customer');

    $this->actingAs($manager)
        ->get(route('marketing.identity-review'))
        ->assertForbidden();

    $this->actingAs($manager)
        ->get(route('marketing.identity-review.show', $review))
        ->assertForbidden();
});

test('unauthorized roles cannot access marketing customers and identity review pages', function () {
    $profile = MarketingProfile::query()->create([
        'first_name' => 'Alex',
    ]);

    $review = MarketingIdentityReview::query()->create([
        'status' => 'pending',
        'source_type' => 'order',
        'source_id' => '2002',
    ]);

    $pouring = User::factory()->create([
        'role' => 'pouring',
        'email_verified_at' => now(),
    ]);

    foreach ([$pouring] as $user) {
        $this->actingAs($user)
            ->get(route('marketing.customers'))
            ->assertForbidden();

        $this->actingAs($user)
            ->get(route('marketing.customers.show', $profile))
            ->assertForbidden();

        $this->actingAs($user)
            ->get(route('marketing.identity-review'))
            ->assertForbidden();

        $this->actingAs($user)
            ->get(route('marketing.identity-review.show', $review))
            ->assertForbidden();
    }
});

test('identity review can be resolved to an existing profile', function () {
    $user = User::factory()->create([
        'role' => 'admin',
        'email_verified_at' => now(),
    ]);
    $profile = MarketingProfile::query()->create([
        'first_name' => 'Existing',
    ]);
    $review = MarketingIdentityReview::query()->create([
        'status' => 'pending',
        'raw_first_name' => 'Riley',
        'raw_last_name' => 'Stone',
        'raw_email' => 'riley@example.com',
        'raw_phone' => '(555) 444-2222',
        'source_type' => 'order',
        'source_id' => '3003',
        'conflict_reasons' => ['email_phone_conflict'],
    ]);

    $this->actingAs($user)
        ->post(route('marketing.identity-review.resolve-existing', $review), [
            'profile_id' => $profile->id,
            'resolution_notes' => 'Confirmed same customer from account notes.',
        ])
        ->assertRedirect(route('marketing.identity-review.show', $review));

    $review->refresh();
    $profile->refresh();

    expect($review->status)->toBe('resolved')
        ->and((int) $review->proposed_marketing_profile_id)->toBe((int) $profile->id)
        ->and((int) $review->reviewed_by)->toBe((int) $user->id)
        ->and($review->reviewed_at)->not->toBeNull()
        ->and($profile->normalized_email)->toBe('riley@example.com')
        ->and($profile->normalized_phone)->toBe('5554442222')
        ->and(MarketingProfileLink::query()
            ->where('source_type', 'order')
            ->where('source_id', '3003')
            ->where('marketing_profile_id', $profile->id)
            ->where('match_method', 'manual_review')
            ->exists())->toBeTrue();
});

test('identity review can be resolved by creating a new profile', function () {
    $user = User::factory()->create([
        'role' => 'marketing_manager',
        'email_verified_at' => now(),
    ]);
    $review = MarketingIdentityReview::query()->create([
        'status' => 'pending',
        'raw_first_name' => 'New',
        'raw_last_name' => 'Person',
        'raw_email' => 'new.person@example.com',
        'raw_phone' => '5553331212',
        'source_type' => 'order',
        'source_id' => '4004',
        'conflict_reasons' => ['ambiguous_exact_match'],
    ]);

    $this->actingAs($user)
        ->post(route('marketing.identity-review.resolve-new', $review), [
            'first_name' => 'New',
            'last_name' => 'Person',
            'email' => 'NEW.PERSON@example.com',
            'phone' => '(555) 333-1212',
            'resolution_notes' => 'Separate household member.',
        ])
        ->assertRedirect(route('marketing.identity-review.show', $review));

    $review->refresh();
    $profile = MarketingProfile::query()->findOrFail($review->proposed_marketing_profile_id);

    expect($review->status)->toBe('resolved')
        ->and((int) $review->reviewed_by)->toBe((int) $user->id)
        ->and($profile->normalized_email)->toBe('new.person@example.com')
        ->and($profile->normalized_phone)->toBe('5553331212')
        ->and(MarketingProfileLink::query()
            ->where('source_type', 'order')
            ->where('source_id', '4004')
            ->where('marketing_profile_id', $profile->id)
            ->where('match_method', 'manual_review')
            ->exists())->toBeTrue();
});

test('identity review can be dismissed with notes', function () {
    $user = User::factory()->create([
        'role' => 'admin',
        'email_verified_at' => now(),
    ]);
    $review = MarketingIdentityReview::query()->create([
        'status' => 'pending',
        'source_type' => 'order',
        'source_id' => '5005',
    ]);

    $this->actingAs($user)
        ->post(route('marketing.identity-review.ignore', $review), [
            'resolution_notes' => 'Insufficient data quality for a safe merge.',
        ])
        ->assertRedirect(route('marketing.identity-review.show', $review));

    $review->refresh();

    expect($review->status)->toBe('ignored')
        ->and((int) $review->reviewed_by)->toBe((int) $user->id)
        ->and($review->reviewed_at)->not->toBeNull()
        ->and($review->resolution_notes)->toContain('Insufficient data quality');
});

test('customers empty state surfaces upstream sync diagnostics and readable headers', function () {
    Order::factory()->create([
        'source' => 'shopify_retail',
        'order_type' => 'retail',
        'shopify_store_key' => 'retail',
        'shopify_store' => 'retail',
        'shopify_order_id' => 9011,
        'shopify_customer_id' => '8801',
        'email' => 'empty-state@example.com',
    ]);
    SquareCustomer::query()->create([
        'square_customer_id' => 'SQ-EMPTY-1',
        'given_name' => 'Square',
        'family_name' => 'Candidate',
        'email' => 'square-empty@example.com',
        'phone' => '5551012020',
        'synced_at' => now(),
    ]);

    $admin = User::factory()->create([
        'role' => 'admin',
        'email_verified_at' => now(),
    ]);

    $this->actingAs($admin)
        ->get(route('marketing.customers'))
        ->assertOk()
        ->assertSeeText('No marketing profiles have been built yet')
        ->assertSeeText('Shopify/Growave/Square')
        ->assertSeeText('Run profile sync to build the canonical customer index.')
        ->assertSeeText('Customer master index')
        ->assertSeeText('Manage Customers')
        ->assertSeeText('The live grid below loads rows on demand so search and filters stay fast.')
        ->assertSee('php artisan marketing:sync-profiles --source=all --chunk=500', false);
});

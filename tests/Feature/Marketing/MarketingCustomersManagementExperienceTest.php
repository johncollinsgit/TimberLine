<?php

use App\Models\CustomerExternalProfile;
use App\Models\MarketingEmailDelivery;
use App\Models\MarketingProfile;
use App\Models\MarketingProfileLink;
use App\Models\MarketingReviewHistory;
use App\Models\MarketingReviewSummary;
use App\Models\MarketingProfileWishlistItem;
use App\Models\Order;
use App\Models\SquareCustomer;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Carbon;

/**
 * @return array<int,array<string,string>>
 */
function parseCustomerEmailTimelineCsv(string $csv): array
{
    $lines = preg_split('/\r\n|\n|\r/', trim($csv)) ?: [];
    if ($lines === [] || count($lines) < 1) {
        return [];
    }

    $columns = str_getcsv((string) array_shift($lines));
    if ($columns === false || $columns === []) {
        return [];
    }

    $rows = [];
    foreach ($lines as $line) {
        if (trim((string) $line) === '') {
            continue;
        }

        $values = str_getcsv((string) $line);
        if ($values === false) {
            continue;
        }

        $combined = array_combine($columns, array_pad($values, count($columns), ''));
        if (is_array($combined)) {
            $rows[] = $combined;
        }
    }

    return $rows;
}

beforeEach(function () {
    $this->withoutMiddleware(ValidateCsrfToken::class);
});

test('customers index renders canonical customer rows with loyalty enrichment and add action', function () {
    $profile = MarketingProfile::query()->create([
        'first_name' => 'Avery',
        'last_name' => 'Lane',
        'email' => 'avery.lane@example.com',
        'normalized_email' => 'avery.lane@example.com',
        'phone' => '(555) 400-9191',
        'normalized_phone' => '5554009191',
        'source_channels' => ['shopify', 'online'],
    ]);

    MarketingProfileLink::query()->create([
        'marketing_profile_id' => $profile->id,
        'source_type' => 'shopify_customer',
        'source_id' => 'retail:9011',
        'source_meta' => [],
        'match_method' => 'seed',
        'confidence' => 1.00,
    ]);

    CustomerExternalProfile::query()->create([
        'marketing_profile_id' => $profile->id,
        'provider' => 'shopify',
        'integration' => 'growave',
        'store_key' => 'retail',
        'external_customer_id' => '9011',
        'points_balance' => 135,
        'vip_tier' => 'Platinum',
        'referral_link' => 'https://example.test/ref/avery',
        'synced_at' => now(),
    ]);

    $admin = User::factory()->create([
        'role' => 'admin',
        'email_verified_at' => now(),
    ]);

    $this->actingAs($admin)
        ->get(route('marketing.customers'))
        ->assertOk()
        ->assertSeeText('Customers')
        ->assertSeeText('Manage Customers')
        ->assertSeeText('Add Customer')
        ->assertSeeText('Search-first results load in the live grid below')
        ->assertSeeText('The live grid below loads rows on demand');

    $this->actingAs($admin)
        ->getJson(route('marketing.customers.data'))
        ->assertOk()
        ->assertJsonPath('data.0.customer', 'Avery Lane')
        ->assertJsonPath('data.0.tier', 'Platinum')
        ->assertJsonPath('data.0.legacy_growave_points', 135);
});

test('customers projections prefer data-rich Growave rows over newer empty duplicates', function () {
    $profile = MarketingProfile::query()->create([
        'first_name' => 'Projection',
        'last_name' => 'Target',
        'email' => 'projection.target@example.com',
        'normalized_email' => 'projection.target@example.com',
    ]);

    CustomerExternalProfile::query()->create([
        'marketing_profile_id' => $profile->id,
        'provider' => 'shopify',
        'integration' => 'growave',
        'store_key' => 'retail',
        'external_customer_id' => 'GRO-RICH-7001',
        'points_balance' => 4321,
        'vip_tier' => 'Gold',
        'referral_link' => 'https://refrr.app/example/preferred',
        'raw_metafields' => [
            ['namespace' => 'growave', 'key' => 'review_count', 'value' => '7', 'type' => 'number_integer'],
            ['namespace' => 'growave', 'key' => 'published_review_count', 'value' => '7', 'type' => 'number_integer'],
            ['namespace' => 'growave', 'key' => 'activity_total', 'value' => '11', 'type' => 'number_integer'],
        ],
        'synced_at' => now()->subHour(),
    ]);

    CustomerExternalProfile::query()->create([
        'marketing_profile_id' => $profile->id,
        'provider' => 'shopify',
        'integration' => 'growave',
        'store_key' => 'retail',
        'external_customer_id' => 'GRO-EMPTY-7002',
        'points_balance' => 0,
        'vip_tier' => null,
        'referral_link' => null,
        'raw_metafields' => [
            ['namespace' => 'growave', 'key' => 'review_count', 'value' => '0', 'type' => 'number_integer'],
            ['namespace' => 'growave', 'key' => 'published_review_count', 'value' => '0', 'type' => 'number_integer'],
            ['namespace' => 'growave', 'key' => 'activity_total', 'value' => '0', 'type' => 'number_integer'],
        ],
        'synced_at' => now(),
    ]);

    MarketingReviewSummary::query()->create([
        'marketing_profile_id' => $profile->id,
        'provider' => 'growave',
        'integration' => 'growave',
        'store_key' => 'retail',
        'external_customer_id' => 'GRO-RICH-7001',
        'external_customer_email' => $profile->email,
        'review_count' => 7,
        'published_review_count' => 7,
        'average_rating' => 4.75,
        'source_synced_at' => now()->subHour(),
        'raw_payload' => [],
    ]);

    MarketingReviewSummary::query()->create([
        'marketing_profile_id' => $profile->id,
        'provider' => 'growave',
        'integration' => 'growave',
        'store_key' => 'retail',
        'external_customer_id' => 'GRO-EMPTY-7002',
        'external_customer_email' => $profile->email,
        'review_count' => 0,
        'published_review_count' => 0,
        'average_rating' => null,
        'source_synced_at' => now(),
        'raw_payload' => [],
    ]);

    $admin = User::factory()->create([
        'role' => 'admin',
        'email_verified_at' => now(),
    ]);

    $this->actingAs($admin)
        ->get(route('marketing.customers'))
        ->assertOk()
        ->assertSeeText('Manage Customers');

    $this->actingAs($admin)
        ->getJson(route('marketing.customers.data'))
        ->assertOk()
        ->assertJsonPath('data.0.customer', 'Projection Target')
        ->assertJsonPath('data.0.legacy_growave_points', 4321)
        ->assertJsonPath('data.0.review_count', 7)
        ->assertJsonPath('data.0.average_rating', 4.75);

    $this->actingAs($admin)
        ->get(route('marketing.customers.show', $profile))
        ->assertOk()
        ->assertSeeText('4,321')
        ->assertSeeText('7')
        ->assertSeeText('4.75')
        ->assertSeeText('Open Legacy Link');
});

test('customers search matches external source ids through canonical profile query', function () {
    $profile = MarketingProfile::query()->create([
        'first_name' => 'Search',
        'last_name' => 'Target',
        'email' => 'search.target@example.com',
        'normalized_email' => 'search.target@example.com',
    ]);

    CustomerExternalProfile::query()->create([
        'marketing_profile_id' => $profile->id,
        'provider' => 'shopify',
        'integration' => 'growave',
        'store_key' => 'retail',
        'external_customer_id' => 'EXT-LOOKUP-7788',
        'points_balance' => 25,
        'synced_at' => now(),
    ]);

    $admin = User::factory()->create([
        'role' => 'admin',
        'email_verified_at' => now(),
    ]);

    $this->actingAs($admin)
        ->get(route('marketing.customers', ['search' => 'LOOKUP-7788']))
        ->assertOk()
        ->assertSeeText('Manage Customers');

    $this->actingAs($admin)
        ->getJson(route('marketing.customers.data', ['search' => 'LOOKUP-7788']))
        ->assertOk()
        ->assertJsonPath('data.0.customer', 'Search Target')
        ->assertJsonPath('data.0.email', 'search.target@example.com');
});

test('customer detail shows native wishlist summary while keeping legacy wishlist provenance visible', function () {
    $profile = MarketingProfile::query()->create([
        'first_name' => 'Wishlist',
        'last_name' => 'Signal',
        'email' => 'wishlist.signal@example.com',
        'normalized_email' => 'wishlist.signal@example.com',
    ]);

    MarketingProfileWishlistItem::query()->create([
        'marketing_profile_id' => $profile->id,
        'provider' => 'backstage',
        'integration' => 'native',
        'store_key' => 'retail',
        'product_id' => 'wish-100',
        'product_handle' => 'cedar-glow',
        'product_title' => 'Cedar Glow',
        'status' => MarketingProfileWishlistItem::STATUS_ACTIVE,
        'source' => 'native_storefront',
        'added_at' => now()->subDay(),
        'last_added_at' => now()->subDay(),
    ]);

    MarketingProfileWishlistItem::query()->create([
        'marketing_profile_id' => $profile->id,
        'provider' => 'growave',
        'integration' => 'growave',
        'store_key' => 'retail',
        'product_id' => 'wish-legacy-200',
        'product_handle' => 'heritage-pine',
        'product_title' => 'Heritage Pine',
        'status' => MarketingProfileWishlistItem::STATUS_ACTIVE,
        'source' => 'growave_import',
        'added_at' => now()->subDays(2),
        'last_added_at' => now()->subDays(2),
        'source_synced_at' => now()->subHour(),
    ]);

    $admin = User::factory()->create([
        'role' => 'admin',
        'email_verified_at' => now(),
    ]);

    $this->actingAs($admin)
        ->get(route('marketing.customers.show', $profile))
        ->assertOk()
        ->assertSeeText('Backstage Native Wishlist')
        ->assertSeeText('Native Backstage Wishlist')
        ->assertSeeText('Legacy Wishlist Rows')
        ->assertSeeText('Cedar Glow')
        ->assertSeeText('Heritage Pine')
        ->assertSeeText('Native Backstage');
});

test('customer detail email timeline shows canonical provider context labels and diagnostics', function () {
    $profile = MarketingProfile::query()->create([
        'first_name' => 'Email',
        'last_name' => 'Timeline',
        'email' => 'email.timeline@example.com',
        'normalized_email' => 'email.timeline@example.com',
    ]);

    MarketingEmailDelivery::query()->create([
        'marketing_profile_id' => $profile->id,
        'provider' => 'sendgrid',
        'email' => $profile->email,
        'status' => 'sent',
        'sent_at' => now()->subMinutes(20),
        'delivered_at' => now()->subMinutes(19),
        'metadata' => [
            'provider_resolution_source' => 'tenant',
            'provider_readiness_status' => 'ready',
            'provider_config_status' => 'configured',
            'provider_using_fallback_config' => false,
        ],
    ]);

    MarketingEmailDelivery::query()->create([
        'marketing_profile_id' => $profile->id,
        'provider' => 'sendgrid',
        'email' => $profile->email,
        'status' => 'failed',
        'failed_at' => now()->subMinutes(18),
        'metadata' => [
            'provider_resolution_source' => 'fallback',
            'provider_readiness_status' => 'ready',
            'provider_config_status' => 'configured',
            'provider_using_fallback_config' => true,
        ],
    ]);

    MarketingEmailDelivery::query()->create([
        'marketing_profile_id' => $profile->id,
        'provider' => 'shopify_email',
        'email' => $profile->email,
        'status' => 'failed',
        'failed_at' => now()->subMinutes(17),
        'metadata' => [
            'provider_resolution_source' => 'tenant',
            'provider_readiness_status' => 'unsupported',
            'provider_config_status' => 'disabled',
            'provider_using_fallback_config' => false,
        ],
    ]);

    MarketingEmailDelivery::query()->create([
        'marketing_profile_id' => $profile->id,
        'provider' => 'sendgrid',
        'email' => $profile->email,
        'status' => 'failed',
        'failed_at' => now()->subMinutes(16),
        'metadata' => [],
    ]);

    $admin = User::factory()->create([
        'role' => 'admin',
        'email_verified_at' => now(),
    ]);

    $this->actingAs($admin)
        ->get(route('marketing.customers.show', $profile))
        ->assertOk()
        ->assertSeeText('Email Delivery Timeline')
        ->assertSeeText('Sent via tenant-configured SendGrid.')
        ->assertSeeText('Sent via fallback provider config.')
        ->assertSeeText('Attempted with unsupported provider runtime.')
        ->assertSeeText('Provider context unavailable for legacy row.')
        ->assertSeeText('Failed while using fallback provider configuration.')
        ->assertSeeText('Failed because provider runtime is unsupported in this app flow.')
        ->assertSeeText('This failed row predates provider-context stamping.')
        ->assertSeeText('Tenant configuration: 2')
        ->assertSeeText('Fallback configuration: 1')
        ->assertSeeText('Legacy / unavailable: 1')
        ->assertSeeText('Unsupported runtime: 1');
});

test('customer detail email timeline filters provider resolution and readiness context', function () {
    $profile = MarketingProfile::query()->create([
        'first_name' => 'Filter',
        'last_name' => 'Timeline',
        'email' => 'filter.timeline@example.com',
        'normalized_email' => 'filter.timeline@example.com',
    ]);

    MarketingEmailDelivery::query()->create([
        'marketing_profile_id' => $profile->id,
        'provider' => 'sendgrid',
        'email' => $profile->email,
        'status' => 'sent',
        'metadata' => [
            'provider_resolution_source' => 'tenant',
            'provider_readiness_status' => 'ready',
            'provider_config_status' => 'configured',
        ],
    ]);

    MarketingEmailDelivery::query()->create([
        'marketing_profile_id' => $profile->id,
        'provider' => 'sendgrid',
        'email' => $profile->email,
        'status' => 'failed',
        'metadata' => [
            'provider_resolution_source' => 'fallback',
            'provider_readiness_status' => 'ready',
            'provider_config_status' => 'configured',
            'provider_using_fallback_config' => true,
        ],
    ]);

    MarketingEmailDelivery::query()->create([
        'marketing_profile_id' => $profile->id,
        'provider' => 'shopify_email',
        'email' => $profile->email,
        'status' => 'failed',
        'metadata' => [
            'provider_resolution_source' => 'tenant',
            'provider_readiness_status' => 'unsupported',
            'provider_config_status' => 'disabled',
        ],
    ]);

    MarketingEmailDelivery::query()->create([
        'marketing_profile_id' => $profile->id,
        'provider' => 'sendgrid',
        'email' => $profile->email,
        'status' => 'failed',
        'metadata' => [],
    ]);

    $admin = User::factory()->create([
        'role' => 'admin',
        'email_verified_at' => now(),
    ]);

    $this->actingAs($admin)
        ->get(route('marketing.customers.show', [
            'marketingProfile' => $profile,
            'provider_resolution_source' => 'fallback',
        ]))
        ->assertOk()
        ->assertSeeText('Sent via fallback provider config.')
        ->assertDontSeeText('Sent via tenant-configured SendGrid.')
        ->assertDontSeeText('Provider context unavailable for legacy row.');

    $this->actingAs($admin)
        ->get(route('marketing.customers.show', [
            'marketingProfile' => $profile,
            'provider_readiness_status' => 'unsupported',
        ]))
        ->assertOk()
        ->assertSeeText('Attempted with unsupported provider runtime.')
        ->assertDontSeeText('Sent via fallback provider config.')
        ->assertDontSeeText('Provider context unavailable for legacy row.');

    $this->actingAs($admin)
        ->get(route('marketing.customers.show', [
            'marketingProfile' => $profile,
            'provider_resolution_source' => 'unknown',
        ]))
        ->assertOk()
        ->assertSeeText('Provider context unavailable for legacy row.')
        ->assertDontSeeText('Sent via tenant-configured SendGrid.');
});

test('customer detail email timeline csv export applies active provider context filters and labels legacy rows', function () {
    $profile = MarketingProfile::query()->create([
        'first_name' => 'Export',
        'last_name' => 'Timeline',
        'email' => 'export.timeline@example.com',
        'normalized_email' => 'export.timeline@example.com',
    ]);

    MarketingEmailDelivery::query()->create([
        'marketing_profile_id' => $profile->id,
        'provider' => 'sendgrid',
        'email' => $profile->email,
        'status' => 'failed',
        'metadata' => [
            'provider_resolution_source' => 'fallback',
            'provider_readiness_status' => 'ready',
            'provider_config_status' => 'configured',
            'provider_using_fallback_config' => true,
        ],
    ]);

    MarketingEmailDelivery::query()->create([
        'marketing_profile_id' => $profile->id,
        'provider' => 'shopify_email',
        'email' => $profile->email,
        'status' => 'failed',
        'metadata' => [
            'provider_resolution_source' => 'tenant',
            'provider_readiness_status' => 'unsupported',
            'provider_config_status' => 'disabled',
        ],
    ]);

    MarketingEmailDelivery::query()->create([
        'marketing_profile_id' => $profile->id,
        'provider' => 'sendgrid',
        'email' => $profile->email,
        'status' => 'failed',
        'metadata' => [],
    ]);

    $admin = User::factory()->create([
        'role' => 'admin',
        'email_verified_at' => now(),
    ]);

    $fallbackResponse = $this->actingAs($admin)
        ->get(route('marketing.customers.email-deliveries.export', [
            'marketingProfile' => $profile,
            'provider_resolution_source' => 'fallback',
        ]));

    $fallbackResponse->assertOk()
        ->assertHeader('content-type', 'text/csv; charset=UTF-8');

    $fallbackRows = collect(parseCustomerEmailTimelineCsv($fallbackResponse->streamedContent()));
    expect($fallbackRows)->toHaveCount(1)
        ->and((string) data_get($fallbackRows->first(), 'provider_resolution_source'))->toBe('fallback')
        ->and((string) data_get($fallbackRows->first(), 'provider_readiness_status'))->toBe('ready')
        ->and((string) data_get($fallbackRows->first(), 'context_label'))->toBe('Sent via fallback provider config.');

    $unsupportedResponse = $this->actingAs($admin)
        ->get(route('marketing.customers.email-deliveries.export', [
            'marketingProfile' => $profile,
            'provider_readiness_status' => 'unsupported',
        ]));

    $unsupportedRows = collect(parseCustomerEmailTimelineCsv($unsupportedResponse->streamedContent()));
    expect($unsupportedRows)->toHaveCount(1)
        ->and((string) data_get($unsupportedRows->first(), 'provider_readiness_status'))->toBe('unsupported')
        ->and((string) data_get($unsupportedRows->first(), 'context_label'))->toBe('Attempted with unsupported provider runtime.');

    $unknownResponse = $this->actingAs($admin)
        ->get(route('marketing.customers.email-deliveries.export', [
            'marketingProfile' => $profile,
            'provider_resolution_source' => 'unknown',
        ]));

    $unknownRows = collect(parseCustomerEmailTimelineCsv($unknownResponse->streamedContent()));
    expect($unknownRows)->toHaveCount(1)
        ->and((string) data_get($unknownRows->first(), 'provider_resolution_source'))->toBe('unknown')
        ->and((string) data_get($unknownRows->first(), 'provider_resolution_source_label'))->toBe('Legacy / unavailable')
        ->and((string) data_get($unknownRows->first(), 'context_label'))->toBe('Provider context unavailable for legacy row.');
});

test('customer detail email timeline filters by date boundaries with day-level semantics', function () {
    $profile = MarketingProfile::query()->create([
        'first_name' => 'Date',
        'last_name' => 'Filter',
        'email' => 'date.filter@example.com',
        'normalized_email' => 'date.filter@example.com',
    ]);

    $tenantRow = MarketingEmailDelivery::query()->create([
        'marketing_profile_id' => $profile->id,
        'provider' => 'sendgrid',
        'email' => $profile->email,
        'status' => 'sent',
        'metadata' => [
            'provider_resolution_source' => 'tenant',
            'provider_readiness_status' => 'ready',
            'provider_config_status' => 'configured',
        ],
    ]);
    $tenantRow->forceFill([
        'created_at' => Carbon::parse('2026-03-01 09:00:00'),
        'updated_at' => Carbon::parse('2026-03-01 09:00:00'),
    ])->saveQuietly();

    $fallbackRow = MarketingEmailDelivery::query()->create([
        'marketing_profile_id' => $profile->id,
        'provider' => 'sendgrid',
        'email' => $profile->email,
        'status' => 'failed',
        'metadata' => [
            'provider_resolution_source' => 'fallback',
            'provider_readiness_status' => 'ready',
            'provider_config_status' => 'configured',
            'provider_using_fallback_config' => true,
        ],
    ]);
    $fallbackRow->forceFill([
        'created_at' => Carbon::parse('2026-03-10 11:00:00'),
        'updated_at' => Carbon::parse('2026-03-10 11:00:00'),
    ])->saveQuietly();

    $unsupportedRow = MarketingEmailDelivery::query()->create([
        'marketing_profile_id' => $profile->id,
        'provider' => 'shopify_email',
        'email' => $profile->email,
        'status' => 'failed',
        'metadata' => [
            'provider_resolution_source' => 'tenant',
            'provider_readiness_status' => 'unsupported',
            'provider_config_status' => 'disabled',
        ],
    ]);
    $unsupportedRow->forceFill([
        'created_at' => Carbon::parse('2026-03-20 16:15:00'),
        'updated_at' => Carbon::parse('2026-03-20 16:15:00'),
    ])->saveQuietly();

    $admin = User::factory()->create([
        'role' => 'admin',
        'email_verified_at' => now(),
    ]);

    $this->actingAs($admin)
        ->get(route('marketing.customers.show', [
            'marketingProfile' => $profile,
            'date_from' => '2026-03-10',
        ]))
        ->assertOk()
        ->assertSeeText('Sent via fallback provider config.')
        ->assertSeeText('Attempted with unsupported provider runtime.')
        ->assertDontSeeText('Sent via tenant-configured SendGrid.');

    $this->actingAs($admin)
        ->get(route('marketing.customers.show', [
            'marketingProfile' => $profile,
            'date_to' => '2026-03-10',
        ]))
        ->assertOk()
        ->assertSeeText('Sent via tenant-configured SendGrid.')
        ->assertSeeText('Sent via fallback provider config.')
        ->assertDontSeeText('Attempted with unsupported provider runtime.');

    $this->actingAs($admin)
        ->get(route('marketing.customers.show', [
            'marketingProfile' => $profile,
            'date_from' => '2026-03-02',
            'date_to' => '2026-03-12',
        ]))
        ->assertOk()
        ->assertSeeText('Sent via fallback provider config.')
        ->assertDontSeeText('Sent via tenant-configured SendGrid.')
        ->assertDontSeeText('Attempted with unsupported provider runtime.');
});

test('customer detail email timeline rejects invalid date range filters', function () {
    $profile = MarketingProfile::query()->create([
        'first_name' => 'Invalid',
        'last_name' => 'Range',
        'email' => 'invalid.range@example.com',
        'normalized_email' => 'invalid.range@example.com',
    ]);

    $admin = User::factory()->create([
        'role' => 'admin',
        'email_verified_at' => now(),
    ]);

    $this->actingAs($admin)
        ->from(route('marketing.customers.show', $profile))
        ->get(route('marketing.customers.show', [
            'marketingProfile' => $profile,
            'date_from' => '2026-03-31',
            'date_to' => '2026-03-01',
        ]))
        ->assertRedirect(route('marketing.customers.show', $profile))
        ->assertSessionHasErrors(['date_to']);
});

test('customer detail email timeline filters by canonical delivery status and combined filters', function () {
    $profile = MarketingProfile::query()->create([
        'first_name' => 'Status',
        'last_name' => 'Filter',
        'email' => 'status.filter@example.com',
        'normalized_email' => 'status.filter@example.com',
    ]);

    $sentTenant = MarketingEmailDelivery::query()->create([
        'marketing_profile_id' => $profile->id,
        'provider' => 'sendgrid',
        'email' => $profile->email,
        'status' => 'sent',
        'sent_at' => now(),
        'metadata' => [
            'provider_resolution_source' => 'tenant',
            'provider_readiness_status' => 'ready',
            'provider_config_status' => 'configured',
        ],
    ]);
    $sentTenant->forceFill([
        'created_at' => Carbon::parse('2026-04-01 08:00:00'),
        'updated_at' => Carbon::parse('2026-04-01 08:00:00'),
    ])->saveQuietly();

    $failedFallback = MarketingEmailDelivery::query()->create([
        'marketing_profile_id' => $profile->id,
        'provider' => 'sendgrid',
        'email' => $profile->email,
        'status' => 'failed',
        'failed_at' => now(),
        'metadata' => [
            'provider_resolution_source' => 'fallback',
            'provider_readiness_status' => 'ready',
            'provider_config_status' => 'configured',
            'provider_using_fallback_config' => true,
        ],
    ]);
    $failedFallback->forceFill([
        'created_at' => Carbon::parse('2026-04-02 09:00:00'),
        'updated_at' => Carbon::parse('2026-04-02 09:00:00'),
    ])->saveQuietly();

    $unsupportedTenant = MarketingEmailDelivery::query()->create([
        'marketing_profile_id' => $profile->id,
        'provider' => 'shopify_email',
        'email' => $profile->email,
        'status' => 'failed',
        'failed_at' => now(),
        'metadata' => [
            'provider_resolution_source' => 'tenant',
            'provider_readiness_status' => 'unsupported',
            'provider_config_status' => 'disabled',
        ],
    ]);
    $unsupportedTenant->forceFill([
        'created_at' => Carbon::parse('2026-04-03 10:00:00'),
        'updated_at' => Carbon::parse('2026-04-03 10:00:00'),
    ])->saveQuietly();

    $admin = User::factory()->create([
        'role' => 'admin',
        'email_verified_at' => now(),
    ]);

    $this->actingAs($admin)
        ->get(route('marketing.customers.show', [
            'marketingProfile' => $profile,
            'status' => 'failed',
        ]))
        ->assertOk()
        ->assertSeeText('Sent via fallback provider config.')
        ->assertSeeText('Attempted with unsupported provider runtime.')
        ->assertDontSeeText('Sent via tenant-configured SendGrid.');

    $this->actingAs($admin)
        ->get(route('marketing.customers.show', [
            'marketingProfile' => $profile,
            'provider_resolution_source' => 'fallback',
            'status' => 'failed',
            'date_from' => '2026-04-01',
            'date_to' => '2026-04-03',
        ]))
        ->assertOk()
        ->assertSeeText('Sent via fallback provider config.')
        ->assertDontSeeText('Attempted with unsupported provider runtime.')
        ->assertDontSeeText('Sent via tenant-configured SendGrid.');

    $this->actingAs($admin)
        ->get(route('marketing.customers.show', [
            'marketingProfile' => $profile,
            'status' => 'clicked',
        ]))
        ->assertOk()
        ->assertSeeText('No email touches match the active timeline filters.');
});

test('customer detail email timeline csv export preserves parity across provider status and date filters', function () {
    $profile = MarketingProfile::query()->create([
        'first_name' => 'Export',
        'last_name' => 'Parity',
        'email' => 'export.parity@example.com',
        'normalized_email' => 'export.parity@example.com',
    ]);

    $matchingRow = MarketingEmailDelivery::query()->create([
        'marketing_profile_id' => $profile->id,
        'provider' => 'sendgrid',
        'email' => $profile->email,
        'status' => 'failed',
        'failed_at' => now(),
        'metadata' => [
            'provider_resolution_source' => 'fallback',
            'provider_readiness_status' => 'ready',
            'provider_config_status' => 'configured',
            'provider_using_fallback_config' => true,
        ],
    ]);
    $matchingRow->forceFill([
        'created_at' => Carbon::parse('2026-05-10 12:00:00'),
        'updated_at' => Carbon::parse('2026-05-10 12:00:00'),
    ])->saveQuietly();

    $outOfRangeRow = MarketingEmailDelivery::query()->create([
        'marketing_profile_id' => $profile->id,
        'provider' => 'sendgrid',
        'email' => $profile->email,
        'status' => 'failed',
        'failed_at' => now(),
        'metadata' => [
            'provider_resolution_source' => 'fallback',
            'provider_readiness_status' => 'ready',
            'provider_config_status' => 'configured',
            'provider_using_fallback_config' => true,
        ],
    ]);
    $outOfRangeRow->forceFill([
        'created_at' => Carbon::parse('2026-05-02 12:00:00'),
        'updated_at' => Carbon::parse('2026-05-02 12:00:00'),
    ])->saveQuietly();

    MarketingEmailDelivery::query()->create([
        'marketing_profile_id' => $profile->id,
        'provider' => 'shopify_email',
        'email' => $profile->email,
        'status' => 'failed',
        'failed_at' => now(),
        'metadata' => [
            'provider_resolution_source' => 'tenant',
            'provider_readiness_status' => 'unsupported',
            'provider_config_status' => 'disabled',
        ],
    ]);

    $admin = User::factory()->create([
        'role' => 'admin',
        'email_verified_at' => now(),
    ]);

    $response = $this->actingAs($admin)
        ->get(route('marketing.customers.email-deliveries.export', [
            'marketingProfile' => $profile,
            'provider_resolution_source' => 'fallback',
            'provider_readiness_status' => 'ready',
            'status' => 'failed',
            'date_from' => '2026-05-05',
            'date_to' => '2026-05-31',
        ]));

    $rows = collect(parseCustomerEmailTimelineCsv($response->streamedContent()));

    $response->assertOk();
    expect($rows)->toHaveCount(1)
        ->and((string) data_get($rows->first(), 'provider_resolution_source'))->toBe('fallback')
        ->and((string) data_get($rows->first(), 'provider_readiness_status'))->toBe('ready')
        ->and((string) data_get($rows->first(), 'status'))->toBe('failed')
        ->and((string) data_get($rows->first(), 'normalized_status'))->toBe('failed')
        ->and((string) data_get($rows->first(), 'context_label'))->toBe('Sent via fallback provider config.');
});

test('customer detail email timeline paginates filtered results and keeps summary counts on the full filtered set', function () {
    $profile = MarketingProfile::query()->create([
        'first_name' => 'Paged',
        'last_name' => 'Timeline',
        'email' => 'paged.timeline@example.com',
        'normalized_email' => 'paged.timeline@example.com',
    ]);

    for ($i = 1; $i <= 33; $i++) {
        MarketingEmailDelivery::query()->create([
            'marketing_profile_id' => $profile->id,
            'provider' => 'sendgrid',
            'email' => "bulk-paginated-{$i}@example.com",
            'status' => 'failed',
            'failed_at' => now(),
            'metadata' => [
                'provider_resolution_source' => 'fallback',
                'provider_readiness_status' => 'ready',
                'provider_config_status' => 'configured',
                'provider_using_fallback_config' => true,
            ],
        ]);
    }

    $admin = User::factory()->create([
        'role' => 'admin',
        'email_verified_at' => now(),
    ]);

    $this->actingAs($admin)
        ->get(route('marketing.customers.show', ['marketingProfile' => $profile]))
        ->assertOk()
        ->assertSeeText('Summary chips reflect the full filtered result set.')
        ->assertSeeText('Showing')
        ->assertSeeText('1-25')
        ->assertSeeText('filtered attempts.')
        ->assertSeeText('Fallback configuration: 33')
        ->assertSeeText('bulk-paginated-33@example.com')
        ->assertDontSeeText('bulk-paginated-8@example.com')
        ->assertSee('email_page=2', false);

    $this->actingAs($admin)
        ->get(route('marketing.customers.show', [
            'marketingProfile' => $profile,
            'email_page' => 2,
        ]))
        ->assertOk()
        ->assertSeeText('Showing')
        ->assertSeeText('26-33')
        ->assertSeeText('filtered attempts.')
        ->assertSeeText('bulk-paginated-8@example.com')
        ->assertDontSeeText('bulk-paginated-33@example.com');
});

test('customer detail email timeline pagination preserves provider filters across pages', function () {
    $profile = MarketingProfile::query()->create([
        'first_name' => 'Paged',
        'last_name' => 'ProviderFilter',
        'email' => 'paged.provider@example.com',
        'normalized_email' => 'paged.provider@example.com',
    ]);

    for ($i = 1; $i <= 28; $i++) {
        MarketingEmailDelivery::query()->create([
            'marketing_profile_id' => $profile->id,
            'provider' => 'sendgrid',
            'email' => "fallback-page-{$i}@example.com",
            'status' => 'failed',
            'metadata' => [
                'provider_resolution_source' => 'fallback',
                'provider_readiness_status' => 'ready',
                'provider_config_status' => 'configured',
                'provider_using_fallback_config' => true,
            ],
        ]);
    }

    for ($i = 1; $i <= 5; $i++) {
        MarketingEmailDelivery::query()->create([
            'marketing_profile_id' => $profile->id,
            'provider' => 'sendgrid',
            'email' => "tenant-page-{$i}@example.com",
            'status' => 'sent',
            'sent_at' => now(),
            'metadata' => [
                'provider_resolution_source' => 'tenant',
                'provider_readiness_status' => 'ready',
                'provider_config_status' => 'configured',
                'provider_using_fallback_config' => false,
            ],
        ]);
    }

    $admin = User::factory()->create([
        'role' => 'admin',
        'email_verified_at' => now(),
    ]);

    $this->actingAs($admin)
        ->get(route('marketing.customers.show', [
            'marketingProfile' => $profile,
            'provider_resolution_source' => 'fallback',
        ]))
        ->assertOk()
        ->assertSeeText('Showing')
        ->assertSeeText('1-25')
        ->assertSeeText('filtered attempts.')
        ->assertSeeText('Fallback configuration: 28')
        ->assertDontSeeText('tenant-page-5@example.com')
        ->assertSee('provider_resolution_source=fallback', false)
        ->assertSee('email_page=2', false);

    $this->actingAs($admin)
        ->get(route('marketing.customers.show', [
            'marketingProfile' => $profile,
            'provider_resolution_source' => 'fallback',
            'email_page' => 2,
        ]))
        ->assertOk()
        ->assertSeeText('Showing')
        ->assertSeeText('26-28')
        ->assertSeeText('filtered attempts.')
        ->assertSeeText('fallback-page-3@example.com')
        ->assertDontSeeText('tenant-page-5@example.com');
});

test('customer detail email timeline pagination respects date and status filters with export parity for full filtered rows', function () {
    $profile = MarketingProfile::query()->create([
        'first_name' => 'Paged',
        'last_name' => 'DateStatus',
        'email' => 'paged.datestatus@example.com',
        'normalized_email' => 'paged.datestatus@example.com',
    ]);

    for ($i = 1; $i <= 31; $i++) {
        $row = MarketingEmailDelivery::query()->create([
            'marketing_profile_id' => $profile->id,
            'provider' => 'sendgrid',
            'email' => "filtered-range-{$i}@example.com",
            'status' => 'failed',
            'failed_at' => now(),
            'metadata' => [
                'provider_resolution_source' => 'fallback',
                'provider_readiness_status' => 'ready',
                'provider_config_status' => 'configured',
                'provider_using_fallback_config' => true,
            ],
        ]);

        $row->forceFill([
            'created_at' => Carbon::parse('2026-06-15 10:00:00'),
            'updated_at' => Carbon::parse('2026-06-15 10:00:00'),
        ])->saveQuietly();
    }

    for ($i = 1; $i <= 6; $i++) {
        $outOfRange = MarketingEmailDelivery::query()->create([
            'marketing_profile_id' => $profile->id,
            'provider' => 'sendgrid',
            'email' => "outside-range-{$i}@example.com",
            'status' => 'failed',
            'failed_at' => now(),
            'metadata' => [
                'provider_resolution_source' => 'fallback',
                'provider_readiness_status' => 'ready',
                'provider_config_status' => 'configured',
                'provider_using_fallback_config' => true,
            ],
        ]);
        $outOfRange->forceFill([
            'created_at' => Carbon::parse('2026-05-15 10:00:00'),
            'updated_at' => Carbon::parse('2026-05-15 10:00:00'),
        ])->saveQuietly();
    }

    $admin = User::factory()->create([
        'role' => 'admin',
        'email_verified_at' => now(),
    ]);

    $filters = [
        'provider_resolution_source' => 'fallback',
        'status' => 'failed',
        'date_from' => '2026-06-01',
        'date_to' => '2026-06-30',
    ];

    $this->actingAs($admin)
        ->get(route('marketing.customers.show', array_merge([
            'marketingProfile' => $profile,
        ], $filters)))
        ->assertOk()
        ->assertSeeText('Showing')
        ->assertSeeText('1-25')
        ->assertSeeText('filtered attempts.')
        ->assertSee('provider_resolution_source=fallback', false)
        ->assertSee('status=failed', false)
        ->assertSee('date_from=2026-06-01', false)
        ->assertSee('date_to=2026-06-30', false)
        ->assertSee('email_page=2', false)
        ->assertDontSeeText('outside-range-6@example.com');

    $exportResponse = $this->actingAs($admin)
        ->get(route('marketing.customers.email-deliveries.export', array_merge([
            'marketingProfile' => $profile,
            'email_page' => 2,
        ], $filters)));

    $exportRows = collect(parseCustomerEmailTimelineCsv($exportResponse->streamedContent()));

    $exportResponse->assertOk()
        ->assertHeader('content-type', 'text/csv; charset=UTF-8');

    expect($exportRows)->toHaveCount(31)
        ->and($exportRows->pluck('recipient_email')->contains('outside-range-1@example.com'))->toBeFalse();
});

test('customer detail email timeline paginates legacy unknown rows safely', function () {
    $profile = MarketingProfile::query()->create([
        'first_name' => 'Paged',
        'last_name' => 'Legacy',
        'email' => 'paged.legacy@example.com',
        'normalized_email' => 'paged.legacy@example.com',
    ]);

    for ($i = 1; $i <= 27; $i++) {
        MarketingEmailDelivery::query()->create([
            'marketing_profile_id' => $profile->id,
            'provider' => 'sendgrid',
            'email' => "legacy-page-{$i}@example.com",
            'status' => 'failed',
            'metadata' => [],
        ]);
    }

    $admin = User::factory()->create([
        'role' => 'admin',
        'email_verified_at' => now(),
    ]);

    $this->actingAs($admin)
        ->get(route('marketing.customers.show', [
            'marketingProfile' => $profile,
            'provider_resolution_source' => 'unknown',
        ]))
        ->assertOk()
        ->assertSeeText('Showing')
        ->assertSeeText('1-25')
        ->assertSeeText('filtered attempts.')
        ->assertSeeText('Legacy / unavailable: 27')
        ->assertDontSeeText('legacy-page-2@example.com');

    $this->actingAs($admin)
        ->get(route('marketing.customers.show', [
            'marketingProfile' => $profile,
            'provider_resolution_source' => 'unknown',
            'email_page' => 2,
        ]))
        ->assertOk()
        ->assertSeeText('Showing')
        ->assertSeeText('26-27')
        ->assertSeeText('filtered attempts.')
        ->assertSeeText('legacy-page-2@example.com');
});

test('customers index includes shopify and square-only canonical profiles without requiring growave', function () {
    Order::factory()->create([
        'source' => 'shopify_retail',
        'order_type' => 'retail',
        'shopify_store_key' => 'retail',
        'shopify_store' => 'retail',
        'shopify_order_id' => 7101,
        'shopify_customer_id' => '8101',
        'first_name' => 'Shopify',
        'last_name' => 'Only',
        'email' => 'shopify.only.index@example.com',
        'phone' => '+1 (555) 440-0101',
    ]);

    SquareCustomer::query()->create([
        'square_customer_id' => 'SQ-CUST-IDX-1',
        'given_name' => 'Square',
        'family_name' => 'Only',
        'email' => 'square.only.index@example.com',
        'phone' => '+1 (555) 440-0202',
        'synced_at' => now(),
    ]);

    $this->artisan('marketing:sync-profiles --source=shopify')->assertExitCode(0);
    $this->artisan('marketing:sync-profiles --source=square')->assertExitCode(0);

    $shopifyProfile = MarketingProfile::query()->where('normalized_email', 'shopify.only.index@example.com')->first();
    $squareProfile = MarketingProfile::query()->where('normalized_email', 'square.only.index@example.com')->first();

    expect($shopifyProfile)->not->toBeNull()
        ->and($squareProfile)->not->toBeNull();

    $admin = User::factory()->create([
        'role' => 'admin',
        'email_verified_at' => now(),
    ]);

    $this->actingAs($admin)
        ->get(route('marketing.customers'))
        ->assertOk()
        ->assertSeeText('Manage Customers');

    $response = $this->actingAs($admin)
        ->getJson(route('marketing.customers.data'));

    $customers = collect($response->json('data'));

    $response->assertOk();

    expect($customers->pluck('customer'))->toContain('Shopify Only', 'Square Only')
        ->and($customers->pluck('email'))->toContain('shopify.only.index@example.com', 'square.only.index@example.com');
});

test('customers index row links to detail page and detail renders canonical identity fields', function () {
    $profile = MarketingProfile::query()->create([
        'first_name' => 'Riley',
        'last_name' => 'Carter',
        'email' => 'riley.carter@example.com',
        'normalized_email' => 'riley.carter@example.com',
        'phone' => '555-321-0000',
        'normalized_phone' => '5553210000',
        'notes' => 'Existing internal notes',
        'source_channels' => ['manual'],
    ]);

    MarketingProfileLink::query()->create([
        'marketing_profile_id' => $profile->id,
        'source_type' => 'manual_customer',
        'source_id' => 'manual_profile:'.$profile->id,
        'source_meta' => [],
        'match_method' => 'seed',
        'confidence' => 1.00,
    ]);

    $admin = User::factory()->create([
        'role' => 'admin',
        'email_verified_at' => now(),
    ]);

    $showUrl = route('marketing.customers.show', $profile);

    $this->actingAs($admin)
        ->get(route('marketing.customers'))
        ->assertOk()
        ->assertSeeText('Manage Customers');

    $this->actingAs($admin)
        ->getJson(route('marketing.customers.data'))
        ->assertOk()
        ->assertJsonPath('data.0.customer', 'Riley Carter')
        ->assertJsonPath('data.0.profile_url', $showUrl);

    $this->actingAs($admin)
        ->get($showUrl)
        ->assertOk()
        ->assertSeeText('Identity + Address Update')
        ->assertSeeText('External Enrichment (Read-Only)')
        ->assertSeeText('riley.carter@example.com')
        ->assertSeeText('555-321-0000')
        ->assertSee('name="first_name"', false)
        ->assertSee('name="last_name"', false)
        ->assertSee('name="email"', false)
        ->assertSee('name="phone"', false);
});

test('customer detail prefers native review projections while keeping legacy growave history visible', function () {
    $profile = MarketingProfile::query()->create([
        'first_name' => 'Native',
        'last_name' => 'Preference',
        'email' => 'native.preference@example.com',
        'normalized_email' => 'native.preference@example.com',
        'phone' => '555-777-1111',
        'normalized_phone' => '5557771111',
        'source_channels' => ['shopify'],
    ]);

    CustomerExternalProfile::query()->create([
        'marketing_profile_id' => $profile->id,
        'provider' => 'shopify',
        'integration' => 'growave',
        'store_key' => 'retail',
        'external_customer_id' => 'LEGACY-NATIVE-PREF',
        'points_balance' => 220,
        'vip_tier' => 'Silver',
        'referral_link' => 'https://example.test/ref/native-preference',
        'synced_at' => now(),
    ]);

    MarketingReviewSummary::query()->create([
        'marketing_profile_id' => $profile->id,
        'provider' => 'growave',
        'integration' => 'growave',
        'store_key' => 'retail',
        'external_customer_id' => 'LEGACY-NATIVE-PREF',
        'review_count' => 7,
        'published_review_count' => 7,
        'average_rating' => 4.20,
        'source_synced_at' => now(),
    ]);

    MarketingReviewHistory::query()->create([
        'marketing_profile_id' => $profile->id,
        'provider' => 'backstage',
        'integration' => 'native',
        'store_key' => 'retail',
        'external_customer_id' => 'profile:' . $profile->id,
        'external_review_id' => 'native-pref-1',
        'rating' => 5,
        'title' => 'Native-first projection',
        'body' => 'This native review should be used as the primary runtime signal.',
        'reviewer_name' => 'Native Preference',
        'reviewer_email' => $profile->email,
        'is_published' => true,
        'status' => 'approved',
        'submission_source' => 'native_storefront',
        'product_id' => 'native-pref-1',
        'product_handle' => 'native-pref-candle',
        'product_title' => 'Native Preference Candle',
        'submitted_at' => now()->subMinutes(3),
        'approved_at' => now()->subMinutes(2),
    ]);

    $admin = User::factory()->create([
        'role' => 'admin',
        'email_verified_at' => now(),
    ]);

    $this->actingAs($admin)
        ->get(route('marketing.customers.show', $profile))
        ->assertOk()
        ->assertSeeText('Active Review Source')
        ->assertSeeText('Native Backstage')
        ->assertSeeText('Native Backstage Reviews')
        ->assertSeeText('Legacy Growave Reviews (Read-Only)');
});

test('customer detail update saves canonical fields and keeps growave enrichment read-only', function () {
    $profile = MarketingProfile::query()->create([
        'first_name' => 'Melissa',
        'last_name' => 'Orr',
        'email' => 'melissa.orr@example.com',
        'normalized_email' => 'melissa.orr@example.com',
        'phone' => '555-100-2000',
        'normalized_phone' => '5551002000',
        'notes' => 'Before update',
        'source_channels' => ['shopify'],
    ]);

    $external = CustomerExternalProfile::query()->create([
        'marketing_profile_id' => $profile->id,
        'provider' => 'shopify',
        'integration' => 'growave',
        'store_key' => 'retail',
        'external_customer_id' => 'GRO-2001',
        'points_balance' => 240,
        'vip_tier' => 'Gold',
        'referral_link' => 'https://example.test/ref/melissa',
        'synced_at' => now(),
    ]);

    $admin = User::factory()->create([
        'role' => 'admin',
        'email_verified_at' => now(),
    ]);

    $this->actingAs($admin)
        ->patch(route('marketing.customers.update', $profile), [
            'first_name' => 'Mel',
            'last_name' => 'Orr-Updated',
            'email' => 'mel.updated@example.com',
            'phone' => '(555) 777-8888',
            'notes' => 'Updated internal profile note',
            'points_balance' => 9999,
            'vip_tier' => 'Diamond',
            'referral_link' => 'https://malicious.example/ref',
        ])
        ->assertRedirect(route('marketing.customers.show', $profile));

    $profile->refresh();
    $external->refresh();

    expect($profile->first_name)->toBe('Mel')
        ->and($profile->last_name)->toBe('Orr-Updated')
        ->and($profile->normalized_email)->toBe('mel.updated@example.com')
        ->and($profile->normalized_phone)->toBe('5557778888')
        ->and($profile->notes)->toBe('Updated internal profile note')
        ->and((int) $external->points_balance)->toBe(240)
        ->and((string) $external->vip_tier)->toBe('Gold')
        ->and((string) $external->referral_link)->toBe('https://example.test/ref/melissa');
});

test('add customer wizard creates canonical customer and manual source link', function () {
    $admin = User::factory()->create([
        'role' => 'admin',
        'email_verified_at' => now(),
    ]);

    $this->actingAs($admin)
        ->post(route('marketing.customers.store-create'), [
            'step' => 1,
            'direction' => 'next',
            'first_name' => 'Morgan',
            'last_name' => 'Reed',
            'email' => 'morgan.reed@example.com',
            'phone' => '555-555-1212',
        ])->assertRedirect(route('marketing.customers.create', ['step' => 2]));

    $this->actingAs($admin)
        ->post(route('marketing.customers.store-create'), [
            'step' => 2,
            'direction' => 'next',
            'customer_context' => 'wholesale',
        ])->assertRedirect(route('marketing.customers.create', ['step' => 3]));

    $this->actingAs($admin)
        ->post(route('marketing.customers.store-create'), [
            'step' => 3,
            'direction' => 'next',
            'decision' => 'continue',
        ])->assertRedirect(route('marketing.customers.create', ['step' => 4]));

    $this->actingAs($admin)
        ->post(route('marketing.customers.store-create'), [
            'step' => 4,
            'direction' => 'next',
            'notes' => 'High-priority wholesale buyer',
            'company_store_name' => 'North Pine Mercantile',
            'tags' => 'wholesale,vip',
            'accepts_email_marketing' => '1',
            'accepts_sms_marketing' => '1',
        ])->assertRedirect(route('marketing.customers.create', ['step' => 5]));

    $response = $this->actingAs($admin)
        ->post(route('marketing.customers.store-create'), [
            'step' => 5,
            'direction' => 'next',
            'confirm_create' => '1',
        ]);

    $profile = MarketingProfile::query()->where('normalized_email', 'morgan.reed@example.com')->first();

    expect($profile)->not->toBeNull()
        ->and((bool) $profile->accepts_email_marketing)->toBeTrue()
        ->and((bool) $profile->accepts_sms_marketing)->toBeTrue()
        ->and((array) $profile->source_channels)->toContain('manual', 'wholesale')
        ->and((string) ($profile->notes ?? ''))->toContain('North Pine Mercantile')
        ->and(MarketingProfileLink::query()
            ->where('marketing_profile_id', $profile->id)
            ->where('source_type', 'manual_customer')
            ->exists())->toBeTrue();

    $response->assertRedirect(route('marketing.customers.show', $profile));
});

test('add customer wizard can select an existing canonical profile instead of creating duplicate', function () {
    $existing = MarketingProfile::query()->create([
        'first_name' => 'Denise',
        'last_name' => 'Wohlford',
        'email' => 'denise@example.com',
        'normalized_email' => 'denise@example.com',
    ]);

    $admin = User::factory()->create([
        'role' => 'admin',
        'email_verified_at' => now(),
    ]);

    $this->actingAs($admin)
        ->post(route('marketing.customers.store-create'), [
            'step' => 1,
            'direction' => 'next',
            'first_name' => 'Denise',
            'last_name' => 'Wohlford',
            'email' => 'DENISE@example.com',
            'phone' => '555-111-2222',
        ])->assertRedirect(route('marketing.customers.create', ['step' => 2]));

    $this->actingAs($admin)
        ->post(route('marketing.customers.store-create'), [
            'step' => 2,
            'direction' => 'next',
            'customer_context' => 'retail',
        ])->assertRedirect(route('marketing.customers.create', ['step' => 3]));

    $this->actingAs($admin)
        ->get(route('marketing.customers.create', ['step' => 3]))
        ->assertOk()
        ->assertSeeText('Denise Wohlford');

    $this->actingAs($admin)
        ->post(route('marketing.customers.store-create'), [
            'step' => 3,
            'direction' => 'next',
            'decision' => 'use_existing',
            'selected_profile_id' => $existing->id,
        ])->assertRedirect(route('marketing.customers.create', ['step' => 4]));

    $this->actingAs($admin)
        ->post(route('marketing.customers.store-create'), [
            'step' => 4,
            'direction' => 'next',
            'notes' => 'Merged via wizard',
        ])->assertRedirect(route('marketing.customers.create', ['step' => 5]));

    $response = $this->actingAs($admin)
        ->post(route('marketing.customers.store-create'), [
            'step' => 5,
            'direction' => 'next',
            'confirm_create' => '1',
        ]);

    expect(MarketingProfile::query()->count())->toBe(1)
        ->and((array) $existing->fresh()->source_channels)->toContain('manual', 'retail')
        ->and(MarketingProfileLink::query()
            ->where('marketing_profile_id', $existing->id)
            ->where('source_type', 'manual_customer')
            ->exists())->toBeTrue();

    $response->assertRedirect(route('marketing.customers.show', $existing));
});

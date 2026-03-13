<?php

use App\Models\MarketingIdentityReview;
use App\Models\MarketingImportRun;
use App\Models\MarketingProfile;
use App\Models\MarketingProfileLink;
use App\Models\SquareCustomer;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config()->set('marketing.square.enabled', true);
    config()->set('marketing.square.sync_customers_enabled', true);
    config()->set('marketing.square.sync_orders_enabled', true);
    config()->set('marketing.square.sync_payments_enabled', true);
    config()->set('marketing.square.access_token', 'test-token');
    config()->set('marketing.square.base_url', 'https://connect.squareup.com');
});

test('square customer sync creates and updates source records idempotently', function () {
    Http::fake([
        'https://connect.squareup.com/v2/customers*' => Http::response([
            'customers' => [[
                'id' => 'SQ-CUST-1',
                'given_name' => 'Taylor',
                'family_name' => 'Lane',
                'email_address' => 'taylor@example.com',
                'phone_number' => '(555) 991-1122',
            ]],
            'cursor' => null,
        ], 200),
    ]);

    $this->artisan('marketing:sync-square-customers --limit=50')->assertExitCode(0);
    $this->artisan('marketing:sync-square-customers --limit=50')->assertExitCode(0);

    expect(\App\Models\SquareCustomer::query()->count())->toBe(1)
        ->and(MarketingImportRun::query()->where('type', 'square_customers_sync')->count())->toBe(2)
        ->and(MarketingProfileLink::query()->where('source_type', 'square_customer')->where('source_id', 'SQ-CUST-1')->count())->toBe(1);
});

test('square customer exact email links to existing marketing profile', function () {
    $profile = MarketingProfile::query()->create([
        'first_name' => 'Existing',
        'email' => 'existing@example.com',
        'normalized_email' => 'existing@example.com',
    ]);

    Http::fake([
        'https://connect.squareup.com/v2/customers*' => Http::response([
            'customers' => [[
                'id' => 'SQ-CUST-EMAIL',
                'email_address' => 'EXISTING@example.com',
                'phone_number' => '',
            ]],
        ], 200),
    ]);

    $this->artisan('marketing:sync-square-customers --limit=1')->assertExitCode(0);

    expect(MarketingProfile::query()->count())->toBe(1)
        ->and(MarketingProfileLink::query()
            ->where('marketing_profile_id', $profile->id)
            ->where('source_type', 'square_customer')
            ->where('source_id', 'SQ-CUST-EMAIL')
            ->exists())->toBeTrue();
});

test('square customer exact phone links to existing marketing profile', function () {
    $profile = MarketingProfile::query()->create([
        'first_name' => 'Phone Match',
        'phone' => '555-123-5678',
        'normalized_phone' => '+15551235678',
    ]);

    Http::fake([
        'https://connect.squareup.com/v2/customers*' => Http::response([
            'customers' => [[
                'id' => 'SQ-CUST-PHONE',
                'email_address' => '',
                'phone_number' => '+1 (555) 123-5678',
            ]],
        ], 200),
    ]);

    $this->artisan('marketing:sync-square-customers --limit=1')->assertExitCode(0);

    expect(MarketingProfile::query()->count())->toBe(1)
        ->and(MarketingProfileLink::query()
            ->where('marketing_profile_id', $profile->id)
            ->where('source_type', 'square_customer')
            ->where('source_id', 'SQ-CUST-PHONE')
            ->exists())->toBeTrue();
});

test('square customer conflicting identifiers create identity review', function () {
    MarketingProfile::query()->create([
        'email' => 'conflict@example.com',
        'normalized_email' => 'conflict@example.com',
    ]);
    MarketingProfile::query()->create([
        'phone' => '5552228888',
        'normalized_phone' => '+15552228888',
    ]);

    Http::fake([
        'https://connect.squareup.com/v2/customers*' => Http::response([
            'customers' => [[
                'id' => 'SQ-CUST-CONFLICT',
                'email_address' => 'conflict@example.com',
                'phone_number' => '5552228888',
            ]],
        ], 200),
    ]);

    $this->artisan('marketing:sync-square-customers --limit=1')->assertExitCode(0);

    expect(MarketingIdentityReview::query()->where('source_type', 'square_customer')->where('source_id', 'SQ-CUST-CONFLICT')->exists())->toBeTrue();
});

test('square order sync creates records and profile links', function () {
    \App\Models\SquareCustomer::query()->create([
        'square_customer_id' => 'SQ-CUST-ORDER',
        'given_name' => 'Order',
        'family_name' => 'Buyer',
        'email' => 'orderbuyer@example.com',
        'phone' => '5559993333',
    ]);

    Http::fake([
        'https://connect.squareup.com/v2/orders/search' => Http::response([
            'orders' => [[
                'id' => 'SQ-ORDER-1',
                'customer_id' => 'SQ-CUST-ORDER',
                'location_id' => 'LOC-1',
                'state' => 'COMPLETED',
                'closed_at' => now()->subDay()->toIso8601String(),
                'source' => ['name' => 'Florida Strawberry Festival'],
                'taxes' => [
                    ['name' => 'county 7%'],
                ],
                'total_money' => ['amount' => 4500, 'currency' => 'USD'],
            ]],
            'cursor' => null,
        ], 200),
    ]);

    $this->artisan('marketing:sync-square-orders --limit=5')->assertExitCode(0);

    expect(\App\Models\SquareOrder::query()->where('square_order_id', 'SQ-ORDER-1')->exists())->toBeTrue()
        ->and(MarketingProfileLink::query()->where('source_type', 'square_order')->where('source_id', 'SQ-ORDER-1')->exists())->toBeTrue();
});

test('square customer sync without limit exhausts paginated results and stores checkpoint summary', function () {
    Http::fake(function (Request $request) {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return match ($query['cursor'] ?? null) {
            'CUR-2' => Http::response([
                'customers' => [[
                    'id' => 'SQ-CUST-2',
                    'given_name' => 'Second',
                    'email_address' => 'second@example.com',
                ]],
                'cursor' => null,
            ], 200),
            default => Http::response([
                'customers' => [[
                    'id' => 'SQ-CUST-1',
                    'given_name' => 'First',
                    'email_address' => 'first@example.com',
                ]],
                'cursor' => 'CUR-2',
            ], 200),
        };
    });

    $this->artisan('marketing:sync-square-customers')->assertExitCode(0);

    $run = MarketingImportRun::query()->where('type', 'square_customers_sync')->latest('id')->firstOrFail();

    expect(SquareCustomer::query()->count())->toBe(2)
        ->and(MarketingProfileLink::query()->where('source_type', 'square_customer')->count())->toBe(2)
        ->and(data_get($run->summary, 'limit'))->toBeNull()
        ->and(data_get($run->summary, 'checkpoint.processed'))->toBe(2)
        ->and(data_get($run->summary, 'checkpoint.cursor'))->toBeNull();
});

test('square customer sync resumes from prior run checkpoint cursor', function () {
    $requestedCursors = [];

    Http::fake(function (Request $request) use (&$requestedCursors) {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
        $cursor = $query['cursor'] ?? null;
        $requestedCursors[] = $cursor;

        return match ($cursor) {
            'CUR-2' => Http::response([
                'customers' => [[
                    'id' => 'SQ-CUST-2',
                    'given_name' => 'Second',
                    'email_address' => 'second@example.com',
                ]],
                'cursor' => null,
            ], 200),
            default => Http::response([
                'customers' => [[
                    'id' => 'SQ-CUST-1',
                    'given_name' => 'First',
                    'email_address' => 'first@example.com',
                ]],
                'cursor' => 'CUR-2',
            ], 200),
        };
    });

    $this->artisan('marketing:sync-square-customers --limit=1 --checkpoint-every=1')->assertExitCode(0);

    $firstRun = MarketingImportRun::query()->where('type', 'square_customers_sync')->latest('id')->firstOrFail();

    $this->artisan('marketing:sync-square-customers --resume-run-id=' . $firstRun->id)->assertExitCode(0);

    expect($requestedCursors)->toBe([null, 'CUR-2'])
        ->and(SquareCustomer::query()->count())->toBe(2)
        ->and(data_get($firstRun->fresh()->summary, 'checkpoint.cursor'))->toBe('CUR-2');
});

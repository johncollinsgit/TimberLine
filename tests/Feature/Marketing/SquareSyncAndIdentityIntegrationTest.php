<?php

use App\Models\MarketingIdentityReview;
use App\Models\MarketingImportRun;
use App\Models\MarketingProfile;
use App\Models\MarketingProfileLink;
use App\Models\SquareCustomer;
use App\Models\Tenant;
use App\Models\TenantMarketingSetting;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

beforeEach(function () {
    Http::preventStrayRequests();
    config(['pos_live.enabled' => false, 'pos_live.runtime_id' => null, 'marketing.square.enabled' => false]);
    $this->tenant = Tenant::create(['name' => 'Source merchant', 'slug' => 'square-tenant-'.Str::lower(Str::random(8))]);
    $this->owner = User::factory()->create(['is_active' => true]);
});

function originalSourceCommand($test, string $command): string
{
    preg_match('/--tenant-id=(\d+)/', $command, $match);
    $tenant = (int) $match[1];
    if (! $test->owner->tenants()->whereKey($tenant)->exists()) {
        $test->owner->tenants()->attach($tenant, ['role' => 'owner', 'membership_active' => true]);
    }
    TenantMarketingSetting::firstOrCreate(['tenant_id' => $tenant, 'key' => 'square_config'], ['value' => [
        'connection_id' => 'original-source-'.$tenant, 'generation' => 1, 'merchant_id' => 'MERCHANT-'.$tenant, 'environment' => 'production',
        'location_ids' => ['LOC-1'], 'credential_flow' => 'core_source_authorization_code',
        'credentials' => app(\App\Services\Pos\PosStore::class)->encrypt(['access_token' => 'fictional-source-key', 'expires_at' => now()->addDay()->toIso8601String()])]]);

    return $command.' --owner-id='.$test->owner->id.' --request-id='.Str::uuid().' --connection-id=original-source-'.$tenant.' --connection-generation=1'
        .(str_starts_with($command, 'marketing:sync-square-customers') ? ' --create-unmatched' : ' --historical-source-only');
}

function originalSourceFake(array|Closure $fake): void
{
    Http::fake(function (Request $request) use ($fake) {
        if (preg_match('~/v2/merchants/(MERCHANT-[0-9]+)$~', $request->url(), $match)) {
            return Http::response(['merchant' => ['id' => $match[1]]]);
        }
        if ($fake instanceof Closure) {
            return $fake($request);
        }
        foreach ($fake as $pattern => $response) {
            if (Str::is($pattern, $request->url())) {
                return $response;
            }
        }
        throw new RuntimeException('Unexpected source request.');
    });
}

test('square customer sync creates and updates source records idempotently', function () {
    originalSourceFake([
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

    $this->artisan(originalSourceCommand($this, 'marketing:sync-square-customers --tenant-id='.$this->tenant->id.' --limit=50'))->assertExitCode(0);
    $this->artisan(originalSourceCommand($this, 'marketing:sync-square-customers --tenant-id='.$this->tenant->id.' --limit=50'))->assertExitCode(0);

    expect(\App\Models\SquareCustomer::query()->count())->toBe(1)
        ->and(MarketingImportRun::query()
            ->where('type', 'square_customers_sync')
            ->where('tenant_id', $this->tenant->id)
            ->count())->toBe(2)
        ->and(MarketingProfileLink::query()->where('source_type', 'square_customer')->where('source_id', 'SQ-CUST-1')->count())->toBe(1);
});

test('square customer exact email remains a visible identity review without implicit association', function () {
    $profile = MarketingProfile::query()->create([
        'tenant_id' => $this->tenant->id,
        'first_name' => 'Existing',
        'email' => 'existing@example.com',
        'normalized_email' => 'existing@example.com',
    ]);

    originalSourceFake([
        'https://connect.squareup.com/v2/customers*' => Http::response([
            'customers' => [[
                'id' => 'SQ-CUST-EMAIL',
                'email_address' => 'EXISTING@example.com',
                'phone_number' => '',
            ]],
        ], 200),
    ]);

    $this->artisan(originalSourceCommand($this, 'marketing:sync-square-customers --tenant-id='.$this->tenant->id.' --limit=1'))->assertExitCode(0);

    expect(MarketingProfile::query()->count())->toBe(1)
        ->and(MarketingProfileLink::query()
            ->where('marketing_profile_id', $profile->id)
            ->where('source_type', 'square_customer')
            ->where('source_id', 'SQ-CUST-EMAIL')
            ->exists())->toBeFalse()
        ->and(MarketingIdentityReview::where('source_type', 'square_customer')->where('source_id', 'SQ-CUST-EMAIL')->where('status', 'pending')->exists())->toBeTrue();
});

test('square customer exact phone remains a visible identity review without implicit association', function () {
    $profile = MarketingProfile::query()->create([
        'tenant_id' => $this->tenant->id,
        'first_name' => 'Phone Match',
        'phone' => '555-123-5678',
        'normalized_phone' => '+15551235678',
    ]);

    originalSourceFake([
        'https://connect.squareup.com/v2/customers*' => Http::response([
            'customers' => [[
                'id' => 'SQ-CUST-PHONE',
                'email_address' => '',
                'phone_number' => '+1 (555) 123-5678',
            ]],
        ], 200),
    ]);

    $this->artisan(originalSourceCommand($this, 'marketing:sync-square-customers --tenant-id='.$this->tenant->id.' --limit=1'))->assertExitCode(0);

    expect(MarketingProfile::query()->count())->toBe(1)
        ->and(MarketingProfileLink::query()
            ->where('marketing_profile_id', $profile->id)
            ->where('source_type', 'square_customer')
            ->where('source_id', 'SQ-CUST-PHONE')
            ->exists())->toBeFalse()
        ->and(MarketingIdentityReview::where('source_type', 'square_customer')->where('source_id', 'SQ-CUST-PHONE')->where('status', 'pending')->exists())->toBeTrue();
});

test('Square contact import does not infer email or SMS permission from a visible contact', function () {
    originalSourceFake([
        'https://connect.squareup.com/v2/customers*' => Http::response([
            'customers' => [[
                'id' => 'SQ-CUST-SMS-FALLBACK',
                'given_name' => 'Phone',
                'family_name' => 'Visible',
                'email_address' => 'visible.phone@example.com',
                'phone_number' => '+1 (555) 123-9898',
                'preferences' => [
                    'email_unsubscribed' => false,
                ],
            ]],
            'cursor' => null,
        ], 200),
    ]);

    $this->artisan(originalSourceCommand($this, 'marketing:sync-square-customers --tenant-id='.$this->tenant->id.' --limit=1'))->assertExitCode(0);

    $profile = MarketingProfile::query()->sole();

    expect($profile->accepts_sms_marketing)->toBeFalse()
        ->and($profile->accepts_email_marketing)->toBeFalse()
        ->and(\App\Models\MarketingConsentEvent::count())->toBe(0)
        ->and($profile->normalized_phone)->toBe('5551239898');
});

test('square customer conflicting identifiers create identity review', function () {
    MarketingProfile::query()->create([
        'tenant_id' => $this->tenant->id,
        'email' => 'conflict@example.com',
        'normalized_email' => 'conflict@example.com',
    ]);
    MarketingProfile::query()->create([
        'tenant_id' => $this->tenant->id,
        'phone' => '5552228888',
        'normalized_phone' => '+15552228888',
    ]);

    originalSourceFake([
        'https://connect.squareup.com/v2/customers*' => Http::response([
            'customers' => [[
                'id' => 'SQ-CUST-CONFLICT',
                'email_address' => 'conflict@example.com',
                'phone_number' => '5552228888',
            ]],
        ], 200),
    ]);

    $this->artisan(originalSourceCommand($this, 'marketing:sync-square-customers --tenant-id='.$this->tenant->id.' --limit=1'))->assertExitCode(0);

    expect(MarketingIdentityReview::query()->where('source_type', 'square_customer')->where('source_id', 'SQ-CUST-CONFLICT')->exists())->toBeTrue();
});

test('square order sync creates records and profile links', function () {
    $profile = MarketingProfile::create(['tenant_id' => $this->tenant->id, 'email' => 'orderbuyer@example.com']);
    MarketingProfileLink::create(['tenant_id' => $this->tenant->id, 'marketing_profile_id' => $profile->id, 'source_type' => 'square_customer', 'source_id' => 'SQ-CUST-ORDER']);
    \App\Models\SquareCustomer::query()->create([
        'tenant_id' => $this->tenant->id,
        'square_customer_id' => 'SQ-CUST-ORDER',
        'given_name' => 'Order',
        'family_name' => 'Buyer',
        'email' => 'orderbuyer@example.com',
        'phone' => '5559993333',
    ]);

    originalSourceFake([
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

    $this->artisan(originalSourceCommand($this, 'marketing:sync-square-orders --tenant-id='.$this->tenant->id.' --limit=5'))->assertExitCode(0);

    expect(\App\Models\SquareOrder::query()->where('square_order_id', 'SQ-ORDER-1')->exists())->toBeTrue()
        ->and(MarketingProfileLink::query()->where('source_type', 'square_order')->where('source_id', 'SQ-ORDER-1')->exists())->toBeTrue()
        ->and(\App\Models\CandleCashTransaction::count())->toBe(0)
        ->and(\App\Models\SquareOrder::sole()->raw_payload['_everbranch_source_import']['historical_only'])->toBeTrue();
});

test('square customer sync without limit exhausts paginated results and stores checkpoint summary', function () {
    originalSourceFake(function (Request $request) {
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

    $this->artisan(originalSourceCommand($this, 'marketing:sync-square-customers --tenant-id='.$this->tenant->id))->assertExitCode(0);

    $run = MarketingImportRun::query()
        ->where('type', 'square_customers_sync')
        ->where('tenant_id', $this->tenant->id)
        ->latest('id')
        ->firstOrFail();

    expect(SquareCustomer::query()->count())->toBe(2)
        ->and(MarketingProfileLink::query()->where('source_type', 'square_customer')->count())->toBe(2)
        ->and(data_get($run->summary, 'limit'))->toBeNull()
        ->and(data_get($run->summary, 'checkpoint.processed'))->toBe(2)
        ->and(data_get($run->summary, 'checkpoint.cursor'))->toBeNull();
});

test('square customer sync resumes from prior run checkpoint cursor', function () {
    $requestedCursors = [];

    originalSourceFake(function (Request $request) use (&$requestedCursors) {
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

    $this->artisan(originalSourceCommand($this, 'marketing:sync-square-customers --tenant-id='.$this->tenant->id.' --limit=1 --checkpoint-every=1'))->assertExitCode(0);

    $firstRun = MarketingImportRun::query()
        ->where('type', 'square_customers_sync')
        ->where('tenant_id', $this->tenant->id)
        ->latest('id')
        ->firstOrFail();

    $this->artisan(originalSourceCommand($this, 'marketing:sync-square-customers --tenant-id='.$this->tenant->id.' --resume-run-id='.$firstRun->id))->assertExitCode(0);

    expect($requestedCursors)->toBe([null, 'CUR-2'])
        ->and(SquareCustomer::query()->count())->toBe(2)
        ->and(data_get($firstRun->fresh()->summary, 'checkpoint.cursor'))->toBe('CUR-2');
});

test('square customer sync resume fails closed when run belongs to another tenant', function () {
    $tenantB = Tenant::query()->create([
        'name' => 'Square Tenant B '.Str::random(4),
        'slug' => 'square-tenant-b-'.strtolower(Str::random(5)),
    ]);

    $foreignRun = MarketingImportRun::query()->create([
        'tenant_id' => $tenantB->id,
        'type' => 'square_customers_sync',
        'status' => 'completed',
        'source_label' => 'square:customers',
        'started_at' => now()->subMinutes(10),
        'finished_at' => now()->subMinutes(5),
        'summary' => [
            'checkpoint' => [
                'cursor' => 'CUR-FOREIGN',
            ],
        ],
    ]);

    originalSourceFake([
        'https://connect.squareup.com/v2/customers*' => Http::response([
            'customers' => [],
            'cursor' => null,
        ], 200),
    ]);

    $this->artisan(originalSourceCommand($this, 'marketing:sync-square-customers --tenant-id='.$this->tenant->id.' --resume-run-id='.$foreignRun->id))
        ->expectsOutputToContain('not accessible for tenant')
        ->assertExitCode(1);

    Http::assertNothingSent();
});

test('square customer sync resume fails closed when run has no tenant ownership', function () {
    $orphanRun = MarketingImportRun::query()->create([
        'tenant_id' => null,
        'type' => 'square_customers_sync',
        'status' => 'completed',
        'source_label' => 'square:customers',
        'started_at' => now()->subMinutes(10),
        'finished_at' => now()->subMinutes(5),
        'summary' => [
            'checkpoint' => [
                'cursor' => 'CUR-ORPHAN',
            ],
        ],
    ]);

    originalSourceFake([
        'https://connect.squareup.com/v2/customers*' => Http::response([
            'customers' => [],
            'cursor' => null,
        ], 200),
    ]);

    $this->artisan(originalSourceCommand($this, 'marketing:sync-square-customers --tenant-id='.$this->tenant->id.' --resume-run-id='.$orphanRun->id))
        ->expectsOutputToContain('not accessible for tenant')
        ->assertExitCode(1);

    Http::assertNothingSent();
});

test('square sync isolates source rows by tenant', function () {
    $tenantB = Tenant::query()->create([
        'name' => 'Square Tenant B '.Str::random(4),
        'slug' => 'square-tenant-b-'.strtolower(Str::random(5)),
    ]);

    originalSourceFake([
        'https://connect.squareup.com/v2/customers*' => Http::response([
            'customers' => [[
                'id' => 'SQ-CUST-SHARED',
                'given_name' => 'Shared',
                'family_name' => 'Customer',
                'email_address' => 'shared@example.com',
            ]],
            'cursor' => null,
        ], 200),
    ]);

    $this->artisan(originalSourceCommand($this, 'marketing:sync-square-customers --tenant-id='.$this->tenant->id.' --limit=1'))->assertExitCode(0);
    $this->artisan(originalSourceCommand($this, 'marketing:sync-square-customers --tenant-id='.$tenantB->id.' --limit=1'))->assertExitCode(0);

    expect(SquareCustomer::query()->where('tenant_id', $this->tenant->id)->count())->toBe(1)
        ->and(SquareCustomer::query()->where('tenant_id', $tenantB->id)->count())->toBe(1)
        ->and(SquareCustomer::query()->where('square_customer_id', 'SQ-CUST-SHARED')->count())->toBe(2)
        ->and(MarketingImportRun::query()->where('type', 'square_customers_sync')->where('tenant_id', $this->tenant->id)->exists())->toBeTrue()
        ->and(MarketingImportRun::query()->where('type', 'square_customers_sync')->where('tenant_id', $tenantB->id)->exists())->toBeTrue();
});

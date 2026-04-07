<?php

require_once __DIR__.'/ShopifyEmbeddedTestHelpers.php';

use App\Models\Tenant;
use App\Models\TenantModuleEntitlement;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    $this->withoutVite();
    config()->set('entitlements.default_plan', 'growth');
    config()->set('shopify_embedded.perf_profiling_enabled', true);
});

function benchmarkGrantMessagingEntitlement(Tenant $tenant): void
{
    TenantModuleEntitlement::query()->updateOrCreate(
        [
            'tenant_id' => $tenant->id,
            'module_key' => 'messaging',
        ],
        [
            'availability_status' => 'available',
            'enabled_status' => 'enabled',
            'billing_status' => 'add_on_comped',
            'currency' => 'USD',
            'entitlement_source' => 'test',
            'price_source' => 'test',
        ]
    );
}

function seedEmbeddedPerformanceProfiles(Tenant $tenant, int $count = 5000): void
{
    $now = now();
    $rows = [];

    for ($index = 1; $index <= $count; $index++) {
        $suffix = str_pad((string) $index, 5, '0', STR_PAD_LEFT);
        $phone = '55521' . str_pad((string) $index, 5, '0', STR_PAD_LEFT);
        $rows[] = [
            'tenant_id' => $tenant->id,
            'first_name' => 'Benchmark',
            'last_name' => 'Customer ' . $suffix,
            'email' => "benchmark{$suffix}@example.com",
            'normalized_email' => "benchmark{$suffix}@example.com",
            'phone' => $phone,
            'normalized_phone' => preg_replace('/\D+/', '', $phone),
            'accepts_sms_marketing' => true,
            'accepts_email_marketing' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ];

        if (count($rows) === 500) {
            DB::table('marketing_profiles')->insert($rows);
            $rows = [];
        }
    }

    if ($rows !== []) {
        DB::table('marketing_profiles')->insert($rows);
    }
}

/**
 * @return array<string,float>
 */
function parseServerTimingHeader(string $header): array
{
    $timings = [];

    foreach (explode(',', $header) as $segment) {
        $segment = trim($segment);
        if ($segment === '') {
            continue;
        }

        if (! preg_match('/^([a-z0-9\\-]+);dur=([0-9.]+)$/i', $segment, $matches)) {
            continue;
        }

        $timings[str_replace('-', '_', strtolower($matches[1]))] = (float) $matches[2];
    }

    return $timings;
}

function benchmarkMedian(array $values): float
{
    sort($values);
    $count = count($values);

    if ($count === 0) {
        return 0.0;
    }

    $middle = intdiv($count, 2);

    if ($count % 2 === 0) {
        return round(($values[$middle - 1] + $values[$middle]) / 2, 2);
    }

    return round((float) $values[$middle], 2);
}

/**
 * @return array{
 *   cold:array{total_ms:float,page_payload_ms:float},
 *   warm_median:array{total_ms:float,page_payload_ms:float},
 *   samples:array<int,array{total_ms:float,page_payload_ms:float}>
 * }
 */
function benchmarkEmbeddedRoute(\Illuminate\Foundation\Testing\TestCase $testCase, string $url, int $iterations = 5): array
{
    $samples = [];

    for ($iteration = 1; $iteration <= $iterations; $iteration++) {
        $response = $testCase->get($url);
        $response->assertOk()->assertHeader('Server-Timing');

        $timings = parseServerTimingHeader((string) $response->headers->get('Server-Timing', ''));
        $samples[] = [
            'total_ms' => (float) ($timings['total'] ?? 0.0),
            'page_payload_ms' => (float) ($timings['page_payload'] ?? 0.0),
        ];
    }

    $warmSamples = array_slice($samples, 1);

    return [
        'cold' => $samples[0],
        'warm_median' => [
            'total_ms' => benchmarkMedian(array_map(static fn (array $sample): float => (float) $sample['total_ms'], $warmSamples)),
            'page_payload_ms' => benchmarkMedian(array_map(static fn (array $sample): float => (float) $sample['page_payload_ms'], $warmSamples)),
        ],
        'samples' => $samples,
    ];
}

test('embedded page benchmark medians stay within the performance budget', function () {
    $tenant = Tenant::query()->create([
        'name' => 'Embedded Benchmark Tenant',
        'slug' => 'embedded-benchmark-tenant',
    ]);

    benchmarkGrantMessagingEntitlement($tenant);
    configureEmbeddedRetailStore($tenant->id);
    seedEmbeddedPerformanceProfiles($tenant);

    $routes = [
        'dashboard' => route('shopify.app', retailEmbeddedSignedQuery()),
        'customers_blank' => route('shopify.app.customers.manage', retailEmbeddedSignedQuery()),
        'customers' => route('shopify.app.customers.manage', array_merge(retailEmbeddedSignedQuery(), [
            'search' => 'benchmark',
        ])),
        'messages' => route('shopify.app.messaging', retailEmbeddedSignedQuery()),
        'rewards' => route('shopify.app.rewards', retailEmbeddedSignedQuery()),
        'settings' => route('shopify.app.settings', retailEmbeddedSignedQuery()),
    ];

    $reports = [];

    foreach ($routes as $name => $url) {
        Cache::flush();
        $reports[$name] = benchmarkEmbeddedRoute($this, $url);
    }

    fwrite(STDOUT, "\n[shopify-embedded-benchmark]\n");
    foreach ($reports as $name => $report) {
        fwrite(
            STDOUT,
            sprintf(
                "%s cold=%0.2fms warm-median=%0.2fms page-payload-median=%0.2fms\n",
                $name,
                $report['cold']['total_ms'],
                $report['warm_median']['total_ms'],
                $report['warm_median']['page_payload_ms'],
            )
        );
    }

    foreach ($reports as $report) {
        expect($report['cold']['total_ms'])->toBeLessThan(1500.0);
        expect($report['warm_median']['total_ms'])->toBeLessThan(1500.0);
    }
});

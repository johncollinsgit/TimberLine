<?php

use App\Models\MarketingImportRun;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Marketing\SquareConnectionContext;
use App\Services\Marketing\SquareSourceRefresh;
use App\Services\Pos\PosStore;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

function legacyEventCoverageFixture($test): void
{
    Http::preventStrayRequests();
    config(['pos_live.runtime_id' => 'legacy_coverage_fixture', 'marketing.square.access_token' => 'fictional-original-reader', 'marketing.square.environment' => 'production']);
    $test->tenant = Tenant::create(['name' => 'Fictional historical merchant', 'slug' => 'coverage-'.Str::lower(Str::random(12))]);
    $test->owner = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
    $test->tenant->users()->attach($test->owner->id, ['role' => 'owner', 'membership_active' => true]);
    $store = app(PosStore::class);
    $id = bin2hex(random_bytes(16));
    $store->rows('connections', $test->tenant->id)->insert($store->values($test->tenant->id, ['id' => $id,
        'merchant_id' => 'fictional-merchant', 'provider_location_id' => 'original-location', 'environment' => 'production',
        'credentials' => $store->encrypt(['access_token' => 'fictional-original-reader', 'expires_at' => now()->addDay()->toIso8601String()]), 'active' => true]));
    Http::fake(['*/v2/merchants/fictional-merchant' => Http::response(['merchant' => ['id' => 'fictional-merchant']]),
        '*/v2/locations' => Http::response(['locations' => [['id' => 'original-location', 'merchant_id' => 'fictional-merchant', 'status' => 'ACTIVE'],
            ['id' => 'retired-location', 'merchant_id' => 'fictional-merchant', 'status' => 'INACTIVE']]])]);
    $refresh = app(SquareSourceRefresh::class);
    $test->policy = $refresh->configure($test->tenant->id, $test->owner, ['enabled' => true, 'expected_version' => $refresh->status($test->tenant->id)['version'],
        'connection_id' => 'legacy:'.$id, 'all_locations' => true, 'lookback_days' => 14, 'create_unmatched' => true]);
    $context = app(SquareConnectionContext::class);
    // Use the real original builder, not a test-owned ordering adapter.
    $test->binding = $context->publicBinding($refresh->configForPolicy($test->tenant->id, $test->policy['version'], $context->resolve($test->tenant->id, 'legacy:'.$id)));
    $test->scope = ['location_id' => 'retired-location', 'starts_on' => '2025-11-01', 'ends_on' => '2025-11-02'];
    $test->roots = [];
    foreach (['orders', 'payments'] as $kind) {
        $type = 'square_'.$kind.'_sync';
        $summary = ['mode' => 'live-sync', 'source_binding' => $test->binding, 'since' => '2020-01-01T00:00:00+00:00', 'limit' => 500,
            'provider_page_limit' => 100, 'historical_source_only' => true, 'create_unmatched' => true,
            'source_refresh_policy_version' => $test->policy['version'], 'request_context' => ['discovery' => 'fictional-historical-source'],
            'errors' => 0, 'checkpoint' => ['cursor' => 'fictional-remaining-'.$kind, 'errors' => 0]];
        $summary['intent_hash'] = legacyEventCoverageIntent($type, $summary, $test->binding);
        // SQLite has no MySQL binary JSON normalization. Exercise that changed
        // representation locally; the MySQL regression uses its actual round trip.
        if (DB::connection()->getDriverName() === 'sqlite') {
            $summary['source_binding'] = array_reverse($summary['source_binding'], true);
        }
        $test->roots[$kind] = MarketingImportRun::create(['tenant_id' => $test->tenant->id, 'created_by' => $test->owner->id,
            'type' => $type, 'status' => 'partial', 'started_at' => '2026-01-10', 'finished_at' => '2026-01-10', 'summary' => $summary]);
        $test->roots[$kind]->refresh();
        $terminal = MarketingImportRun::create(['tenant_id' => $test->tenant->id, 'created_by' => $test->owner->id,
            'type' => $type, 'status' => 'completed', 'started_at' => '2026-01-11', 'finished_at' => '2026-01-11',
            'summary' => [...$test->roots[$kind]->summary, 'resumed_from_run_id' => $test->roots[$kind]->id,
                'initial_cursor' => 'fictional-remaining-'.$kind, 'checkpoint' => ['cursor' => null, 'errors' => 0]]]);
        $test->scope[$kind.'_run_id'] = $terminal->id;
    }
    Http::fake();
}

function legacyEventCoverageIntent(string $type, array $summary, array $binding, ?string $initialCursor = null): string
{
    $intent = [$type, $binding, $summary['limit'], $summary['since'], $initialCursor, false, $summary['create_unmatched'], $summary['historical_source_only']];
    if ($summary['request_context']) {
        $intent[] = $summary['request_context'];
    }

    return hash('sha256', json_encode([...$intent, ['provider_page_limit' => $summary['provider_page_limit']]], JSON_THROW_ON_ERROR));
}

<?php

use App\Models\MarketingImportRun;
use App\Models\TenantMarketingSetting;
use App\Services\Team\Studio\EventSquareCoverage;
use Illuminate\Support\Facades\Http;

require_once __DIR__.'/../../Support/EventSquareCoverageFixtures.php';

beforeEach(fn () => legacyEventCoverageFixture($this));

test('historical orders and payments retain first-page proof after stored JSON changes key order', function () {
    foreach ($this->roots as $kind => $root) {
        expect(array_keys($root->summary['source_binding']))->not->toBe(array_keys($this->binding));
        expect(legacyEventCoverageIntent('square_'.$kind.'_sync', $root->summary, $root->summary['source_binding']))->not->toBe($root->summary['intent_hash']);
    }
    expect(app(EventSquareCoverage::class)->proven($this->tenant->id, $this->scope, 'America/New_York'))->toBeTrue();
    Http::assertNothingSent();
});

test('legacy serialization compatibility refuses altered original intent or source evidence', function (string $change, string $kind) {
    $root = $this->roots[$kind];
    $summary = $root->summary;
    if ($change === 'non_null_original_cursor') {
        $summary['intent_hash'] = legacyEventCoverageIntent('square_'.$kind.'_sync', $summary, $this->binding, 'skipped-first-page');
    } elseif ($change === 'unknown_binding_key') {
        $summary['source_binding']['unreviewed_scope'] = 'unexpected';
        $summary['intent_hash'] = legacyEventCoverageIntent('square_'.$kind.'_sync', $summary, $summary['source_binding']);
    } elseif ($change === 'binding_generation') {
        $summary['source_binding']['connection_generation'] = 'changed-original-generation';
    } elseif ($change === 'binding_locations') {
        $summary['source_binding']['location_ids'][] = 'unreviewed-location';
    } elseif ($change === 'context') {
        $summary['request_context']['discovery'] = 'different-query';
    } elseif ($change === 'unknown_context_key') {
        $summary['request_context']['unreviewed_query'] = true;
    } elseif ($change === 'page_limit') {
        $summary['provider_page_limit'] = 50;
    } elseif ($change === 'errors') {
        $summary['checkpoint']['errors'] = 1;
    } elseif ($change === 'missing_parent') {
        $summary['resumed_from_run_id'] = 999999999;
    } else {
        $summary['request_context']['date_field'] = 'updated_at';
    }
    $root->update(['summary' => $summary]);
    if (in_array($change, ['unknown_binding_key', 'binding_generation', 'binding_locations'], true)) {
        $terminal = MarketingImportRun::findOrFail($this->scope[$kind.'_run_id']);
        $terminal->update(['summary' => [...$terminal->summary, 'source_binding' => $summary['source_binding']]]);
    }
    expect(app(EventSquareCoverage::class)->proven($this->tenant->id, $this->scope, 'America/New_York'))->toBeFalse();
    Http::assertNothingSent();
})->with(['non_null_original_cursor', 'unknown_binding_key', 'binding_generation', 'binding_locations', 'context', 'unknown_context_key', 'page_limit', 'errors', 'missing_parent', 'updated_query'])->with(['orders', 'payments']);

test('pre-page-size legacy hashes remain valid only without a forged recorded page size', function () {
    foreach ($this->roots as $kind => $root) {
        $summary = $root->summary;
        unset($summary['provider_page_limit']);
        $summary['intent_hash'] = hash('sha256', json_encode(['square_'.$kind.'_sync', $this->binding, 500, $summary['since'], null, false, true, true, $summary['request_context']], JSON_THROW_ON_ERROR));
        $root->update(['summary' => $summary]);
    }
    expect(app(EventSquareCoverage::class)->proven($this->tenant->id, $this->scope, 'America/New_York'))->toBeTrue();
    $root = $this->roots['payments'];
    $root->update(['summary' => [...$root->summary, 'provider_page_limit' => 50]]);
    expect(app(EventSquareCoverage::class)->proven($this->tenant->id, $this->scope, 'America/New_York'))->toBeFalse();
});

test('legacy first-page proof retains current original reader policy and terminal EOF gates', function (string $change) {
    if ($change === 'not_eof') {
        $run = MarketingImportRun::findOrFail($this->scope['payments_run_id']);
        $run->update(['status' => 'partial', 'summary' => [...$run->summary, 'checkpoint' => ['cursor' => 'remaining-final-page', 'errors' => 0]]]);
    } elseif ($change === 'policy') {
        $row = TenantMarketingSetting::where('tenant_id', $this->tenant->id)->where('key', 'square_source_refresh')->firstOrFail();
        $row->update(['value' => [...$row->value, 'enabled' => false]]);
    } else {
        config(['marketing.square.access_token' => 'replacement-original-reader']);
    }
    expect(app(EventSquareCoverage::class)->proven($this->tenant->id, $this->scope, 'America/New_York'))->toBeFalse();
})->with(['not_eof', 'policy', 'original_reader']);

test('a later terminal receipt cannot prove locations first read before the offline delivery cutoff', function (string $kind, string $start) {
    $cutoff = \Carbon\CarbonImmutable::parse($this->scope['ends_on'], 'America/New_York')->addDay()->startOfDay()->utc()->addHours(72);
    $terminal = MarketingImportRun::findOrFail($this->scope[$kind.'_run_id']);
    expect($terminal->started_at->gt($cutoff))->toBeTrue();
    expect(app(EventSquareCoverage::class)->proven($this->tenant->id, $this->scope, 'America/New_York'))->toBeTrue();
    // Only the original start time changes; binding, original null-cursor
    // intent, query, source access and completed terminal EOF remain valid.
    $this->roots[$kind]->update(['started_at' => $start === 'missing' ? null : $cutoff->subSecond()]);
    expect(app(EventSquareCoverage::class)->proven($this->tenant->id, $this->scope, 'America/New_York'))->toBeFalse();
    Http::assertNothingSent();
})->with(['orders', 'payments'])->with(['before_cutoff', 'missing']);

test('an original first-page read exactly at the offline delivery cutoff retains coverage', function (string $kind) {
    $cutoff = \Carbon\CarbonImmutable::parse($this->scope['ends_on'], 'America/New_York')->addDay()->startOfDay()->utc()->addHours(72);
    $this->roots[$kind]->update(['started_at' => $cutoff]);
    expect(app(EventSquareCoverage::class)->proven($this->tenant->id, $this->scope, 'America/New_York'))->toBeTrue();
    Http::assertNothingSent();
})->with(['orders', 'payments']);

test('long original receipt chains retain every proof and use two bounded MySQL reads', function () {
    $middle = [];
    foreach (['orders', 'payments'] as $kind) {
        $parent = $this->roots[$kind];
        foreach (range(1, 60) as $page) {
            $parent = MarketingImportRun::create(['tenant_id' => $this->tenant->id, 'type' => 'square_'.$kind.'_sync', 'status' => 'partial', 'started_at' => '2026-01-11',
                'summary' => [...$this->roots[$kind]->summary, 'resumed_from_run_id' => $parent->id, 'initial_cursor' => 'retained-page', 'checkpoint' => ['cursor' => 'remaining-page', 'errors' => 0]]]);
            if ($page === 30) {
                $middle[$kind] = $parent;
            }
        }
        $head = MarketingImportRun::findOrFail($this->scope[$kind.'_run_id']);
        $head->update(['summary' => [...$head->summary, 'resumed_from_run_id' => $parent->id]]);
    }
    \Illuminate\Support\Facades\DB::enableQueryLog();
    \Illuminate\Support\Facades\DB::flushQueryLog();
    $coverage = app(EventSquareCoverage::class);
    expect($coverage->proven($this->tenant->id, $this->scope, 'America/New_York'))->toBeTrue();
    $queries = collect(\Illuminate\Support\Facades\DB::getQueryLog());
    \Illuminate\Support\Facades\DB::disableQueryLog();
    if (\Illuminate\Support\Facades\DB::connection()->getDriverName() === 'mysql') {
        expect($queries->filter(fn ($query) => str_contains($query['query'], 'WITH RECURSIVE square_proof_ancestry')))->toHaveCount(2);
    }
    $middle['payments']->update(['summary' => [...$middle['payments']->summary, 'checkpoint' => ['cursor' => 'remaining-page', 'errors' => 1]]]);
    expect($coverage->proven($this->tenant->id, $this->scope, 'America/New_York'))->toBeFalse();
    $middle['payments']->update(['summary' => [...$middle['payments']->summary, 'checkpoint' => ['cursor' => 'remaining-page', 'errors' => 0], 'resumed_from_run_id' => $this->scope['payments_run_id']]]);
    expect($coverage->proven($this->tenant->id, $this->scope, 'America/New_York'))->toBeFalse();
    Http::assertNothingSent();
});

test('batch ancestry never crosses a missing foreign or wrong-type parent', function (string $change) {
    $root = $this->roots['payments'];
    if ($change === 'missing') {
        $root->delete();
    } elseif ($change === 'foreign') {
        $foreign = \App\Models\Tenant::create(['name' => 'Other fictional merchant', 'slug' => 'other-'.\Illuminate\Support\Str::random(12)]);
        $root->update(['tenant_id' => $foreign->id]);
    } else {
        $root->update(['type' => 'square_orders_sync']);
    }
    expect(app(EventSquareCoverage::class)->proven($this->tenant->id, $this->scope, 'America/New_York'))->toBeFalse();
})->with(['missing', 'foreign', 'wrong_type']);

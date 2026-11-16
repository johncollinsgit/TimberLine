<?php

use App\Services\Team\Studio\EventSquareCoverage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\DatabaseTransactions::class);

require_once __DIR__.'/../Support/EventSquareCoverageFixtures.php';

beforeEach(function () {
    $db = DB::connection();
    if ($db->getDriverName() !== 'mysql') {
        $this->markTestSkipped('Requires the disposable real MySQL JSON acceptance database.');
    }
    if (! app()->environment('testing') || getenv('TEAM_WORK_DISPOSABLE_MYSQL') !== '1'
        || ! in_array($db->getDatabaseName(), ['everbranch_ci', 'codex_events_test'], true)
        || ! in_array($db->getConfig('host'), ['localhost', '127.0.0.1'], true)) {
        throw new RuntimeException('Requires explicitly authorized local disposable event MySQL.');
    }
    legacyEventCoverageFixture($this);
});

test('real MySQL JSON reordering preserves exact historical null-cursor order and payment proofs', function () {
    foreach ($this->roots as $kind => $root) {
        $stored = $root->fresh()->summary;
        expect(array_keys($stored['source_binding']))->not->toBe(array_keys($this->binding));
        expect(legacyEventCoverageIntent('square_'.$kind.'_sync', $stored, $stored['source_binding']))->not->toBe($stored['intent_hash']);
    }
    expect(app(EventSquareCoverage::class)->proven($this->tenant->id, $this->scope, 'America/New_York'))->toBeTrue();
    Http::assertNothingSent();
});

test('real MySQL legacy proof cannot turn a skipped original page or changed query into complete coverage', function (string $change) {
    $root = $this->roots['payments'];
    $summary = $root->summary;
    if ($change === 'non_null_cursor') {
        $summary['intent_hash'] = legacyEventCoverageIntent('square_payments_sync', $summary, $this->binding, 'skipped-first-page');
    } elseif ($change === 'unknown_key') {
        $summary['source_binding']['unreviewed_scope'] = true;
        $summary['intent_hash'] = legacyEventCoverageIntent('square_payments_sync', $summary, $summary['source_binding']);
    } elseif ($change === 'context') {
        $summary['request_context']['discovery'] = 'replacement-query';
    } else {
        $summary['provider_page_limit'] = 50;
    }
    $root->update(['summary' => $summary]);
    if ($change === 'unknown_key') {
        $terminal = \App\Models\MarketingImportRun::findOrFail($this->scope['payments_run_id']);
        $terminal->update(['summary' => [...$terminal->summary, 'source_binding' => $summary['source_binding']]]);
    }
    expect(app(EventSquareCoverage::class)->proven($this->tenant->id, $this->scope, 'America/New_York'))->toBeFalse();
})->with(['non_null_cursor', 'unknown_key', 'context', 'page_limit']);

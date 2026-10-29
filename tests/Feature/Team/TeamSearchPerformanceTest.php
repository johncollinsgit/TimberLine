<?php

use App\Services\Team\Search\SearchDocuments;
use App\Services\Team\Search\SearchIndex;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../../Support/TeamFixtures.php';

test('ten thousand stored documents meet the search response latency budget', function (): void {
    $this->withoutVite();
    config(['team_work.enabled' => true, 'team_work.search_enabled' => true]);
    [$tenant, $owner] = teamWorkspace();
    $task = teamTask($tenant, $owner);
    $rows = [];
    $documents = [];
    for ($i = 0; $i < 9999; $i++) {
        $record = [...$task, 'id' => (string) \Illuminate\Support\Str::uuid(), 'title' => 'Campus maintenance '.$i, 'description' => 'Facilities inspection notes for Avery Hall'];
        unset($record['memberships']);
        $rows[] = ['tenant_id' => $tenant->id, 'id' => $record['id'], 'assigned_user_id' => null, 'parent_id' => null, 'title' => $record['title'], 'due_on' => null, 'due_at' => null, 'completed_at' => null, 'deleted' => false, 'version' => 1, 'payload' => json_encode($record)];
        $documents[] = $record;
        if (count($rows) === 200) {
            DB::table('team_tasks')->insert($rows);
            $rows = [];
        }
    }
    if ($rows) {
        DB::table('team_tasks')->insert($rows);
    }
    $index = app(SearchIndex::class);
    $projection = app(SearchDocuments::class);
    foreach ($documents as $record) {
        $index->put($tenant->id, 'task', $record['id'], $projection->native('task', $record));
    }
    $times = [];
    $queryCount = 0;
    $queryTime = 0;
    $profiles = [];
    \Illuminate\Support\Facades\Event::listen(\Illuminate\Log\Events\MessageLogged::class, function ($event) use (&$profiles) {
        if ($event->message === 'team.search') {
            $profiles[] = $event->context;
        }
    });
    DB::listen(function ($event) use (&$queryCount, &$queryTime) {
        $queryCount++;
        $queryTime += $event->time;
    });
    foreach (range(0, 19) as $i) {
        $start = microtime(true);
        $response = $this->actingAs($owner)->getJson(route('team.search', ['tenant' => $tenant->slug, 'q' => 'campus maint']));
        $times[] = (microtime(true) - $start) * 1000;
        $response->assertOk()->assertJsonCount(8, 'items');
    }
    sort($times);
    fwrite(STDOUT, sprintf("\n10k-document search p95: %.1f ms\n", $times[18]));
    fwrite(STDOUT, sprintf("Search queries/request: %.1f; mean SQL time: %.1f ms\n", $queryCount / 20, $queryTime / 20));
    fwrite(STDOUT, json_encode(end($profiles))."\n");
    expect($times[18])->toBeLessThan(200);
})->skip(getenv('TEAM_SEARCH_BENCHMARK') !== '1', 'Opt-in 10,000-document latency benchmark.');

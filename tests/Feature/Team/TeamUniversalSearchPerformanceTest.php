<?php

use App\Models\TeamArea;
use App\Models\TeamProviderConnection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

require_once __DIR__.'/../../Support/TeamFixtures.php';

test('canonical Macon search handles one thousand long saved notes without repeated policy or fuzzy work', function (): void {
    $this->withoutVite();
    config(['team_work.enabled' => true, 'team_work.premium_enabled' => true, 'team_operations.enabled' => true,
        'team_work.search_enabled' => false, 'team_work.search_index_enabled' => false]);
    Http::preventStrayRequests();
    [$tenant, $owner] = teamWorkspace();
    $area = TeamArea::create(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'name' => 'Events', 'slug' => 'events', 'profile' => 'events', 'settings' => []]);
    $connection = TeamProviderConnection::create(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'user_id' => $owner->id, 'area_id' => $area->id, 'provider' => 'asana', 'account_key' => hash('sha256', 'fictional-profile'), 'account_name' => 'Fictional source', 'status' => 'connected', 'generation' => 1, 'credentials' => [], 'settings' => ['source' => ['asana_workspace' => '100', 'asana_project' => '200']], 'sync_state' => []]);
    $budget = (string) Str::uuid();
    $rows = [];
    $notes = str_repeat('Organizer contact information vendor application insurance certificate parking instructions Thursday setup access window Friday Saturday Sunday hotel reservation booth schedule teardown return pickup travel compensation staff assignment candle stock count map venue address https madeinthesouthshows com savannah detail ', 8);
    for ($i = 0; $i < 1000; $i++) {
        $rows[] = ['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'area_id' => $area->id, 'connection_id' => $connection->id, 'kind' => 'event', 'title' => $i === 0 ? 'Made in the South Macon' : 'Fictional holiday market '.$i, 'source_key' => hash('sha256', 'event-'.$i), 'status' => 'source', 'visibility' => 'area', 'version' => 1, 'data' => \Illuminate\Support\Facades\Crypt::encryptString(json_encode(['_generation' => 1, 'source_project' => '200', 'source_workspace' => '100', 'original_notes' => $notes.' Reference event '.$i, 'budget_id' => $budget])), 'created_at' => now(), 'updated_at' => now()];
    }
    foreach (array_chunk($rows, 100) as $chunk) {
        DB::table('team_operation_records')->insert($chunk);
    }
    $metrics = ['events' => 1000, 'query' => 'Macon', 'note_words' => str_word_count($notes), 'query_count' => 0, 'area_queries' => 0, 'connection_queries' => 0, 'record_queries' => 0];
    $measuring = true;
    DB::listen(function ($event) use (&$metrics, &$measuring): void {
        if (! $measuring) {
            return;
        }
        $metrics['query_count']++;
        foreach (['area_queries' => 'team_areas', 'connection_queries' => 'team_provider_connections', 'record_queries' => 'team_operation_records'] as $key => $table) {
            if (str_contains($event->sql, 'from "'.$table.'"') || str_contains($event->sql, 'from `'.$table.'`')) {
                $metrics[$key]++;
            }
        }
    });
    $started = microtime(true);
    $response = $this->actingAs($owner)->getJson(route('team.search.all', ['tenant' => $tenant->slug, 'q' => 'Macon']));
    $elapsed = round((microtime(true) - $started) * 1000, 1);
    $measuring = false;
    $metrics['elapsed_ms'] = $elapsed;
    if ($path = getenv('TEAM_SEARCH_PERFORMANCE_EVIDENCE')) {
        if (! str_starts_with($path, '/Users/johncollins/Documents/Codex/2026-11-06/i-x20/outputs/')) {
            throw new RuntimeException('Private aggregate evidence only.');
        }
        file_put_contents($path, json_encode($metrics, JSON_PRETTY_PRINT));
        chmod($path, 0600);
    }
    $response->assertOk()->assertJsonPath('coverage.complete', true);
    expect($response->json('items.0.title'))->toBe('Made in the South Macon');
    expect($metrics['query_count'])->toBeLessThan(250);
    expect($metrics['area_queries'])->toBeLessThan(25)->and($metrics['connection_queries'])->toBeLessThan(10)->and($metrics['record_queries'])->toBeLessThan(10);
    Http::assertNothingSent();
});

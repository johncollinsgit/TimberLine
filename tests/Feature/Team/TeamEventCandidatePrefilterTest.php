<?php

use App\Models\TeamArea;
use App\Models\TeamOperationRecord;
use App\Models\TeamProviderConnection;
use App\Services\Team\Search\CanonicalEventPrefilter;
use App\Services\Team\Search\SearchMatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

require_once __DIR__.'/../../Support/TeamFixtures.php';

test('event candidate filtering preserves authorized relevance phrases aliases history and source fences', function () {
    $this->withoutVite();
    config(['team_work.enabled' => true, 'team_work.premium_enabled' => true, 'team_operations.enabled' => true, 'team_work.search_enabled' => false, 'team_work.search_index_enabled' => false]);
    $this->travelTo(now('UTC')->setDateTime(2026, 11, 8, 12, 0));
    Http::preventStrayRequests();
    [$tenant, $owner] = teamWorkspace();
    $area = TeamArea::create(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'name' => 'Events', 'slug' => 'events', 'profile' => 'events', 'settings' => []]);
    $member = teamMember($tenant, 'member');
    DB::table('team_area_members')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'area_id' => $area->id, 'user_id' => $member->id, 'role' => 'viewer']);
    $connection = TeamProviderConnection::create(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'user_id' => $owner->id, 'area_id' => $area->id, 'provider' => 'asana', 'account_key' => hash('sha256', Str::uuid()), 'status' => 'connected', 'generation' => 1, 'settings' => ['source' => ['asana_workspace' => '100', 'asana_project' => '200']]]);
    $create = fn ($title, $data = [], $extra = []) => TeamOperationRecord::create(array_replace(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'area_id' => $area->id, 'kind' => 'event', 'title' => $title, 'source_key' => hash('sha256', Str::uuid()), 'status' => 'accepted', 'starts_on' => '2026-11-08', 'data' => $data], $extra));
    $source = $create('Macon Érable Market', ['_generation' => 1, 'source_workspace' => '100', 'source_project' => '200', 'source_id' => '300', 'original_notes' => "Set-Up Time: Thursday 10am-7pm\nCost: $480\nReturn: Monday pickup\nVenue Address: Érable Hall\nBooth Number: 423"], ['connection_id' => $connection->id]);
    $source->update(['data' => [...$source->data, 'source_version' => 'source-auditopaque2021', 'reviewed_fields' => ['setup_time' => 'Thursday 10am-7pm'], '_teams_enrichment' => ['field_reviews' => [['field' => 'setup_time', 'value' => 'Thursday 10am-7pm', 'passage' => 'Set-Up Time: Thursday 10am-7pm', 'review_state' => 'approved', 'reviewed_by' => 918273645, 'reviewed_at' => '2021-11-08T12:00:00Z', 'source_version' => 'source-auditopaque2021']]]]]);
    $create('Macon old market', ['notes' => 'Archived copper lantern festival'], ['starts_on' => '2022-11-04', 'ends_on' => '2022-11-06']);
    $create('Private intermediate values', ['description' => 'Copper', 'private_computed_value' => 'An intervening field', 'notes' => 'lantern', 'forecast_override' => ['description' => 'velvet unicorn sentinel']]);
    $create('Unconfirmed dates', ['sessions' => [['id' => (string) Str::uuid(), 'kind' => 'setup', 'date' => '2040-01-01']]], ['starts_on' => null]);
    $create('Macon assigned secret', ['notes' => 'Classified harbor sentinel'], ['visibility' => 'assigned', 'assigned_user_id' => $owner->id]);
    $create('Macon incorrect generation', ['_generation' => 2, 'source_workspace' => '100', 'source_project' => '200', 'source_id' => '301'], ['connection_id' => $connection->id]);
    $create('Macon wrong selected project', ['_generation' => 1, 'source_workspace' => '100', 'source_project' => '999', 'source_id' => '302'], ['connection_id' => $connection->id]);
    foreach ([$owner, $member] as $user) {
        foreach (['Macon', 'Macno', 'érable', 'setup time', 'fee', 'venue fee unconfirmed', 'return instructions', 'needs review', 'explicit label', 'Macon 2022', '"Copper lantern"', '"Thursday 10am"', 'velvet unicorn sentinel', '2040', 'Classified harbor sentinel', 'incorrect generation', 'wrong selected project', '918273645', 'auditopaque2021'] as $query) {
            $url = route('team.search.all', ['tenant' => $tenant->slug, 'q' => $query, 'type' => 'event', 'limit' => 25]);
            $this->instance(CanonicalEventPrefilter::class, new CanonicalEventPrefilter);
            $optimized = $this->actingAs($user)->getJson($url)->assertOk()->json('items');
            $this->instance(CanonicalEventPrefilter::class, new class extends CanonicalEventPrefilter
            {
                public function mightMatch(object $candidate, SearchMatcher $matcher, array $query): bool
                {
                    return true;
                }
            });
            $unfiltered = $this->getJson($url)->assertOk()->json('items');
            expect($optimized)->toBe($unfiltered, 'Candidate parity: '.$query);
            expect(json_encode($optimized))->not->toContain('velvet unicorn sentinel', 'Macon incorrect generation', 'Macon wrong selected project');
        }
    }
    $connection->update(['generation' => 3]);
    $this->instance(CanonicalEventPrefilter::class, new CanonicalEventPrefilter);
    $this->actingAs($owner)->getJson(route('team.search.all', ['tenant' => $tenant->slug, 'q' => 'Macon', 'type' => 'event']))->assertOk()->assertDontSee($source->id);
    [$other] = teamWorkspace();
    $this->getJson(route('team.search.all', ['tenant' => $other->slug, 'q' => 'Macon']))->assertForbidden();
    Http::assertNothingSent();
});

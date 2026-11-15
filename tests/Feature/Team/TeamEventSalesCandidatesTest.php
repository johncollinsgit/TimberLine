<?php

use App\Models\TeamOperationRecord;
use App\Models\TenantMarketingSetting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

require_once __DIR__.'/../../Support/TeamStudioFixtures.php';

beforeEach(function (): void {
    $this->withoutVite();
    Http::preventStrayRequests();
    config(['team_work.enabled' => true, 'team_work.premium_enabled' => true, 'team_operations.enabled' => true]);
    [$this->tenant, $this->owner] = teamWorkspace();
    $this->area = studioArea($this->tenant);
});

function salesCandidateRecord($tenant, $area, string $title, string $kind = 'event', array $data = []): TeamOperationRecord
{
    return TeamOperationRecord::create(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'area_id' => $area->id,
        'kind' => $kind, 'title' => $title, 'source_key' => hash('sha256', Str::uuid()), 'status' => 'accepted', 'version' => 1,
        'starts_on' => '2025-11-01', 'ends_on' => '2025-11-03', 'data' => $data]);
}

test('historical mapping suggestions preserve source segments without allocating or leaking payroll', function (): void {
    $event = salesCandidateRecord($this->tenant, $this->area, '11.13.26 - 11.15.26 Fictional Fall Show');
    $first = salesCandidateRecord($this->tenant, $this->area, 'Fictional Fall Show', 'financial', ['person_name' => 'Former staff', 'source_tab' => '2025', 'source_row' => 9,
        'net_sales_cents' => 100000, 'payroll_cents' => 12345, 'costs' => ['lodging' => 50000]]);
    $second = salesCandidateRecord($this->tenant, $this->area, 'Fictional Fall Show', 'financial', ['person_name' => 'Another staff', 'source_tab' => '2025', 'source_row' => 10, 'needs_allocation_review' => true]);
    $staff = salesCandidateRecord($this->tenant, $this->area, 'Former staff', 'staff', ['identity_review' => true]);
    salesCandidateRecord($this->tenant, $this->area, 'Fictional Spring Show', 'financial');
    [$other] = teamWorkspace();
    $otherArea = studioArea($other);
    salesCandidateRecord($other, $otherArea, 'Fictional Fall Show', 'financial', ['person_name' => 'Private other company']);
    salesCandidateRecord($other, $otherArea, 'Private other staff', 'staff');
    TenantMarketingSetting::create(['tenant_id' => $this->tenant->id, 'key' => 'square_source_refresh', 'value' => ['discovered_locations' => [['id' => 'OLDLOCATION', 'name' => 'Former staff', 'status' => 'INACTIVE']]]]);
    $count = TeamOperationRecord::count();
    $response = $this->actingAs($this->owner)->getJson('/team/events/'.$event->id.'/sales-candidates?tenant='.$this->tenant->slug)->assertOk()
        ->assertJsonPath('mapping', null)->assertJsonPath('square_locations.0.status', 'INACTIVE')->assertJsonMissingPath('candidates.0.payroll_cents')
        ->assertJsonPath('staff_choices.0.id', $staff->id)->assertJsonPath('staff_choices.0.identity_review_required', true)
        ->assertJsonMissingPath('candidates.0.costs');
    expect(array_column($response->json('candidates'), 'id'))->toContain($first->id, $second->id)->toHaveCount(3);
    expect($response->getContent())->not->toContain('Private other company', 'Private other staff', 'Fictional Spring Show', '12345');
    expect(TeamOperationRecord::count())->toBe($count)->and(TeamOperationRecord::where('kind', 'event_link')->count())->toBe(0);
    Http::assertNothingSent();
});

test('source candidate reconciliation requires a manager even for an assigned staff member', function (): void {
    $member = teamMember($this->tenant, 'member');
    DB::table('team_area_members')->insert(['id' => Str::uuid(), 'tenant_id' => $this->tenant->id, 'area_id' => $this->area->id, 'user_id' => $member->id, 'role' => 'editor']);
    $event = salesCandidateRecord($this->tenant, $this->area, 'Fictional Fall Show');
    $event->update(['assigned_user_id' => $member->id]);
    $this->actingAs($member)->getJson('/team/events/'.$event->id.'/sales-candidates?tenant='.$this->tenant->slug)->assertForbidden();
    [$other, $owner] = teamWorkspace();
    $this->actingAs($owner)->getJson('/team/events/'.$event->id.'/sales-candidates?tenant='.$other->slug)->assertNotFound();
});

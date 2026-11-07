<?php

use App\Models\TeamOperationRecord;
use App\Services\Team\Studio\EventCostCalculator;
use App\Services\Team\Studio\ModernForestryPolicy;
use App\Services\Team\Studio\QualificationRules;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require_once __DIR__.'/../../Support/TeamStudioFixtures.php';

beforeEach(function () {
    $this->withoutVite();
    config(['team_work.enabled' => true, 'team_work.premium_enabled' => true, 'team_operations.enabled' => true]);
});

test('qualifications distinguish unknown facts and reject executable conditions', function () {
    $q = app(QualificationRules::class);
    expect($q->matches(['all' => [['field' => 'staff.eligible', 'op' => 'eq', 'value' => true], ['field' => 'event.days', 'op' => 'gte', 'value' => 3]]], ['staff' => ['eligible' => true]]))->toBeNull();
    expect($q->matches(['any' => [['field' => 'staff.eligible', 'op' => 'eq', 'value' => true], ['field' => 'event.days', 'op' => 'gte', 'value' => 3]]], ['staff' => ['eligible' => true]]))->toBeTrue();
    expect(fn () => $q->validate(['field' => 'staff.eligible', 'op' => 'eval', 'value' => 'shell']))->toThrow(\Illuminate\Validation\ValidationException::class);
});

test('cost preview uses integer amounts, setup hours, explicit trip legs and credited deposits', function () {
    $result = app(EventCostCalculator::class)->preview(studioInputs([
        'expected_net_sales_minor' => 100000,
        'crew' => [['id' => 'a', 'name' => 'Fictional operator', 'pay_type' => 'hourly', 'work_minutes' => 1200, 'days' => []]],
        'fees' => [['label' => 'Venue', 'total_minor' => 48000, 'deposit_minor' => 10000, 'deposit_credited' => true]],
        'hotels' => [['label' => 'Hotel', 'check_in' => '2026-11-05', 'check_out' => '2026-11-09', 'rooms' => 2, 'nightly_total_minor' => 10000]],
        'trips' => [['id' => 'drive', 'kind' => 'studio_event', 'distance_millimiles' => 100000, 'round_trip' => true, 'vehicles' => 1]],
    ]), studioPolicy());
    expect($result['complete'])->toBeTrue()->and($result['lines'][0]['amount_minor'])->toBe(27600)
        ->and($result['lines'][1]['remaining_minor'])->toBe(38000)->and($result['lines'][2]['amount_minor'])->toBe(80000)
        ->and($result['lines'][3]['amount_minor'])->toBe(13400)->and($result['total_cost_minor'])->toBe(169000);
});

test('per diem overlaps take one highest qualifying allowance per person per date', function () {
    $result = app(EventCostCalculator::class)->preview(studioInputs(['crew' => [['id' => 'a', 'name' => 'Fictional operator', 'pay_type' => 'hourly', 'work_minutes' => 600, 'days' => [['date' => '2026-11-05', 'setup' => true, 'event_minutes' => 540, 'drive_minutes' => 240]]]]]), studioPolicy());
    expect($result['complete'])->toBeTrue()->and($result['lines'][1]['amount_minor'])->toBe(5500)->and($result['lines'][1]['rules'])->toHaveCount(3);
});

test('commission minimum applies while unknown sales remain provisional', function () {
    $inputs = studioInputs(['crew' => [['id' => 'a', 'name' => 'Fictional operator', 'pay_type' => 'commission', 'work_minutes' => 600, 'days' => []]]]);
    $calculator = app(EventCostCalculator::class);
    expect($calculator->preview($inputs, studioPolicy())['total_cost_minor'])->toBeNull();
    $result = $calculator->preview($inputs + ['expected_net_sales_minor' => 10000], studioPolicy());
    expect($result['lines'][0]['amount_minor'])->toBe(13200);
});

test('rule conflicts, expired policies and missing categories do not become zero totals', function () {
    $policy = studioPolicy();
    $policy['rules'][] = array_replace($policy['rules'][0], ['id' => 'other_hourly']);
    $result = app(EventCostCalculator::class)->preview(studioInputs(['crew' => [['id' => 'a', 'name' => 'Fictional operator', 'pay_type' => 'hourly', 'work_minutes' => 60, 'days' => []]]]), $policy);
    expect($result['total_cost_minor'])->toBeNull()->and(implode(' ', $result['review_items']))->toContain('conflicting');
    $result = app(EventCostCalculator::class)->preview(studioInputs(), array_replace(studioPolicy(), ['expires_on' => '2026-10-31', 'required_categories' => ['lodging']]));
    expect($result['complete'])->toBeFalse()->and($result['review_items'])->toHaveCount(2);
});

test('duplicate person dates and reverse hotel nights are rejected', function () {
    $c = app(EventCostCalculator::class);
    expect(fn () => $c->inputs(studioInputs(['crew' => [['id' => 'a', 'name' => 'Operator', 'pay_type' => 'hourly', 'days' => [['date' => '2026-11-06'], ['date' => '2026-11-06']]]]])))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    expect(fn () => $c->inputs(studioInputs(['hotels' => [['label' => 'Hotel', 'check_in' => '2026-11-08', 'check_out' => '2026-11-05', 'rooms' => 1]]])))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
});

test('published policies are immutable, encrypted and private to authorized tenant admins', function () {
    [$t, $owner] = teamWorkspace();
    $a = studioArea($t);
    $input = studioDefinition($t, $owner, $a);
    $this->postJson('/team/studio/definition?tenant='.$t->slug.'&area='.$a->id, $input)->assertOk()->assertJsonPath('version', 1);
    $this->postJson('/team/studio/definition?tenant='.$t->slug.'&area='.$a->id, array_replace($input, ['request_id' => (string) Str::uuid(), 'expected_version' => 1]))->assertConflict();
    expect(DB::table('team_operation_records')->value('data'))->not->toContain('Commission minimum');
    $member = teamMember($t, 'member');
    DB::table('team_area_members')->insert(['id' => Str::uuid(), 'tenant_id' => $t->id, 'area_id' => $a->id, 'user_id' => $member->id, 'role' => 'editor']);
    $this->actingAs($member)->getJson('/team/studio?tenant='.$t->slug.'&area='.$a->id)->assertOk()->assertJsonMissingPath('definitions');
    $this->postJson('/team/studio/definition?tenant='.$t->slug.'&area='.$a->id, $input)->assertForbidden();
    [$other, $foreign] = teamWorkspace();
    $this->actingAs($foreign)->getJson('/team/studio?tenant='.$other->slug.'&area='.$a->id)->assertNotFound();
});

test('criteria discrepancy blocks policy publication until owner resolution', function () {
    [$t, $owner] = teamWorkspace();
    $a = studioArea($t);
    $this->actingAs($owner)->postJson('/team/studio/definition?tenant='.$t->slug.'&area='.$a->id, ['id' => Str::uuid(), 'request_id' => Str::uuid(), 'expected_version' => 0, 'kind' => 'cost_policy', 'publish' => true, 'definition' => app(ModernForestryPolicy::class)->draft()])->assertUnprocessable();
});

test('wizard saves partial drafts then freezes one event and budget across retries', function () {
    [$t, $owner] = teamWorkspace();
    $a = studioArea($t);
    $policy = studioDefinition($t, $owner, $a);
    $draft = ['id' => (string) Str::uuid(), 'request_id' => (string) Str::uuid(), 'expected_version' => 0, 'title' => 'Fictional winter show', 'step' => 0, 'logistics' => [], 'budget_inputs' => [], 'revision' => null];
    $url = '/team/studio/draft?tenant='.$t->slug.'&area='.$a->id;
    $this->postJson($url, $draft)->assertOk()->assertJsonPath('version', 1);
    $draft = array_replace($draft, ['request_id' => (string) Str::uuid(), 'expected_version' => 1, 'step' => 4, 'policy_id' => $policy['id'], 'logistics' => ['starts_on' => '2026-11-06', 'ends_on' => '2026-11-08', 'timezone' => 'America/New_York'], 'budget_inputs' => studioInputs()]);
    $this->postJson($url, $draft)->assertOk()->assertJsonPath('version', 2);
    $create = ['request_id' => (string) Str::uuid(), 'draft_id' => $draft['id'], 'expected_version' => 2, 'accept_review_items' => true];
    $url = '/team/studio/create?tenant='.$t->slug.'&area='.$a->id;
    $first = $this->postJson($url, $create)->assertOk()->json();
    $this->postJson($url, $create)->assertOk()->assertJsonPath('event_id', $first['event_id']);
    expect(TeamOperationRecord::where('kind', 'event')->count())->toBe(1)->and(TeamOperationRecord::where('kind', 'event_budget')->count())->toBe(1);
    $budget = TeamOperationRecord::find($first['budget_id']);
    expect($budget->data['policy_snapshot']['rules'][0]['effect']['rate_minor'])->toBe(1200);
    $this->postJson($url, array_replace($create, ['request_id' => (string) Str::uuid()]))->assertConflict();
});

test('Modern Forestry authority cannot cut over automatically even after 2027', function () {
    [$t, $owner] = teamWorkspace();
    $a = studioArea($t);
    config(['team_operations.presets.'.$t->slug.'.events' => ['asana_workspace' => 'fictional', 'migration_not_before' => '2027-01-01']]);
    $this->travelTo(now()->setDate(2026, 11, 7));
    $this->actingAs($owner)->getJson('/team/studio?tenant='.$t->slug.'&area='.$a->id)->assertOk()->assertJsonPath('authority.provider', 'asana')->assertJsonPath('authority.bulk_migration_allowed', false)->assertJsonPath('authority.cutover_enabled', false);
    $this->travelTo(now()->setDate(2027, 1, 1));
    $this->getJson('/team/studio?tenant='.$t->slug.'&area='.$a->id)->assertOk()->assertJsonPath('authority.cutover_enabled', false);
});

test('known commission does not bypass a minimum when staff hours are unknown', function () {
    $result = app(EventCostCalculator::class)->preview(studioInputs(['expected_net_sales_minor' => 100000, 'crew' => [['id' => 'a', 'name' => 'Fictional operator', 'pay_type' => 'commission', 'work_minutes' => null, 'days' => []]]]), studioPolicy());
    expect($result['total_cost_minor'])->toBeNull()->and($result['lines'][0]['amount_minor'])->toBeNull();
});

test('two tenants publish different terminology structures and rates without inheriting one another', function () {
    [$a, $alice] = teamWorkspace();
    [$b, $bob] = teamWorkspace();
    $aa = studioArea($a);
    $ba = studioArea($b);
    foreach ([[$a, $alice, $aa, 'Crew', 1200], [$b, $bob, $ba, 'Department', 2000]] as [$tenant, $user, $area, $term, $rate]) {
        $policy = studioPolicy();
        $policy['name'] = $term.' policy';
        $policy['rules'][0]['effect']['rate_minor'] = $rate;
        studioDefinition($tenant, $user, $area, ['definition' => $policy]);
        $blueprint = ['name' => $term.' workspace', 'terminology' => ['team' => $term], 'stages' => [['id' => 'active', 'name' => $term.' active']], 'fields' => [['id' => 'reference', 'name' => $term.' reference', 'type' => 'text', 'required' => false]], 'forms' => [['id' => 'intake', 'name' => $term.' intake', 'field_ids' => ['reference']]], 'templates' => [], 'dashboards' => []];
        $this->actingAs($user)->postJson('/team/studio/definition?tenant='.$tenant->slug.'&area='.$area->id, ['id' => Str::uuid(), 'request_id' => Str::uuid(), 'expected_version' => 0, 'kind' => 'blueprint', 'publish' => true, 'definition' => $blueprint])->assertOk();
        $this->getJson('/team/studio?tenant='.$tenant->slug.'&area='.$area->id)->assertOk()->assertJsonPath('blueprint.data.definition.terminology.team', $term);
    }
    $this->actingAs($alice)->getJson('/team/studio?tenant='.$a->slug.'&area='.$ba->id)->assertNotFound();
    $this->actingAs($bob)->getJson('/team/studio?tenant='.$b->slug.'&area='.$aa->id)->assertNotFound();
});

test('a tenant can select priority-based per diem while still paying once per person date', function () {
    $policy = studioPolicy();
    $policy['per_diem_selection'] = 'priority';
    foreach ($policy['rules'] as &$rule) {
        if ($rule['id'] === 'long_drive') {
            $rule['priority'] = 20;
        }
    }
    unset($rule);
    $result = app(EventCostCalculator::class)->preview(studioInputs(['crew' => [['id' => 'a', 'name' => 'Fictional operator', 'pay_type' => 'hourly', 'work_minutes' => 600, 'days' => [['date' => '2026-11-05', 'setup' => true, 'event_minutes' => 540, 'drive_minutes' => 240]]]]]), $policy);
    expect($result['complete'])->toBeTrue()->and($result['lines'][1]['amount_minor'])->toBe(3500);
});

test('the shared browser shell injects only current authenticated escaped boot data and is never cached', function () {
    [$tenant, $owner] = teamWorkspace();
    $tenant->update(['name' => '</script><script>unsafe()</script>']);
    $response = $this->actingAs($owner)->get('/teams/studio?tenant='.$tenant->slug)->assertOk()->assertSee('<script id="teams-boot" type="application/json">', false)->assertDontSee('</script><script>unsafe()', false)->assertSee('/teams-workspace/assets/', false);
    expect($response->headers->get('Cache-Control'))->toContain('no-store', 'private');
});

test('unknown allowance qualification and uncovered dates never appear as known zero costs', function () {
    $calculator = app(EventCostCalculator::class);
    $inputs = studioInputs(['crew' => [['id' => 'a', 'name' => 'Fictional operator', 'pay_type' => 'hourly', 'work_minutes' => 60, 'days' => [['date' => '2026-11-05', 'setup' => false]]]]]);
    $result = $calculator->preview($inputs, studioPolicy());
    expect($result['complete'])->toBeFalse()->and($result['lines'][1]['amount_minor'])->toBeNull();
    $inputs['crew'][0]['days'][0] += ['event_minutes' => 540, 'drive_minutes' => 240];
    $result = $calculator->preview($inputs, array_replace(studioPolicy(), ['effective_on' => '2026-11-06']));
    expect($result['lines'][1]['amount_minor'])->toBeNull();
});

test('an unqualified possible minimum cannot be bypassed by a known commission', function () {
    $policy = studioPolicy();
    foreach ($policy['rules'] as &$rule) {
        if ($rule['category'] === 'minimum_pay') {
            $rule['when'] = ['field' => 'staff.minimum_eligible', 'op' => 'eq', 'value' => true];
        }
    }
    unset($rule);
    $result = app(EventCostCalculator::class)->preview(studioInputs(['expected_net_sales_minor' => 100000, 'crew' => [['id' => 'a', 'name' => 'Fictional operator', 'pay_type' => 'commission', 'work_minutes' => 600, 'days' => []]]]), $policy);
    expect($result['lines'][0]['amount_minor'])->toBeNull()->and($result['known_cost_minor'])->toBe(0)->and($result['total_cost_minor'])->toBeNull();
});

test('explicit budget recalculation freezes a new policy snapshot once without creating another event', function () {
    [$tenant, $owner] = teamWorkspace();
    $area = studioArea($tenant);
    $studio = app(\App\Services\Team\Studio\TeamsStudio::class);
    $firstPolicy = studioDefinition($tenant, $owner, $area);
    $inputs = studioInputs(['crew' => [['id' => 'a', 'name' => 'Fictional operator', 'pay_type' => 'hourly', 'work_minutes' => 60, 'days' => []]]]);
    $base = ['title' => 'Fictional budget event', 'step' => 4, 'logistics' => ['starts_on' => '2026-11-06', 'ends_on' => '2026-11-08', 'timezone' => 'America/New_York'], 'budget_inputs' => $inputs];
    $draft = $studio->saveDraft($tenant, $owner, $area, $base + ['id' => (string) Str::uuid(), 'request_id' => (string) Str::uuid(), 'expected_version' => 0, 'policy_id' => $firstPolicy['id']]);
    $created = $studio->createEvent($tenant, $owner, $area, ['request_id' => (string) Str::uuid(), 'draft_id' => $draft['id'], 'expected_version' => 1, 'accept_review_items' => true]);
    $newPolicy = studioPolicy();
    $newPolicy['rules'][0]['effect']['rate_minor'] = 1500;
    $published = studioDefinition($tenant, $owner, $area, ['definition' => $newPolicy]);
    $oldBudget = TeamOperationRecord::findOrFail($created['budget_id'])->data;
    expect($oldBudget['preview']['total_cost_minor'])->toBe(4800)->and($oldBudget['policy_snapshot']['rules'][0]['effect']['rate_minor'])->toBe(1200);
    $revision = ['event_id' => $created['event_id'], 'event_version' => 1, 'budget_id' => $created['budget_id'], 'reason' => 'Explicit recalculation after revised event hours and policy.'];
    $base['budget_inputs']['crew'][0]['work_minutes'] = 120;
    $draft = $studio->saveDraft($tenant, $owner, $area, $base + ['id' => (string) Str::uuid(), 'request_id' => (string) Str::uuid(), 'expected_version' => 0, 'policy_id' => $published['id'], 'revision' => $revision]);
    $create = ['request_id' => (string) Str::uuid(), 'draft_id' => $draft['id'], 'expected_version' => 1, 'accept_review_items' => true];
    $this->actingAs($owner)->postJson('/team/studio/create?tenant='.$tenant->slug.'&area='.$area->id, $create)->assertUnprocessable();
    $command = $create + ['event_id' => $created['event_id'], 'expected_event_version' => 1, 'expected_budget_id' => $created['budget_id'], 'reason' => $revision['reason']];
    $url = '/team/studio/budget?tenant='.$tenant->slug.'&area='.$area->id;
    $result = $this->postJson($url, $command)->assertOk()->assertJsonPath('event_version', 2)->assertJsonPath('budget.total_cost_minor', 7500)->json();
    $this->postJson($url, $command)->assertOk()->assertJsonPath('budget_id', $result['budget_id']);
    $this->postJson($url, array_replace($command, ['request_id' => (string) Str::uuid()]))->assertConflict();
    expect(TeamOperationRecord::where('kind', 'event')->count())->toBe(1)->and(TeamOperationRecord::where('kind', 'event_budget')->count())->toBe(2)
        ->and(TeamOperationRecord::findOrFail($created['budget_id'])->data)->toBe($oldBudget)
        ->and(TeamOperationRecord::findOrFail($created['event_id'])->data['_teams_enrichment']['budget_id'])->toBe($result['budget_id']);
});

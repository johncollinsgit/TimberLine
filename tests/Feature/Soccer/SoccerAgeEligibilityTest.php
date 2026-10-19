<?php

use App\Models\Tenant;
use App\Services\Soccer\SoccerEligibilityService;
use App\Services\Soccer\SoccerFamilyHelpService;
use App\Services\Soccer\SoccerFamilyService;
use App\Services\Soccer\SoccerRegistrationService;
use App\Services\Soccer\SoccerStore;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    config()->set('soccer.enabled', true);
    config()->set('soccer.help_ai_enabled', false);
    $this->tenant = Tenant::create(['name' => 'Wren', 'slug' => 'wren-family-soccer']);
    $this->store = app(SoccerStore::class);
    $this->season = $this->store->insert('seasons', $this->tenant->id, ['season_key' => 'spring-2027', 'name' => 'Spring 2027', 'starts_on' => '2027-03-15', 'age_as_of' => '2027-03-15', 'registration_open' => true, 'teams' => json_encode(config('soccer.teams'))]);
    $this->prior = $this->store->insert('seasons', $this->tenant->id, ['season_key' => 'fall-2026', 'name' => 'Fall 2026', 'teams' => json_encode(config('soccer.teams'))]);
    $this->family = $this->store->insert('families', $this->tenant->id, ['email' => 'example@example.com', 'profile' => $this->store->encrypt(['parentName' => 'Example Parent', 'phone' => '2025550101', 'emergencyContact' => 'Example Contact', 'emergencyPhone' => '2025550102'])]);
});
function ageAuditPlayer($t, string $birthday, string $history = 'none'): array
{
    $id = $t->store->insert('players', $t->tenant->id, ['family_id' => $t->family, 'name' => 'Example Player', 'birthday' => $birthday, 'profile' => $t->store->encrypt(['returning' => true])]);
    $t->store->insert('releases', $t->tenant->id, ['family_id' => $t->family, 'player_id' => $id, 'season_key' => 'spring-2027', 'source_key' => 'portal:'.Str::uuid(), 'form_version' => 'test', 'answers' => $t->store->encrypt([]), 'sha256' => str_repeat('a', 64), 'signed_at' => now()]);
    if ($history === 'historical') {
        $t->store->insert('registrations', $t->tenant->id, ['family_id' => $t->family, 'player_id' => $id, 'season_id' => $t->prior, 'status' => 'historical', 'team_key' => 'red-falcons', 'amount_cents' => 0, 'needs_jersey' => false, 'queued_at' => now()]);
    }
    if (in_array($history, ['d1', 'portal'])) {
        $t->store->insert('releases', $t->tenant->id, ['family_id' => $t->family, 'player_id' => $id, 'season_key' => 'fall-2026', 'source_key' => $history.':'.Str::uuid(), 'form_version' => 'test', 'answers' => $t->store->encrypt([]), 'sha256' => str_repeat('b', 64), 'signed_at' => now()]);
    }

    return [$id, ['season_id' => $t->season, 'volunteer' => false, 'children' => [['player_id' => $id, 'needs_jersey' => false]]]];
}
it('enforces new and returning upper boundaries in quotes and enrollment', function ($birthday, $history, $allowed) {
    [$player,$input] = ageAuditPlayer($this, $birthday, $history);
    $service = app(SoccerRegistrationService::class);
    if ($allowed) {
        expect($service->quote($this->tenant->id, $this->family, $input)['due_today_cents'])->toBe(3000);
        $service->register($this->tenant->id, $this->family, $input);
        expect($this->store->query('registrations', $this->tenant->id)->where('season_id', $this->season)->count())->toBe(1);
    } else {
        expect(fn () => $service->quote($this->tenant->id, $this->family, $input))->toThrow(ValidationException::class);
        expect(fn () => $service->register($this->tenant->id, $this->family, $input))->toThrow(ValidationException::class);
        expect($this->store->query('invoices', $this->tenant->id)->count())->toBe(0);
    }
})->with([
    'new turns 3 on cutoff' => ['2024-03-15', 'none', true], 'under 3 by one day' => ['2024-03-16', 'none', false],
    'new age 10' => ['2017-03-15', 'none', true], 'new age 11' => ['2016-03-15', 'none', false], 'new age 12' => ['2015-03-15', 'none', false],
    'returning 11' => ['2016-03-15', 'historical', true], 'returning 12' => ['2015-03-15', 'historical', true],
    'returning 12 until next day' => ['2014-03-16', 'd1', true], 'returning 13 on cutoff' => ['2014-03-15', 'd1', false],
    'fresh portal release cannot claim prior attendance' => ['2015-03-15', 'portal', false],
]);
it('allows younger profiles and exposes server assessed eligibility', function () {
    $family = $this->store->row('families', $this->tenant->id, $this->family);
    $id = app(SoccerFamilyService::class)->player($this->tenant->id, $family, ['name' => 'Young sibling', 'birthday' => '2024-09-01', 'profile' => []]);
    $profile = app(SoccerFamilyService::class)->profile($this->tenant->id, $family);
    $player = $profile['players']->firstWhere('id', $id);
    expect($player['eligibility'])->toMatchArray(['eligible' => false, 'age' => 2, 'returning' => false]);
    expect($this->store->query('registrations', $this->tenant->id)->count())->toBe(0);
});
it('does not use another child or tenant evidence as returning status', function () {
    [$id] = ageAuditPlayer($this, '2015-03-15');
    $p = $this->store->row('players', $this->tenant->id, $id);
    $season = $this->store->row('seasons', $this->tenant->id, $this->season);
    $other = Tenant::create(['name' => 'Other', 'slug' => 'other']);
    $this->store->insert('releases', $other->id, ['family_id' => $this->family, 'player_id' => $id, 'season_key' => 'fall-2026', 'source_key' => 'd1:cross-tenant', 'form_version' => 'test', 'answers' => $this->store->encrypt([]), 'sha256' => str_repeat('c', 64), 'signed_at' => now()]);
    expect(app(SoccerEligibilityService::class)->assess($this->tenant->id, $p, $season)['eligible'])->toBeFalse();
});
it('answers age questions from policy and refers unknown exceptions to Sarah', function () {
    ageAuditPlayer($this, '2015-03-15', 'historical');
    $family = $this->store->row('families', $this->tenant->id, $this->family);
    $help = app(SoccerFamilyHelpService::class);
    $answer = $help->ask($this->tenant->id, $family, 'Can returning players age 12 play?');
    expect($answer['answer'])->toContain('through age 12')->toContain('Red Falcons')->not->toContain('expected team not available');
    $unknown = $help->ask($this->tenant->id, $family, 'Will you guarantee a special exception?');
    expect($unknown['needs_review'])->toBeTrue();
    expect($unknown['answer'])->toContain('Sarah Collins')->toContain('678-644-1829')->toContain('cannot promise an exception');
});

<?php

use App\Models\Scent;
use App\Services\ScentGovernance\ResolveScentMatchService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function topScentMatch(string $raw): ?string
{
    return app(ResolveScentMatchService::class)
        ->resolveCandidates($raw)
        ->first()['name'] ?? null;
}

beforeEach(function (): void {
    // The canonical catalog is seeded by RefreshDatabase; ensure the scents this test
    // asserts on exist regardless of seeder contents.
    Scent::firstOrCreate(['name' => 'Appalachian Maple Bourbon']); // no stored abbreviation on purpose
    Scent::firstOrCreate(['name' => 'Beard']);
    Scent::updateOrCreate(['name' => 'Amber Fog'], ['abbreviation' => 'AF']);
    Scent::firstOrCreate(['name' => 'Honey Amber']);
    Scent::updateOrCreate(['name' => 'Campfire'], ['abbreviation' => 'CF']);
});

it('matches a verbose retail title past container/size noise', function () {
    expect(topScentMatch('- Appalachian maple bourbon mason candle 4oz'))
        ->toBe('Appalachian Maple Bourbon');
});

it('resolves an initialism even without a stored abbreviation', function () {
    expect(topScentMatch('one AMB'))->toBe('Appalachian Maple Bourbon');
    expect(topScentMatch('AMB'))->toBe('Appalachian Maple Bourbon');
});

it('matches a single-word scent buried in material/container noise', function () {
    expect(topScentMatch('Beard Soy Candle'))->toBe('Beard');
});

it('still resolves clean names and stored abbreviations (no regression)', function () {
    expect(topScentMatch('Campfire'))->toBe('Campfire');
    expect(topScentMatch('CF'))->toBe('Campfire');
    expect(topScentMatch('Amber Fog wholesale'))->toBe('Amber Fog');
});

<?php

use App\Models\Tenant;
use App\Services\Soccer\SoccerImportService;
use App\Services\Soccer\SoccerStore;

beforeEach(function () {
    $this->tenant = Tenant::create(['name' => 'Wren', 'slug' => 'wren-family-soccer']);
    $this->store = app(SoccerStore::class);
    $this->import = app(SoccerImportService::class);
    foreach (['spring-2026', 'fall-2026', 'spring-2027'] as $key) {
        $this->store->insert('seasons', $this->tenant->id, ['season_key' => $key, 'name' => $key, 'teams' => json_encode(config('soccer.teams'))]);
    }
});
function releaseFixture(string $id, string $email, string $name, string $dob): array
{
    return ['id' => $id, 'parentEmail' => $email, 'playerName' => $name, 'submittedAt' => 1787002544000, 'answers' => ['child1Name' => $name, 'child1Birthday' => $dob, 'guardianName' => 'Example Parent', 'motherPhone' => '8645550100', 'guardianSignature' => 'Example Parent', 'releaseSignature' => 'Example Parent']];
}
it('preserves repeated signed evidence while linking the same verified family child', function () {
    $rows = [releaseFixture('a', 'parent@example.com', 'Child Example', '2020-01-02'), releaseFixture('b', 'PARENT@example.com', 'Child Example', '2020-01-02')];
    expect($this->import->releases($this->tenant->id, $rows)['imported'])->toBe(2);
    expect($this->store->query('players', $this->tenant->id)->count())->toBe(1)->and($this->store->query('releases', $this->tenant->id)->count())->toBe(2);
    expect($this->import->releases($this->tenant->id, $rows)['replayed'])->toBe(2);
});
it('requires review for conflicting birthdays, aliases, and changed household emails', function () {
    $rows = [releaseFixture('a', 'parent@example.com', 'Child Example', '2020-01-02'), releaseFixture('b', 'parent@example.com', 'Child Example', '2020-02-01'), releaseFixture('c', 'parent@example.com', 'Child', '2020-01-02'), releaseFixture('d', 'changed@example.com', 'Child Example', '2020-01-02')];
    expect($this->import->releases($this->tenant->id, $rows)['review'])->toBe(3);
    expect($this->store->query('players', $this->tenant->id)->count())->toBe(1)->and($this->store->query('releases', $this->tenant->id)->count())->toBe(4);
});
it('imports each historical season once without inferring a payment or new enrollment', function () {
    $path = tempnam(sys_get_temp_dir(), 'soccer-csv');
    $h = fopen($path, 'w');
    fputcsv($h, ['Email Address', 'Parent Name', 'Child Name', 'Child - Birthday', 'Age', 'I have paid via....'], escape: '');
    fputcsv($h, ['parent@example.com', 'Example Parent', 'Child Example', '1/2/2020', '6', 'Venmo'], escape: '');
    fclose($h);
    try {
        expect($this->import->csv($this->tenant->id, $path, 'spring-2026')['created'])->toBe(1);
        expect($this->import->csv($this->tenant->id, $path, 'fall-2026')['matched'])->toBe(1);
        expect($this->import->csv($this->tenant->id, $path, 'spring-2026')['replayed'])->toBe(1);
        expect($this->store->query('registrations', $this->tenant->id)->where('status', 'historical')->count())->toBe(2)->and($this->store->query('payments', $this->tenant->id)->count())->toBe(0)->and($this->store->query('invoices', $this->tenant->id)->count())->toBe(0);
    } finally {
        unlink($path);
    }
});

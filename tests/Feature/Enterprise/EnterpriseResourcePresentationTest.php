<?php

use App\Enterprise\LocationCatalog;
use Illuminate\Support\Facades\Http;

test('a provided generic catalog renders no campus plan or college provenance', function (): void {
    Http::preventStrayRequests();
    $presentation = ['locations' => ['catalog' => 'client-workshop-b', 'entries' => [
        ['map_number' => 'workshop', 'name' => 'Synthetic Workshop', 'source_label' => 'Reviewed workshop register', 'source_url' => null],
    ]]];
    $html = view('enterprise.resources-panels', ['enterprisePresentation' => $presentation])->render();
    expect($html)->toContain('Location directory · 1', 'Synthetic Workshop');
    expect($html)->not->toContain('Lees-McRae', 'lmc.edu', 'data:image/png;base64,', 'id="res-plan"', 'id="res-plan-image"', 'directory on page 2');
    $script = view('enterprise.resources-script', ['enterprisePresentation' => $presentation])->render();
    expect($script)->toContain('Reviewed workshop register', 'client-workshop-b');
    expect($script)->not->toContain('Lees-McRae', 'lmc.edu', 'All 68', 'official campus parking map, page 2');
    Http::assertNothingSent();
});

test('the fixed Lees catalog retains its official 68 entry plan', function (): void {
    $catalog = app(LocationCatalog::class)->all();
    $html = view('enterprise.resources-panels', ['enterprisePresentation' => ['locations' => $catalog]])->render();
    expect($catalog['count'])->toBe(68);
    expect($html)->toContain('Official map directory · 68', 'id="res-plan-image"', 'data:image/png;base64,', 'Official Lees-McRae campus parking plan');
});

test('an authorized source entry enables the plan only with exact official page two provenance', function (): void {
    $entry = ['map_number' => '1', 'name' => 'Robb Center',
        'source_url' => 'https://www.lmc.edu/campus-life/campus-safety/campus-operations/files/campus-parking-map.pdf', 'source_page' => 2];
    $catalog = ['catalog' => 'client-catalog', 'entries' => [$entry]];
    $html = view('enterprise.resources-panels', ['enterprisePresentation' => ['locations' => $catalog]])->render();
    expect($html)->toContain('id="res-plan-image"', 'Official map directory · 1');
    $catalog['entries'][0]['source_page'] = 3;
    $html = view('enterprise.resources-panels', ['enterprisePresentation' => ['locations' => $catalog]])->render();
    expect($html)->not->toContain('id="res-plan-image"', 'data:image/png;base64,', 'Lees-McRae');
});

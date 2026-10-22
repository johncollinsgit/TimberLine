<?php

use App\Models\HighLevel\Installation;
use Illuminate\Support\Facades\Http;

test('pest-control demo serves a fictional shell without authorization or live installation writes', function () {
    Http::preventStrayRequests();
    config(['highlevel.enabled' => true]);
    $before = Installation::count();
    $response = $this->get('/crm/fleet/demo/pest-control')
        ->assertOk()->assertSee('id="pest-demo"', false)
        ->assertSee('250 synthetic trucks')->assertSee('No CRM, Bouncie or messaging connection')
        ->assertHeader('Referrer-Policy', 'no-referrer');
    expect($response->headers->get('Cache-Control'))->toContain('no-store')->toContain('private');
    expect(Installation::count())->toBe($before);
    expect(config('highlevel.vehicle_limit'))->toBe(100);
    Http::assertNothingSent();
});

test('pest-control preview retains the existing surface availability gate', function () {
    config(['highlevel.enabled' => false]);
    $this->get('/crm/fleet/demo/pest-control')->assertNotFound();
});

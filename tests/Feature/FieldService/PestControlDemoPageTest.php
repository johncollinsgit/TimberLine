<?php

use App\Console\Commands\EverbranchPreparePestControlDemo;

test('the public pest-control fleet demonstration identifies its fictional data and video', function (): void {
    $this->get(route('platform.pest-control-fleet-demo'))
        ->assertOk()
        ->assertSee('Green Shield Pest Control')
        ->assertSee(EverbranchPreparePestControlDemo::OWNER_EMAIL)
        ->assertSee(EverbranchPreparePestControlDemo::DEFAULT_PASSWORD)
        ->assertSee(route('platform.pest-control-fleet-demo.login'), false)
        ->assertSee('Enter the demo workspace')
        ->assertSee('green-shield-fleet-demo.mp4?v=20260813-map-title')
        ->assertSee('green-shield-fleet-demo-poster.jpg?v=20260813-map-title')
        ->assertSee('Demonstration data is fictional');
});

test('the demo login handoff returns an old link to the public demo page', function (): void {
    $this->get(route('platform.pest-control-fleet-demo.login.redirect'))
        ->assertRedirect(route('platform.pest-control-fleet-demo'));
});

test('the demo handoff signs into the isolated fictional account and opens its workspace', function (): void {
    $this->artisan('everbranch:prepare-pest-control-demo')->assertSuccessful();

    $owner = \App\Models\User::query()->where('email', EverbranchPreparePestControlDemo::OWNER_EMAIL)->firstOrFail();

    $this->post(route('platform.pest-control-fleet-demo.login'))
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('field-service.index', ['tenant' => 'green-shield-pest-control'], absolute: false));

    $this->assertAuthenticatedAs($owner);
});

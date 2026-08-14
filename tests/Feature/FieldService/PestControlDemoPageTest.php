<?php

use App\Console\Commands\EverbranchPreparePestControlDemo;

test('the public pest-control fleet demonstration identifies its fictional data and video', function (): void {
    $this->get(route('platform.pest-control-fleet-demo'))
        ->assertOk()
        ->assertSee('Green Shield Pest Control')
        ->assertSee(EverbranchPreparePestControlDemo::OWNER_EMAIL)
        ->assertSee(EverbranchPreparePestControlDemo::DEFAULT_PASSWORD)
        ->assertSee(route('platform.pest-control-fleet-demo.login'), false)
        ->assertSee('green-shield-fleet-demo.mp4')
        ->assertSee('Demonstration data is fictional');
});

test('the demo login handoff prefills the fictional user and preserves the workspace destination', function (): void {
    $this->get(route('platform.pest-control-fleet-demo.login'))
        ->assertRedirect(route('login', ['email' => EverbranchPreparePestControlDemo::OWNER_EMAIL]))
        ->assertSessionHas('url.intended', route('field-service.index', ['tenant' => 'green-shield-pest-control'], absolute: false));

    $this->get(route('login', ['email' => EverbranchPreparePestControlDemo::OWNER_EMAIL]))
        ->assertOk()
        ->assertSee(EverbranchPreparePestControlDemo::OWNER_EMAIL);
});

test('the fictional demo account returns to its Green Shield workspace after password login', function (): void {
    $this->artisan('everbranch:prepare-pest-control-demo')->assertSuccessful();

    $this->get(route('platform.pest-control-fleet-demo.login'));

    $this->post(route('login.store'), [
        'email' => EverbranchPreparePestControlDemo::OWNER_EMAIL,
        'password' => EverbranchPreparePestControlDemo::DEFAULT_PASSWORD,
    ])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('field-service.index', ['tenant' => 'green-shield-pest-control'], absolute: false));

    $this->assertAuthenticated();
});

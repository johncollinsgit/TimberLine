<?php

use App\Console\Commands\EverbranchPreparePestControlDemo;

test('the public pest-control fleet demonstration identifies its fictional data and video', function (): void {
    $this->get(route('platform.pest-control-fleet-demo'))
        ->assertOk()
        ->assertSee('Green Shield Pest Control')
        ->assertSee(EverbranchPreparePestControlDemo::OWNER_EMAIL)
        ->assertSee(EverbranchPreparePestControlDemo::DEFAULT_PASSWORD)
        ->assertSee('green-shield-fleet-demo.mp4')
        ->assertSee('Demonstration data is fictional');
});

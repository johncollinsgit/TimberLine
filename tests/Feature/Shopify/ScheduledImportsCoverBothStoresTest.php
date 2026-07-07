<?php

use Illuminate\Console\Scheduling\Schedule;

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

/**
 * @return array<int,string>
 */
function scheduledCommandStrings(): array
{
    return collect(app(Schedule::class)->events())
        ->map(fn ($event) => (string) $event->command)
        ->all();
}

it('polls Shopify orders automatically for both retail and wholesale', function () {
    $imports = collect(scheduledCommandStrings())
        ->filter(fn (string $c) => str_contains($c, 'shopify:import-orders'));

    expect($imports->contains(fn (string $c) => str_contains($c, "--store='retail'")))->toBeTrue();
    expect($imports->contains(fn (string $c) => str_contains($c, "--store='wholesale'")))->toBeTrue();
});

it('audits webhook drift for both retail and wholesale', function () {
    $verify = collect(scheduledCommandStrings())
        ->filter(fn (string $c) => str_contains($c, 'shopify:webhooks:verify'));

    expect($verify->contains(fn (string $c) => str_contains($c, "--store='retail'")))->toBeTrue();
    expect($verify->contains(fn (string $c) => str_contains($c, "--store='wholesale'")))->toBeTrue();
});

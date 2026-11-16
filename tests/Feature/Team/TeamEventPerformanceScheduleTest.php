<?php

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\StringInput;

test('the scheduled event reporting worker parses against the actual queue worker definition', function () {
    $worker = collect(app(Schedule::class)->events())->first(fn ($event) => str_contains($event->command ?? '', 'queue:work')
        && str_contains($event->command, 'event-performance'));
    expect($worker)->not->toBeNull();
    $definition = Artisan::all()['queue:work']->getDefinition();
    // Parse the actual scheduled shell command, including its PHP/Artisan prefix.
    // No worker is executed and no queue/cache operation is performed.
    $input = new StringInput($worker->command);
    $input->bind(new InputDefinition([
        new InputArgument('php_binary', InputArgument::REQUIRED),
        new InputArgument('artisan_script', InputArgument::REQUIRED),
        new InputArgument('artisan_command', InputArgument::REQUIRED),
        ...array_values($definition->getArguments()),
        ...array_values($definition->getOptions()),
    ]));
    $input->validate();
    expect($input->getArgument('artisan_command'))->toBe('queue:work')
        ->and($input->getArgument('connection'))->toBe('event_performance')
        ->and($input->getOption('queue'))->toBe('event-performance')
        ->and($input->getOption('stop-when-empty'))->toBeTrue()
        ->and((int) $input->getOption('tries'))->toBe(2)
        ->and((int) $input->getOption('sleep'))->toBe(1)
        ->and((int) $input->getOption('timeout'))->toBe(600)
        ->and($worker->expression)->toBe('* * * * *')
        ->and($worker->runInBackground)->toBeTrue()
        ->and($worker->withoutOverlapping)->toBeTrue()
        ->and($worker->expiresAt)->toBe(15)
        ->and(config('queue.connections.event_performance.retry_after'))->toBeGreaterThan((int) $input->getOption('timeout'));
});

<?php

use Illuminate\Console\Command as ConsoleCommand;

it('refuses to fingerprint a non-MySQL connection instead of treating it as production evidence', function (): void {
    config()->set('database.default', 'sqlite');

    $this->artisan('schema:fingerprint')
        ->expectsOutputToContain('requires a MySQL connection')
        ->assertExitCode(ConsoleCommand::FAILURE);
});

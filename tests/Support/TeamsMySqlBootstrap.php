<?php

// Before RefreshDatabase may run, require an explicitly disposable local database.
require __DIR__.'/../../vendor/autoload.php';
$setting = static fn (string $name): string => (string) ($_ENV[$name] ?? $_SERVER[$name] ?? getenv($name));
if ($setting('APP_ENV') !== 'testing' || $setting('DB_CONNECTION') !== 'mysql' || $setting('TEAM_WORK_DISPOSABLE_MYSQL') !== '1' || ! str_starts_with($setting('DB_DATABASE'), 'codex_teams_') || ! in_array($setting('DB_HOST'), ['localhost', '127.0.0.1'], true)) {
    throw new RuntimeException('Teams MySQL acceptance requires an explicit isolated local codex_teams_ database.');
}

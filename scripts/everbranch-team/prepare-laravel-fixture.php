<?php

declare(strict_types=1);

// Creates a NEW disposable database only. Never points at an existing file/database.
$root = dirname(__DIR__, 2);
$database = getenv('TEAM_LOCAL_DATABASE');
$allowed = str_replace('\\', '/', $root.'/storage/framework/testing/');
$normalized = str_replace('\\', '/', (string) $database);
if (PHP_SAPI !== 'cli' || getenv('APP_ENV') !== 'testing' || getenv('DB_CONNECTION') !== 'sqlite'
    || ! str_starts_with($normalized, $allowed) || str_contains(substr($normalized, strlen($allowed)), '/')
    || ! preg_match('/^team-fixture-[a-f0-9-]+\.sqlite$/D', basename($normalized)) || file_exists($database)
    || getenv('DB_DATABASE') !== $database) {
    fwrite(STDERR, "Refusing: choose a new fixture SQLite file inside this checkout's storage/framework/testing.\n");
    exit(2);
}
if (! is_dir(dirname($database))) {
    mkdir(dirname($database), 0700, true);
}
$handle = fopen($database, 'x');
fclose($handle);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (config('database.default') !== 'sqlite' || config('database.connections.sqlite.database') !== $database) {
    throw new RuntimeException('Isolated database binding mismatch.');
}
Illuminate\Support\Facades\Artisan::call('migrate', ['--force' => true, '--no-interaction' => true]);
$tenants = [];
foreach (['Evergreen' => 'fictional-evergreen', 'Other Company' => 'fictional-other'] as $name => $slug) {
    $tenant = App\Models\Tenant::query()->create(['name' => $name, 'slug' => $slug]);
    App\Models\TenantAccessProfile::query()->create(['tenant_id' => $tenant->id, 'plan_key' => 'base', 'operating_mode' => 'direct', 'source' => 'local-fictional-test']);
    App\Models\TenantModuleEntitlement::query()->create(['tenant_id' => $tenant->id, 'module_key' => 'team_work', 'enabled_status' => 'enabled', 'availability_status' => 'available', 'entitlement_source' => 'local-fictional-test']);
    $tenants[] = $tenant;
}
config(['team_work.enabled' => true, 'team_work.tenant_allowlist' => array_map(fn ($t) => (string) $t->id, $tenants)]);
$users = [];
foreach (['owner' => 'owner', 'member' => 'member', 'guest' => 'guest', 'viewer' => 'viewer', 'other' => 'owner'] as $name => $role) {
    $user = App\Models\User::query()->forceCreate(['name' => ucfirst($name).' Fictional', 'email' => $name.'@fictional.invalid', 'password' => Illuminate\Support\Facades\Hash::make('Fictional-local-only-2026!'), 'email_verified_at' => now(), 'is_active' => true, 'role' => 'member']);
    $tenant = $name === 'other' ? $tenants[1] : $tenants[0];
    $tenant->users()->attach($user->id, ['role' => $role, 'membership_active' => true]);
    $users[$name] = $user;
}
$repo = app(App\Services\Team\TeamWorkRepository::class);
$access = app(App\Services\Team\TeamWorkAccess::class);
$projectId = (string) Illuminate\Support\Str::uuid();
$taskId = (string) Illuminate\Support\Str::uuid();
$commands = [
    ['kind' => 'project.create', 'id' => $projectId, 'name' => 'Field operations', 'description' => 'Fictional private project.'],
    ['kind' => 'project.member', 'id' => $projectId, 'expected_version' => 1, 'user_id' => (string) $users['member']->id, 'access' => 'editor'],
    ['kind' => 'project.member', 'id' => $projectId, 'expected_version' => 2, 'user_id' => (string) $users['guest']->id, 'access' => 'viewer'],
    ['kind' => 'project.member', 'id' => $projectId, 'expected_version' => 3, 'user_id' => (string) $users['viewer']->id, 'access' => 'viewer'],
    ['kind' => 'task.create', 'id' => $taskId, 'title' => 'Photograph the north field entrance', 'description' => 'Fictional native notes in the real Laravel application.', 'assignee_id' => (string) $users['member']->id, 'due_on' => '2026-10-20', 'memberships' => [$projectId => []]],
];
foreach ($commands as $command) {
    $command['idempotency_key'] = (string) Illuminate\Support\Str::uuid();
    $repo->execute($tenants[0]->id, fn () => $access->context($users['owner'], $tenants[0]), $command);
}
file_put_contents($database.'.fixture.json', json_encode(['database' => $database, 'tenants' => array_map(fn ($t) => ['id' => $t->id, 'slug' => $t->slug], $tenants), 'projectId' => $projectId, 'taskId' => $taskId], JSON_PRETTY_PRINT));
echo json_encode(['fixture' => 'created', 'tenants' => count($tenants), 'syntheticUsers' => count($users), 'database' => $database]), "\n";

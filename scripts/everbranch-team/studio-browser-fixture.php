<?php

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
set_exception_handler(static function (Throwable $error): never {
    fwrite(STDERR, $error->getMessage()."\n");
    exit(1);
});
if (! app()->environment('testing') || config('database.default') !== 'sqlite' || ! str_contains(config('database.connections.sqlite.database'), '/codex-teams-studio-')) {
    throw new RuntimeException('Isolated Studio browser DB only.');
}
require base_path('tests/Support/TeamStudioFixtures.php');
[$tenant,$owner] = teamWorkspace();
$tenant->update(['slug' => 'fictional-studio']);
$owner->update(['name' => 'Fictional Owner', 'email' => 'owner@fixture.invalid', 'password' => Illuminate\Support\Facades\Hash::make('Fictional-local-only-2026!'), 'email_verified_at' => now()]);
$area = studioArea($tenant);
app(App\Services\Team\Studio\TeamsStudio::class)->saveDefinition($tenant, $owner, $area, ['id' => (string) Illuminate\Support\Str::uuid(), 'request_id' => (string) Illuminate\Support\Str::uuid(), 'expected_version' => 0, 'kind' => 'cost_policy', 'publish' => true, 'definition' => studioPolicy()]);
echo json_encode(['tenant' => $tenant->slug, 'tenant_id' => (string) $tenant->id, 'user_id' => (string) $owner->id, 'area_id' => $area->id]);

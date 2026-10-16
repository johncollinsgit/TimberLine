<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(Tests\TestCase::class);

it('retains AI records when table creation completed before migration bookkeeping', function (string $name, string $file, array $fields): void {
    if (DB::connection()->getDriverName() !== 'mysql' || ! preg_match('/(^|_)(ci|test|testing)(_|$)/', DB::connection()->getDatabaseName())) {
        $this->markTestSkipped('Requires a disposable MySQL test database.');
    }
    Schema::dropIfExists('ai_branch_'.$name);
    $migration = require database_path('migrations/'.$file);
    $migration->up();
    $id = DB::table('ai_branch_'.$name)->insertGetId(['tenant_id' => 918273, 'created_at' => now(), 'updated_at' => now(), ...$fields]);
    $before = (array) DB::table('ai_branch_'.$name)->find($id);
    $migration->up();
    $migration->up();
    expect((array) DB::table('ai_branch_'.$name)->find($id))->toBe($before);
    foreach (['messages', 'body', 'payload'] as $field) {
        if (array_key_exists($field, $fields)) {
            expect(Schema::getColumnType('ai_branch_'.$name, $field))->toBe('longtext');
        }
    }
    $migration->down();
    expect(Schema::hasTable('ai_branch_'.$name))->toBeFalse();
    $migration->up();
})->with([
    ['accounts', '2026_10_16_000000_create_ai_branch_accounts.php', ['enabled' => false, 'budget_cents' => 50]],
    ['conversations', '2026_10_16_000001_create_ai_branch_conversations.php', ['user_id' => 918273, 'context_hash' => str_repeat('a', 64), 'messages' => 'encrypted-sentinel', 'expires_at' => now()->addDay()]],
    ['memories', '2026_10_16_000002_create_ai_branch_memories.php', ['user_id' => 918273, 'body' => 'encrypted-sentinel', 'expires_at' => now()->addDay()]],
    ['actions', '2026_10_16_000003_create_ai_branch_actions.php', ['user_id' => 918273, 'client_uuid' => '00000000-0000-4000-8000-000000000001', 'kind' => 'internal_job_note', 'payload' => 'encrypted-sentinel', 'digest' => str_repeat('a', 64), 'expires_at' => now()->addDay()]],
]);

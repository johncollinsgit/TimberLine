<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(Tests\TestCase::class);

it('retains marketing desk data and unique constraints after completed MySQL DDL is replayed', function (): void {
    if (DB::connection()->getDriverName() !== 'mysql' || ! preg_match('/(^|_)(ci|test|testing)(_|$)/i', DB::connection()->getDatabaseName())) {
        $this->markTestSkipped('Requires disposable MySQL.');
    }
    $paths = array_map(fn ($name) => database_path('migrations/'.$name), ['2026_10_16_100000_create_marketing_brand_knowledge_versions.php', '2026_10_16_100001_create_marketing_reply_policies.php', '2026_10_16_100002_create_marketing_social_posts.php', '2026_10_16_100003_create_marketing_social_publications.php', '2026_10_16_100004_create_marketing_assistant_turns.php', '2026_10_16_100005_create_marketing_audience_permissions.php', '2026_10_16_100006_create_marketing_automation_enrollments.php', '2026_10_16_100007_create_marketing_delivery_authorizations.php', '2026_10_16_100008_create_marketing_delivery_attempts.php', '2026_10_16_100009_create_marketing_conversation_controls.php', '2026_10_16_100010_create_marketing_social_account_bindings.php']);
    foreach ($paths as $path) {
        $migration = require $path;
        $migration->up();
        $table = 'marketing_'.preg_replace('/^.*_create_marketing_(.*)\.php$/', '$1', basename($path));
        $expected = array_filter(Schema::getIndexes($table), fn ($index) => ! ($index['primary'] ?? false));
        // CREATE TABLE and ALTER INDEX are separate durable MySQL statements.
        foreach ($expected as $index) {
            Schema::table($table, fn ($blueprint) => $blueprint->dropIndex($index['name']));
        }
        $migration->up();
        foreach ($expected as $index) {
            expect(Schema::hasIndex($table, $index['name']))->toBeTrue();
        }
        $migration->up();
    }
    $key = hash('sha256', random_bytes(20));
    DB::table('marketing_social_account_bindings')->insert(['tenant_id' => 99999999, 'provider' => 'facebook', 'fingerprint' => $key, 'created_at' => now(), 'updated_at' => now()]);
    foreach ($paths as $path) {
        (require $path)->up();
    }
    expect(DB::table('marketing_social_account_bindings')->where('fingerprint', $key)->value('tenant_id'))->toBe(99999999)
        ->and(Schema::hasIndex('marketing_social_account_bindings', 'md_social_binding_unique'))->toBeTrue()
        ->and(Schema::hasIndex('marketing_delivery_attempts', 'md_delivery_attempt_unique'))->toBeTrue()
        ->and(Schema::getColumnType('marketing_assistant_turns', 'input'))->toBe('longtext');
    DB::table('marketing_social_account_bindings')->where('fingerprint', $key)->delete();
});

<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(Tests\TestCase::class);

beforeEach(function (): void {
    if (DB::connection()->getDriverName() !== 'mysql' || getenv('SES_PLATFORM_DISPOSABLE_MYSQL') !== '1') {
        $this->markTestSkipped('Requires explicit disposable MySQL platform-mail recovery rehearsal.');
    }
    if (! app()->environment('testing') || ! preg_match('/(^|_)(ci|test|testing)(_|$)/i', DB::connection()->getDatabaseName())
        || ! in_array(config('database.connections.mysql.host'), ['localhost', '127.0.0.1'], true)) {
        throw new RuntimeException('Refusing recovery outside isolated disposable MySQL.');
    }
    Schema::dropIfExists('platform_email_deliveries');
    Schema::dropIfExists('platform_email_messages');
});

function platformRecoverySentinel(): array
{
    $mime = Crypt::encryptString('Fictional non-customer MIME sentinel');
    $message = DB::table('platform_email_messages')->insertGetId([
        'group_key' => str_repeat('a', 64), 'payload_hash' => str_repeat('b', 64), 'mime' => $mime,
        'from_email' => 'sender@example.test', 'configuration_set' => 'platform-recovery-fixture',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    return [$message, $mime];
}

it('recovers retained platform MIME before child creation and every trailing index and foreign key', function (string $partial): void {
    $migration = require database_path('migrations/2026_10_20_200000_create_platform_email_deliveries.php');
    $migration->up();
    [$id, $mime] = platformRecoverySentinel();
    $ciphertext = Crypt::encryptString('recipient@example.test');
    if ($partial === 'parent-only') {
        Schema::drop('platform_email_deliveries');
        Schema::table('platform_email_messages', fn (Blueprint $t) => $t->dropUnique('pem_group_uq'));
    } elseif ($partial === 'constraints') {
        DB::table('platform_email_deliveries')->insert(['platform_email_message_id' => $id, 'recipient_hash' => str_repeat('c', 64),
            'recipient' => $ciphertext, 'state' => 'unknown', 'provider_cost_micros' => 1000, 'submitted_at' => now(),
            'event_hashes' => json_encode(['durable-event']), 'created_at' => now(), 'updated_at' => now()]);
        Schema::table('platform_email_deliveries', fn (Blueprint $t) => $t->dropForeign('ped_message_fk'));
        foreach (['ped_message_recipient_uq', 'ped_recipient_state_idx', 'ped_pending_idx'] as $name) {
            Schema::table('platform_email_deliveries', fn (Blueprint $t) => $t->dropIndex($name));
        }
        Schema::table('platform_email_messages', fn (Blueprint $t) => $t->dropUnique('pem_group_uq'));
    }
    $migration->up();
    $migration->up();
    expect(DB::table('platform_email_messages')->where('id', $id)->value('mime'))->toBe($mime)
        ->and(Schema::hasIndex('platform_email_messages', 'pem_group_uq'))->toBeTrue();
    foreach (['ped_message_recipient_uq', 'ped_recipient_state_idx', 'ped_pending_idx'] as $name) {
        expect(Schema::hasIndex('platform_email_deliveries', $name))->toBeTrue();
    }
    expect(collect(Schema::getForeignKeys('platform_email_deliveries'))->firstWhere('name', 'ped_message_fk')['on_delete'])->toBe('restrict');
    if ($partial === 'constraints') {
        $row = DB::table('platform_email_deliveries')->sole();
        expect($row->recipient)->toBe($ciphertext)->and($row->state)->toBe('unknown')
            ->and(json_decode($row->event_hashes, true))->toBe(['durable-event']);
    }
})->with(['parent-only', 'constraints', 'completed-before-bookkeeping']);

it('stops on conflicting message identities without deleting encrypted evidence', function (): void {
    $migration = require database_path('migrations/2026_10_20_200000_create_platform_email_deliveries.php');
    $migration->up();
    [$id, $mime] = platformRecoverySentinel();
    Schema::table('platform_email_messages', fn (Blueprint $t) => $t->dropUnique('pem_group_uq'));
    $duplicate = (array) DB::table('platform_email_messages')->where('id', $id)->first();
    unset($duplicate['id']);
    DB::table('platform_email_messages')->insert($duplicate);
    expect(fn () => $migration->up())->toThrow(Illuminate\Database\QueryException::class);
    expect(DB::table('platform_email_messages')->count())->toBe(2)
        ->and(DB::table('platform_email_messages')->where('id', $id)->value('mime'))->toBe($mime);
});

it('rejects same-named changed indexes or parent deletion rules', function (string $conflict): void {
    $migration = require database_path('migrations/2026_10_20_200000_create_platform_email_deliveries.php');
    $migration->up();
    [$id, $mime] = platformRecoverySentinel();
    if ($conflict === 'index') {
        Schema::table('platform_email_deliveries', fn (Blueprint $t) => $t->dropIndex('ped_pending_idx'));
        Schema::table('platform_email_deliveries', fn (Blueprint $t) => $t->index(['recipient_hash'], 'ped_pending_idx'));
    } else {
        Schema::table('platform_email_deliveries', fn (Blueprint $t) => $t->dropForeign('ped_message_fk'));
        Schema::table('platform_email_deliveries', fn (Blueprint $t) => $t->foreign('platform_email_message_id', 'ped_message_fk')->references('id')->on('platform_email_messages')->cascadeOnDelete());
    }
    expect(fn () => $migration->up())->toThrow(RuntimeException::class, 'differs from the reviewed schema');
    expect(DB::table('platform_email_messages')->where('id', $id)->value('mime'))->toBe($mime);
})->with(['index', 'foreign-key']);

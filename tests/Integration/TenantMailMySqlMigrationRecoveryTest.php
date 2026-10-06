<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(Tests\TestCase::class);

it('resumes tenant mail migration after MySQL retains early tables', function (): void {
    if (DB::connection()->getDriverName() !== 'mysql') {
        $this->markTestSkipped('This recovery contract requires MySQL.');
    }
    foreach (['tenants', 'users'] as $name) {
        if (! Schema::hasTable($name)) {
            Schema::create($name, fn (Blueprint $table) => $table->id());
        }
    }
    $migration = require database_path('migrations/2026_10_06_170000_create_tenant_mailboxes.php');
    $migration->up();
    Schema::dropIfExists('tenant_mail_messages');

    expect(Schema::hasTable('tenant_mail_domains'))->toBeTrue()
        ->and(Schema::hasTable('tenant_mailboxes'))->toBeTrue()
        ->and(Schema::hasTable('tenant_mailbox_users'))->toBeTrue()
        ->and(Schema::hasTable('tenant_mail_messages'))->toBeFalse();

    $migration->up();
    $migration->up();
    expect(Schema::hasTable('tenant_mail_messages'))->toBeTrue()
        ->and(Schema::hasIndex('tenant_mail_messages', 'mail_msg_provider_dedupe_idx'))->toBeTrue();
});

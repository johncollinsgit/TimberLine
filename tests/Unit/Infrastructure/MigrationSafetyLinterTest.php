<?php

declare(strict_types=1);

use Everbranch\Ci\MigrationSafetyLinter;

require_once dirname(__DIR__, 3).'/scripts/ci/lib/MigrationSafetyLinter.php';

function migrationSafetyLinter(): MigrationSafetyLinter
{
    return new MigrationSafetyLinter(dirname(__DIR__, 3));
}

it('accepts a guarded single-step migration with safe identifiers', function (): void {
    $source = <<<'PHP'
<?php
if (! Schema::hasTable('short_records')) {
    Schema::create('short_records', function (Blueprint $table): void {
        $table->id();
        $table->string('status')->index();
    });
}
PHP;

    expect(migrationSafetyLinter()->lintMigration('safe.php', $source, []))->toBe([]);
});

it('rejects an unguarded table creation', function (): void {
    $source = <<<'PHP'
<?php
Schema::create('orders', function (Blueprint $table): void {
    $table->id();
});
PHP;

    expect(migrationSafetyLinter()->lintMigration('unguarded.php', $source, []))
        ->toContain("unguarded.php: Schema::create('orders') is not guarded by Schema::hasTable(). MySQL may retain that table after an interrupted migration.");
});

it('rejects a Laravel-generated identifier longer than MySQL permits', function (): void {
    $source = <<<'PHP'
<?php
if (! Schema::hasTable('website_shipping_rate_quotes')) {
    Schema::create('website_shipping_rate_quotes', function (Blueprint $table): void {
        $table->foreignId('website_fulfillment_location_id')->constrained();
    });
}
PHP;

    $errors = migrationSafetyLinter()->lintMigration('long-name.php', $source, []);

    expect($errors)->toHaveCount(1)
        ->and($errors[0])->toContain('website_shipping_rate_quotes_website_fulfillment_location_id_foreign')
        ->and($errors[0])->toContain('MySQL permits at most 64');
});

it('permanently rejects the 65-character Customer Loop index regression', function (): void {
    $source = <<<'PHP'
<?php
Schema::table('customer_loop_actions', function (Blueprint $table): void {
    $table->index(['tenant_id', 'marketing_profile_id', 'status']);
});
PHP;

    $errors = migrationSafetyLinter()->lintMigration('customer-loop-regression.php', $source, []);

    expect($errors)->toHaveCount(1)
        ->and($errors[0])->toContain('customer_loop_actions_tenant_id_marketing_profile_id_status_index')
        ->and($errors[0])->toContain('is 65 characters');
});

it('requires an interruption recovery scenario for a multi-step migration', function (): void {
    $source = <<<'PHP'
<?php
if (! Schema::hasTable('first_table')) {
    Schema::create('first_table', function (Blueprint $table): void {
        $table->id();
    });
}
if (! Schema::hasTable('second_table')) {
    Schema::create('second_table', function (Blueprint $table): void {
        $table->id();
    });
}
PHP;

    expect(migrationSafetyLinter()->lintMigration('multi-step.php', $source, []))
        ->toContain('multi-step.php: contains 2 schema/DDL steps but is not registered in tests/Integration/migration-recovery-manifest.php.');
});

it('rejects overlong explicit identifiers', function (): void {
    $identifier = str_repeat('identifier_', 7);
    $source = <<<PHP
<?php
Schema::table('orders', function (Blueprint \$table): void {
    \$table->index(['tenant_id'], '{$identifier}');
});
PHP;

    $errors = migrationSafetyLinter()->lintMigration('explicit.php', $source, []);

    expect($errors)->toHaveCount(1)
        ->and($errors[0])->toContain('explicit index identifier')
        ->and($errors[0])->toContain('MySQL permits at most 64');
});

it('rejects overlong identifiers in literal raw DDL', function (): void {
    $identifier = str_repeat('raw_constraint_', 5);
    $source = <<<PHP
<?php
DB::statement('ALTER TABLE orders ADD CONSTRAINT {$identifier} FOREIGN KEY (tenant_id) REFERENCES tenants(id)');
PHP;

    $errors = migrationSafetyLinter()->lintMigration('raw.php', $source, []);

    expect($errors)->toHaveCount(1)
        ->and($errors[0])->toContain('raw SQL identifier')
        ->and($errors[0])->toContain('MySQL permits at most 64');
});

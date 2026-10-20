<?php

use App\Services\Marketing\Email\SesSimulatorFixture;
use Illuminate\Support\Facades\DB;

uses(Tests\TestCase::class);

it('isolates authenticated simulator events and restores the original connection even after failure', function (): void {
    $root = sys_get_temp_dir().'/ses-fixture-test-'.bin2hex(random_bytes(8));
    mkdir($root.'/app/private', 0700, true);
    $this->app->useStoragePath($root);
    $path = $root.'/app/private/ses-simulator.sqlite';
    $sqlite = new PDO('sqlite:'.$path);
    $sqlite->exec('CREATE TABLE tenants (id INTEGER PRIMARY KEY, slug TEXT)');
    $sqlite->exec("INSERT INTO tenants VALUES (900000001, 'ses-simulator-fixture')");
    chmod($path, 0600);
    config()->set('ses_migration.simulator_fixture_enabled', true);
    $original = DB::getDefaultConnection();
    $fixture = app(SesSimulatorFixture::class);
    try {
        expect($fixture->run(fn () => DB::table('tenants')->pluck('slug')->all()))->toBe(['ses-simulator-fixture'])
            ->and(DB::getDefaultConnection())->toBe($original);
        expect(fn () => $fixture->run(fn () => throw new RuntimeException('fixture callback failure')))->toThrow(RuntimeException::class, 'fixture callback failure');
        expect(DB::getDefaultConnection())->toBe($original);
        $sqlite->exec("INSERT INTO tenants VALUES (1,'must-never-be-loaded')");
        expect(fn () => $fixture->run(fn () => true))->toThrow(RuntimeException::class, 'only the approved fictional tenant');
        expect(DB::getDefaultConnection())->toBe($original);
    } finally {
        DB::purge('ses_simulator');
        unlink($path);
        rmdir($root.'/app/private');
        rmdir($root.'/app');
        rmdir($root);
    }
});

it('requires the default-off fixture gate and exact non-customer native tenant context', function (): void {
    $fixture = app(SesSimulatorFixture::class);
    $event = ['mail' => ['source' => 'no-reply@theeverbranch.com', 'destination' => ['success@simulator.amazonses.com'],
        'tags' => ['tenant_id' => ['900000001'], 'ses:tenant-name' => ['everbranch-tenant-900000001'], 'ses:configuration-set' => ['everbranch-marketing']]]];
    expect($fixture->matches($event))->toBeFalse();
    config()->set('ses_migration.simulator_fixture_enabled', true);
    expect($fixture->matches($event))->toBeTrue();
    foreach (['mail.source' => 'other@theeverbranch.com', 'mail.destination.0' => 'customer@example.test',
        'mail.tags.tenant_id.0' => '1', 'mail.tags.ses:tenant-name.0' => 'everbranch-tenant-1', 'mail.tags.ses:configuration-set.0' => 'wrong'] as $key => $value) {
        $other = $event;
        data_set($other, $key, $value);
        expect($fixture->matches($other))->toBeFalse();
    }
});

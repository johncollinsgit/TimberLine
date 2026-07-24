<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(Tests\TestCase::class);

it('recovers an unrecorded partial workflow studio migration on mysql', function (): void {
    if (DB::connection()->getDriverName() !== 'mysql') {
        $this->markTestSkipped('This recovery contract requires MySQL.');
    }

    expect(Schema::hasIndex('automation_workflow_links', 'awl_workflow_step_source_uq'))
        ->toBeTrue()
        ->and(Schema::hasIndex('automation_workflow_links', 'automation_links_workflow_source_unique'))
        ->toBeFalse();

    DB::statement(
        'ALTER TABLE automation_workflow_links
        ADD UNIQUE INDEX automation_links_workflow_source_unique
        (automation_workflow_id, source_system, source_id)'
    );
    DB::statement(
        'ALTER TABLE automation_workflow_links
        DROP INDEX awl_workflow_step_source_uq'
    );
    Schema::drop('automation_workflow_domain_events');

    expect(Schema::hasIndex('automation_workflow_links', 'automation_links_workflow_source_unique'))
        ->toBeTrue()
        ->and(Schema::hasIndex('automation_workflow_links', 'awl_workflow_step_source_uq'))
        ->toBeFalse()
        ->and(Schema::hasTable('automation_workflow_domain_events'))
        ->toBeFalse();

    DB::table('migrations')
        ->where('migration', '2026_07_24_120000_add_workflow_studio_v2_foundation')
        ->delete();

    expect(Artisan::call('migrate', ['--force' => true]))->toBe(0)
        ->and(Schema::hasIndex('automation_workflow_links', 'awl_workflow_step_source_uq'))
        ->toBeTrue()
        ->and(Schema::hasIndex('automation_workflow_links', 'automation_links_workflow_source_unique'))
        ->toBeFalse()
        ->and(Schema::hasTable('automation_workflow_domain_events'))
        ->toBeTrue()
        ->and(
            DB::table('migrations')
                ->where('migration', '2026_07_24_120000_add_workflow_studio_v2_foundation')
                ->exists()
        )->toBeTrue();
});

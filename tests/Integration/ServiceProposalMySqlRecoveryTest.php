<?php

use App\Models\Tenant;
use App\Proposals\ProposalService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

uses(Tests\TestCase::class);

it('repairs retained proposal evidence after partial MySQL table index and foreign-key DDL', function (): void {
    if (DB::connection()->getDriverName() !== 'mysql' || ! preg_match('/(^|_)(ci|test|testing)(_|$)/i', DB::connection()->getDatabaseName())) {
        $this->markTestSkipped('Requires disposable MySQL.');
    }
    $migration = require database_path('migrations/2026_10_22_150000_create_service_proposal_tables.php');
    $migration->up();
    $tenant = Tenant::create(['name' => 'Proposal recovery fixture', 'slug' => 'proposal-recovery-'.Str::lower(Str::random(12))]);
    config(['service_proposals.owner_workspaces.'.$tenant->id => 'enterprise', 'service_proposals.publish_enabled' => true]);
    $service = app(ProposalService::class);
    $draft = $service->leesMcRaeDraft($tenant, 1);
    $version = $service->publish($tenant, 1, $draft->id, 1, 'Recovery fixture password', 7, Str::uuid()->toString())['version'];
    $service->requestAgreement($version, [['id' => $draft->payload['items'][0]['id']]], Str::uuid()->toString(), '127.0.0.1');
    $service->comment($version, 'Ross', 'approach', 'Retained recovery comment', Str::uuid()->toString());
    $ciphertext = DB::table('service_proposal_versions')->where('id', $version->id)->value('payload');
    $tables = array_map(fn ($kind) => 'service_proposal_'.$kind, ['assets', 'drafts', 'versions', 'reviews', 'comments']);
    foreach (array_reverse($tables) as $table) {
        foreach (Schema::getForeignKeys($table) as $foreign) {
            Schema::table($table, fn ($blueprint) => $blueprint->dropForeign($foreign['name']));
        }
    }
    foreach (array_reverse($tables) as $table) {
        foreach (Schema::getIndexes($table) as $index) {
            if (! $index['primary']) {
                Schema::table($table, fn ($blueprint) => $blueprint->dropIndex($index['name']));
            }
        }
    }
    $migration->up();
    $migration->up();
    foreach ($tables as $table) {
        expect(Schema::getForeignKeys($table))->not->toBeEmpty();
        expect(DB::table($table)->where('tenant_id', $tenant->id)->count())->toBeGreaterThan(0);
    }
    expect(Schema::hasIndex('service_proposal_versions', 'sp_v_publish_uq'))->toBeTrue()
        ->and(DB::table('service_proposal_versions')->where('id', $version->id)->value('payload'))->toBe($ciphertext);
    foreach (array_reverse($tables) as $table) {
        DB::table($table)->where('tenant_id', $tenant->id)->delete();
    }
    $tenant->delete();
});

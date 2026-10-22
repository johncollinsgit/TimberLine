<?php

namespace Tests\Integration;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Explicit disposable service only; never accepts the retail/production database. */
final class EnterpriseMySqlMigrationRecoveryTest extends TestCase
{
    public function createApplication()
    {
        $app = require dirname(__DIR__, 2).'/apps/enterprise/bootstrap/app.php';
        $this->traitsUsedByTest = array_flip(class_uses_recursive(self::class));
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    public function test_durable_mysql_partial_schema_recovers_without_grants_or_lost_work(): void
    {
        if (getenv('ENTERPRISE_DISPOSABLE_MYSQL') !== '1') {
            $this->markTestSkipped('Requires an explicitly disposable Enterprise MySQL service.');
        }
        if (! app()->environment('testing') || config('database.connections.enterprise.driver') !== 'mysql'
            || config('database.connections.enterprise.database') !== 'everbranch_enterprise_ci'
            || ! in_array(config('database.connections.enterprise.host'), ['127.0.0.1', 'localhost'], true)) {
            throw new \RuntimeException('Refusing recovery outside the isolated Enterprise MySQL fixture.');
        }
        $this->assertSame(0, $this->app->make(Kernel::class)->call('migrate', ['--force' => true]));
        DB::table('tenants')->insert(['id' => 9901, 'name' => 'Synthetic durable tenant', 'slug' => 'synthetic-durable']);
        DB::table('users')->insert(['id' => 9901, 'name' => 'Synthetic durable member', 'email' => 'mysql-recovery@example.invalid', 'password' => 'synthetic-fixture-only']);
        DB::table('team_work_locks')->insert(['tenant_id' => 9901]);
        DB::table('tenant_mail_domains')->insert(['id' => 9901, 'tenant_id' => 9901, 'domain' => 'mysql-recovery.example.invalid',
            'transport' => 'direct', 'status' => 'pending_dns', 'dns_records' => '{"proof":"synthetic-durable-proof"}']);
        $encrypted = \Illuminate\Support\Facades\Crypt::encryptString('{"password":"synthetic-only-no-provider"}');
        DB::table('tenant_mailboxes')->insert(['id' => 9901, 'tenant_id' => 9901, 'tenant_mail_domain_id' => 9901,
            'address' => 'member@mysql-recovery.example.invalid', 'display_name' => 'Synthetic retained mailbox', 'provider_credentials' => $encrypted]);

        config(['service_proposals.owner_workspaces.9901' => 'enterprise', 'service_proposals.publish_enabled' => true]);
        $proposals = app(\App\Proposals\ProposalService::class);
        $proposalTenant = \App\Models\Tenant::findOrFail(9901);
        $draft = $proposals->leesMcRaeDraft($proposalTenant, 9901);
        $publication = $proposals->publish($proposalTenant, 9901, $draft->id, 1, 'Synthetic recovery password', 1, (string) \Illuminate\Support\Str::uuid(), 'reviewer');
        $comment = $proposals->comment($publication['version'], 'Ross', 'approach', 'Synthetic retained proposal evidence', (string) \Illuminate\Support\Str::uuid());
        $proposalCipher = DB::table('service_proposal_versions')->where('id', $publication['version']->id)->value('payload');
        $commentCipher = DB::table('service_proposal_comments')->where('id', $comment->id)->value('payload');
        Schema::table('service_proposal_comments', fn (Blueprint $table) => $table->dropForeign('sp_c_version_fk'));
        Schema::table('service_proposal_comments', fn (Blueprint $table) => $table->dropUnique('sp_c_request_uq'));
        $proposalMigration = require database_path('migrations/2026_10_22_000700_create_enterprise_service_proposals.php');
        $proposalMigration->up();
        $proposalMigration->up();
        $proposalMigration->down();
        $this->assertSame($proposalCipher, DB::table('service_proposal_versions')->where('id', $publication['version']->id)->value('payload'));
        $this->assertSame($commentCipher, DB::table('service_proposal_comments')->where('id', $comment->id)->value('payload'));
        $this->assertTrue(Schema::hasIndex('service_proposal_comments', 'sp_c_request_uq', 'unique'));
        $this->assertSame('service_proposal_versions', collect(Schema::getForeignKeys('service_proposal_comments'))->firstWhere('name', 'sp_c_version_fk')['foreign_table']);

        // MySQL retains these tables/data while later DDL or bookkeeping is absent.
        Schema::drop('team_operation_attempts');
        Schema::drop('team_operation_revisions');
        Schema::drop('team_file_chunks');
        Schema::drop('tenant_module_states');
        Schema::drop('tenant_mailbox_users');
        Schema::table('tenant_mailboxes', fn (Blueprint $table) => $table->dropUnique('ee_mail_address'));
        Schema::table('tenant_mailboxes', fn (Blueprint $table) => $table->dropForeign('ee_mail_domain_fk'));
        Schema::table('users', fn (Blueprint $table) => $table->dropUnique('ee_users_email'));
        Schema::table('tenant_user', fn (Blueprint $table) => $table->dropForeign('ee_tu_user_fk'));
        Schema::table('tenant_user', fn (Blueprint $table) => $table->dropForeign('ee_tu_tenant_fk'));
        Schema::table('tenant_user', fn (Blueprint $table) => $table->dropUnique('ee_tu_membership'));
        Schema::table('team_projects', fn (Blueprint $table) => $table->dropIndex('team_project_tenant_archive_idx'));
        Schema::table('team_operation_records', fn (Blueprint $table) => $table->dropUnique('tor_source'));
        DB::table('team_operation_records')->insert(['id' => '10000000-0000-4000-8000-000000000001', 'tenant_id' => 9901,
            'area_id' => '10000000-0000-4000-8000-000000000002', 'kind' => 'ent_ai_usage', 'source_key' => 'synthetic-voice-retention',
            'title' => 'Private AI usage', 'data' => $encrypted, 'voice_expires_at' => '2026-01-01 00:00:00']);
        Schema::table('team_operation_records', fn (Blueprint $table) => $table->dropIndex('tor_voice_sessions'));
        Schema::table('team_operation_records', fn (Blueprint $table) => $table->dropIndex('tor_voice_previews'));
        $voice = require database_path('migrations/2026_10_22_000600_add_private_voice_retention.php');
        $voiceRoot = require base_path('database/migrations/2026_10_22_190000_add_private_voice_retention.php');
        $identity = require database_path('migrations/2026_10_21_000100_create_enterprise_identity.php');
        $access = require database_path('migrations/2026_10_21_000200_create_enterprise_access_profiles.php');
        $shared = require database_path('migrations/2026_10_21_000300_create_enterprise_shared_work_schema.php');
        $mailboxes = require database_path('migrations/2026_10_22_000400_create_enterprise_mailboxes.php');
        foreach ([$identity, $access, $shared, $mailboxes, $voice, $voiceRoot] as $migration) {
            $migration->up();
            $migration->up();
            $migration->down(); // Code rollback must retain all evidence.
        }
        $this->assertSame('Synthetic durable tenant', DB::table('tenants')->where('id', 9901)->value('name'));
        $this->assertSame('mysql-recovery@example.invalid', DB::table('users')->where('id', 9901)->value('email'));
        $this->assertTrue(DB::table('team_work_locks')->where('tenant_id', 9901)->exists());
        $this->assertSame($encrypted, DB::table('tenant_mailboxes')->where('id', 9901)->value('provider_credentials'));
        $this->assertStringContainsString('synthetic-durable-proof', DB::table('tenant_mail_domains')->where('id', 9901)->value('dns_records'));
        $this->assertSame(0, DB::table('tenant_mailbox_users')->count());
        $this->assertTrue(Schema::hasIndex('tenant_mailboxes', 'ee_mail_address', 'unique'));
        $this->assertSame('tenant_mail_domains', collect(Schema::getForeignKeys('tenant_mailboxes'))->firstWhere('name', 'ee_mail_domain_fk')['foreign_table']);
        foreach (['tenant_user', 'tenant_access_profiles', 'tenant_module_entitlements'] as $table) {
            $this->assertSame(0, DB::table($table)->count());
        }
        $this->assertTrue(Schema::hasIndex('users', 'ee_users_email', 'unique'));
        $this->assertTrue(Schema::hasIndex('tenant_user', 'ee_tu_membership', 'unique'));
        $this->assertTrue(Schema::hasIndex('team_projects', 'team_project_tenant_archive_idx'));
        $this->assertTrue(Schema::hasIndex('team_operation_records', 'tor_source', 'unique'));
        foreach (['tor_voice_expiry', 'tor_voice_sessions', 'tor_voice_previews'] as $index) {
            $this->assertTrue(Schema::hasIndex('team_operation_records', $index));
        }
        $this->assertSame($encrypted, DB::table('team_operation_records')->where('source_key', 'synthetic-voice-retention')->value('data'));
        foreach (['tenant_module_states', 'team_file_chunks', 'team_operation_attempts', 'team_operation_revisions'] as $table) {
            $this->assertTrue(Schema::hasTable($table));
        }
        $foreign = collect(Schema::getForeignKeys('tenant_user'))->firstWhere('name', 'ee_tu_user_fk');
        $this->assertSame(['user_id'], $foreign['columns']);
        $this->assertSame('users', $foreign['foreign_table']);
    }
}

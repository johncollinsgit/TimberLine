<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('team_search_documents')) {
            Schema::create('team_search_documents', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->unsignedBigInteger('tenant_id');
                $t->string('kind', 24);
                $t->string('entity_id', 120);
                $t->unsignedInteger('schema_version');
                $t->char('fingerprint', 64);
                $t->longText('payload'); // Encrypted, even for otherwise unencrypted native work.
                $t->timestamp('indexed_at');
            });
        }
        if (! Schema::hasIndex('team_search_documents', 'tsd_identity')) {
            Schema::table('team_search_documents', fn (Blueprint $t) => $t->unique(['tenant_id', 'kind', 'entity_id'], 'tsd_identity'));
        }
        if (! Schema::hasColumn('team_search_documents', 'sort_closed')) {
            Schema::table('team_search_documents', fn (Blueprint $t) => $t->boolean('sort_closed')->default(false));
        }
        if (! Schema::hasColumn('team_search_documents', 'sort_updated')) {
            Schema::table('team_search_documents', fn (Blueprint $t) => $t->bigInteger('sort_updated')->default(0));
        }
        if (! Schema::hasTable('team_search_terms')) {
            Schema::create('team_search_terms', function (Blueprint $t) {
                $t->unsignedBigInteger('tenant_id');
                $t->unsignedBigInteger('document_id');
                $t->char('token', 64); // Tenant-keyed HMAC of words, prefixes and bigrams.
            });
        }
        if (! Schema::hasIndex('team_search_terms', 'tst_lookup')) {
            Schema::table('team_search_terms', fn (Blueprint $t) => $t->unique(['tenant_id', 'token', 'document_id'], 'tst_lookup'));
        }
        if (! Schema::hasIndex('team_search_terms', 'tst_document')) {
            Schema::table('team_search_terms', fn (Blueprint $t) => $t->index(['tenant_id', 'document_id'], 'tst_document'));
        }
        if (! Schema::hasTable('team_search_runs')) {
            Schema::create('team_search_runs', function (Blueprint $t) {
                $t->unsignedBigInteger('tenant_id')->primary();
                $t->unsignedInteger('schema_version')->default(1);
                $t->string('source', 24)->default('project');
                $t->string('after_id', 120)->nullable();
                $t->timestamp('completed_at')->nullable();
            });
        }
        if (! Schema::hasColumn('team_search_runs', 'warnings')) {
            Schema::table('team_search_runs', fn (Blueprint $t) => $t->unsignedInteger('warnings')->default(0));
        }
        if (Schema::hasTable('team_source_snapshots') && ! Schema::hasColumn('team_source_snapshots', 'search_generation')) {
            Schema::table('team_source_snapshots', fn (Blueprint $t) => $t->unsignedInteger('search_generation')->nullable());
        }
        if (Schema::hasTable('team_source_snapshots') && ! Schema::hasColumn('team_source_snapshots', 'search_observed_at')) {
            Schema::table('team_source_snapshots', fn (Blueprint $t) => $t->timestamp('search_observed_at')->nullable());
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('team_search_terms');
        Schema::dropIfExists('team_search_documents');
        Schema::dropIfExists('team_search_runs');
    }
};

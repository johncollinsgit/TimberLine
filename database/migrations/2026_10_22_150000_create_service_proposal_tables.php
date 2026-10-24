<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['assets', 'drafts', 'versions', 'reviews', 'comments'] as $kind) {
            $name = 'service_proposal_'.$kind;
            if (! Schema::hasTable($name)) {
                Schema::create($name, function (Blueprint $table) use ($kind): void {
                    $table->uuid('id')->primary();
                    $table->unsignedBigInteger('tenant_id');
                    $table->longText('payload'); // Encrypted at rest, including client details.
                    $table->unsignedBigInteger('created_by')->nullable();
                    if ($kind === 'assets') {
                        $table->string('kind', 16);
                        $table->unsignedInteger('revision')->default(1);
                    } elseif ($kind === 'drafts') {
                        $table->uuid('client_id');
                        $table->unsignedInteger('revision')->default(1);
                    } elseif ($kind === 'versions') {
                        $table->uuid('draft_id');
                        $table->unsignedInteger('number');
                        $table->char('content_hash', 64);
                        $table->char('token_hash', 64);
                        $table->string('password_hash');
                        $table->char('publish_key', 64);
                        $table->longText('access_result');
                        $table->timestamp('expires_at');
                        $table->timestamp('revoked_at')->nullable();
                    } else {
                        $table->uuid('version_id');
                        if ($kind === 'reviews') {
                            $table->char('selection_hash', 64);
                        }
                        $table->char('request_key', 64);
                    }
                    $table->timestamps();
                });
            }
            $prefix = 'sp_'.substr($kind, 0, 1);
            $indexes = [[$prefix.'_tenant_id_uq', ['tenant_id', 'id'], true]];
            if ($kind === 'versions') {
                $indexes[] = ['sp_v_number_uq', ['tenant_id', 'draft_id', 'number'], true];
                $indexes[] = ['sp_v_token_uq', ['token_hash'], true];
                $indexes[] = ['sp_v_publish_uq', ['tenant_id', 'draft_id', 'publish_key'], true];
            } elseif (in_array($kind, ['reviews', 'comments'], true)) {
                $indexes[] = [$prefix.'_request_uq', ['tenant_id', 'version_id', 'request_key'], true];
            }
            foreach ($indexes as [$index, $columns, $unique]) {
                if (! Schema::hasIndex($name, $index)) {
                    Schema::table($name, fn (Blueprint $table) => $table->unique($columns, $index));
                }
            }
            $foreigns = [[$prefix.'_tenant_fk', ['tenant_id'], 'tenants', ['id']]];
            if ($kind === 'drafts') {
                $foreigns[] = ['sp_d_client_fk', ['tenant_id', 'client_id'], 'service_proposal_assets', ['tenant_id', 'id']];
            } elseif ($kind === 'versions') {
                $foreigns[] = ['sp_v_draft_fk', ['tenant_id', 'draft_id'], 'service_proposal_drafts', ['tenant_id', 'id']];
            } elseif (in_array($kind, ['reviews', 'comments'], true)) {
                $foreigns[] = [$prefix.'_version_fk', ['tenant_id', 'version_id'], 'service_proposal_versions', ['tenant_id', 'id']];
            }
            foreach ($foreigns as [$key, $columns, $parent, $references]) {
                if (! collect(Schema::getForeignKeys($name))->contains(fn ($foreign) => $foreign['name'] === $key)) {
                    Schema::table($name, fn (Blueprint $table) => $table->foreign($columns, $key)->references($references)->on($parent)->restrictOnDelete());
                }
            }
        }
    }

    public function down(): void
    {
        foreach (['comments', 'reviews', 'versions', 'drafts', 'assets'] as $kind) {
            Schema::dropIfExists('service_proposal_'.$kind);
        }
    }
};

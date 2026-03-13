<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marketing_profiles', function (Blueprint $table): void {
            if (! Schema::hasColumn('marketing_profiles', 'address_line_1')) {
                $table->string('address_line_1')->nullable()->after('normalized_phone');
            }
            if (! Schema::hasColumn('marketing_profiles', 'address_line_2')) {
                $table->string('address_line_2')->nullable()->after('address_line_1');
            }
            if (! Schema::hasColumn('marketing_profiles', 'city')) {
                $table->string('city')->nullable()->after('address_line_2');
            }
            if (! Schema::hasColumn('marketing_profiles', 'state')) {
                $table->string('state', 120)->nullable()->after('city');
            }
            if (! Schema::hasColumn('marketing_profiles', 'postal_code')) {
                $table->string('postal_code', 40)->nullable()->after('state');
            }
            if (! Schema::hasColumn('marketing_profiles', 'country')) {
                $table->string('country', 120)->nullable()->after('postal_code');
            }
        });

        Schema::create('marketing_groups', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('is_internal')->default(false)->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('marketing_group_members', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('marketing_group_id')->constrained('marketing_groups')->cascadeOnDelete();
            $table->foreignId('marketing_profile_id')->constrained('marketing_profiles')->cascadeOnDelete();
            $table->foreignId('added_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['marketing_group_id', 'marketing_profile_id'], 'mgm_group_profile_unique');
            $table->index(['marketing_profile_id', 'marketing_group_id'], 'mgm_profile_group_idx');
        });

        Schema::create('marketing_campaign_groups', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('campaign_id')->constrained('marketing_campaigns')->cascadeOnDelete();
            $table->foreignId('marketing_group_id')->constrained('marketing_groups')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['campaign_id', 'marketing_group_id'], 'mcg_campaign_group_unique');
        });

        Schema::create('marketing_group_import_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('marketing_group_id')->nullable()->constrained('marketing_groups')->nullOnDelete();
            $table->string('file_name')->nullable();
            $table->string('status')->default('running')->index();
            $table->timestamp('started_at')->nullable()->index();
            $table->timestamp('finished_at')->nullable()->index();
            $table->json('summary')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('marketing_group_import_rows', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('marketing_group_import_run_id')->constrained('marketing_group_import_runs')->cascadeOnDelete();
            $table->unsignedInteger('row_number')->nullable()->index();
            $table->string('status')->default('pending')->index();
            $table->string('external_key')->nullable()->index();
            $table->foreignId('marketing_profile_id')->nullable()->constrained('marketing_profiles')->nullOnDelete();
            $table->json('messages')->nullable();
            $table->json('payload')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketing_group_import_rows');
        Schema::dropIfExists('marketing_group_import_runs');
        Schema::dropIfExists('marketing_campaign_groups');
        Schema::dropIfExists('marketing_group_members');
        Schema::dropIfExists('marketing_groups');

        Schema::table('marketing_profiles', function (Blueprint $table): void {
            foreach (['address_line_1', 'address_line_2', 'city', 'state', 'postal_code', 'country'] as $column) {
                if (Schema::hasColumn('marketing_profiles', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};

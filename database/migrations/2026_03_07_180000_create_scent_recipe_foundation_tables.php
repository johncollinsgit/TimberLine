<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('scents')) {
            return;
        }

        if (! Schema::hasTable('scent_recipes')) {
            Schema::create('scent_recipes', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('scent_id')->constrained('scents')->cascadeOnDelete();
                $table->unsignedInteger('version')->default(1);
                $table->string('status', 32)->default('draft');
                $table->boolean('is_active')->default(false);
                $table->timestamp('activated_at')->nullable();
                $table->text('notes')->nullable();
                $table->string('source_context', 120)->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();

                $table->unique(['scent_id', 'version']);
                $table->index(['scent_id', 'is_active']);
                $table->index('status');
            });
        }

        if (! Schema::hasTable('scent_recipe_components')) {
            Schema::create('scent_recipe_components', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('scent_recipe_id')->constrained('scent_recipes')->cascadeOnDelete();
                $table->string('component_type', 32)->default('oil');
                $table->foreignId('base_oil_id')->nullable()->constrained('base_oils')->nullOnDelete();
                $table->foreignId('blend_template_id')->nullable()->constrained('blends')->nullOnDelete();
                $table->decimal('parts', 10, 4)->nullable();
                $table->decimal('percentage', 8, 4)->nullable();
                $table->unsignedSmallInteger('sort_order')->default(0);
                $table->timestamps();

                $table->index(['scent_recipe_id', 'component_type']);
                $table->index(['component_type', 'base_oil_id']);
                $table->index(['component_type', 'blend_template_id']);
            });
        }

        if (Schema::hasTable('blends')) {
            Schema::table('blends', function (Blueprint $table): void {
                if (! Schema::hasColumn('blends', 'lifecycle_status')) {
                    $table->string('lifecycle_status', 32)->default('active')->after('is_blend');
                }

                if (! Schema::hasColumn('blends', 'is_active')) {
                    $table->boolean('is_active')->default(true)->after('lifecycle_status');
                }
            });
        }

        if (Schema::hasTable('blend_components')) {
            Schema::table('blend_components', function (Blueprint $table): void {
                if (! Schema::hasColumn('blend_components', 'component_type')) {
                    $table->string('component_type', 32)->default('oil')->after('blend_id');
                }

                if (! Schema::hasColumn('blend_components', 'blend_template_id')) {
                    $table->foreignId('blend_template_id')->nullable()->after('base_oil_id')->constrained('blends')->nullOnDelete();
                }

                if (! Schema::hasColumn('blend_components', 'percentage')) {
                    $table->decimal('percentage', 8, 4)->nullable()->after('ratio_weight');
                }

                if (! Schema::hasColumn('blend_components', 'sort_order')) {
                    $table->unsignedSmallInteger('sort_order')->default(0)->after('percentage');
                }
            });

            DB::table('blend_components')->whereNull('component_type')->update(['component_type' => 'oil']);
            $this->ensureBlendComponentsBaseOilNullable();
        }

        Schema::table('scents', function (Blueprint $table): void {
            if (! Schema::hasColumn('scents', 'lifecycle_status')) {
                $table->string('lifecycle_status', 32)->nullable()->after('availability_json');
            }

            if (! Schema::hasColumn('scents', 'current_scent_recipe_id')) {
                $table->foreignId('current_scent_recipe_id')
                    ->nullable()
                    ->after('source_wholesale_custom_scent_id')
                    ->constrained('scent_recipes')
                    ->nullOnDelete();
            }
        });

        DB::table('scents')
            ->select(['id', 'is_active'])
            ->orderBy('id')
            ->chunkById(200, function ($rows): void {
                foreach ($rows as $row) {
                    DB::table('scents')
                        ->where('id', $row->id)
                        ->whereNull('lifecycle_status')
                        ->update([
                            'lifecycle_status' => (bool) ($row->is_active ?? false) ? 'active' : 'inactive',
                        ]);
                }
            });

        $baseOilMap = [];
        if (Schema::hasTable('base_oils')) {
            $baseOilMap = DB::table('base_oils')
                ->get(['id', 'name'])
                ->mapWithKeys(function ($row): array {
                    $key = mb_strtolower(trim((string) $row->name));
                    return $key !== '' ? [$key => (int) $row->id] : [];
                })
                ->all();
        }

        DB::table('scents')
            ->select(['id', 'is_active', 'oil_blend_id', 'oil_reference_name', 'current_scent_recipe_id'])
            ->orderBy('id')
            ->chunkById(200, function ($rows) use ($baseOilMap): void {
                foreach ($rows as $row) {
                    $activeRecipe = DB::table('scent_recipes')
                        ->where('scent_id', $row->id)
                        ->where('is_active', true)
                        ->orderByDesc('version')
                        ->first(['id']);

                    if ($activeRecipe) {
                        if ((int) ($row->current_scent_recipe_id ?? 0) !== (int) $activeRecipe->id) {
                            DB::table('scents')
                                ->where('id', $row->id)
                                ->update(['current_scent_recipe_id' => (int) $activeRecipe->id]);
                        }

                        continue;
                    }

                    $nextVersion = ((int) (DB::table('scent_recipes')->where('scent_id', $row->id)->max('version') ?? 0)) + 1;
                    $status = (bool) ($row->is_active ?? false) ? 'active' : 'inactive';

                    $recipeId = DB::table('scent_recipes')->insertGetId([
                        'scent_id' => $row->id,
                        'version' => $nextVersion,
                        'status' => $status,
                        'is_active' => true,
                        'activated_at' => $status === 'active' ? now() : null,
                        'source_context' => 'legacy-backfill',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                    $blendId = blank($row->oil_blend_id ?? null) ? null : (int) $row->oil_blend_id;
                    $oilName = mb_strtolower(trim((string) ($row->oil_reference_name ?? '')));
                    $baseOilId = $oilName !== '' ? ($baseOilMap[$oilName] ?? null) : null;

                    if ($blendId) {
                        DB::table('scent_recipe_components')->insert([
                            'scent_recipe_id' => $recipeId,
                            'component_type' => 'blend_template',
                            'blend_template_id' => $blendId,
                            'parts' => 1,
                            'percentage' => 100,
                            'sort_order' => 0,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    } elseif ($baseOilId) {
                        DB::table('scent_recipe_components')->insert([
                            'scent_recipe_id' => $recipeId,
                            'component_type' => 'oil',
                            'base_oil_id' => $baseOilId,
                            'parts' => 1,
                            'percentage' => 100,
                            'sort_order' => 0,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }

                    DB::table('scents')
                        ->where('id', $row->id)
                        ->update(['current_scent_recipe_id' => $recipeId]);
                }
            });
    }

    public function down(): void
    {
        if (Schema::hasTable('scents')) {
            Schema::table('scents', function (Blueprint $table): void {
                if (Schema::hasColumn('scents', 'current_scent_recipe_id')) {
                    $table->dropConstrainedForeignId('current_scent_recipe_id');
                }

                if (Schema::hasColumn('scents', 'lifecycle_status')) {
                    $table->dropColumn('lifecycle_status');
                }
            });
        }

        if (Schema::hasTable('blend_components')) {
            Schema::table('blend_components', function (Blueprint $table): void {
                if (Schema::hasColumn('blend_components', 'blend_template_id')) {
                    $table->dropConstrainedForeignId('blend_template_id');
                }

                foreach (['component_type', 'percentage', 'sort_order'] as $column) {
                    if (Schema::hasColumn('blend_components', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }

        if (Schema::hasTable('blends')) {
            Schema::table('blends', function (Blueprint $table): void {
                foreach (['lifecycle_status', 'is_active'] as $column) {
                    if (Schema::hasColumn('blends', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }

        Schema::dropIfExists('scent_recipe_components');
        Schema::dropIfExists('scent_recipes');
    }

    protected function ensureBlendComponentsBaseOilNullable(): void
    {
        if (! Schema::hasTable('blend_components')) {
            return;
        }

        if ($this->isBlendComponentBaseOilNullable()) {
            return;
        }

        try {
            Schema::table('blend_components', function (Blueprint $table): void {
                $table->foreignId('base_oil_id')->nullable()->change();
            });
        } catch (\Throwable) {
            $this->rebuildBlendComponentsTableWithNullableBaseOil();
        }
    }

    protected function isBlendComponentBaseOilNullable(): bool
    {
        $driver = DB::getDriverName();

        if ($driver === 'sqlite') {
            $columns = DB::select("PRAGMA table_info('blend_components')");
            foreach ($columns as $column) {
                if (($column->name ?? null) === 'base_oil_id') {
                    return (int) ($column->notnull ?? 1) === 0;
                }
            }

            return false;
        }

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            $row = DB::selectOne(
                'SELECT IS_NULLABLE FROM information_schema.columns WHERE table_schema = database() AND table_name = ? AND column_name = ?',
                ['blend_components', 'base_oil_id']
            );

            return strtoupper((string) ($row->IS_NULLABLE ?? 'NO')) === 'YES';
        }

        if ($driver === 'pgsql') {
            $row = DB::selectOne(
                'SELECT is_nullable FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = ? AND column_name = ?',
                ['blend_components', 'base_oil_id']
            );

            return strtoupper((string) ($row->is_nullable ?? 'NO')) === 'YES';
        }

        return false;
    }

    protected function rebuildBlendComponentsTableWithNullableBaseOil(): void
    {
        $tempTable = 'blend_components_rebuild_tmp';
        Schema::dropIfExists($tempTable);

        Schema::create($tempTable, function (Blueprint $table): void {
            $table->id();
            $table->foreignId('blend_id')->constrained('blends')->cascadeOnDelete();
            $table->string('component_type', 32)->default('oil');
            $table->foreignId('base_oil_id')->nullable()->constrained('base_oils')->nullOnDelete();
            $table->foreignId('blend_template_id')->nullable()->constrained('blends')->nullOnDelete();
            $table->unsignedInteger('ratio_weight');
            $table->decimal('percentage', 8, 4)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['blend_id', 'base_oil_id']);
            $table->index(['blend_id', 'component_type']);
        });

        $hasComponentType = Schema::hasColumn('blend_components', 'component_type');
        $hasBlendTemplateId = Schema::hasColumn('blend_components', 'blend_template_id');
        $hasPercentage = Schema::hasColumn('blend_components', 'percentage');
        $hasSortOrder = Schema::hasColumn('blend_components', 'sort_order');

        DB::statement(sprintf(
            "INSERT INTO %s (id, blend_id, component_type, base_oil_id, blend_template_id, ratio_weight, percentage, sort_order, created_at, updated_at)
             SELECT id,
                    blend_id,
                    %s as component_type,
                    base_oil_id,
                    %s as blend_template_id,
                    ratio_weight,
                    %s as percentage,
                    %s as sort_order,
                    created_at,
                    updated_at
             FROM blend_components",
            $tempTable,
            $hasComponentType ? 'component_type' : "'oil'",
            $hasBlendTemplateId ? 'blend_template_id' : 'NULL',
            $hasPercentage ? 'percentage' : 'NULL',
            $hasSortOrder ? 'sort_order' : '0'
        ));

        Schema::disableForeignKeyConstraints();
        Schema::drop('blend_components');
        Schema::rename($tempTable, 'blend_components');
        Schema::enableForeignKeyConstraints();
    }
};

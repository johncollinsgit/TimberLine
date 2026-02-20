<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('wholesale_custom_scents')) {
            Schema::rename('wholesale_custom_scents', 'wholesale_custom_scents_legacy');
        }

        Schema::create('wholesale_custom_scents', function (Blueprint $table) {
            $table->id();
            $table->string('account_name');
            $table->string('custom_scent_name');
            $table->foreignId('canonical_scent_id')->nullable()->constrained('scents')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['account_name', 'custom_scent_name']);
        });

        if (Schema::hasTable('wholesale_custom_scents_legacy')) {
            $legacyRows = DB::table('wholesale_custom_scents_legacy')->get();
            if ($legacyRows->isNotEmpty()) {
                $scents = DB::table('scents')->pluck('display_name', 'id')->all();
                $scentsFallback = DB::table('scents')->pluck('name', 'id')->all();

                foreach ($legacyRows as $row) {
                    $scentName = $scents[$row->scent_id] ?? $scentsFallback[$row->scent_id] ?? null;
                    if ($scentName === null) {
                        $scentName = 'Custom Scent';
                    }

                    DB::table('wholesale_custom_scents')->insert([
                        'account_name' => $row->account_name,
                        'custom_scent_name' => $scentName,
                        'canonical_scent_id' => $row->scent_id,
                        'notes' => null,
                        'active' => true,
                        'created_at' => $row->created_at ?? now(),
                        'updated_at' => $row->updated_at ?? now(),
                    ]);
                }
            }

            Schema::drop('wholesale_custom_scents_legacy');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('wholesale_custom_scents');

        Schema::create('wholesale_custom_scents', function (Blueprint $table) {
            $table->id();
            $table->string('account_name');
            $table->foreignId('scent_id')->constrained('scents')->cascadeOnDelete();
            $table->timestamps();
        });
    }
};

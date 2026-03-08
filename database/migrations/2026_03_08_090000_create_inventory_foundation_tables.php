<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('wax_inventories')) {
            Schema::create('wax_inventories', function (Blueprint $table): void {
                $table->id();
                $table->string('name')->unique();
                $table->decimal('on_hand_grams', 12, 2)->default(0);
                $table->decimal('reorder_threshold_grams', 12, 2)->default(163293.26); // 360 lb
                $table->boolean('active')->default(true);
                $table->text('notes')->nullable();
                $table->timestamps();

                $table->index('active');
            });
        }

        if (! Schema::hasTable('inventory_adjustments')) {
            Schema::create('inventory_adjustments', function (Blueprint $table): void {
                $table->id();
                $table->string('item_type', 16); // oil|wax
                $table->foreignId('base_oil_id')->nullable()->constrained('base_oils')->nullOnDelete();
                $table->foreignId('wax_inventory_id')->nullable()->constrained('wax_inventories')->nullOnDelete();
                $table->decimal('grams_delta', 12, 2);
                $table->decimal('before_grams', 12, 2);
                $table->decimal('after_grams', 12, 2);
                $table->string('reason', 32);
                $table->text('notes')->nullable();
                $table->unsignedBigInteger('performed_by')->nullable();
                $table->string('source_type')->nullable();
                $table->unsignedBigInteger('source_id')->nullable();
                $table->timestamps();

                $table->index(['item_type', 'base_oil_id']);
                $table->index(['item_type', 'wax_inventory_id']);
                $table->index('reason');
                $table->index(['source_type', 'source_id']);
                $table->index('created_at');
            });
        }

        if (Schema::hasTable('wax_inventories')) {
            $exists = DB::table('wax_inventories')
                ->whereRaw('lower(name) = ?', ['candle wax'])
                ->exists();

            if (! $exists) {
                DB::table('wax_inventories')->insert([
                    'name' => 'Candle Wax',
                    'on_hand_grams' => 0,
                    'reorder_threshold_grams' => 163293.26, // 360 lb
                    'active' => true,
                    'notes' => 'Default wax inventory row. Grams are canonical; UI may display 45 lb box equivalents.',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_adjustments');
        Schema::dropIfExists('wax_inventories');
    }
};

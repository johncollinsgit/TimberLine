<?php

use App\Services\Marketing\LegacyCandleCashCorrectionService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('candle_cash_transactions')) {
            Schema::table('candle_cash_transactions', function (Blueprint $table): void {
                if (! Schema::hasColumn('candle_cash_transactions', 'legacy_points_origin')) {
                    $table->boolean('legacy_points_origin')->default(false)->after('points');
                }

                if (! Schema::hasColumn('candle_cash_transactions', 'legacy_points_value')) {
                    $table->integer('legacy_points_value')->nullable()->after('legacy_points_origin');
                }
            });

            Schema::table('candle_cash_transactions', function (Blueprint $table): void {
                if (Schema::hasColumn('candle_cash_transactions', 'candle_cash_delta')) {
                    $table->decimal('candle_cash_delta', 12, 3)->default(0)->change();
                }
            });
        }

        if (Schema::hasTable('candle_cash_balances') && Schema::hasColumn('candle_cash_balances', 'balance')) {
            Schema::table('candle_cash_balances', function (Blueprint $table): void {
                $table->decimal('balance', 12, 3)->default(0)->change();
            });
        }

        app(LegacyCandleCashCorrectionService::class)->apply();
    }

    public function down(): void
    {
        if (Schema::hasTable('candle_cash_transactions') && Schema::hasColumn('candle_cash_transactions', 'candle_cash_delta')) {
            Schema::table('candle_cash_transactions', function (Blueprint $table): void {
                $table->integer('candle_cash_delta')->default(0)->change();
            });
        }

        if (Schema::hasTable('candle_cash_balances') && Schema::hasColumn('candle_cash_balances', 'balance')) {
            Schema::table('candle_cash_balances', function (Blueprint $table): void {
                $table->integer('balance')->default(0)->change();
            });
        }

        if (Schema::hasTable('candle_cash_transactions')) {
            Schema::table('candle_cash_transactions', function (Blueprint $table): void {
                if (Schema::hasColumn('candle_cash_transactions', 'legacy_points_value')) {
                    $table->dropColumn('legacy_points_value');
                }

                if (Schema::hasColumn('candle_cash_transactions', 'legacy_points_origin')) {
                    $table->dropColumn('legacy_points_origin');
                }
            });
        }
    }
};

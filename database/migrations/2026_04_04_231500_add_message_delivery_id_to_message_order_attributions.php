<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (
            ! Schema::hasTable('marketing_message_order_attributions')
            || Schema::hasColumn('marketing_message_order_attributions', 'marketing_message_delivery_id')
        ) {
            return;
        }

        Schema::table('marketing_message_order_attributions', function (Blueprint $table): void {
            $table->foreignId('marketing_message_delivery_id')
                ->nullable()
                ->after('marketing_email_delivery_id');
        });

        try {
            Schema::table('marketing_message_order_attributions', function (Blueprint $table): void {
                $table->index(
                    ['tenant_id', 'store_key', 'marketing_message_delivery_id'],
                    'mm_order_attribution_tenant_store_msg_delivery_idx'
                );
            });
        } catch (\Throwable) {
            // Safe no-op when index already exists.
        }

        try {
            Schema::table('marketing_message_order_attributions', function (Blueprint $table): void {
                $table->foreign('marketing_message_delivery_id', 'mm_ord_attr_message_delivery_fk')
                    ->references('id')
                    ->on('marketing_message_deliveries')
                    ->nullOnDelete();
            });
        } catch (\Throwable) {
            // Safe no-op when FK already exists.
        }

        if (! Schema::hasTable('marketing_message_engagement_events')) {
            return;
        }

        DB::table('marketing_message_order_attributions')
            ->whereNull('marketing_message_delivery_id')
            ->whereNotNull('marketing_message_engagement_event_id')
            ->orderBy('id')
            ->chunkById(200, function ($rows): void {
                $eventIds = collect($rows)
                    ->pluck('marketing_message_engagement_event_id')
                    ->map(fn ($value): int => (int) $value)
                    ->filter(fn (int $value): bool => $value > 0)
                    ->unique()
                    ->values();

                if ($eventIds->isEmpty()) {
                    return;
                }

                $deliveryIdsByEvent = DB::table('marketing_message_engagement_events')
                    ->whereIn('id', $eventIds->all())
                    ->pluck('marketing_message_delivery_id', 'id');

                foreach ($rows as $row) {
                    $eventId = (int) ($row->marketing_message_engagement_event_id ?? 0);
                    $deliveryId = (int) ($deliveryIdsByEvent[$eventId] ?? 0);
                    if ($deliveryId <= 0) {
                        continue;
                    }

                    DB::table('marketing_message_order_attributions')
                        ->where('id', (int) ($row->id ?? 0))
                        ->update([
                            'marketing_message_delivery_id' => $deliveryId,
                        ]);
                }
            });
    }

    public function down(): void
    {
        if (
            ! Schema::hasTable('marketing_message_order_attributions')
            || ! Schema::hasColumn('marketing_message_order_attributions', 'marketing_message_delivery_id')
        ) {
            return;
        }

        try {
            Schema::table('marketing_message_order_attributions', function (Blueprint $table): void {
                $table->dropForeign('mm_ord_attr_message_delivery_fk');
            });
        } catch (\Throwable) {
            // Safe no-op when FK is absent.
        }

        try {
            Schema::table('marketing_message_order_attributions', function (Blueprint $table): void {
                $table->dropIndex('mm_order_attribution_tenant_store_msg_delivery_idx');
            });
        } catch (\Throwable) {
            // Safe no-op when index is absent.
        }

        Schema::table('marketing_message_order_attributions', function (Blueprint $table): void {
            $table->dropColumn('marketing_message_delivery_id');
        });
    }
};

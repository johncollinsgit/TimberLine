<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        $this->addEventInstancePublicSlug();
        $this->addCandleCashRedemptionLifecycleColumns();
        $this->createMarketingConsentRequestsTable();
    }

    public function down(): void
    {
        Schema::dropIfExists('marketing_consent_requests');

        Schema::table('candle_cash_redemptions', function (Blueprint $table): void {
            foreach ([
                'status',
                'issued_at',
                'expires_at',
                'canceled_at',
                'redeemed_channel',
                'external_order_source',
                'external_order_id',
                'redemption_context',
                'reconciliation_notes',
                'redeemed_by',
            ] as $column) {
                if (Schema::hasColumn('candle_cash_redemptions', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('event_instances', function (Blueprint $table): void {
            if (Schema::hasColumn('event_instances', 'public_slug')) {
                try {
                    $table->dropUnique('event_instances_public_slug_unique');
                } catch (\Throwable) {
                    // no-op when running against databases that do not expose this index name.
                }
                $table->dropColumn('public_slug');
            }
        });
    }

    protected function addEventInstancePublicSlug(): void
    {
        Schema::table('event_instances', function (Blueprint $table): void {
            if (! Schema::hasColumn('event_instances', 'public_slug')) {
                $table->string('public_slug')->nullable()->after('title');
                $table->unique('public_slug');
            }
        });

        if (! Schema::hasColumn('event_instances', 'public_slug')) {
            return;
        }

        $existing = DB::table('event_instances')
            ->whereNotNull('public_slug')
            ->pluck('public_slug')
            ->map(static fn ($value): string => strtolower(trim((string) $value)))
            ->filter()
            ->all();

        $used = array_fill_keys($existing, true);

        DB::table('event_instances')
            ->select(['id', 'title', 'public_slug'])
            ->orderBy('id')
            ->lazy()
            ->each(function (object $row) use (&$used): void {
                $current = strtolower(trim((string) ($row->public_slug ?? '')));
                if ($current !== '') {
                    $used[$current] = true;

                    return;
                }

                $base = Str::slug((string) ($row->title ?? ''));
                if ($base === '') {
                    $base = 'event-instance';
                }

                $candidate = $base;
                $suffix = 2;
                while (isset($used[$candidate])) {
                    $candidate = $base . '-' . $suffix;
                    $suffix++;
                }

                $used[$candidate] = true;

                DB::table('event_instances')
                    ->where('id', (int) $row->id)
                    ->update([
                        'public_slug' => $candidate,
                        'updated_at' => now(),
                    ]);
            });
    }

    protected function addCandleCashRedemptionLifecycleColumns(): void
    {
        Schema::table('candle_cash_redemptions', function (Blueprint $table): void {
            if (! Schema::hasColumn('candle_cash_redemptions', 'status')) {
                $table->string('status', 40)->default('issued')->after('redemption_code')->index();
            }
            if (! Schema::hasColumn('candle_cash_redemptions', 'issued_at')) {
                $table->timestamp('issued_at')->nullable()->after('status')->index();
            }
            if (! Schema::hasColumn('candle_cash_redemptions', 'expires_at')) {
                $table->timestamp('expires_at')->nullable()->after('issued_at')->index();
            }
            if (! Schema::hasColumn('candle_cash_redemptions', 'canceled_at')) {
                $table->timestamp('canceled_at')->nullable()->after('redeemed_at')->index();
            }
            if (! Schema::hasColumn('candle_cash_redemptions', 'redeemed_channel')) {
                $table->string('redeemed_channel', 80)->nullable()->after('platform')->index();
            }
            if (! Schema::hasColumn('candle_cash_redemptions', 'external_order_source')) {
                $table->string('external_order_source', 80)->nullable()->after('redeemed_channel')->index();
            }
            if (! Schema::hasColumn('candle_cash_redemptions', 'external_order_id')) {
                $table->string('external_order_id', 120)->nullable()->after('external_order_source')->index();
            }
            if (! Schema::hasColumn('candle_cash_redemptions', 'redemption_context')) {
                $table->json('redemption_context')->nullable()->after('external_order_id');
            }
            if (! Schema::hasColumn('candle_cash_redemptions', 'reconciliation_notes')) {
                $table->text('reconciliation_notes')->nullable()->after('redemption_context');
            }
            if (! Schema::hasColumn('candle_cash_redemptions', 'redeemed_by')) {
                $table->foreignId('redeemed_by')->nullable()->after('reconciliation_notes')->constrained('users')->nullOnDelete();
            }
        });

        DB::table('candle_cash_redemptions')
            ->whereNull('issued_at')
            ->update([
                'issued_at' => DB::raw('coalesce(created_at, CURRENT_TIMESTAMP)'),
            ]);

        DB::table('candle_cash_redemptions')
            ->where(function ($query): void {
                $query->whereNull('status')->orWhere('status', '');
            })
            ->update([
                'status' => DB::raw("
                    case
                        when canceled_at is not null then 'canceled'
                        when redeemed_at is not null then 'redeemed'
                        when expires_at is not null and expires_at < CURRENT_TIMESTAMP then 'expired'
                        else 'issued'
                    end
                "),
            ]);
    }

    protected function createMarketingConsentRequestsTable(): void
    {
        if (Schema::hasTable('marketing_consent_requests')) {
            return;
        }

        Schema::create('marketing_consent_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('marketing_profile_id')->nullable()->constrained('marketing_profiles')->nullOnDelete();
            $table->string('channel', 40)->default('sms')->index();
            $table->string('token', 120)->nullable()->unique();
            $table->string('status', 40)->default('requested')->index();
            $table->string('source_type', 120)->nullable()->index();
            $table->string('source_id', 160)->nullable()->index();
            $table->json('payload')->nullable();
            $table->timestamp('requested_at')->nullable()->index();
            $table->timestamp('confirmed_at')->nullable()->index();
            $table->timestamp('revoked_at')->nullable()->index();
            $table->timestamp('expires_at')->nullable()->index();
            $table->unsignedInteger('reward_awarded_points')->default(0);
            $table->timestamp('reward_awarded_at')->nullable()->index();
            $table->timestamps();
        });
    }
};

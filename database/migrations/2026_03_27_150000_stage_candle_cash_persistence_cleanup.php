<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->addCanonicalColumns();
        $this->backfillCanonicalColumns();
        $this->translateBirthdayPersistence();
        $this->translateMarketingSettings();
    }

    public function down(): void
    {
        $this->revertMarketingSettings();
        $this->revertBirthdayPersistence();

        if (Schema::hasTable('birthday_reward_issuances') && Schema::hasColumn('birthday_reward_issuances', 'candle_cash_awarded')) {
            Schema::table('birthday_reward_issuances', function (Blueprint $table): void {
                $table->dropColumn('candle_cash_awarded');
            });
        }

        if (Schema::hasTable('marketing_consent_requests') && Schema::hasColumn('marketing_consent_requests', 'reward_awarded_candle_cash')) {
            Schema::table('marketing_consent_requests', function (Blueprint $table): void {
                $table->dropColumn('reward_awarded_candle_cash');
            });
        }

        if (Schema::hasTable('candle_cash_task_completions') && Schema::hasColumn('candle_cash_task_completions', 'reward_candle_cash')) {
            Schema::table('candle_cash_task_completions', function (Blueprint $table): void {
                $table->dropColumn('reward_candle_cash');
            });
        }

        if (Schema::hasTable('candle_cash_redemptions') && Schema::hasColumn('candle_cash_redemptions', 'candle_cash_spent')) {
            Schema::table('candle_cash_redemptions', function (Blueprint $table): void {
                $table->dropColumn('candle_cash_spent');
            });
        }

        if (Schema::hasTable('candle_cash_rewards') && Schema::hasColumn('candle_cash_rewards', 'candle_cash_cost')) {
            Schema::table('candle_cash_rewards', function (Blueprint $table): void {
                $table->dropColumn('candle_cash_cost');
            });
        }

        if (Schema::hasTable('candle_cash_transactions') && Schema::hasColumn('candle_cash_transactions', 'candle_cash_delta')) {
            Schema::table('candle_cash_transactions', function (Blueprint $table): void {
                $table->dropColumn('candle_cash_delta');
            });
        }
    }

    protected function addCanonicalColumns(): void
    {
        if (Schema::hasTable('candle_cash_transactions') && ! Schema::hasColumn('candle_cash_transactions', 'candle_cash_delta')) {
            Schema::table('candle_cash_transactions', function (Blueprint $table): void {
                $table->integer('candle_cash_delta')->default(0)->after('points');
            });
        }

        if (Schema::hasTable('candle_cash_rewards') && ! Schema::hasColumn('candle_cash_rewards', 'candle_cash_cost')) {
            Schema::table('candle_cash_rewards', function (Blueprint $table): void {
                $table->unsignedInteger('candle_cash_cost')->default(0)->after('points_cost');
            });
        }

        if (Schema::hasTable('candle_cash_redemptions') && ! Schema::hasColumn('candle_cash_redemptions', 'candle_cash_spent')) {
            Schema::table('candle_cash_redemptions', function (Blueprint $table): void {
                $table->unsignedInteger('candle_cash_spent')->default(0)->after('points_spent');
            });
        }

        if (Schema::hasTable('candle_cash_task_completions') && ! Schema::hasColumn('candle_cash_task_completions', 'reward_candle_cash')) {
            Schema::table('candle_cash_task_completions', function (Blueprint $table): void {
                $table->integer('reward_candle_cash')->default(0)->after('reward_points');
            });
        }

        if (Schema::hasTable('birthday_reward_issuances') && ! Schema::hasColumn('birthday_reward_issuances', 'candle_cash_awarded')) {
            Schema::table('birthday_reward_issuances', function (Blueprint $table): void {
                $table->integer('candle_cash_awarded')->nullable()->after('points_awarded');
            });
        }

        if (Schema::hasTable('marketing_consent_requests') && ! Schema::hasColumn('marketing_consent_requests', 'reward_awarded_candle_cash')) {
            Schema::table('marketing_consent_requests', function (Blueprint $table): void {
                $table->unsignedInteger('reward_awarded_candle_cash')->default(0)->after('reward_awarded_points');
            });
        }
    }

    protected function backfillCanonicalColumns(): void
    {
        if (Schema::hasTable('candle_cash_transactions') && Schema::hasColumn('candle_cash_transactions', 'points') && Schema::hasColumn('candle_cash_transactions', 'candle_cash_delta')) {
            DB::table('candle_cash_transactions')->update([
                'candle_cash_delta' => DB::raw('points'),
            ]);
        }

        if (Schema::hasTable('candle_cash_rewards') && Schema::hasColumn('candle_cash_rewards', 'points_cost') && Schema::hasColumn('candle_cash_rewards', 'candle_cash_cost')) {
            DB::table('candle_cash_rewards')->update([
                'candle_cash_cost' => DB::raw('points_cost'),
            ]);
        }

        if (Schema::hasTable('candle_cash_redemptions') && Schema::hasColumn('candle_cash_redemptions', 'points_spent') && Schema::hasColumn('candle_cash_redemptions', 'candle_cash_spent')) {
            DB::table('candle_cash_redemptions')->update([
                'candle_cash_spent' => DB::raw('points_spent'),
            ]);
        }

        if (Schema::hasTable('candle_cash_task_completions') && Schema::hasColumn('candle_cash_task_completions', 'reward_points') && Schema::hasColumn('candle_cash_task_completions', 'reward_candle_cash')) {
            DB::table('candle_cash_task_completions')->update([
                'reward_candle_cash' => DB::raw('reward_points'),
            ]);
        }

        if (Schema::hasTable('birthday_reward_issuances') && Schema::hasColumn('birthday_reward_issuances', 'points_awarded') && Schema::hasColumn('birthday_reward_issuances', 'candle_cash_awarded')) {
            DB::table('birthday_reward_issuances')->update([
                'candle_cash_awarded' => DB::raw('points_awarded'),
            ]);
        }

        if (Schema::hasTable('marketing_consent_requests') && Schema::hasColumn('marketing_consent_requests', 'reward_awarded_points') && Schema::hasColumn('marketing_consent_requests', 'reward_awarded_candle_cash')) {
            DB::table('marketing_consent_requests')->update([
                'reward_awarded_candle_cash' => DB::raw('reward_awarded_points'),
            ]);
        }
    }

    protected function translateBirthdayPersistence(): void
    {
        if (Schema::hasTable('birthday_reward_issuances') && Schema::hasColumn('birthday_reward_issuances', 'reward_type')) {
            DB::table('birthday_reward_issuances')
                ->where('reward_type', 'points')
                ->update(['reward_type' => 'candle_cash']);
        }
    }

    protected function translateMarketingSettings(): void
    {
        $this->transformSetting('candle_cash_program_config', function (array $value): array {
            if (array_key_exists('points_per_dollar', $value) && ! array_key_exists('legacy_points_per_candle_cash', $value)) {
                $value['legacy_points_per_candle_cash'] = (int) $value['points_per_dollar'];
                unset($value['points_per_dollar']);
            }

            return $value;
        });

        $this->transformSetting('birthday_reward_config', function (array $value): array {
            if (($value['reward_type'] ?? null) === 'points') {
                $value['reward_type'] = 'candle_cash';
            }

            if (array_key_exists('points_amount', $value) && ! array_key_exists('candle_cash_amount', $value)) {
                $value['candle_cash_amount'] = (int) $value['points_amount'];
                unset($value['points_amount']);
            }

            return $value;
        });

        $legacyConsentBonus = DB::table('marketing_settings')->where('key', 'candle_cash_consent_bonus_points')->first();
        $canonicalConsentBonus = DB::table('marketing_settings')->where('key', 'candle_cash_consent_bonus')->first();

        if ($legacyConsentBonus && ! $canonicalConsentBonus) {
            $value = $this->decodeSettingValue($legacyConsentBonus->value ?? null);
            if (array_key_exists('points', $value) && ! array_key_exists('candle_cash', $value)) {
                $value['candle_cash'] = (int) $value['points'];
                unset($value['points']);
            }

            DB::table('marketing_settings')
                ->where('id', $legacyConsentBonus->id)
                ->update([
                    'key' => 'candle_cash_consent_bonus',
                    'value' => json_encode($value),
                    'description' => 'Optional bonus Candle Cash for confirmed SMS consent capture events.',
                    'updated_at' => now(),
                ]);
        } elseif ($legacyConsentBonus && $canonicalConsentBonus) {
            DB::table('marketing_settings')
                ->where('id', $legacyConsentBonus->id)
                ->delete();
        }
    }

    protected function revertBirthdayPersistence(): void
    {
        if (Schema::hasTable('birthday_reward_issuances') && Schema::hasColumn('birthday_reward_issuances', 'reward_type')) {
            DB::table('birthday_reward_issuances')
                ->where('reward_type', 'candle_cash')
                ->update(['reward_type' => 'points']);
        }
    }

    protected function revertMarketingSettings(): void
    {
        $this->transformSetting('candle_cash_program_config', function (array $value): array {
            if (array_key_exists('legacy_points_per_candle_cash', $value) && ! array_key_exists('points_per_dollar', $value)) {
                $value['points_per_dollar'] = (int) $value['legacy_points_per_candle_cash'];
                unset($value['legacy_points_per_candle_cash']);
            }

            return $value;
        });

        $this->transformSetting('birthday_reward_config', function (array $value): array {
            if (($value['reward_type'] ?? null) === 'candle_cash') {
                $value['reward_type'] = 'points';
            }

            if (array_key_exists('candle_cash_amount', $value) && ! array_key_exists('points_amount', $value)) {
                $value['points_amount'] = (int) $value['candle_cash_amount'];
                unset($value['candle_cash_amount']);
            }

            return $value;
        });

        $canonicalConsentBonus = DB::table('marketing_settings')->where('key', 'candle_cash_consent_bonus')->first();
        $legacyConsentBonus = DB::table('marketing_settings')->where('key', 'candle_cash_consent_bonus_points')->first();

        if ($canonicalConsentBonus && ! $legacyConsentBonus) {
            $value = $this->decodeSettingValue($canonicalConsentBonus->value ?? null);
            if (array_key_exists('candle_cash', $value) && ! array_key_exists('points', $value)) {
                $value['points'] = (int) $value['candle_cash'];
                unset($value['candle_cash']);
            }

            DB::table('marketing_settings')
                ->where('id', $canonicalConsentBonus->id)
                ->update([
                    'key' => 'candle_cash_consent_bonus_points',
                    'value' => json_encode($value),
                    'description' => 'Optional bonus Candle Cash points for confirmed SMS consent capture events.',
                    'updated_at' => now(),
                ]);
        }
    }

    protected function transformSetting(string $key, callable $transformer): void
    {
        $row = DB::table('marketing_settings')->where('key', $key)->first();
        if (! $row) {
            return;
        }

        $value = $this->decodeSettingValue($row->value ?? null);
        $nextValue = $transformer($value);

        if ($nextValue === $value) {
            return;
        }

        DB::table('marketing_settings')
            ->where('id', $row->id)
            ->update([
                'value' => json_encode($nextValue),
                'updated_at' => now(),
            ]);
    }

    /**
     * @return array<string,mixed>
     */
    protected function decodeSettingValue(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }
};

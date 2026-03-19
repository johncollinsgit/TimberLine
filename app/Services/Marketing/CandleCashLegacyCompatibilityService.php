<?php

namespace App\Services\Marketing;

use App\Models\CandleCashLegacyCompatibilityUsage;
use App\Models\MarketingSetting;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CandleCashLegacyCompatibilityService
{
    /**
     * @var array<string,bool>
     */
    protected static array $seen = [];

    protected static ?bool $tableAvailable = null;

    public function record(string $path, string $operation, string $context, array $meta = []): void
    {
        if (! $this->tableAvailable()) {
            return;
        }

        $path = $this->normalizeToken($path, 120);
        $operation = $this->normalizeToken($operation, 40);
        $context = $this->normalizeContext($context);

        if ($path === '' || $operation === '' || $context === '') {
            return;
        }

        $dedupeKey = $path.'|'.$operation.'|'.$context;
        if (isset(self::$seen[$dedupeKey])) {
            return;
        }

        self::$seen[$dedupeKey] = true;

        $meta = $this->normalizeMeta($meta);
        $now = now();

        $existing = CandleCashLegacyCompatibilityUsage::query()
            ->where('path', $path)
            ->where('operation', $operation)
            ->where('context', $context)
            ->first();

        if ($existing) {
            $existing->forceFill([
                'hits' => (int) $existing->hits + 1,
                'last_seen_at' => $now,
                'meta' => $existing->meta ?: ($meta !== [] ? $meta : null),
            ])->save();

            return;
        }

        CandleCashLegacyCompatibilityUsage::query()->create([
            'path' => $path,
            'operation' => $operation,
            'context' => $context,
            'hits' => 1,
            'first_seen_at' => $now,
            'last_seen_at' => $now,
            'meta' => $meta !== [] ? $meta : null,
        ]);
    }

    public function reset(): int
    {
        self::$seen = [];
        self::$tableAvailable = null;

        if (! $this->tableAvailable()) {
            return 0;
        }

        return (int) CandleCashLegacyCompatibilityUsage::query()->delete();
    }

    /**
     * @return array<string,mixed>
     */
    public function summary(): array
    {
        $observations = $this->tableAvailable()
            ? CandleCashLegacyCompatibilityUsage::query()
                ->orderByDesc('last_seen_at')
                ->orderBy('operation')
                ->orderBy('path')
                ->get()
            : collect();

        $rows = $observations->map(function (CandleCashLegacyCompatibilityUsage $row): array {
            return [
                'path' => (string) $row->path,
                'operation' => (string) $row->operation,
                'context' => (string) $row->context,
                'hits' => (int) $row->hits,
                'first_seen_at' => optional($row->first_seen_at)->toIso8601String(),
                'last_seen_at' => optional($row->last_seen_at)->toIso8601String(),
            ];
        })->all();

        $byOperation = $observations
            ->groupBy('operation')
            ->map(fn ($group): int => (int) $group->sum('hits'))
            ->all();

        $staticAudit = $this->staticAudit();
        $blockingSignals = collect($rows)
            ->filter(fn (array $row): bool => in_array((string) ($row['operation'] ?? ''), ['legacy_read', 'legacy_write', 'fallback_read', 'config_fallback', 'normalization'], true))
            ->values()
            ->all();

        $blockingReasons = [];
        if ($blockingSignals !== []) {
            $blockingReasons[] = 'Observed runtime legacy compatibility usage signals.';
        }
        if ((int) data_get($staticAudit, 'settings.legacy_program_points_per_dollar') > 0) {
            $blockingReasons[] = 'Legacy points_per_dollar program config is still stored.';
        }
        if ((int) data_get($staticAudit, 'settings.legacy_birthday_reward_type_points') > 0) {
            $blockingReasons[] = 'Birthday reward config still stores reward_type=points.';
        }
        if ((int) data_get($staticAudit, 'settings.legacy_birthday_points_amount') > 0) {
            $blockingReasons[] = 'Birthday reward config still stores points_amount.';
        }
        if ((int) data_get($staticAudit, 'settings.legacy_consent_bonus_setting') > 0) {
            $blockingReasons[] = 'Legacy candle_cash_consent_bonus_points setting still exists.';
        }
        if ((int) data_get($staticAudit, 'birthday_rows.reward_type_points') > 0) {
            $blockingReasons[] = 'Birthday issuance rows still store reward_type=points.';
        }
        if ((int) data_get($staticAudit, 'columns.total_legacy_only_rows') > 0) {
            $blockingReasons[] = 'Some canonical Candle Cash columns are still missing values where legacy columns are populated.';
        }
        if ((int) data_get($staticAudit, 'columns.total_diverged_rows') > 0) {
            $blockingReasons[] = 'Some canonical Candle Cash columns have diverged from legacy columns.';
        }
        if ((int) data_get($staticAudit, 'env.active_legacy_env_count') > 0) {
            $blockingReasons[] = 'Legacy Candle Cash env fallbacks are still set.';
        }

        return [
            'observed' => [
                'total_signals' => count($rows),
                'by_operation' => $byOperation,
                'rows' => $rows,
            ],
            'static_audit' => $staticAudit,
            'go_no_go' => [
                'ready_to_drop_old_columns' => $blockingReasons === [],
                'blocking_reasons' => $blockingReasons,
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    protected function staticAudit(): array
    {
        $columnPairs = [
            ['table' => 'candle_cash_transactions', 'legacy' => 'points', 'canonical' => 'candle_cash_delta'],
            ['table' => 'candle_cash_rewards', 'legacy' => 'points_cost', 'canonical' => 'candle_cash_cost'],
            ['table' => 'candle_cash_redemptions', 'legacy' => 'points_spent', 'canonical' => 'candle_cash_spent'],
            ['table' => 'candle_cash_task_completions', 'legacy' => 'reward_points', 'canonical' => 'reward_candle_cash'],
            ['table' => 'birthday_reward_issuances', 'legacy' => 'points_awarded', 'canonical' => 'candle_cash_awarded'],
            ['table' => 'marketing_consent_requests', 'legacy' => 'reward_awarded_points', 'canonical' => 'reward_awarded_candle_cash'],
        ];

        $columnAudit = [];
        foreach ($columnPairs as $pair) {
            $table = (string) $pair['table'];
            $legacy = (string) $pair['legacy'];
            $canonical = (string) $pair['canonical'];

            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $legacy) || ! Schema::hasColumn($table, $canonical)) {
                continue;
            }

            $legacyOnlyRows = (int) DB::table($table)
                ->whereNull($canonical)
                ->whereNotNull($legacy)
                ->count();

            $divergedQuery = DB::table($table)
                ->whereNotNull($legacy)
                ->whereNotNull($canonical);

            if ($table === 'candle_cash_transactions') {
                if (Schema::hasColumn($table, 'legacy_points_origin')) {
                    $divergedQuery->where(function ($query): void {
                        $query->whereNull('legacy_points_origin')
                            ->orWhere('legacy_points_origin', false);
                    });
                }

                if (Schema::hasColumn($table, 'source')) {
                    $divergedQuery->where('source', '!=', 'legacy_rebase');
                }

                $divergedQuery->whereRaw('ROUND(COALESCE('.$legacy.', 0), 3) != ROUND(COALESCE('.$canonical.', 0), 3)');
            } else {
                $divergedQuery->whereColumn($legacy, '!=', $canonical);
            }

            $divergedRows = (int) $divergedQuery->count();

            $columnAudit[$table] = [
                'legacy_column' => $legacy,
                'canonical_column' => $canonical,
                'legacy_only_rows' => $legacyOnlyRows,
                'diverged_rows' => $divergedRows,
            ];
        }

        $programConfig = [];
        $birthdayConfig = [];
        $legacyConsentBonusSetting = 0;

        if (Schema::hasTable('marketing_settings')) {
            $programConfig = (array) optional(MarketingSetting::query()->where('key', 'candle_cash_program_config')->first())->value;
            $birthdayConfig = (array) optional(MarketingSetting::query()->where('key', 'birthday_reward_config')->first())->value;
            $legacyConsentBonusSetting = MarketingSetting::query()->where('key', 'candle_cash_consent_bonus_points')->exists() ? 1 : 0;
        }

        $legacyEnv = [
            'MARKETING_CANDLE_CASH_POINTS_PER_DOLLAR' => $this->envIsSet('MARKETING_CANDLE_CASH_POINTS_PER_DOLLAR'),
            'MARKETING_SMS_CONSENT_BONUS_POINTS' => $this->envIsSet('MARKETING_SMS_CONSENT_BONUS_POINTS'),
            'MARKETING_BIRTHDAY_POINTS_AMOUNT' => $this->envIsSet('MARKETING_BIRTHDAY_POINTS_AMOUNT'),
            'MARKETING_BIRTHDAY_REWARD_TYPE_POINTS' => strtolower(trim((string) Env::get('MARKETING_BIRTHDAY_REWARD_TYPE', ''))) === 'points',
        ];

        return [
            'columns' => [
                'pairs' => $columnAudit,
                'total_legacy_only_rows' => collect($columnAudit)->sum('legacy_only_rows'),
                'total_diverged_rows' => collect($columnAudit)->sum('diverged_rows'),
            ],
            'settings' => [
                'legacy_program_points_per_dollar' => array_key_exists('points_per_dollar', $programConfig) ? 1 : 0,
                'legacy_birthday_reward_type_points' => (($birthdayConfig['reward_type'] ?? null) === 'points') ? 1 : 0,
                'legacy_birthday_points_amount' => array_key_exists('points_amount', $birthdayConfig) ? 1 : 0,
                'legacy_consent_bonus_setting' => $legacyConsentBonusSetting,
            ],
            'birthday_rows' => [
                'reward_type_points' => Schema::hasTable('birthday_reward_issuances')
                    ? (int) DB::table('birthday_reward_issuances')->where('reward_type', 'points')->count()
                    : 0,
            ],
            'env' => [
                'legacy_keys' => $legacyEnv,
                'active_legacy_env_count' => collect($legacyEnv)->filter()->count(),
            ],
        ];
    }

    protected function tableAvailable(): bool
    {
        if (self::$tableAvailable !== null) {
            return self::$tableAvailable;
        }

        return self::$tableAvailable = Schema::hasTable('candle_cash_legacy_compatibility_usages');
    }

    /**
     * @param  array<string,mixed>  $meta
     * @return array<string,mixed>
     */
    protected function normalizeMeta(array $meta): array
    {
        return collect($meta)
            ->filter(fn (mixed $value): bool => $value !== null && $value !== '')
            ->map(function (mixed $value): mixed {
                if (is_scalar($value)) {
                    return $value;
                }

                return is_array($value) ? $value : (string) $value;
            })
            ->all();
    }

    protected function normalizeToken(string $value, int $maxLength): string
    {
        return substr(trim(strtolower($value)), 0, $maxLength);
    }

    protected function normalizeContext(string $value): string
    {
        return substr(trim($value), 0, 160);
    }

    protected function envIsSet(string $key): bool
    {
        $value = Env::get($key);

        return $value !== null && $value !== '';
    }
}

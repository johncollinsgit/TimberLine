<?php

namespace App\Services\Replacements;

use App\Models\ReplacementModule;
use App\Services\Replacements\Contracts\ReplacementActivationAdapter;

/**
 * Activates modules whose installed storefront code already resolves its
 * provider from replacement_modules. It never publishes a theme, disables a
 * vendor, returns shipping rates, or enables subscription billing.
 */
class DatabaseProviderActivationAdapter implements ReplacementActivationAdapter
{
    private const SUPPORTED = ['storeify_forms', 'omnium_locator', 'shop_calendar'];

    public function supports(ReplacementModule $module): bool
    {
        return in_array($module->module_key, self::SUPPORTED, true)
            && (bool) data_get($module->metadata, 'activation_adapter_ready', false)
            && data_get($module->metadata, 'activation_adapter_kind') === 'verified_storefront'
            && filled(data_get($module->metadata, 'source_fingerprint'))
            && filled(data_get($module->metadata, 'target_fingerprint'));
    }

    public function activate(ReplacementModule $module): array
    {
        $before = [
            'status' => $module->status,
            'active_provider' => $module->active_provider,
            'activated_at' => $module->activated_at?->toIso8601String(),
        ];

        $module->forceFill([
            'status' => 'active',
            'active_provider' => $module->target_provider,
            'activated_at' => now(),
            'rolled_back_at' => null,
            'lock_version' => ((int) $module->lock_version) + 1,
        ])->save();

        return $before;
    }

    public function rollback(ReplacementModule $module, array $rollbackPayload): void
    {
        $module->forceFill([
            'status' => (string) ($rollbackPayload['status'] ?? 'rolled_back'),
            'active_provider' => (string) ($rollbackPayload['active_provider'] ?? 'legacy'),
            'activated_at' => $rollbackPayload['activated_at'] ?? null,
            'rolled_back_at' => now(),
            'lock_version' => ((int) $module->lock_version) + 1,
        ])->save();
    }

    public function smokeTest(ReplacementModule $module): array
    {
        $fresh = $module->fresh();
        $passed = $fresh instanceof ReplacementModule
            && $fresh->status === 'active'
            && $fresh->active_provider === $fresh->target_provider;

        return [
            'passed' => $passed,
            'checks' => [
                'provider_flag' => $passed ? 'passed' : 'failed',
                'external_customer_checks' => 'required_from_verified_storefront_adapter',
            ],
        ];
    }
}

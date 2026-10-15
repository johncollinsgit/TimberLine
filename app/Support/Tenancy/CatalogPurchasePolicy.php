<?php

namespace App\Support\Tenancy;

/** Commercial availability is separate from approved pricing and existing access. */
class CatalogPurchasePolicy
{
    public static function addonEnabled(string $addonKey): bool
    {
        $definition = config('module_catalog.addons.'.strtolower(trim($addonKey)));

        return is_array($definition) && (bool) ($definition['purchase_enabled'] ?? true);
    }

    public static function moduleEnabled(string $moduleKey): bool
    {
        foreach ((array) config('module_catalog.addons', []) as $key => $definition) {
            if (is_array($definition) && in_array($moduleKey, (array) ($definition['modules'] ?? []), true)
                && ! self::addonEnabled((string) $key)) {
                return false;
            }
        }

        return true;
    }
}

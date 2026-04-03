<?php

test('canonical module catalog drives entitlements and commercial config for reconciled modules', function () {
    $catalogModules = (array) config('module_catalog.modules', []);
    $entitlementModules = (array) config('entitlements.modules', []);
    $commercialPlans = (array) config('commercial.plans', []);
    $commercialAddons = (array) config('commercial.addons', []);

    foreach (['square', 'messaging', 'bulk_email_marketing', 'additional_channels', 'future_niche_modules'] as $moduleKey) {
        expect($catalogModules)->toHaveKey($moduleKey)
            ->and($entitlementModules)->toHaveKey($moduleKey)
            ->and((string) data_get($entitlementModules, $moduleKey.'.status'))
            ->toBe((string) data_get($catalogModules, $moduleKey.'.status'))
            ->and((string) data_get($entitlementModules, $moduleKey.'.billing_mode'))
            ->toBe((string) data_get($catalogModules, $moduleKey.'.billing_mode'))
            ->and((string) data_get($entitlementModules, $moduleKey.'.market_state'))
            ->toBe((string) data_get($catalogModules, $moduleKey.'.market_state'));
    }

    expect((array) data_get($commercialPlans, 'starter.modules', []))->toContain('square')
        ->and((array) data_get($commercialAddons, 'messaging.modules', []))->toBe(['messaging'])
        ->and((array) data_get($commercialAddons, 'bulk_email_marketing.modules', []))->toBe(['bulk_email_marketing'])
        ->and((array) data_get($commercialAddons, 'additional_channels.modules', []))->toBe(['additional_channels'])
        ->and((array) data_get($commercialAddons, 'future_niche_modules.modules', []))->toBe(['future_niche_modules']);
});

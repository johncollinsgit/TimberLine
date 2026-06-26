<?php

test('wholesale storefront access is tag-driven only', function (): void {
    $guardPath = dirname(base_path()).DIRECTORY_SEPARATOR.'modernforestrywholesale-theme'.DIRECTORY_SEPARATOR.'snippets'.DIRECTORY_SEPARATOR.'wholesale-guard.liquid';
    if (! is_file($guardPath)) {
        $guardPath = base_path('tests/Fixtures/shopify/wholesale-guard.liquid');
    }

    $guard = file_get_contents($guardPath);

    expect($guard)->not->toBeFalse()
        ->and((string) $guard)->toContain("customer.tags contains 'wholesale'")
        ->and((string) $guard)->not->toContain('orders_count > 0')
        ->and((string) $guard)->not->toContain('grandfathered_existing_customer');
});

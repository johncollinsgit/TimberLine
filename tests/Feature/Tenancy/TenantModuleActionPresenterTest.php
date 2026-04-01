<?php

use App\Support\Tenancy\TenantModuleActionPresenter;

test('tenant module action presenter maps locked add-on modules to add-module CTA state', function () {
    $presented = TenantModuleActionPresenter::present([
        'module_key' => 'sms',
        'label' => 'SMS',
        'ui_state' => 'locked',
        'setup_status' => 'not_started',
        'reason' => 'add_on_required',
        'cta' => 'add',
        'source' => 'flag',
        'upgrade_prompt_eligible' => true,
    ], null, [
        'store_route' => 'shopify.app.store',
        'plans_route' => 'shopify.app.plans',
        'contact_route' => 'platform.contact',
    ]);

    expect($presented['reason_label'])->toBe('Available as an add-on')
        ->and($presented['cta_label'])->toBe('Add module')
        ->and($presented['cta_target'])->toBe('app_store')
        ->and($presented['self_serve_eligible'])->toBeTrue()
        ->and($presented['cta_href'])->toContain('/shopify/app/store');
});

test('tenant module action presenter maps contact-sales modules to request CTA state', function () {
    $presented = TenantModuleActionPresenter::present([
        'module_key' => 'ai',
        'label' => 'AI / Intelligence',
        'ui_state' => 'coming_soon',
        'setup_status' => 'not_started',
        'reason' => 'contact_sales_required',
        'cta' => 'request',
        'source' => 'flag',
    ], null, [
        'contact_route' => 'platform.contact',
    ]);

    expect($presented['reason_label'])->toBe('Contact sales')
        ->and($presented['cta_label'])->toBe('Contact sales');
});

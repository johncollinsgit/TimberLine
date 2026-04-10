<?php

use App\Support\Tenancy\TenantModuleUi;

test('tenant module ui presenter exposes locked upgrade prompt metadata', function () {
    $presented = TenantModuleUi::present([
        'module_key' => 'ai',
        'label' => 'AI Assistant',
        'ui_state' => 'locked',
        'setup_status' => 'not_started',
        'upgrade_prompt_eligible' => true,
    ]);

    expect($presented['module_key'])->toBe('ai')
        ->and($presented['state_label'])->toBe('Locked')
        ->and($presented['upgrade_prompt_eligible'])->toBeTrue()
        ->and($presented['show_upgrade_prompt'])->toBeTrue()
        ->and($presented['tone'])->toBe('critical');
});

test('tenant module ui presenter keeps setup-needed details distinct from access state', function () {
    $presented = TenantModuleUi::present([
        'module_key' => 'campaigns',
        'label' => 'Campaigns',
        'ui_state' => 'setup_needed',
        'setup_status' => 'in_progress',
        'has_access' => true,
        'upgrade_prompt_eligible' => false,
    ]);

    expect($presented['ui_state'])->toBe('setup_needed')
        ->and($presented['state_label'])->toBe('Needs Setup')
        ->and($presented['setup_status_label'])->toBe('In Progress')
        ->and($presented['show_upgrade_prompt'])->toBeFalse()
        ->and($presented['description'])->toContain('in progress');
});

test('tenant module ui checklist groups setup locked and coming-soon modules', function () {
    $checklist = TenantModuleUi::checklist([
        'customers' => [
            'module_key' => 'customers',
            'label' => 'Customers',
            'ui_state' => 'active',
            'setup_status' => 'configured',
            'has_access' => true,
        ],
        'campaigns' => [
            'module_key' => 'campaigns',
            'label' => 'Campaigns',
            'ui_state' => 'setup_needed',
            'setup_status' => 'not_started',
            'has_access' => true,
        ],
        'ai' => [
            'module_key' => 'ai',
            'label' => 'AI Assistant',
            'ui_state' => 'locked',
            'setup_status' => 'not_started',
            'has_access' => false,
            'upgrade_prompt_eligible' => true,
        ],
        'vip' => [
            'module_key' => 'vip',
            'label' => 'VIP',
            'ui_state' => 'coming_soon',
            'setup_status' => 'not_started',
            'coming_soon' => true,
            'has_access' => true,
        ],
    ], ['customers', 'campaigns', 'ai', 'vip']);

    expect($checklist['counts']['total'])->toBe(4)
        ->and($checklist['counts']['active'])->toBe(1)
        ->and($checklist['counts']['setup'])->toBe(1)
        ->and($checklist['counts']['locked'])->toBe(1)
        ->and($checklist['counts']['coming_soon'])->toBe(1)
        ->and($checklist['next_actions'])->toContain('Finish setup for modules marked as Needs Setup.')
        ->and($checklist['next_actions'])->toContain('Review locked modules and trigger upgrade prompts where eligible.')
        ->and($checklist['next_actions'])->toContain('Track coming-soon modules separately from live setup tasks.');
});

@props([
    'moduleState' => null,
    'label' => null,
    'size' => 'md',
    'compact' => false,
    'hideActive' => false,
])

@php
    $presented = \App\Support\Tenancy\TenantModuleUi::present(
        is_array($moduleState) ? $moduleState : null,
        is_string($label) ? $label : null
    );

    $size = in_array($size, ['sm', 'md'], true) ? $size : 'md';
    $compactLabel = match ($presented['ui_state']) {
        'setup_needed' => 'Setup',
        'coming_soon' => 'Soon',
        default => $presented['state_label'],
    };
    $displayLabel = $compact ? $compactLabel : $presented['state_label'];
@endphp

@if(! ($hideActive && ($presented['ui_state'] ?? '') === 'active'))
    @once
        <style>
            .tenant-module-state-badge {
                --tenant-module-state-bg: rgba(226, 232, 240, 0.7);
                --tenant-module-state-border: rgba(148, 163, 184, 0.4);
                --tenant-module-state-text: rgba(15, 23, 42, 0.78);
                --tenant-module-state-dot: rgba(71, 85, 105, 0.84);

                display: inline-flex;
                align-items: center;
                gap: 6px;
                border-radius: 999px;
                border: 1px solid var(--tenant-module-state-border);
                background: var(--tenant-module-state-bg);
                color: var(--tenant-module-state-text);
                font-weight: 700;
                letter-spacing: 0.02em;
                white-space: nowrap;
            }

            .tenant-module-state-badge--sm {
                min-height: 20px;
                padding: 0 8px;
                font-size: 10px;
            }

            .tenant-module-state-badge--md {
                min-height: 24px;
                padding: 0 10px;
                font-size: 11px;
            }

            .tenant-module-state-badge__dot {
                width: 7px;
                height: 7px;
                border-radius: 999px;
                background: var(--tenant-module-state-dot);
                flex: none;
            }

            .tenant-module-state-badge--active {
                --tenant-module-state-bg: rgba(13, 148, 136, 0.14);
                --tenant-module-state-border: rgba(13, 148, 136, 0.32);
                --tenant-module-state-text: #0f766e;
                --tenant-module-state-dot: #0f766e;
            }

            .tenant-module-state-badge--setup_needed {
                --tenant-module-state-bg: rgba(180, 83, 9, 0.14);
                --tenant-module-state-border: rgba(180, 83, 9, 0.3);
                --tenant-module-state-text: #92400e;
                --tenant-module-state-dot: #c2410c;
            }

            .tenant-module-state-badge--locked {
                --tenant-module-state-bg: rgba(190, 24, 93, 0.14);
                --tenant-module-state-border: rgba(190, 24, 93, 0.28);
                --tenant-module-state-text: #9f1239;
                --tenant-module-state-dot: #be123c;
            }

            .tenant-module-state-badge--coming_soon {
                --tenant-module-state-bg: rgba(3, 105, 161, 0.12);
                --tenant-module-state-border: rgba(3, 105, 161, 0.26);
                --tenant-module-state-text: #075985;
                --tenant-module-state-dot: #0284c7;
            }
        </style>
    @endonce

    <span
        class="tenant-module-state-badge tenant-module-state-badge--{{ $presented['ui_state'] }} tenant-module-state-badge--{{ $size }}"
        data-module-state="{{ $presented['ui_state'] }}"
        title="{{ $presented['description'] }}"
    >
        <span class="tenant-module-state-badge__dot" aria-hidden="true"></span>
        <span>{{ $displayLabel }}</span>
    </span>
@endif


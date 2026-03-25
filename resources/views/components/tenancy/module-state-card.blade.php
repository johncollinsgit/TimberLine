@props([
    'moduleState' => null,
    'title' => null,
    'description' => null,
    'showDescription' => true,
])

@php
    $presented = \App\Support\Tenancy\TenantModuleUi::present(
        is_array($moduleState) ? $moduleState : null,
        is_string($title) ? $title : null
    );
    $resolvedTitle = trim((string) ($title ?? $presented['label']));
    $resolvedDescription = trim((string) ($description ?? $presented['description']));
@endphp

@once
    <style>
        .tenant-module-state-card {
            border-radius: 16px;
            border: 1px solid rgba(15, 23, 42, 0.08);
            background: rgba(255, 255, 255, 0.94);
            box-shadow: 0 14px 28px rgba(15, 23, 42, 0.05);
            padding: 14px 16px;
            display: grid;
            gap: 10px;
        }

        .tenant-module-state-card__head {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 10px;
        }

        .tenant-module-state-card__title {
            margin: 0;
            font-size: 14px;
            font-weight: 700;
            color: rgba(15, 23, 42, 0.92);
            line-height: 1.3;
        }

        .tenant-module-state-card__meta {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 6px;
        }

        .tenant-module-state-card__setup {
            display: inline-flex;
            align-items: center;
            border-radius: 999px;
            border: 1px solid rgba(15, 23, 42, 0.1);
            padding: 4px 8px;
            font-size: 10px;
            font-weight: 700;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: rgba(15, 23, 42, 0.62);
            background: rgba(248, 250, 252, 0.96);
        }

        .tenant-module-state-card__description {
            margin: 0;
            font-size: 12px;
            line-height: 1.55;
            color: rgba(15, 23, 42, 0.66);
        }
    </style>
@endonce

<article
    class="tenant-module-state-card"
    data-module-key="{{ $presented['module_key'] }}"
    data-module-state="{{ $presented['ui_state'] }}"
>
    <header class="tenant-module-state-card__head">
        <h3 class="tenant-module-state-card__title">{{ $resolvedTitle }}</h3>
        <x-tenancy.module-state-badge :module-state="$presented" size="sm" />
    </header>

    <div class="tenant-module-state-card__meta">
        <span class="tenant-module-state-card__setup">{{ $presented['setup_status_label'] }}</span>
    </div>

    @if($showDescription && $resolvedDescription !== '')
        <p class="tenant-module-state-card__description">{{ $resolvedDescription }}</p>
    @endif

    @if(trim((string) $slot) !== '')
        <div>{{ $slot }}</div>
    @endif
</article>


@props([
    'module' => [],
    'moduleState' => null,
    'focused' => false,
    'technicalDetails' => true,
])

@php
    $module = is_array($module) ? $module : [];
    $moduleState = is_array($moduleState) ? $moduleState : (is_array($module['module_state'] ?? null) ? (array) $module['module_state'] : []);
    $buyerSetup = is_array($module['buyer_setup'] ?? null) ? (array) $module['buyer_setup'] : [];
    $moduleKey = trim((string) ($module['module_key'] ?? $module['key'] ?? ''));
    $displayName = trim((string) ($module['display_name'] ?? $module['title'] ?? Str::headline($moduleKey)));
    $description = trim((string) ($module['short_description'] ?? $module['description'] ?? ''));
    $outcome = trim((string) ($buyerSetup['outcome'] ?? $description));
    $bestFor = trim((string) ($buyerSetup['best_for'] ?? ''));
    $nextStep = trim((string) ($buyerSetup['next_step'] ?? data_get($moduleState, 'reason_description', data_get($moduleState, 'description', 'Review this module when you are ready.'))));
    $whatYouNeed = array_values(array_filter(array_map(
        static fn (mixed $item): string => trim((string) $item),
        (array) ($buyerSetup['what_you_need'] ?? [])
    )));
    $setupSteps = array_values(array_filter(array_map(
        static fn (mixed $item): string => trim((string) $item),
        (array) ($buyerSetup['setup_steps'] ?? [])
    )));
    $primaryAction = trim((string) ($buyerSetup['primary_action'] ?? data_get($moduleState, 'cta_label', 'Review module')));
    $helpText = trim((string) ($buyerSetup['help_text'] ?? ''));
@endphp

@once
    <style>
        .tenant-module-next-card {
            border-radius: 8px;
            border: 1px solid rgba(15, 23, 42, 0.1);
            background: rgba(255, 255, 255, 0.98);
            box-shadow: 0 14px 30px rgba(15, 23, 42, 0.06);
            padding: 16px;
            display: grid;
            gap: 14px;
        }

        .tenant-module-next-card[data-focused="true"] {
            border-color: rgba(14, 116, 144, 0.38);
            box-shadow: 0 18px 40px rgba(14, 116, 144, 0.14);
        }

        .tenant-module-next-card__head {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 12px;
        }

        .tenant-module-next-card__title {
            margin: 0;
            color: rgba(15, 23, 42, 0.94);
            font-size: 15px;
            font-weight: 800;
            line-height: 1.3;
        }

        .tenant-module-next-card__summary,
        .tenant-module-next-card__text,
        .tenant-module-next-card__list,
        .tenant-module-next-card__details {
            color: rgba(15, 23, 42, 0.7);
            font-size: 13px;
            line-height: 1.55;
        }

        .tenant-module-next-card__summary {
            margin: 0;
            color: rgba(15, 23, 42, 0.78);
        }

        .tenant-module-next-card__section {
            display: grid;
            gap: 6px;
        }

        .tenant-module-next-card__label {
            color: rgba(15, 23, 42, 0.58);
            font-size: 10px;
            font-weight: 800;
            letter-spacing: 0.08em;
            text-transform: uppercase;
        }

        .tenant-module-next-card__text {
            margin: 0;
        }

        .tenant-module-next-card__list {
            margin: 0;
            padding-left: 18px;
        }

        .tenant-module-next-card__list li + li {
            margin-top: 4px;
        }

        .tenant-module-next-card__steps {
            display: grid;
            gap: 6px;
            margin: 0;
            padding: 0;
            list-style: none;
        }

        .tenant-module-next-card__step {
            display: grid;
            grid-template-columns: 22px minmax(0, 1fr);
            gap: 8px;
            align-items: start;
            color: rgba(15, 23, 42, 0.72);
            font-size: 13px;
            line-height: 1.5;
        }

        .tenant-module-next-card__step-number {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 22px;
            height: 22px;
            border-radius: 999px;
            background: rgba(15, 118, 110, 0.12);
            color: #0f766e;
            font-size: 11px;
            font-weight: 800;
        }

        .tenant-module-next-card__actions {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            align-items: center;
        }

        .tenant-module-next-card__help {
            margin: 0;
            color: rgba(15, 23, 42, 0.58);
            font-size: 12px;
            line-height: 1.45;
        }

        .tenant-module-next-card__details {
            border-top: 1px solid rgba(15, 23, 42, 0.08);
            padding-top: 10px;
        }

        .tenant-module-next-card__details summary {
            cursor: pointer;
            color: rgba(15, 23, 42, 0.62);
            font-size: 12px;
            font-weight: 800;
        }

        .tenant-module-next-card__details-grid {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            margin-top: 10px;
        }

        .tenant-module-next-card__pill {
            display: inline-flex;
            align-items: center;
            border-radius: 999px;
            border: 1px solid rgba(15, 23, 42, 0.1);
            background: rgba(248, 250, 252, 0.96);
            padding: 4px 8px;
            color: rgba(15, 23, 42, 0.68);
            font-size: 11px;
            font-weight: 700;
        }
    </style>
@endonce

<article
    class="tenant-module-next-card"
    data-module-key="{{ $moduleKey }}"
    data-focused="{{ $focused ? 'true' : 'false' }}"
>
    <header class="tenant-module-next-card__head">
        <div>
            <h3 class="tenant-module-next-card__title">{{ $displayName }}</h3>
            @if($outcome !== '')
                <p class="tenant-module-next-card__summary">{{ $outcome }}</p>
            @endif
        </div>
        <x-tenancy.module-state-badge :module-state="$moduleState" size="sm" />
    </header>

    @if($bestFor !== '')
        <section class="tenant-module-next-card__section">
            <div class="tenant-module-next-card__label">What this does</div>
            <p class="tenant-module-next-card__text">{{ $bestFor }}</p>
        </section>
    @endif

    @if($nextStep !== '')
        <section class="tenant-module-next-card__section">
            <div class="tenant-module-next-card__label">Best next step</div>
            <p class="tenant-module-next-card__text">{{ $nextStep }}</p>
        </section>
    @endif

    @if($whatYouNeed !== [])
        <section class="tenant-module-next-card__section">
            <div class="tenant-module-next-card__label">What you need before setup</div>
            <ul class="tenant-module-next-card__list">
                @foreach($whatYouNeed as $item)
                    <li>{{ $item }}</li>
                @endforeach
            </ul>
        </section>
    @endif

    @if($setupSteps !== [])
        <section class="tenant-module-next-card__section">
            <div class="tenant-module-next-card__label">Setup steps</div>
            <ol class="tenant-module-next-card__steps">
                @foreach($setupSteps as $step)
                    <li class="tenant-module-next-card__step">
                        <span class="tenant-module-next-card__step-number">{{ $loop->iteration }}</span>
                        <span>{{ $step }}</span>
                    </li>
                @endforeach
            </ol>
        </section>
    @endif

    <div class="tenant-module-next-card__actions" aria-label="{{ $primaryAction }}">
        {{ $slot }}
    </div>

    @if($helpText !== '')
        <p class="tenant-module-next-card__help">{{ $helpText }}</p>
    @endif

    @if($technicalDetails)
        <details class="tenant-module-next-card__details">
            <summary>Plan and setup details</summary>
            <div class="tenant-module-next-card__details-grid">
                <span class="tenant-module-next-card__pill">{{ $module['category_label'] ?? 'Customer operations' }}</span>
                <span class="tenant-module-next-card__pill">{{ $module['lifecycle_label'] ?? 'Catalog' }}</span>
                <span class="tenant-module-next-card__pill">{{ $module['setup_effort_label'] ?? 'Standard setup' }}</span>
                <span class="tenant-module-next-card__pill">{{ $module['required_integrations_label'] ?? 'No required integration' }}</span>
                <span class="tenant-module-next-card__pill">Pricing: {{ $module['pricing_impact_label'] ?? 'Pricing guidance only' }}</span>
                <span class="tenant-module-next-card__pill">Access: {{ $module['entitlement_requirement_label'] ?? 'Access review required' }}</span>
                <span class="tenant-module-next-card__pill">Mobile: {{ $module['mobile_relevance_label'] ?? 'Not mobile-specific' }}</span>
            </div>
        </details>
    @endif
</article>

@props([
    'moduleStates' => [],
    'title' => 'Module setup checklist',
    'subtitle' => 'Track what is ready, what needs setup, and what is locked or still coming soon.',
    'moduleOrder' => [],
    'showActive' => true,
    'ctaHref' => null,
])

@php
    $checklist = \App\Support\Tenancy\TenantModuleUi::checklist(
        is_array($moduleStates) ? $moduleStates : [],
        is_array($moduleOrder) ? $moduleOrder : []
    );
@endphp

@once
    <style>
        .tenant-module-checklist {
            border-radius: 16px;
            border: 1px solid rgba(15, 23, 42, 0.1);
            background: rgba(255, 255, 255, 0.95);
            box-shadow: 0 16px 34px rgba(15, 23, 42, 0.06);
            padding: 16px;
            display: grid;
            gap: 14px;
        }

        .tenant-module-checklist__head h2 {
            margin: 0;
            font-size: 1rem;
            font-weight: 700;
            color: rgba(15, 23, 42, 0.95);
        }

        .tenant-module-checklist__head p {
            margin: 6px 0 0;
            font-size: 13px;
            line-height: 1.55;
            color: rgba(15, 23, 42, 0.68);
        }

        .tenant-module-checklist__summary {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }

        .tenant-module-checklist__count {
            display: inline-flex;
            align-items: center;
            border-radius: 999px;
            border: 1px solid rgba(15, 23, 42, 0.1);
            background: rgba(248, 250, 252, 0.96);
            padding: 5px 10px;
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 0.03em;
            color: rgba(15, 23, 42, 0.7);
        }

        .tenant-module-checklist__sections {
            display: grid;
            gap: 10px;
        }

        .tenant-module-checklist__section {
            border-radius: 12px;
            border: 1px solid rgba(15, 23, 42, 0.08);
            background: rgba(248, 250, 252, 0.94);
            padding: 12px;
            display: grid;
            gap: 8px;
        }

        .tenant-module-checklist__section h3 {
            margin: 0;
            font-size: 12px;
            font-weight: 700;
            letter-spacing: 0.1em;
            text-transform: uppercase;
            color: rgba(15, 23, 42, 0.62);
        }

        .tenant-module-checklist__list {
            display: grid;
            gap: 6px;
        }

        .tenant-module-checklist__row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
            font-size: 12px;
            color: rgba(15, 23, 42, 0.8);
        }

        .tenant-module-checklist__row-label {
            font-weight: 600;
        }

        .tenant-module-checklist__actions {
            display: grid;
            gap: 6px;
            font-size: 12px;
            color: rgba(15, 23, 42, 0.72);
        }

        .tenant-module-checklist__actions p {
            margin: 0;
        }

        .tenant-module-checklist__cta {
            color: #0f766e;
            text-decoration: none;
            font-weight: 700;
        }
    </style>
@endonce

<section class="tenant-module-checklist" data-module-checklist="true">
    <header class="tenant-module-checklist__head">
        <h2>{{ $title }}</h2>
        <p>{{ $subtitle }}</p>
    </header>

    <div class="tenant-module-checklist__summary" aria-label="Module state summary">
        <span class="tenant-module-checklist__count">Total {{ $checklist['counts']['total'] }}</span>
        <span class="tenant-module-checklist__count">Needs Setup {{ $checklist['counts']['setup'] }}</span>
        <span class="tenant-module-checklist__count">Locked {{ $checklist['counts']['locked'] }}</span>
        <span class="tenant-module-checklist__count">Soon {{ $checklist['counts']['coming_soon'] }}</span>
    </div>

    <div class="tenant-module-checklist__sections">
        @if(($checklist['setup'] ?? []) !== [])
            <section class="tenant-module-checklist__section">
                <h3>Needs Setup</h3>
                <div class="tenant-module-checklist__list">
                    @foreach($checklist['setup'] as $item)
                        <div class="tenant-module-checklist__row" data-module-state="setup_needed">
                            <span class="tenant-module-checklist__row-label">{{ $item['label'] }}</span>
                            <x-tenancy.module-state-badge :module-state="$item" size="sm" compact />
                        </div>
                    @endforeach
                </div>
            </section>
        @endif

        @if(($checklist['locked'] ?? []) !== [])
            <section class="tenant-module-checklist__section">
                <h3>Locked</h3>
                <div class="tenant-module-checklist__list">
                    @foreach($checklist['locked'] as $item)
                        <div class="tenant-module-checklist__row" data-module-state="locked">
                            <span class="tenant-module-checklist__row-label">{{ $item['label'] }}</span>
                            <x-tenancy.module-state-badge :module-state="$item" size="sm" compact />
                        </div>
                    @endforeach
                </div>
                @if(is_string($ctaHref) && trim($ctaHref) !== '' && collect($checklist['locked'])->contains(fn (array $item): bool => (bool) ($item['upgrade_prompt_eligible'] ?? false)))
                    <a class="tenant-module-checklist__cta" href="{{ $ctaHref }}">Review upgrade options</a>
                @endif
            </section>
        @endif

        @if(($checklist['coming_soon'] ?? []) !== [])
            <section class="tenant-module-checklist__section">
                <h3>Coming Soon</h3>
                <div class="tenant-module-checklist__list">
                    @foreach($checklist['coming_soon'] as $item)
                        <div class="tenant-module-checklist__row" data-module-state="coming_soon">
                            <span class="tenant-module-checklist__row-label">{{ $item['label'] }}</span>
                            <x-tenancy.module-state-badge :module-state="$item" size="sm" compact />
                        </div>
                    @endforeach
                </div>
            </section>
        @endif

        @if($showActive && ($checklist['active'] ?? []) !== [])
            <section class="tenant-module-checklist__section">
                <h3>Ready</h3>
                <div class="tenant-module-checklist__list">
                    @foreach($checklist['active'] as $item)
                        <div class="tenant-module-checklist__row" data-module-state="active">
                            <span class="tenant-module-checklist__row-label">{{ $item['label'] }}</span>
                            <x-tenancy.module-state-badge :module-state="$item" size="sm" :hide-active="false" />
                        </div>
                    @endforeach
                </div>
            </section>
        @endif
    </div>

    @if(($checklist['next_actions'] ?? []) !== [])
        <div class="tenant-module-checklist__actions" aria-label="Recommended next actions">
            @foreach($checklist['next_actions'] as $action)
                <p>{{ $action }}</p>
            @endforeach
        </div>
    @endif
</section>

@php
    $theme = $theme ?? 'backstage';
    $wireNavigate = (bool) ($wireNavigate ?? false);
    $overview = $overview ?? [];
    $resolvedRewardsLabel = trim((string) ($rewardsLabel ?? data_get($displayLabels ?? [], 'rewards_label', data_get($overview, 'program_name', 'Rewards'))));
    if ($resolvedRewardsLabel === '') {
        $resolvedRewardsLabel = 'Rewards';
    }
    $resolvedRewardsLabelLower = strtolower($resolvedRewardsLabel);
    $resolvedRewardsBalanceLabel = trim((string) ($rewardsBalanceLabel ?? data_get($displayLabels ?? [], 'rewards_balance_label', $resolvedRewardsLabel . ' balance')));
    if ($resolvedRewardsBalanceLabel === '') {
        $resolvedRewardsBalanceLabel = $resolvedRewardsLabel . ' balance';
    }

    $earnPreview = collect(data_get($overview, 'earn_preview', []));
    $redeemPreview = collect(data_get($overview, 'redeem_preview', []));
    $earningModes = collect(data_get($overview, 'earning_modes', []))
        ->filter()
        ->take(2)
        ->implode(' + ');
    $statusCards = [
        [
            'label' => 'Program label',
            'value' => data_get($overview, 'program_name', $resolvedRewardsLabel),
            'detail' => 'The live program label customers see across this workspace.',
        ],
        [
            'label' => 'Ways to earn',
            'value' => data_get($overview, 'earning_rules_active') ? 'Active' : 'Not active',
            'detail' => number_format((int) data_get($overview, 'earning_rule_count', 0)).' live earn rules currently configured.',
        ],
        [
            'label' => 'Ways to redeem',
            'value' => data_get($overview, 'redeem_rules_active') ? 'Active' : 'Not active',
            'detail' => number_format((int) data_get($overview, 'redeem_rule_count', 0)).' live reward rows currently available.',
        ],
        [
            'label' => 'Value model',
            'value' => data_get($overview, 'measurement_label', 'Configured'),
            'detail' => 'How rewards are measured and redeemed across the live program.',
        ],
        [
            'label' => 'Program structure',
            'value' => $earningModes !== '' ? $earningModes : 'Task-based',
            'detail' => 'Customers earn through configured tasks, then spend '.$resolvedRewardsBalanceLabel.' on available offers.',
        ],
    ];

    $taskPanels = [
        [
            'eyebrow' => 'Tasks',
            'title' => 'Ways to Earn',
            'copy' => 'Review the live tasks customers can currently complete to earn '.$resolvedRewardsLabelLower.'.',
            'button' => 'Open Ways to Earn',
            'href' => $earnUrl,
            'rows' => $earnPreview,
            'empty' => 'No live earn rules are active yet.',
        ],
        [
            'eyebrow' => 'Tasks',
            'title' => 'Ways to Redeem',
            'copy' => 'Review the rows customers can currently redeem with their '.strtolower($resolvedRewardsBalanceLabel).'.',
            'button' => 'Open Ways to Redeem',
            'href' => $redeemUrl,
            'rows' => $redeemPreview,
            'empty' => 'No live reward rows are active yet.',
        ],
    ];

    $classes = $theme === 'embedded'
        ? [
            'stack' => 'rewards-overview-stack',
            'intro' => 'rewards-overview-card',
            'intro_body' => 'rewards-overview-intro-body',
            'eyebrow' => 'rewards-overview-eyebrow',
            'title' => 'rewards-overview-title',
            'copy' => 'rewards-overview-copy',
            'copy_spaced' => 'rewards-overview-copy rewards-overview-copy-spaced',
            'overview' => 'rewards-overview-card',
            'overview_header' => 'rewards-overview-header',
            'overview_grid' => 'rewards-overview-grid',
            'summary_card' => 'rewards-overview-summary-card',
            'summary_label' => 'rewards-overview-summary-label',
            'summary_value' => 'rewards-overview-summary-value',
            'summary_detail' => 'rewards-overview-summary-detail',
            'structure' => 'rewards-overview-structure',
            'panel' => 'rewards-overview-panel',
            'panel_head' => 'rewards-overview-panel-head',
            'panel_text' => 'rewards-overview-panel-text',
            'button' => 'rewards-overview-button',
            'previews' => 'rewards-overview-previews',
            'preview' => 'rewards-overview-preview',
            'preview_title' => 'rewards-overview-preview-title',
            'preview_detail' => 'rewards-overview-preview-detail',
            'note' => 'rewards-overview-card',
            'note_body' => 'rewards-overview-note-body',
        ]
        : [
            'stack' => 'space-y-6',
            'intro' => 'fb-page-surface p-6',
            'intro_body' => 'max-w-3xl space-y-4',
            'eyebrow' => 'fb-kpi-label',
            'title' => 'mt-2 text-lg font-semibold text-zinc-950',
            'copy' => 'text-sm leading-7 text-zinc-600',
            'copy_spaced' => 'mt-3 text-sm leading-7 text-zinc-600',
            'overview' => 'fb-page-surface fb-page-surface--subtle p-6',
            'overview_header' => 'max-w-2xl',
            'overview_grid' => 'mt-6 grid gap-4 md:grid-cols-2 xl:grid-cols-4',
            'summary_card' => 'fb-surface-inset p-5',
            'summary_label' => 'fb-kpi-label',
            'summary_value' => 'mt-3 text-2xl font-semibold text-zinc-950',
            'summary_detail' => 'mt-2 text-sm leading-6 text-zinc-600',
            'structure' => 'grid gap-4 xl:grid-cols-2',
            'panel' => 'fb-page-surface fb-page-surface--subtle p-6',
            'panel_head' => 'flex items-start justify-between gap-4',
            'panel_text' => 'max-w-xl',
            'button' => 'fb-btn-soft fb-link-soft',
            'previews' => 'mt-5 space-y-3',
            'preview' => 'fb-surface-inset px-4 py-3',
            'preview_title' => 'font-medium text-zinc-950',
            'preview_detail' => 'mt-1 text-sm text-zinc-600',
            'note' => 'fb-page-surface fb-page-surface--muted p-6',
            'note_body' => 'max-w-3xl',
        ];
@endphp

@if($theme === 'embedded')
    <div class="{{ $classes['stack'] }}">
        <section class="{{ $classes['intro'] }}">
            <div class="{{ $classes['intro_body'] }}">
                <div>
                    <div class="{{ $classes['eyebrow'] }}">Program overview</div>
                    <h2 class="{{ $classes['title'] }}">{{ $resolvedRewardsLabel }}</h2>
                </div>
                <p class="{{ $classes['copy'] }}">
                    This page reflects the live {{ $resolvedRewardsLabel === 'Candle Cash' ? 'Candle Cash tasks and reward rows' : 'earn and redeem rows' }} currently managed by Backstage.
                </p>
                <p class="{{ $classes['copy'] }}">
                    Use it to quickly review how the {{ $resolvedRewardsLabelLower }} program is currently structured, including how customers earn and what offers are available.
                </p>
                <p class="{{ $classes['copy'] }}">
                    For now, use Ways to Earn and Ways to Redeem in the sidebar to review and manage the live task and reward rows already maintained in Backstage.
                </p>
            </div>
        </section>

        <section class="{{ $classes['overview'] }}">
            <div class="{{ $classes['overview_header'] }}">
                <div class="{{ $classes['eyebrow'] }}">Overview</div>
                <h2 class="{{ $classes['title'] }}">How {{ $resolvedRewardsLabelLower }} works today</h2>
                <p class="{{ $classes['copy_spaced'] }}">{{ data_get($overview, 'program_summary') }}</p>
            </div>

            <div class="{{ $classes['overview_grid'] }}">
                @foreach($statusCards as $card)
                    <article class="{{ $classes['summary_card'] }}">
                        <div class="{{ $classes['summary_label'] }}">{{ $card['label'] }}</div>
                        <div class="{{ $classes['summary_value'] }}">{{ $card['value'] }}</div>
                        <p class="{{ $classes['summary_detail'] }}">{{ $card['detail'] }}</p>
                    </article>
                @endforeach
            </div>
        </section>

        <section class="{{ $classes['structure'] }}">
            @foreach($taskPanels as $panel)
                <article class="{{ $classes['panel'] }}">
                    <div class="{{ $classes['panel_head'] }}">
                        <div class="{{ $classes['panel_text'] }}">
                            <div class="{{ $classes['eyebrow'] }}">{{ $panel['title'] }}</div>
                            <h2 class="{{ $classes['title'] }}">{{ $panel['title'] === 'Ways to Earn' ? 'Live earn rules' : 'Live reward rows' }}</h2>
                            <p class="{{ $classes['copy_spaced'] }}">
                                {{ $panel['copy'] }}
                            </p>
                        </div>
                        <a href="{{ $panel['href'] }}" @if($wireNavigate) wire:navigate @endif class="{{ $classes['button'] }}">
                            {{ $panel['button'] }}
                        </a>
                    </div>

                    @if(collect($panel['rows'])->isNotEmpty())
                        <div class="{{ $classes['previews'] }}">
                            @foreach($panel['rows'] as $row)
                                <div class="{{ $classes['preview'] }}">
                                    <div class="{{ $classes['preview_title'] }}">{{ $row['title'] }}</div>
                                    <div class="{{ $classes['preview_detail'] }}">{{ $row['detail'] }}</div>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </article>
            @endforeach
        </section>

        <section class="{{ $classes['note'] }}">
            <div class="{{ $classes['note_body'] }}">
                <div class="{{ $classes['eyebrow'] }}">Note</div>
                <p class="{{ $classes['copy_spaced'] }}">
                    This page is meant to stay simple. Use the linked rule editors when you need to review or update the detailed earn and redeem rows.
                </p>
            </div>
        </section>
    </div>
@else
    <div class="space-y-6">
        <section class="rounded-[1.8rem] border border-zinc-200 bg-zinc-50 p-6">
            <div class="max-w-3xl space-y-4">
                <div>
                    <div class="text-[11px] uppercase tracking-[0.24em] text-zinc-500">Rewards</div>
                    <h2 class="mt-2 text-lg font-semibold text-zinc-950">{{ $resolvedRewardsLabel }} Central</h2>
                </div>
                <p class="text-sm leading-7 text-zinc-600">
                    This page is split into Tasks and Status so it is easier to separate what you manage from what is currently live.
                </p>
                <p class="text-sm leading-7 text-zinc-600">
                    Use Tasks for actionable earn and redeem rules, and use Status for the current live program state.
                </p>
            </div>
        </section>

        <section class="rounded-[1.8rem] border border-zinc-200 bg-zinc-50 p-6">
            <div class="max-w-3xl">
                <div class="text-[11px] uppercase tracking-[0.24em] text-zinc-500">Tasks</div>
                <h2 class="mt-2 text-lg font-semibold text-zinc-950">Actionable earn and redeem rules</h2>
                <p class="mt-3 text-sm leading-7 text-zinc-600">
                    Review the live tasks and reward rows already powering {{ $resolvedRewardsLabelLower }}, then jump straight into the editors when you need to make a change.
                </p>
            </div>

            <div class="mt-6 grid gap-4 xl:grid-cols-2">
                @foreach($taskPanels as $panel)
                    <article class="rounded-[1.6rem] border border-zinc-200 bg-white p-5">
                        <div class="flex items-start justify-between gap-4">
                            <div class="max-w-xl">
                                <div class="text-[11px] uppercase tracking-[0.24em] text-zinc-500">{{ $panel['eyebrow'] }}</div>
                                <h3 class="mt-2 text-lg font-semibold text-zinc-950">{{ $panel['title'] }}</h3>
                                <p class="mt-3 text-sm leading-7 text-zinc-600">{{ $panel['copy'] }}</p>
                            </div>
                            <a href="{{ $panel['href'] }}" @if($wireNavigate) wire:navigate @endif class="inline-flex shrink-0 rounded-full border border-zinc-200 bg-zinc-50 px-4 py-2 text-xs font-semibold text-zinc-700">
                                {{ $panel['button'] }}
                            </a>
                        </div>

                        <div class="mt-5 space-y-3">
                            @forelse($panel['rows'] as $row)
                                <div class="rounded-2xl border border-zinc-200 bg-zinc-50 px-4 py-3">
                                    <div class="font-medium text-zinc-950">{{ $row['title'] }}</div>
                                    <div class="mt-1 text-sm text-zinc-500">{{ $row['detail'] }}</div>
                                </div>
                            @empty
                                <div class="rounded-2xl border border-dashed border-zinc-200 bg-zinc-50 px-4 py-3 text-sm text-zinc-500">
                                    {{ $panel['empty'] }}
                                </div>
                            @endforelse
                        </div>
                    </article>
                @endforeach
            </div>
        </section>

        <section class="rounded-[1.8rem] border border-zinc-200 bg-zinc-50 p-6">
            <div class="max-w-3xl">
                <div class="text-[11px] uppercase tracking-[0.24em] text-zinc-500">Status</div>
                <h2 class="mt-2 text-lg font-semibold text-zinc-950">Current live program state</h2>
                <p class="mt-3 text-sm leading-7 text-zinc-600">
                    Use this section to confirm the current program label, value model, and whether earn and redeem rules are active.
                </p>
            </div>

            <div class="mt-6 grid gap-4 md:grid-cols-2 xl:grid-cols-5">
                @foreach($statusCards as $card)
                    <article class="rounded-[1.6rem] border border-zinc-200 bg-white p-5">
                        <div class="text-[11px] uppercase tracking-[0.24em] text-zinc-500">{{ $card['label'] }}</div>
                        <div class="mt-3 text-xl font-semibold text-zinc-950">{{ $card['value'] }}</div>
                        <p class="mt-2 text-sm leading-6 text-zinc-600">{{ $card['detail'] }}</p>
                    </article>
                @endforeach
            </div>

            <div class="mt-6 rounded-[1.6rem] border border-zinc-200 bg-white p-5">
                <div class="text-[11px] uppercase tracking-[0.24em] text-zinc-500">Status note</div>
                <p class="mt-3 text-sm leading-7 text-zinc-600">{{ data_get($overview, 'program_summary') }}</p>
            </div>
        </section>
    </div>
@endif

@php
    $theme = $theme ?? 'backstage';
    $wireNavigate = (bool) ($wireNavigate ?? false);
    $overview = $overview ?? [];

    $earnPreview = collect(data_get($overview, 'earn_preview', []));
    $redeemPreview = collect(data_get($overview, 'redeem_preview', []));
    $earningModes = collect(data_get($overview, 'earning_modes', []))
        ->filter()
        ->take(2)
        ->implode(' + ');

    $cards = [
        [
            'label' => 'Program name',
            'value' => data_get($overview, 'program_name', 'Candle Cash'),
            'detail' => 'The live Candle Cash label customers see across the program.',
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
            'label' => 'Program structure',
            'value' => $earningModes !== '' ? $earningModes : 'Task-based',
            'detail' => 'Customers earn through configured tasks, then spend Candle Cash on available rewards.',
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
            'intro' => 'rounded-[1.8rem] border border-white/10 bg-black/15 p-6',
            'intro_body' => 'max-w-3xl space-y-4',
            'eyebrow' => 'text-[11px] uppercase tracking-[0.24em] text-white/45',
            'title' => 'mt-2 text-lg font-semibold text-white',
            'copy' => 'text-sm leading-7 text-white/72',
            'copy_spaced' => 'mt-3 text-sm leading-7 text-white/72',
            'overview' => 'rounded-[1.8rem] border border-white/10 bg-black/15 p-6',
            'overview_header' => 'max-w-2xl',
            'overview_grid' => 'mt-6 grid gap-4 md:grid-cols-2 xl:grid-cols-4',
            'summary_card' => 'rounded-[1.6rem] border border-white/10 bg-white/5 p-5',
            'summary_label' => 'text-[11px] uppercase tracking-[0.24em] text-white/45',
            'summary_value' => 'mt-3 text-2xl font-semibold text-white',
            'summary_detail' => 'mt-2 text-sm leading-6 text-white/62',
            'structure' => 'grid gap-4 xl:grid-cols-2',
            'panel' => 'rounded-[1.8rem] border border-white/10 bg-black/15 p-6',
            'panel_head' => 'flex items-start justify-between gap-4',
            'panel_text' => 'max-w-xl',
            'button' => 'inline-flex shrink-0 rounded-full border border-white/10 bg-white/5 px-4 py-2 text-xs font-semibold text-white/80',
            'previews' => 'mt-5 space-y-3',
            'preview' => 'rounded-2xl border border-white/10 bg-white/5 px-4 py-3',
            'preview_title' => 'font-medium text-white',
            'preview_detail' => 'mt-1 text-sm text-white/55',
            'note' => 'rounded-[1.8rem] border border-white/10 bg-black/15 p-6',
            'note_body' => 'max-w-3xl',
        ];
@endphp

<div class="{{ $classes['stack'] }}">
    <section class="{{ $classes['intro'] }}">
        <div class="{{ $classes['intro_body'] }}">
            <div>
                <div class="{{ $classes['eyebrow'] }}">Program overview</div>
                <h2 class="{{ $classes['title'] }}">Rewards</h2>
            </div>
            <p class="{{ $classes['copy'] }}">
                This page reflects the live Candle Cash tasks and reward rows currently managed by Backstage.
            </p>
            <p class="{{ $classes['copy'] }}">
                Use it to quickly review how the Candle Cash program is currently structured, including how customers earn Candle Cash and what rewards are available.
            </p>
            <p class="{{ $classes['copy'] }}">
                For now, use Ways to Earn and Ways to Redeem in the sidebar to review and manage the live task and reward rows already maintained in Backstage.
            </p>
        </div>
    </section>

    <section class="{{ $classes['overview'] }}">
        <div class="{{ $classes['overview_header'] }}">
            <div class="{{ $classes['eyebrow'] }}">Overview</div>
            <h2 class="{{ $classes['title'] }}">How Candle Cash works today</h2>
            <p class="{{ $classes['copy_spaced'] }}">{{ data_get($overview, 'program_summary') }}</p>
        </div>

        <div class="{{ $classes['overview_grid'] }}">
            @foreach($cards as $card)
                <article class="{{ $classes['summary_card'] }}">
                    <div class="{{ $classes['summary_label'] }}">{{ $card['label'] }}</div>
                    <div class="{{ $classes['summary_value'] }}">{{ $card['value'] }}</div>
                    <p class="{{ $classes['summary_detail'] }}">{{ $card['detail'] }}</p>
                </article>
            @endforeach
        </div>
    </section>

    <section class="{{ $classes['structure'] }}">
        <article class="{{ $classes['panel'] }}">
            <div class="{{ $classes['panel_head'] }}">
                <div class="{{ $classes['panel_text'] }}">
                    <div class="{{ $classes['eyebrow'] }}">Ways to Earn</div>
                    <h2 class="{{ $classes['title'] }}">Live earn rules</h2>
                    <p class="{{ $classes['copy_spaced'] }}">
                        Review the live Candle Cash tasks customers can currently complete to earn Candle Cash.
                    </p>
                </div>
                <a href="{{ $earnUrl }}" @if($wireNavigate) wire:navigate @endif class="{{ $classes['button'] }}">
                    Open Ways to Earn
                </a>
            </div>

            @if($earnPreview->isNotEmpty())
                <div class="{{ $classes['previews'] }}">
                    @foreach($earnPreview as $row)
                        <div class="{{ $classes['preview'] }}">
                            <div class="{{ $classes['preview_title'] }}">{{ $row['title'] }}</div>
                            <div class="{{ $classes['preview_detail'] }}">{{ $row['detail'] }}</div>
                        </div>
                    @endforeach
                </div>
            @endif
        </article>

        <article class="{{ $classes['panel'] }}">
            <div class="{{ $classes['panel_head'] }}">
                <div class="{{ $classes['panel_text'] }}">
                    <div class="{{ $classes['eyebrow'] }}">Ways to Redeem</div>
                    <h2 class="{{ $classes['title'] }}">Live reward rows</h2>
                    <p class="{{ $classes['copy_spaced'] }}">
                        Review the reward rows customers can currently redeem with their Candle Cash balance.
                    </p>
                </div>
                <a href="{{ $redeemUrl }}" @if($wireNavigate) wire:navigate @endif class="{{ $classes['button'] }}">
                    Open Ways to Redeem
                </a>
            </div>

            @if($redeemPreview->isNotEmpty())
                <div class="{{ $classes['previews'] }}">
                    @foreach($redeemPreview as $row)
                        <div class="{{ $classes['preview'] }}">
                            <div class="{{ $classes['preview_title'] }}">{{ $row['title'] }}</div>
                            <div class="{{ $classes['preview_detail'] }}">{{ $row['detail'] }}</div>
                        </div>
                    @endforeach
                </div>
            @endif
        </article>
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

<x-layouts::app :title="$currentSection['label']">
    @php
        $payload = is_array($moduleStorePayload ?? null) ? $moduleStorePayload : [];
        $currentPlan = is_array($payload['current_plan'] ?? null) ? $payload['current_plan'] : ['label' => 'your current plan'];
        $blueprintRecommendations = is_array($payload['blueprint_recommendations'] ?? null) ? $payload['blueprint_recommendations'] : [];
        $blueprintContext = is_array($blueprintRecommendations['context'] ?? null) ? $blueprintRecommendations['context'] : [];
        $blueprintRows = array_values((array) ($blueprintRecommendations['rows'] ?? []));
        $modules = collect((array) ($payload['modules'] ?? []))->values();
        $focusModule = strtolower(trim((string) request('module', '')));
        $categories = $modules
            ->groupBy(fn (array $module): string => (string) ($module['category'] ?? 'other'))
            ->map(fn ($items, string $key): array => [
                'key' => $key,
                'label' => (string) data_get($items->first(), 'category_label', str($key)->headline()),
                'count' => $items->count(),
            ])
            ->sortBy('label')
            ->values();
        $moduleCards = $modules->map(function (array $module) use ($focusModule): array {
            $state = is_array($module['module_state'] ?? null) ? (array) $module['module_state'] : [];
            $purchase = is_array($module['purchase'] ?? null) ? (array) $module['purchase'] : [];
            $buyerSetup = is_array($module['buyer_setup'] ?? null) ? (array) $module['buyer_setup'] : [];
            $moduleKey = (string) ($module['module_key'] ?? '');
            $cta = (string) ($state['cta'] ?? 'none');
            $action = null;

            if ($cta === 'add' && filled($purchase['addon_key'] ?? null)) {
                $action = ['kind' => 'purchase', 'label' => 'Review purchase', 'url' => route('billing.addons.checkout', ['addonKey' => $purchase['addon_key']])];
            } elseif ($cta === 'add') {
                $action = ['kind' => 'add', 'label' => (string) ($state['cta_label'] ?? 'Add Branch'), 'url' => route('marketing.modules.activate', ['moduleKey' => $moduleKey])];
            } elseif ($cta === 'request') {
                $action = ['kind' => 'request', 'label' => (string) ($state['cta_label'] ?? 'Request access'), 'url' => route('marketing.modules.request', ['moduleKey' => $moduleKey])];
            } elseif (filled($state['cta_href'] ?? null)) {
                $action = ['kind' => 'link', 'label' => (string) ($state['cta_label'] ?? 'Open Branch'), 'url' => (string) $state['cta_href']];
            }

            $price = trim((string) ($purchase['price_display'] ?? ''));
            if ($price === '') {
                $price = match ((string) ($module['state_bucket'] ?? '')) {
                    'active' => 'Included with your plan',
                    'upgrade' => 'Plan change needed',
                    'request' => 'Talk to us about pricing',
                    default => 'Pricing shown before you confirm',
                };
            }

            return [
                'key' => $moduleKey,
                'name' => (string) ($module['display_name'] ?? str($moduleKey)->headline()),
                'description' => (string) ($module['short_description'] ?? $module['description'] ?? ''),
                'category' => (string) ($module['category'] ?? 'other'),
                'category_label' => (string) ($module['category_label'] ?? 'Other'),
                'cover_image' => (string) ($module['cover_image'] ?? '/images/branch-covers/customer-operations.png'),
                'state_label' => (string) ($state['display_state_label'] ?? 'Available'),
                'price' => $price,
                'action' => $action,
                'outcome' => (string) ($buyerSetup['outcome'] ?? $module['description'] ?? ''),
                'best_for' => (string) ($buyerSetup['best_for'] ?? ''),
                'what_you_need' => array_values(array_filter(array_map('strval', (array) ($buyerSetup['what_you_need'] ?? [])))),
                'setup_steps' => array_values(array_filter(array_map('strval', (array) ($buyerSetup['setup_steps'] ?? [])))),
                'help_text' => (string) ($buyerSetup['help_text'] ?? ''),
                'focused' => $focusModule !== '' && $focusModule === $moduleKey,
            ];
        })->values();
    @endphp

    <style>
        [x-cloak] { display: none !important; }
        .branch-directory { max-width: 1440px; margin: 0 auto; color: var(--fb-text-primary, #0d1b1e); }
        .branch-directory__header { border-bottom: 1px solid var(--fb-border, #e7eceb); padding: .4rem 0 1.8rem; }
        .branch-directory__eyebrow { color: var(--fb-brand-2, #1e5a63); font-size: .71rem; font-weight: 800; letter-spacing: .13em; text-transform: uppercase; }
        .branch-directory__header h1 { font-size: clamp(1.85rem, 3vw, 2.55rem); letter-spacing: -.035em; line-height: 1.05; margin: .5rem 0 0; }
        .branch-directory__header p { color: var(--fb-text-secondary, #5d6b6a); font-size: .98rem; line-height: 1.55; margin: .7rem 0 0; max-width: 46rem; }
        .branch-directory__header-links { display: flex; flex-wrap: wrap; gap: .8rem; margin-top: 1rem; }
        .branch-directory__header-links a { color: var(--fb-brand, #123c43); font-size: .84rem; font-weight: 750; text-decoration: underline; text-underline-offset: 3px; }
        .branch-directory__guidance { align-items: baseline; border-left: 3px solid var(--fb-brand-2, #1e5a63); color: var(--fb-text-secondary, #5d6b6a); display: flex; flex-wrap: wrap; font-size: .82rem; gap: .35rem .6rem; line-height: 1.45; margin-top: 1rem; padding-left: .75rem; }
        .branch-directory__guidance strong { color: var(--fb-text-primary, #0d1b1e); }
        .branch-directory__layout { align-items: start; display: grid; gap: 2rem; grid-template-columns: 220px minmax(0, 1fr); padding-top: 1.5rem; }
        .branch-directory__filter-title { color: var(--fb-text-secondary, #5d6b6a); font-size: .68rem; font-weight: 800; letter-spacing: .12em; margin: 0 0 .65rem; text-transform: uppercase; }
        .branch-directory__categories { border-top: 1px solid var(--fb-border, #e7eceb); display: grid; }
        .branch-directory__category { align-items: center; background: transparent; border: 0; border-bottom: 1px solid var(--fb-border, #e7eceb); color: var(--fb-text-secondary, #5d6b6a); display: flex; font: inherit; font-size: .86rem; font-weight: 650; justify-content: space-between; min-height: 44px; padding: 0 .35rem; text-align: left; width: 100%; }
        .branch-directory__category:hover, .branch-directory__category[aria-pressed="true"] { background: #f2f6f5; color: var(--fb-brand, #123c43); }
        .branch-directory__category:focus-visible, .branch-directory__search:focus-visible, .branch-directory__button:focus-visible, .branch-directory__dialog-close:focus-visible { outline: 3px solid rgba(30, 90, 99, .3); outline-offset: 2px; }
        .branch-directory__category small { color: inherit; font-size: .72rem; }
        .branch-directory__search { background: white; border: 1px solid #b9c8c5; border-radius: 8px; color: inherit; font: inherit; min-height: 46px; padding: 0 .85rem; width: 100%; }
        .branch-directory__search::placeholder { color: #71807d; }
        .branch-directory__result-head { align-items: end; display: flex; gap: 1rem; justify-content: space-between; margin-bottom: 1rem; }
        .branch-directory__result-head h2 { font-size: 1.15rem; margin: 0; }
        .branch-directory__result-head p { color: var(--fb-text-secondary, #5d6b6a); font-size: .83rem; margin: 0; }
        .branch-directory__grid { display: grid; gap: 1.2rem; grid-template-columns: repeat(3, minmax(0, 1fr)); }
        .branch-directory__card { background: #fff; border: 1px solid var(--fb-border, #e7eceb); border-radius: 10px; display: flex; flex-direction: column; min-height: 100%; overflow: hidden; transition: border-color 150ms ease, box-shadow 150ms ease, transform 150ms ease; }
        .branch-directory__card:hover, .branch-directory__card[data-focused="true"] { border-color: #93aaa5; box-shadow: 0 16px 34px -26px rgba(13, 27, 30, .65); transform: translateY(-2px); }
        .branch-directory__image { background-color: #e5ecea; background-position: center; background-repeat: no-repeat; background-size: cover; border: 0; color: inherit; display: flex; height: 138px; overflow: hidden; padding: 0; position: relative; text-align: left; width: 100%; }
        a.branch-directory__image, button.branch-directory__image { cursor: pointer; text-decoration: none; }
        a.branch-directory__image:hover, button.branch-directory__image:hover { filter: brightness(.96); }
        a.branch-directory__image:focus-visible, button.branch-directory__image:focus-visible { outline: 3px solid rgba(30, 90, 99, .5); outline-offset: -4px; }
        .branch-directory__card-body { display: flex; flex: 1; flex-direction: column; gap: .75rem; padding: 1rem; }
        .branch-directory__meta { color: var(--fb-brand-2, #1e5a63); display: flex; font-size: .68rem; font-weight: 800; justify-content: space-between; letter-spacing: .09em; text-transform: uppercase; }
        .branch-directory__card h3 { font-size: 1.02rem; line-height: 1.2; margin: 0; }
        .branch-directory__card p { color: var(--fb-text-secondary, #5d6b6a); font-size: .84rem; line-height: 1.48; margin: 0; }
        .branch-directory__price { border-top: 1px solid var(--fb-border, #e7eceb); color: #182c30; font-size: .85rem; font-weight: 750; margin-top: auto; padding-top: .8rem; }
        .branch-directory__button, .branch-directory__dialog-close { background: #123c43; border: 1px solid #123c43; border-radius: 8px; color: white; cursor: pointer; font: inherit; font-size: .84rem; font-weight: 750; min-height: 42px; padding: 0 .9rem; text-align: center; text-decoration: none; }
        .branch-directory__button:hover { background: #0d3036; }
        .branch-directory__button--quiet, .branch-directory__dialog-close { background: white; color: #234046; }
        .branch-directory__button--quiet:hover, .branch-directory__dialog-close:hover { background: #f2f6f5; }
        .branch-directory__empty { border: 1px dashed #b9c8c5; color: var(--fb-text-secondary, #5d6b6a); padding: 2.5rem 1.5rem; text-align: center; }
        .branch-directory__backdrop { align-items: center; background: rgba(13, 27, 30, .5); display: flex; inset: 0; justify-content: center; padding: 1rem; position: fixed; z-index: 80; }
        .branch-directory__dialog { background: white; border-radius: 12px; box-shadow: 0 24px 70px -20px rgba(0, 0, 0, .45); max-width: 580px; overflow: hidden; width: 100%; }
        .branch-directory__dialog-image { height: 150px; object-fit: cover; width: 100%; }
        .branch-directory__dialog-body { padding: 1.3rem; }
        .branch-directory__dialog-step { color: var(--fb-brand-2, #1e5a63); font-size: .7rem; font-weight: 800; letter-spacing: .1em; text-transform: uppercase; }
        .branch-directory__dialog h2 { font-size: 1.45rem; letter-spacing: -.025em; margin: .35rem 0 0; }
        .branch-directory__dialog p { color: var(--fb-text-secondary, #5d6b6a); font-size: .9rem; line-height: 1.55; }
        .branch-directory__dialog-list { color: var(--fb-text-secondary, #5d6b6a); display: grid; font-size: .88rem; gap: .6rem; line-height: 1.45; margin: 1rem 0 0; padding-left: 1.2rem; }
        .branch-directory__recommendations { display: grid; gap: .65rem; margin-top: 1rem; }
        .branch-directory__recommendation { border: 1px solid var(--fb-border, #e7eceb); padding: .8rem; }
        .branch-directory__recommendation strong { display: block; font-size: .9rem; }
        .branch-directory__recommendation span { color: var(--fb-text-secondary, #5d6b6a); display: block; font-size: .79rem; line-height: 1.45; margin-top: .2rem; }
        .branch-directory__dialog-actions { border-top: 1px solid var(--fb-border, #e7eceb); display: flex; gap: .65rem; justify-content: space-between; margin-top: 1.25rem; padding-top: 1rem; }
        .branch-directory__dialog-actions > div { display: flex; gap: .65rem; }
        @media (max-width: 1100px) { .branch-directory__grid { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
        @media (max-width: 760px) { .branch-directory__layout { display: block; } .branch-directory__filters { margin-bottom: 1.5rem; } .branch-directory__categories { display: flex; overflow-x: auto; } .branch-directory__category { border-top: 1px solid var(--fb-border, #e7eceb); border-right: 1px solid var(--fb-border, #e7eceb); flex: 0 0 auto; padding: 0 .75rem; white-space: nowrap; } .branch-directory__grid { grid-template-columns: 1fr; } .branch-directory__image { height: 160px; } }
    </style>

    <div class="branch-directory" x-data="{
        query: '', category: 'all', selected: null, guidanceOpen: false, wizardStep: 1, visibleCount: {{ $moduleCards->count() }},
        matches(el) { const text = (el.dataset.search || ''); return (this.category === 'all' || el.dataset.category === this.category) && text.includes(this.query.trim().toLowerCase()); },
        updateCount() { this.$nextTick(() => { this.visibleCount = [...this.$root.querySelectorAll('[data-branch-card]')].filter((el) => !el.hidden && getComputedStyle(el).display !== 'none').length; }); },
        setCategory(category) { this.category = category; this.updateCount(); },
        openBranch(branch) { this.selected = branch; this.wizardStep = 1; this.$nextTick(() => this.$refs.dialog?.focus()); },
        closeBranch() { this.selected = null; },
    }" x-init="updateCount()">
        <header class="branch-directory__header">
            <div class="branch-directory__eyebrow">Branches</div>
            <h1>Choose what helps your business grow next.</h1>
            <p>Add what your business needs next. Browse simple, purpose-built tools for your business. Every Branch explains what it does and its price before you make a change. Viewing a Branch never changes billing or access; checkout is not active here.</p>
            <div class="branch-directory__header-links">
                <a href="{{ route('custom-module-requests.create') }}">Request something custom</a>
                <a href="{{ route('custom-module-requests.create') }}">Request customization</a>
            </div>
            @if($blueprintRecommendations !== [])
                <div class="branch-directory__guidance">
                    <strong>Setup guidance</strong>
                    <span>Recommended for your setup</span>
                    <span>· {{ $blueprintContext['business_template_label'] ?? 'Workspace' }} setup profile</span>
                    @if((bool) ($blueprintContext['is_demo'] ?? false))
                        <span>· Demo tenant context</span>
                    @elseif((bool) ($blueprintContext['is_sandbox'] ?? false))
                        <span>· Sandbox tenant context</span>
                    @endif
                    <button type="button" class="branch-directory__button branch-directory__button--quiet" style="min-height: 32px; padding: 0 .55rem" @click="guidanceOpen = true">View guidance</button>
                </div>
            @endif
            <span class="sr-only">Access: Requires add-on access or a request.</span>
        </header>

        <div class="branch-directory__layout">
            <aside class="branch-directory__filters" aria-label="Filter Branches">
                <label class="branch-directory__filter-title" for="branch-search">Find a Branch</label>
                <input id="branch-search" class="branch-directory__search" type="search" x-model.debounce.100ms="query" @input="updateCount()" placeholder="Search Branches" autocomplete="off">
                <p class="branch-directory__filter-title" style="margin-top: 1.5rem">Categories</p>
                <div class="branch-directory__categories">
                    <button class="branch-directory__category" type="button" :aria-pressed="category === 'all'" @click="setCategory('all')">All Branches <small>{{ $moduleCards->count() }}</small></button>
                    @foreach($categories as $category)
                        <button class="branch-directory__category" type="button" :aria-pressed="category === @js($category['key'])" @click="setCategory(@js($category['key']))">{{ $category['label'] }} <small>{{ $category['count'] }}</small></button>
                    @endforeach
                </div>
            </aside>

            <main aria-live="polite" aria-atomic="true">
                <div class="branch-directory__result-head">
                    <div><h2>Browse Branches</h2><p x-text="visibleCount + (visibleCount === 1 ? ' Branch' : ' Branches') + ' shown'"></p></div>
                    <p>{{ $currentPlan['label'] ?? 'Your current plan' }}</p>
                </div>
                <div class="branch-directory__grid">
                    @foreach($moduleCards as $card)
                        <article class="branch-directory__card" data-branch-card data-focused="{{ $card['focused'] ? 'true' : 'false' }}" data-category="{{ $card['category'] }}" data-search="{{ strtolower(implode(' ', [$card['name'], $card['description'], $card['category_label'], $card['outcome']])) }}" x-show="matches($el)" x-cloak>
                            @php($imageAttributes = "style=\"background-image: url('{$card['cover_image']}')\"")
                            @if($card['action'] && $card['action']['kind'] === 'link')
                                <a class="branch-directory__image" {!! $imageAttributes !!} href="{{ $card['action']['url'] }}" aria-label="Open {{ $card['name'] }} Branch">
                            @elseif($card['action'])
                                <button class="branch-directory__image" {!! $imageAttributes !!} type="button" @click='openBranch(@json($card))' aria-label="Open {{ $card['name'] }} Branch">
                            @else
                                <div class="branch-directory__image" {!! $imageAttributes !!} role="img" aria-label="Preview of the {{ $card['name'] }} Branch page">
                            @endif
                            @if($card['action'] && $card['action']['kind'] === 'link')
                                </a>
                            @elseif($card['action'])
                                </button>
                            @else
                                </div>
                            @endif
                            <div class="branch-directory__card-body">
                                <div class="branch-directory__meta"><span>{{ $card['category_label'] }}</span><span>{{ $card['state_label'] }}</span></div>
                                <h3>{{ $card['name'] }}</h3>
                                <p>{{ $card['description'] }}</p>
                                <div class="branch-directory__price">{{ $card['price'] }}</div>
                                @if($card['action'])
                                    @if($card['action']['kind'] === 'link')
                                        <a class="branch-directory__button" href="{{ $card['action']['url'] }}">{{ $card['action']['label'] }}</a>
                                    @else
                                        <button class="branch-directory__button" type="button" @click='openBranch(@json($card))'>{{ $card['action']['label'] }}</button>
                                    @endif
                                @endif
                            </div>
                        </article>
                    @endforeach
                </div>
                <div class="branch-directory__empty" x-show="visibleCount === 0" x-cloak>
                    No Branches match that search. Try a different word or choose another category.
                </div>
            </main>
        </div>

        @if($blueprintRows !== [])
            <div class="branch-directory__backdrop" x-show="guidanceOpen" x-cloak @click.self="guidanceOpen = false" @keydown.escape.window="guidanceOpen = false" role="presentation">
                <section class="branch-directory__dialog" tabindex="-1" role="dialog" aria-modal="true" aria-label="Setup guidance">
                    <div class="branch-directory__dialog-body">
                        <div class="branch-directory__dialog-step">Your setup</div>
                        <h2>Recommended for your setup</h2>
                        <p>These are planning suggestions only. They do not change your access or billing.</p>
                        <div class="branch-directory__recommendations">
                            @foreach($blueprintRows as $row)
                                <div class="branch-directory__recommendation">
                                    <strong>{{ $row['label'] ?? 'Branch' }} · {{ $row['display_state_label'] ?? 'Planned' }}</strong>
                                    <span>{{ $row['reason'] ?? 'Recommended by your setup profile.' }}</span>
                                </div>
                            @endforeach
                        </div>
                        <div class="branch-directory__dialog-actions">
                            <button class="branch-directory__dialog-close" type="button" @click="guidanceOpen = false">Close</button>
                        </div>
                    </div>
                </section>
            </div>
        @endif

        <div class="branch-directory__backdrop" x-show="selected" x-cloak @click.self="closeBranch()" @keydown.escape.window="closeBranch()" role="presentation">
            <section class="branch-directory__dialog" x-ref="dialog" tabindex="-1" role="dialog" aria-modal="true" :aria-label="selected ? selected.name + ' setup' : 'Branch setup'">
                <img class="branch-directory__dialog-image" :src="selected?.cover_image" alt="">
                <div class="branch-directory__dialog-body">
                    <template x-if="wizardStep === 1">
                        <div>
                            <div class="branch-directory__dialog-step">Step 1 of 3 · Your choice</div>
                            <h2 x-text="selected?.name"></h2>
                            <p x-text="selected?.outcome"></p>
                            <p x-show="selected?.best_for"><strong>Best for:</strong> <span x-text="selected?.best_for"></span></p>
                        </div>
                    </template>
                    <template x-if="wizardStep === 2">
                        <div>
                            <div class="branch-directory__dialog-step">Step 2 of 3 · Prepare</div>
                            <h2>What you’ll need</h2>
                            <ul class="branch-directory__dialog-list"><template x-for="item in (selected?.what_you_need || [])" :key="item"><li x-text="item"></li></template></ul>
                            <p x-show="!(selected?.what_you_need || []).length">We’ll guide you through the essentials after you add this Branch.</p>
                        </div>
                    </template>
                    <template x-if="wizardStep === 3">
                        <div>
                            <div class="branch-directory__dialog-step">Step 3 of 3 · Confirm</div>
                            <h2>Here’s what happens next</h2>
                            <ol class="branch-directory__dialog-list"><template x-for="step in (selected?.setup_steps || []).slice(0, 4)" :key="step"><li x-text="step"></li></template></ol>
                            <p x-show="selected?.help_text" x-text="selected?.help_text"></p>
                        </div>
                    </template>
                    <div class="branch-directory__dialog-actions">
                        <button class="branch-directory__dialog-close" type="button" @click="closeBranch()">Cancel</button>
                        <div>
                            <button class="branch-directory__dialog-close" type="button" x-show="wizardStep > 1" @click="wizardStep--">Back</button>
                            <button class="branch-directory__button" type="button" x-show="wizardStep < 3" @click="wizardStep++">Continue</button>
                            <form x-show="wizardStep === 3" method="POST" :action="selected?.action?.url">
                                @csrf
                                <button class="branch-directory__button" type="submit" x-text="selected?.action?.kind === 'purchase' ? 'Continue to purchase' : selected?.action?.label"></button>
                            </form>
                        </div>
                    </div>
                </div>
            </section>
        </div>
    </div>
</x-layouts::app>

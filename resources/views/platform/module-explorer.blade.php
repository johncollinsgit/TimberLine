@php
    $catalog = is_array($catalog ?? null) ? $catalog : [];
    $modules = is_array($modules ?? null) ? $modules : [];
    $selectedModule = is_array($selectedModule ?? null) ? $selectedModule : null;
    $filters = is_array($filters ?? null) ? $filters : [];
    $categories = collect($modules)->pluck('category_label', 'category')->filter()->sort()->all();
    $integrations = collect($modules)->flatMap(fn (array $module): array => (array) ($module['required_integrations'] ?? []))->filter()->unique()->sort()->values();
    $setupEfforts = collect($modules)->pluck('setup_effort_label', 'setup_effort')->filter()->sort()->all();
    $industries = collect($modules)->flatMap(fn (array $module): array => (array) ($module['industry_relevance'] ?? []))->filter()->unique()->sort()->values();
    $selectedKey = (string) ($selectedModule['key'] ?? '');
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    @include('partials.head', [
        'title' => $selectedModule ? ($selectedModule['display_name'].' module') : 'Explore Everbranch modules',
        'description' => 'Browse Everbranch modules by business outcome, setup effort, integration, and industry without joining a workspace.',
    ])
</head>
<body class="fb-public-body eb-explorer-body eb-studio-support-body">
    <a class="eb-skip-link" href="#module-catalog">Skip to module catalog</a>

    <header class="eb-explorer-nav">
        <a href="{{ route('platform.promo') }}" class="eb-explorer-brand" aria-label="Everbranch home">
            <img src="{{ asset((string) config('everbranch.brand_assets.mark', 'brand/everbranch-mark.svg')) }}" alt="" />
            <span>Everbranch</span>
        </a>
        <nav aria-label="Module explorer navigation">
            <a href="{{ route('platform.modules.explore') }}" aria-current="page">Modules</a>
            <a href="{{ route('platform.contact', ['intent' => 'walkthrough']) }}">Guided walkthrough</a>
            <a href="{{ route('platform.plans') }}">Plans</a>
            <a href="{{ route('login') }}">Sign in</a>
        </nav>
    </header>

    <main class="eb-explorer-shell" data-module-explorer>
        <section class="eb-explorer-hero" aria-labelledby="explorer-title">
            <div>
                <p class="eb-explorer-kicker">Module Explorer</p>
                <h1 id="explorer-title">Find the part of Everbranch that solves today’s problem.</h1>
                <p>Browse in plain business language. Nothing here connects an account, changes a plan, or exposes a customer workspace.</p>
            </div>
            <div class="eb-explorer-hero-actions">
                <a class="fb-btn fb-btn-primary" href="{{ route('platform.contact', ['intent' => 'walkthrough']) }}">Request a guided walkthrough</a>
                <a class="fb-btn fb-btn-secondary" href="{{ route('platform.start') }}">Create a workspace</a>
            </div>
        </section>

        <section class="eb-explorer-controls" aria-label="Filter modules">
            <label class="eb-explorer-search">
                <span>Search modules</span>
                <input
                    type="search"
                    value="{{ $filters['query'] ?? '' }}"
                    placeholder="Try “calendar,” “customers,” or “invoices”"
                    autocomplete="off"
                    data-explorer-search
                />
            </label>
            <label>
                <span>Business area</span>
                <select data-explorer-filter="category">
                    <option value="">All areas</option>
                    @foreach($categories as $key => $label)
                        <option value="{{ $key }}" @selected(($filters['category'] ?? '') === $key)>{{ $label }}</option>
                    @endforeach
                </select>
            </label>
            <label>
                <span>Integration</span>
                <select data-explorer-filter="integration">
                    <option value="">Any integration</option>
                    @foreach($integrations as $integration)
                        <option value="{{ strtolower($integration) }}" @selected(strtolower((string) ($filters['integration'] ?? '')) === strtolower($integration))>{{ $integration }}</option>
                    @endforeach
                </select>
            </label>
            <label>
                <span>Setup effort</span>
                <select data-explorer-filter="setup">
                    <option value="">Any effort</option>
                    @foreach($setupEfforts as $key => $label)
                        <option value="{{ $key }}" @selected(($filters['setup'] ?? '') === $key)>{{ $label }}</option>
                    @endforeach
                </select>
            </label>
            <label>
                <span>Industry fit</span>
                <select data-explorer-filter="industry">
                    <option value="">Any industry</option>
                    @foreach($industries as $industry)
                        <option value="{{ strtolower($industry) }}" @selected(strtolower((string) ($filters['industry'] ?? '')) === strtolower($industry))>{{ $industry }}</option>
                    @endforeach
                </select>
            </label>
            <label>
                <span>Sort</span>
                <select data-explorer-sort>
                    <option value="recommended" @selected(($filters['sort'] ?? 'recommended') === 'recommended')>Recommended</option>
                    <option value="name" @selected(($filters['sort'] ?? '') === 'name')>Name</option>
                    <option value="effort" @selected(($filters['sort'] ?? '') === 'effort')>Setup effort</option>
                </select>
            </label>
        </section>

        <div class="eb-explorer-layout">
            <section id="module-catalog" class="eb-explorer-catalog" aria-labelledby="catalog-heading">
                <div class="eb-explorer-section-head">
                    <div>
                        <p class="eb-explorer-kicker">Catalog</p>
                        <h2 id="catalog-heading"><span data-explorer-count>{{ count($modules) }}</span> modules ready to explore</h2>
                    </div>
                    <button type="button" class="eb-explorer-clear" data-explorer-clear>Clear filters</button>
                </div>

                <div class="eb-module-list" data-explorer-list>
                    @foreach($modules as $position => $module)
                        @php
                            $buyerSetup = is_array($module['buyer_setup'] ?? null) ? $module['buyer_setup'] : [];
                            $searchText = strtolower(implode(' ', array_filter([
                                $module['display_name'] ?? '',
                                $module['description'] ?? '',
                                $buyerSetup['outcome'] ?? '',
                                $buyerSetup['best_for'] ?? '',
                                implode(' ', (array) ($module['data_used'] ?? [])),
                                implode(' ', (array) ($module['industry_relevance'] ?? [])),
                                implode(' ', (array) ($module['required_integrations'] ?? [])),
                            ])));
                            $isSelected = $selectedKey !== '' && $selectedKey === ($module['key'] ?? '');
                        @endphp
                        <article
                            class="eb-module-row {{ $isSelected ? 'is-selected' : '' }}"
                            data-module-row
                            data-name="{{ strtolower((string) ($module['display_name'] ?? '')) }}"
                            data-search="{{ $searchText }}"
                            data-category="{{ $module['category'] ?? '' }}"
                            data-integration="{{ strtolower(implode('|', (array) ($module['required_integrations'] ?? []))) }}"
                            data-setup="{{ $module['setup_effort'] ?? '' }}"
                            data-industry="{{ strtolower(implode('|', (array) ($module['industry_relevance'] ?? []))) }}"
                            data-position="{{ $position }}"
                        >
                            <a href="{{ route('platform.modules.show', ['module' => $module['key']]) }}" aria-label="Learn about {{ $module['display_name'] }}">
                                <div class="eb-module-row-main">
                                    <div class="eb-module-monogram" aria-hidden="true">{{ strtoupper(substr((string) ($module['display_name'] ?? 'E'), 0, 1)) }}</div>
                                    <div>
                                        <div class="eb-module-row-title">
                                            <h3>{{ $module['display_name'] }}</h3>
                                            <span>{{ $module['category_label'] }}</span>
                                        </div>
                                        <p>{{ $buyerSetup['outcome'] ?? $module['short_description'] ?? $module['description'] }}</p>
                                        <div class="eb-module-row-meta">
                                            <span>{{ $module['setup_effort_label'] }}</span>
                                            <span>{{ $module['required_integrations_label'] }}</span>
                                            <span>{{ data_get($module, 'purchase.price_display', $module['pricing_impact_label']) }}</span>
                                            <span>{{ $module['is_standalone'] ? 'Works on its own' : 'Builds on '.implode(', ', (array) $module['dependencies']) }}</span>
                                        </div>
                                    </div>
                                </div>
                                <span class="eb-module-row-arrow" aria-hidden="true">→</span>
                            </a>
                        </article>
                    @endforeach
                </div>

                <div class="eb-explorer-empty" data-explorer-empty hidden>
                    <div aria-hidden="true">⌕</div>
                    <h3>No modules match those filters.</h3>
                    <p>Try a broader business area or clear the filters to see the full catalog.</p>
                    <button type="button" class="fb-btn fb-btn-secondary" data-explorer-clear>Show all modules</button>
                </div>
            </section>

            <aside class="eb-module-detail" aria-label="Module details">
                @if($selectedModule)
                    @php $buyerSetup = is_array($selectedModule['buyer_setup'] ?? null) ? $selectedModule['buyer_setup'] : []; @endphp
                    <div class="eb-module-detail-head">
                        <a href="{{ route('platform.modules.explore', request()->only(['q', 'category', 'integration', 'setup', 'industry', 'sort'])) }}" class="eb-module-detail-back">← All modules</a>
                        <span class="eb-module-status">{{ $selectedModule['lifecycle_label'] }}</span>
                    </div>
                    <p class="eb-explorer-kicker">{{ $selectedModule['category_label'] }}</p>
                    <h2>{{ $selectedModule['display_name'] }}</h2>
                    <p class="eb-module-detail-lead">{{ $buyerSetup['outcome'] ?? $selectedModule['long_description'] ?? $selectedModule['description'] }}</p>

                    <dl class="eb-module-facts">
                        <div><dt>Best for</dt><dd>{{ $buyerSetup['best_for'] ?? 'Small teams that want clearer, connected daily work.' }}</dd></div>
                        <div><dt>Setup</dt><dd>{{ $selectedModule['setup_effort_label'] }}</dd></div>
                        <div><dt>Integrations</dt><dd>{{ $selectedModule['required_integrations_label'] }}</dd></div>
                        <div><dt>Mobile</dt><dd>{{ $selectedModule['mobile_relevance_label'] }}</dd></div>
                        <div><dt>Access</dt><dd>{{ $selectedModule['entitlement_requirement_label'] }}</dd></div>
                        <div><dt>Price</dt><dd>{{ data_get($selectedModule, 'purchase.price_display', $selectedModule['pricing_impact_label']) }}</dd></div>
                        <div><dt>Dependencies</dt><dd>{{ $selectedModule['is_standalone'] ? 'Standalone' : implode(', ', (array) $selectedModule['dependencies']) }}</dd></div>
                    </dl>

                    <section class="eb-module-detail-section">
                        <h3>What you can do</h3>
                        <ul>
                            @foreach((array) ($selectedModule['primary_actions'] ?? []) as $action)
                                <li>{{ $action }}</li>
                            @endforeach
                        </ul>
                    </section>

                    <section class="eb-module-detail-section">
                        <h3>Data it uses</h3>
                        <div class="eb-module-tags">
                            @foreach((array) ($selectedModule['data_used'] ?? []) as $data)
                                <span>{{ $data }}</span>
                            @endforeach
                        </div>
                    </section>

                    <section class="eb-module-detail-section">
                        <h3>What happens after you add it</h3>
                        <p>{{ $buyerSetup['next_step'] ?? 'Everbranch opens the module in your workspace and guides you through the required setup.' }}</p>
                    </section>

                    <div class="eb-module-detail-actions">
                        <a class="fb-btn fb-btn-primary" href="{{ route('platform.start', ['module' => $selectedModule['key']]) }}">
                            {{ $buyerSetup['primary_action'] ?? 'Start with this module' }}
                        </a>
                        <a class="fb-btn fb-btn-secondary" href="{{ route('platform.contact', ['intent' => 'walkthrough', 'module' => $selectedModule['key']]) }}">See it in a guided walkthrough</a>
                        <a class="eb-module-contact" href="{{ route('platform.contact', ['module' => $selectedModule['key']]) }}">Ask Everbranch a question</a>
                    </div>
                @else
                    <div class="eb-module-detail-placeholder">
                        <div class="eb-module-monogram" aria-hidden="true">E</div>
                        <h2>Choose a module to see how it works.</h2>
                        <p>You’ll see the business outcome, setup effort, data used, integrations, plan access, and the first steps after enabling it.</p>
                        <a href="{{ route('platform.contact', ['intent' => 'walkthrough']) }}" class="fb-btn fb-btn-secondary">Request a guided walkthrough</a>
                    </div>
                @endif
            </aside>
        </div>
    </main>

    <script>
        (() => {
            const root = document.querySelector('[data-module-explorer]');
            if (!root) return;

            const search = root.querySelector('[data-explorer-search]');
            const filters = Array.from(root.querySelectorAll('[data-explorer-filter]'));
            const sort = root.querySelector('[data-explorer-sort]');
            const list = root.querySelector('[data-explorer-list]');
            const rows = Array.from(root.querySelectorAll('[data-module-row]'));
            const count = root.querySelector('[data-explorer-count]');
            const empty = root.querySelector('[data-explorer-empty]');

            const normalize = value => String(value || '').trim().toLowerCase();
            const apply = () => {
                const query = normalize(search?.value);
                const values = Object.fromEntries(filters.map(control => [control.dataset.explorerFilter, normalize(control.value)]));
                let visible = 0;

                rows.forEach(row => {
                    const matches = (!query || normalize(row.dataset.search).includes(query))
                        && (!values.category || row.dataset.category === values.category)
                        && (!values.integration || normalize(row.dataset.integration).split('|').includes(values.integration))
                        && (!values.setup || row.dataset.setup === values.setup)
                        && (!values.industry || normalize(row.dataset.industry).split('|').includes(values.industry));
                    row.hidden = !matches;
                    if (matches) visible += 1;
                });

                const mode = sort?.value || 'recommended';
                const sorted = [...rows].sort((left, right) => {
                    if (mode === 'name') return left.dataset.name.localeCompare(right.dataset.name);
                    if (mode === 'effort') return left.dataset.setup.localeCompare(right.dataset.setup) || left.dataset.name.localeCompare(right.dataset.name);
                    return Number(left.dataset.position) - Number(right.dataset.position);
                });
                sorted.forEach(row => list?.appendChild(row));

                if (count) count.textContent = String(visible);
                if (empty) empty.hidden = visible !== 0;

                const url = new URL(window.location.href);
                const next = {q: search?.value || '', ...values, sort: mode};
                Object.entries(next).forEach(([key, value]) => value && value !== 'recommended'
                    ? url.searchParams.set(key, value)
                    : url.searchParams.delete(key));
                window.history.replaceState({}, '', `${url.pathname}${url.search}${url.hash}`);
            };

            let timer;
            search?.addEventListener('input', () => {
                clearTimeout(timer);
                timer = window.setTimeout(apply, 120);
            });
            filters.forEach(control => control.addEventListener('change', apply));
            sort?.addEventListener('change', apply);
            root.querySelectorAll('[data-explorer-clear]').forEach(button => button.addEventListener('click', () => {
                if (search) search.value = '';
                filters.forEach(control => control.value = '');
                if (sort) sort.value = 'recommended';
                apply();
                search?.focus();
            }));
            apply();
        })();
    </script>
</body>
</html>

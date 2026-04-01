@php
    $experience = is_array($dashboard['experience_profile'] ?? null) ? $dashboard['experience_profile'] : [];
    $workspace = is_array($experience['workspace'] ?? null) ? $experience['workspace'] : [];
    $hero = is_array($dashboard['hero'] ?? null) ? $dashboard['hero'] : [];
    $summaryCards = is_array($dashboard['summary_cards'] ?? null) ? $dashboard['summary_cards'] : [];
    $nextActions = is_array($dashboard['next_actions'] ?? null) ? $dashboard['next_actions'] : [];
    $pinnedModules = is_array($dashboard['pinned_modules'] ?? null) ? $dashboard['pinned_modules'] : [];
@endphp

<div class="mx-auto w-full max-w-[1800px] px-3 py-4 sm:px-4 sm:py-6 md:px-6 min-w-0">
    <div class="space-y-6 sm:space-y-8 min-w-0">
        <section class="mf-app-card rounded-3xl p-5 sm:p-8">
            <div class="flex flex-col gap-5 xl:flex-row xl:items-end xl:justify-between">
                <div class="max-w-3xl">
                    <div class="text-[11px] uppercase tracking-[0.28em] text-[var(--fb-muted)]">{{ $workspace['label'] ?? 'Unified workspace' }}</div>
                    <h1 class="mt-3 text-2xl font-semibold text-[var(--fb-text)] sm:text-3xl">One home that adapts to the tenant in front of you.</h1>
                    <p class="mt-3 text-sm leading-6 text-[var(--fb-muted)]">{{ $workspace['subtitle'] ?? 'Search, shortcuts, and recommendations shift with channel type, entitlements, and workflow relevance.' }}</p>
                </div>

                <div class="w-full max-w-xl">
                    <form wire:submit="submitSearch" class="flex flex-col gap-3 sm:flex-row sm:items-center">
                        <label for="dashboard-launchpad-search" class="sr-only">Search the workspace</label>
                        <input
                            id="dashboard-launchpad-search"
                            type="text"
                            wire:model.defer="search"
                            placeholder="{{ $workspace['command_placeholder'] ?? 'Search the workspace' }}"
                            class="w-full rounded-3xl border border-[var(--fb-border)] bg-white px-5 py-4 text-base text-[var(--fb-text)] placeholder:text-[var(--fb-muted)] focus:outline-none"
                            style="box-shadow: var(--fb-shadow-soft);"
                            autocomplete="off"
                        />
                        <button
                            type="submit"
                            class="inline-flex shrink-0 items-center justify-center rounded-3xl border border-[var(--fb-brand)] bg-[var(--fb-brand)] px-5 py-4 text-sm font-semibold text-white hover:bg-[var(--fb-brand-2)] hover:border-[var(--fb-brand-2)] focus:outline-none"
                        >
                            Search
                        </button>
                        <button
                            type="button"
                            data-command-trigger
                            class="inline-flex shrink-0 items-center justify-center rounded-3xl border border-[var(--fb-border)] bg-[var(--fb-surface-muted)] px-5 py-4 text-sm font-semibold text-[var(--fb-text)] focus:outline-none"
                        >
                            Open Palette
                        </button>
                    </form>
                </div>
            </div>
        </section>

        <section class="mf-app-card rounded-3xl p-5 sm:p-6">
            <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                <div>
                    <div class="text-[11px] uppercase tracking-[0.24em] text-[var(--fb-muted)]">Primary KPI</div>
                    <h2 class="mt-2 text-xl font-semibold text-[var(--fb-text)]">{{ $hero['label'] ?? 'Workspace readiness' }}</h2>
                    <p class="mt-2 text-sm text-[var(--fb-muted)]">{{ $hero['supporting'] ?? '' }}</p>
                </div>
                <div class="rounded-3xl border border-[var(--fb-border)] bg-[var(--fb-surface-muted)] px-5 py-4">
                    <div class="text-3xl font-semibold text-[var(--fb-text)]">{{ $hero['value'] ?? 'Ready' }}</div>
                </div>
            </div>

            @if($summaryCards !== [])
                <div class="mt-5 grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-4">
                    @foreach($summaryCards as $card)
                        <div class="rounded-3xl border border-[var(--fb-border)] bg-[var(--fb-surface-muted)] p-4 sm:p-5">
                            <div class="text-xs uppercase tracking-[0.24em] text-[var(--fb-muted)]">{{ $card['label'] ?? 'Metric' }}</div>
                            <div class="mt-3 text-3xl font-semibold text-[var(--fb-text)]">{{ $card['value'] ?? '0' }}</div>
                            <div class="mt-2 text-xs text-[var(--fb-muted)]">{{ $card['detail'] ?? '' }}</div>
                        </div>
                    @endforeach
                </div>
            @endif
        </section>

        <div class="grid grid-cols-1 gap-6 xl:grid-cols-[1.1fr_0.9fr]">
            <section class="mf-app-card rounded-3xl p-5 sm:p-6">
                <div class="mb-5">
                    <h2 class="text-lg font-semibold text-[var(--fb-text)] sm:text-xl">Recommended next actions</h2>
                    <p class="mt-1 text-sm text-[var(--fb-muted)]">Actions shift with tenant mode, current signals, and module availability.</p>
                </div>

                <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                    @foreach($nextActions as $action)
                        @php
                            $isCommandAction = ($action['intent'] ?? null) === 'open-command';
                        @endphp
                        @if($isCommandAction)
                            <button
                                type="button"
                                data-command-trigger
                                class="group relative overflow-hidden rounded-3xl border border-[var(--fb-border)] bg-[var(--fb-surface-muted)] p-4 text-left transition hover:-translate-y-0.5 focus:outline-none"
                                style="box-shadow: var(--fb-shadow-soft);"
                            >
                                <div class="text-sm font-semibold text-[var(--fb-text)]">{{ $action['label'] ?? 'Action' }}</div>
                                <div class="mt-2 text-sm leading-6 text-[var(--fb-muted)]">{{ $action['description'] ?? '' }}</div>
                            </button>
                        @else
                            <a
                                href="{{ $action['href'] ?? '#' }}"
                                class="group relative overflow-hidden rounded-3xl border border-[var(--fb-border)] bg-[var(--fb-surface-muted)] p-4 transition hover:-translate-y-0.5 focus:outline-none"
                                style="box-shadow: var(--fb-shadow-soft);"
                            >
                                <div class="text-sm font-semibold text-[var(--fb-text)]">{{ $action['label'] ?? 'Action' }}</div>
                                <div class="mt-2 text-sm leading-6 text-[var(--fb-muted)]">{{ $action['description'] ?? '' }}</div>
                            </a>
                        @endif
                    @endforeach
                </div>
            </section>

            <section class="mf-app-card rounded-3xl p-5 sm:p-6">
                <div class="mb-5 flex items-center justify-between gap-3">
                    <div>
                        <h2 class="text-lg font-semibold text-[var(--fb-text)] sm:text-xl">Pinned modules</h2>
                        <p class="mt-1 text-sm text-[var(--fb-muted)]">A quick read on active and high-value next-step modules.</p>
                    </div>
                    @if(auth()->user()?->canAccessMarketing())
                        <a href="{{ route('marketing.modules') }}" class="inline-flex items-center rounded-full border border-[var(--fb-border)] bg-[var(--fb-surface-muted)] px-3 py-1.5 text-xs font-semibold text-[var(--fb-brand)]">Open Modules</a>
                    @endif
                </div>

                <div class="space-y-3">
                    @forelse($pinnedModules as $module)
                        <a href="{{ $module['href'] ?? '#' }}" class="block rounded-3xl border border-[var(--fb-border)] bg-[var(--fb-surface-muted)] p-4 transition hover:-translate-y-0.5">
                            <div class="flex items-start justify-between gap-3">
                                <div>
                                    <div class="text-sm font-semibold text-[var(--fb-text)]">{{ $module['display_name'] ?? 'Module' }}</div>
                                    <div class="mt-1 text-sm leading-6 text-[var(--fb-muted)]">{{ $module['description'] ?? '' }}</div>
                                </div>
                                <span class="rounded-full border border-[var(--fb-border)] bg-white px-2.5 py-1 text-[10px] uppercase tracking-[0.18em] text-[var(--fb-muted)]">{{ $module['state_label'] ?? 'Module' }}</span>
                            </div>
                        </a>
                    @empty
                        <div class="rounded-3xl border border-dashed border-[var(--fb-border)] bg-[var(--fb-surface-muted)] p-4 text-sm text-[var(--fb-muted)]">
                            Module recommendations will appear here as tenant entitlements and App Store availability evolve.
                        </div>
                    @endforelse
                </div>
            </section>
        </div>
    </div>
</div>

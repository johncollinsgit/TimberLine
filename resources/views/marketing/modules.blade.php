<x-layouts::app :title="$currentSection['label']">
    @php
        $payload = is_array($moduleStorePayload ?? null) ? $moduleStorePayload : [];
        $currentPlan = is_array($payload['current_plan'] ?? null) ? $payload['current_plan'] : ['label' => 'Unknown', 'operating_mode' => 'direct'];
        $sectionsPayload = is_array($payload['sections'] ?? null) ? $payload['sections'] : [];
        $storeSections = [
            'active' => 'Active now',
            'available' => 'Available to add',
            'upgrade' => 'Upgrade path',
            'request' => 'Request or sales assist',
        ];
        $focusModule = strtolower(trim((string) request('module', '')));
    @endphp

    <div class="mx-auto w-full max-w-[1800px] px-3 py-4 sm:px-4 sm:py-6 md:px-6 space-y-6 min-w-0">
        <x-marketing.partials.section-shell
            :section="$currentSection"
            :sections="$sections"
        />

        <section class="rounded-[2rem] border border-white/10 bg-[radial-gradient(circle_at_top_left,rgba(255,255,255,0.09),transparent_34%),linear-gradient(180deg,rgba(255,255,255,0.05),rgba(255,255,255,0.03))] p-5 sm:p-6 shadow-[0_24px_60px_-42px_rgba(0,0,0,0.65)] backdrop-blur-xl">
            <div class="flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
                <div>
                    <div class="text-[11px] uppercase tracking-[0.28em] text-white/50">Module Store</div>
                    <h2 class="mt-2 text-xl font-semibold text-white sm:text-2xl">Tenant-aware module catalog</h2>
                    <p class="mt-2 max-w-4xl text-sm text-white/65">This surface is driven by the canonical module catalog and tenant entitlement resolver, not scattered plan checks.</p>
                </div>
                <div class="rounded-2xl border border-white/10 bg-white/5 px-4 py-3 text-xs text-white/60">
                    Plan {{ $currentPlan['label'] ?? 'Unknown' }} · mode {{ strtoupper((string) ($currentPlan['operating_mode'] ?? 'direct')) }}
                </div>
            </div>
        </section>

        @foreach($storeSections as $sectionKey => $sectionLabel)
            @php
                $modules = is_array($sectionsPayload[$sectionKey] ?? null) ? $sectionsPayload[$sectionKey] : [];
            @endphp

            @if($modules === [])
                @continue
            @endif

            <section class="rounded-[2rem] border border-white/10 bg-black/15 p-5 sm:p-6 shadow-[0_24px_60px_-46px_rgba(0,0,0,0.85)] backdrop-blur-xl">
                <div class="flex items-end justify-between gap-3">
                    <div>
                        <div class="text-[11px] uppercase tracking-[0.26em] text-white/45">Catalog Section</div>
                        <h2 class="mt-2 text-lg font-semibold text-white">{{ $sectionLabel }}</h2>
                    </div>
                    <span class="rounded-full border border-white/10 bg-white/5 px-3 py-1 text-xs text-white/70">{{ count($modules) }} modules</span>
                </div>

                <div class="mt-4 grid gap-4 lg:grid-cols-2 2xl:grid-cols-3">
                    @foreach($modules as $module)
                        @php
                            $moduleState = is_array($module['module_state'] ?? null) ? $module['module_state'] : [];
                            $cta = (string) ($moduleState['cta'] ?? 'none');
                            $moduleKey = (string) ($module['module_key'] ?? '');
                            $isFocused = $focusModule !== '' && $focusModule === $moduleKey;
                            $ctaHref = trim((string) ($moduleState['cta_href'] ?? ''));
                        @endphp
                        <article class="rounded-[1.45rem] border p-4 {{ $isFocused ? 'border-sky-300/35 bg-sky-400/[0.08]' : 'border-white/10 bg-white/5' }}">
                            <div class="flex items-start justify-between gap-3">
                                <div>
                                    <h3 class="text-base font-semibold text-white">{{ $module['display_name'] ?? $moduleKey }}</h3>
                                    <p class="mt-2 text-sm leading-6 text-white/65">{{ $module['description'] ?? '' }}</p>
                                </div>
                                <x-tenancy.module-state-badge :module-state="$moduleState" size="sm" />
                            </div>

                            <div class="mt-3 flex flex-wrap gap-2 text-xs text-white/60">
                                <span class="rounded-full border border-white/10 bg-black/20 px-2 py-1">{{ strtoupper(str_replace('_', ' ', (string) ($module['billing_mode'] ?? 'unavailable'))) }}</span>
                                @foreach((array) ($module['included_in_plans'] ?? []) as $planKey)
                                    <span class="rounded-full border border-white/10 bg-black/20 px-2 py-1">{{ strtoupper((string) $planKey) }}</span>
                                @endforeach
                            </div>

                            <p class="mt-3 text-sm leading-6 text-white/65">{{ $moduleState['reason_description'] ?? $moduleState['description'] ?? '' }}</p>

                            <div class="mt-4 flex flex-wrap gap-2">
                                @if($cta === 'add')
                                    <form method="POST" action="{{ route('marketing.modules.activate', ['moduleKey' => $moduleKey]) }}">
                                        @csrf
                                        <button type="submit" class="inline-flex rounded-full border border-emerald-300/30 bg-emerald-400/[0.12] px-3 py-1.5 text-xs font-semibold text-emerald-50">{{ $moduleState['cta_label'] ?? 'Add module' }}</button>
                                    </form>
                                @elseif($cta === 'request')
                                    <form method="POST" action="{{ route('marketing.modules.request', ['moduleKey' => $moduleKey]) }}">
                                        @csrf
                                        <button type="submit" class="inline-flex rounded-full border border-sky-300/30 bg-sky-400/[0.12] px-3 py-1.5 text-xs font-semibold text-sky-50">{{ $moduleState['cta_label'] ?? 'Request access' }}</button>
                                    </form>
                                @elseif($cta === 'upgrade' && $ctaHref !== '')
                                    <a href="{{ $ctaHref }}" class="inline-flex rounded-full border border-amber-300/30 bg-amber-400/[0.12] px-3 py-1.5 text-xs font-semibold text-amber-50">{{ $moduleState['cta_label'] ?? 'Upgrade plan' }}</a>
                                @elseif($ctaHref !== '')
                                    <a href="{{ $ctaHref }}" class="inline-flex rounded-full border border-white/15 bg-white/5 px-3 py-1.5 text-xs font-semibold text-white/80">{{ $moduleState['cta_label'] ?? 'Learn more' }}</a>
                                @endif
                            </div>
                        </article>
                    @endforeach
                </div>
            </section>
        @endforeach
    </div>
</x-layouts::app>

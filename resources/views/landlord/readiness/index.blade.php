<x-app-layout>
    @php
        $overall = is_array($overall ?? null) ? $overall : [];
        $summary = is_array($summary ?? null) ? $summary : [];
        $sections = is_array($sections ?? null) ? $sections : [];
        $statusClasses = [
            'ready' => 'border-emerald-200 bg-emerald-50 text-emerald-800',
            'partial' => 'border-sky-200 bg-sky-50 text-sky-800',
            'blocked' => 'border-rose-200 bg-rose-50 text-rose-800',
            'pending_external' => 'border-amber-200 bg-amber-50 text-amber-800',
            'disabled' => 'border-zinc-300 bg-zinc-100 text-zinc-800',
            'not_started' => 'border-violet-200 bg-violet-50 text-violet-800',
        ];
        $statusLabels = [
            'ready' => 'ready',
            'partial' => 'partial',
            'blocked' => 'blocked',
            'pending_external' => 'pending_external',
            'disabled' => 'disabled',
            'not_started' => 'not_started',
        ];
    @endphp

    <div class="space-y-6">
        <section class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.2em] text-zinc-500">Landlord</p>
                    <h1 class="mt-1 text-2xl font-semibold text-zinc-950">Self-Service Readiness Dashboard</h1>
                    <p class="mt-2 max-w-4xl text-sm text-zinc-600">
                        Operator-only launch posture summary for onboarding, Shopify, billing, modules, custom requests, commercial intent, privacy, evidence, and mobile readiness.
                    </p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <a href="{{ route('landlord.onboarding.intake') }}" class="rounded-full border border-zinc-300 px-4 py-2 text-xs font-semibold text-zinc-700 hover:bg-zinc-100">
                        Intake Queue
                    </a>
                    <a href="{{ route('landlord.commercial-intent.index') }}" class="rounded-full border border-zinc-300 px-4 py-2 text-xs font-semibold text-zinc-700 hover:bg-zinc-100">
                        Commercial Intent
                    </a>
                    <a href="{{ route('landlord.custom-module-requests.index') }}" class="rounded-full border border-zinc-300 px-4 py-2 text-xs font-semibold text-zinc-700 hover:bg-zinc-100">
                        Custom Requests
                    </a>
                </div>
            </div>
        </section>

        <section class="rounded-2xl border border-rose-200 bg-rose-50 p-5 text-sm text-rose-950">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.16em] text-rose-700">Launch answer</p>
                    <h2 class="mt-1 text-xl font-semibold">{{ $overall['answer'] ?? 'No. Everbranch is not launch-ready yet.' }}</h2>
                    <p class="mt-2 max-w-4xl">{{ $overall['explanation'] ?? 'Readiness remains blocked until manual evidence and launch decisions are complete.' }}</p>
                </div>
                <span class="inline-flex rounded-full border px-3 py-1 text-xs font-semibold {{ $statusClasses[$overall['status'] ?? 'blocked'] ?? $statusClasses['blocked'] }}">
                    {{ $statusLabels[$overall['status'] ?? 'blocked'] ?? 'blocked' }}
                </span>
            </div>
            <p class="mt-4 font-semibold">Next operator action: {{ $overall['next_action'] ?? 'Continue readiness evidence capture.' }}</p>
        </section>

        <section class="grid gap-3 sm:grid-cols-2 xl:grid-cols-6">
            @foreach (['ready', 'partial', 'blocked', 'pending_external', 'disabled', 'not_started'] as $status)
                <article class="rounded-xl border border-zinc-200 bg-white p-4 shadow-sm">
                    <p class="text-xs font-medium uppercase tracking-wide text-zinc-500">{{ $status }}</p>
                    <p class="mt-2 text-2xl font-semibold text-zinc-950">{{ number_format((int) ($summary[$status] ?? 0)) }}</p>
                </article>
            @endforeach
        </section>

        <section class="grid gap-4 lg:grid-cols-2">
            @foreach ($sections as $section)
                @php
                    $status = (string) ($section['status'] ?? 'blocked');
                    $blockers = is_array($section['blockers'] ?? null) ? $section['blockers'] : [];
                    $href = trim((string) ($section['href'] ?? ''));
                    $doc = trim((string) ($section['doc'] ?? ''));
                @endphp
                <article class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <p class="font-mono text-xs text-zinc-500">{{ $section['key'] ?? 'readiness' }}</p>
                            <h2 class="mt-1 text-lg font-semibold text-zinc-950">{{ $section['title'] ?? 'Readiness section' }}</h2>
                        </div>
                        <span class="inline-flex rounded-full border px-3 py-1 text-xs font-semibold {{ $statusClasses[$status] ?? $statusClasses['blocked'] }}">
                            {{ $statusLabels[$status] ?? $status }}
                        </span>
                    </div>

                    <p class="mt-3 text-sm text-zinc-600">{{ $section['explanation'] ?? 'No explanation available.' }}</p>

                    @if (array_key_exists('metric', $section))
                        <p class="mt-3 text-xs font-semibold text-zinc-700">Current count: {{ number_format((int) ($section['metric'] ?? 0)) }}</p>
                    @endif

                    <div class="mt-4">
                        <p class="text-xs font-semibold uppercase tracking-[0.12em] text-zinc-500">Blockers</p>
                        @if ($blockers === [])
                            <p class="mt-2 text-sm text-zinc-600">No active blocker recorded for this readiness area.</p>
                        @else
                            <ul class="mt-2 list-disc space-y-1 pl-5 text-sm text-zinc-600">
                                @foreach ($blockers as $blocker)
                                    <li>{{ $blocker }}</li>
                                @endforeach
                            </ul>
                        @endif
                    </div>

                    <p class="mt-4 text-sm font-semibold text-zinc-800">Next action: {{ $section['next_action'] ?? 'Review this readiness area.' }}</p>

                    <div class="mt-4 flex flex-wrap gap-2 text-xs font-semibold">
                        @if ($href !== '')
                            <a href="{{ $href }}" class="rounded-full border border-zinc-300 px-3 py-1.5 text-zinc-700 hover:bg-zinc-100">Open surface</a>
                        @endif
                        @if ($doc !== '')
                            <span class="rounded-full border border-zinc-200 bg-zinc-50 px-3 py-1.5 text-zinc-600">{{ $doc }}</span>
                        @endif
                    </div>
                </article>
            @endforeach
        </section>

        <section class="rounded-2xl border border-zinc-200 bg-white p-5 text-sm text-zinc-600 shadow-sm">
            <p class="font-semibold text-zinc-950">Scope boundary</p>
            <p class="mt-2">
                This dashboard is a status/control surface only. It does not activate billing, complete external Shopify evidence, approve public launch, install modules, change feature access, or turn Modern Forestry mobile work into a generic Everbranch mobile app.
            </p>
        </section>
    </div>
</x-app-layout>

@php
    $guideRows = collect($onboardingGuideRows ?? []);
@endphp
<x-app-layout>
    <x-slot name="header">
        <h1 class="text-xl font-semibold text-zinc-900">{{ config('everbranch.landlord_portal_name', 'Everbranch Admin') }}</h1>
    </x-slot>

    <div class="space-y-6">
        <section class="overflow-hidden rounded-2xl border border-zinc-200 bg-white shadow-sm">
            <header class="sticky top-0 z-20 border-b border-zinc-200 bg-white/95 backdrop-blur">
                <div class="flex flex-wrap items-start justify-between gap-4 px-6 py-5">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-[0.2em] text-zinc-500">Landlord</p>
                        <h2 class="mt-1 text-2xl font-semibold text-zinc-950">{{ config('everbranch.landlord_portal_name', 'Everbranch Admin') }} Console</h2>
                        <p class="mt-1 max-w-3xl text-sm text-zinc-600">
                            Operational overview for tenant health, commercial configuration access, and guarded landlord-only actions.
                        </p>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <a
                            href="{{ route('landlord.commercial.index') }}"
                            class="inline-flex items-center rounded-lg bg-zinc-900 px-4 py-2 text-xs font-semibold text-white hover:bg-zinc-800"
                        >
                            Open Commercial Config
                        </a>
                        <a
                            href="{{ route('landlord.readiness') }}"
                            class="inline-flex items-center rounded-lg border border-zinc-300 px-4 py-2 text-xs font-semibold text-zinc-700 hover:bg-zinc-100"
                        >
                            Readiness
                        </a>
                        <a
                            href="{{ route('landlord.onboarding.intake') }}"
                            class="inline-flex items-center rounded-lg border border-zinc-300 px-4 py-2 text-xs font-semibold text-zinc-700 hover:bg-zinc-100"
                        >
                            Intake Queue
                        </a>
                        <a
                            href="{{ route('landlord.onboarding.prospects.index') }}"
                            class="inline-flex items-center rounded-lg border border-emerald-300 bg-emerald-50 px-4 py-2 text-xs font-semibold text-emerald-800 hover:bg-emerald-100"
                        >
                            Launch Partner Onboarding
                        </a>
                        <a
                            href="{{ route('landlord.commercial-intent.index') }}"
                            class="inline-flex items-center rounded-lg border border-zinc-300 px-4 py-2 text-xs font-semibold text-zinc-700 hover:bg-zinc-100"
                        >
                            Commercial Intent
                        </a>
                        <a
                            href="{{ route('landlord.onboarding.journey') }}"
                            class="inline-flex items-center rounded-lg border border-zinc-300 px-4 py-2 text-xs font-semibold text-zinc-700 hover:bg-zinc-100"
                        >
                            Onboarding Diagnostics
                        </a>
                        <a
                            href="{{ route('landlord.custom-module-requests.index') }}"
                            class="inline-flex items-center rounded-lg border border-zinc-300 px-4 py-2 text-xs font-semibold text-zinc-700 hover:bg-zinc-100"
                        >
                            Custom Requests
                        </a>
                        <a
                            href="{{ route('landlord.service-inquiries.index') }}"
                            class="inline-flex items-center rounded-lg border border-zinc-300 px-4 py-2 text-xs font-semibold text-zinc-700 hover:bg-zinc-100"
                        >
                            Service Inquiries
                        </a>
                        <a
                            href="{{ route('landlord.tenants.index') }}"
                            class="inline-flex items-center rounded-lg border border-zinc-300 px-4 py-2 text-xs font-semibold text-zinc-700 hover:bg-zinc-100"
                        >
                            Open Tenant Directory
                        </a>
                        <a
                            href="{{ route('landlord.transactions.index') }}"
                            class="inline-flex items-center rounded-lg border border-emerald-700 bg-emerald-700 px-4 py-2 text-xs font-semibold text-white shadow-sm hover:bg-emerald-800"
                        >
                            Transactions
                        </a>
                    </div>
                </div>
                <nav class="overflow-x-auto border-t border-zinc-200 px-6 py-3">
                    <ul class="flex min-w-max items-center gap-2 text-xs font-medium text-zinc-600">
                        <li><a href="#overview" class="rounded-md border border-zinc-300 px-3 py-1.5 hover:bg-zinc-100">Overview</a></li>
                        <li><a href="#owner-intake" class="rounded-md border border-zinc-300 px-3 py-1.5 hover:bg-zinc-100">Owner intake</a></li>
                        <li><a href="#onboarding-triage" class="rounded-md border border-zinc-300 px-3 py-1.5 hover:bg-zinc-100">Onboarding triage</a></li>
                        <li><a href="#recent-tenants" class="rounded-md border border-zinc-300 px-3 py-1.5 hover:bg-zinc-100">Workspace switcher</a></li>
                    </ul>
                </nav>
            </header>

            <div class="space-y-8 p-6">
                @if(session('status'))<div class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900">{{ session('status') }}</div>@endif
                <section id="overview" class="space-y-4 scroll-mt-36">
                    <div>
                        <h3 class="text-lg font-semibold text-zinc-950">Overview</h3>
                        <p class="text-sm text-zinc-600">
                            Current landlord-host visibility across tenant health and connected Shopify stores.
                        </p>
                    </div>

                    <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                        <article class="rounded-xl border border-zinc-200 bg-zinc-50 p-4">
                            <p class="text-xs font-medium uppercase tracking-wide text-zinc-500">Total tenants</p>
                            <p class="mt-2 text-2xl font-semibold text-zinc-950">{{ number_format((int) ($metrics['total_tenants'] ?? 0)) }}</p>
                        </article>
                        <article class="rounded-xl border border-zinc-200 bg-zinc-50 p-4">
                            <p class="text-xs font-medium uppercase tracking-wide text-zinc-500">Healthy</p>
                            <p class="mt-2 text-2xl font-semibold text-zinc-950">{{ number_format((int) ($metrics['healthy_tenants'] ?? 0)) }}</p>
                        </article>
                        <article class="rounded-xl border border-zinc-200 bg-zinc-50 p-4">
                            <p class="text-xs font-medium uppercase tracking-wide text-zinc-500">Shopify connected</p>
                            <p class="mt-2 text-2xl font-semibold text-zinc-950">{{ number_format((int) ($metrics['tenants_with_connected_shopify'] ?? 0)) }}</p>
                        </article>
                        <article class="rounded-xl border border-zinc-200 bg-zinc-50 p-4">
                            <p class="text-xs font-medium uppercase tracking-wide text-zinc-500">Needs attention</p>
                            <p class="mt-2 text-2xl font-semibold text-zinc-950">{{ number_format((int) ($metrics['tenants_needing_attention'] ?? 0)) }}</p>
                        </article>
                    </div>
                </section>

                @php
                    $operator = is_array($operatorSnapshot ?? null) ? $operatorSnapshot : [];
                @endphp
                <section id="operator-command-center" class="space-y-4 scroll-mt-36">
                    <div class="flex flex-wrap items-end justify-between gap-3"><div><p class="text-xs font-semibold uppercase tracking-[.18em] text-emerald-800">Operator command center</p><h3 class="mt-1 text-lg font-semibold text-zinc-950">Revenue, usage, support, and weekly break-even</h3><p class="mt-1 text-sm text-zinc-600">Weekly summary sends Mondays at 8:00 AM Eastern by text and email. Costs are only as complete as the receipt ledger below.</p></div><div class="flex flex-wrap gap-2"><a href="{{ route('landlord.transactions.index') }}" class="rounded-xl border border-emerald-700 bg-emerald-50 px-4 py-2 text-sm font-semibold text-emerald-900 hover:bg-emerald-100">Open Transactions</a><a href="{{ route('landlord.support-tickets.index') }}" class="rounded-xl bg-zinc-950 px-4 py-2 text-sm font-semibold text-white">Open Tickets ({{ $operator['open_tickets'] ?? 0 }})</a></div></div>
                    <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4"><article class="rounded-xl border border-zinc-200 bg-zinc-50 p-4"><p class="text-xs font-semibold uppercase tracking-wide text-zinc-500">Weekly recurring cost</p><p class="mt-2 text-2xl font-semibold text-zinc-950">${{ number_format(($operator['weekly_cost_cents'] ?? 0) / 100, 2) }}</p></article><article class="rounded-xl border border-zinc-200 bg-zinc-50 p-4"><p class="text-xs font-semibold uppercase tracking-wide text-zinc-500">Everbranch revenue YTD</p><p class="mt-2 text-2xl font-semibold text-zinc-950">${{ number_format(($operator['ytd_revenue_cents'] ?? 0) / 100, 2) }}</p></article><article class="rounded-xl border border-zinc-200 bg-zinc-50 p-4"><p class="text-xs font-semibold uppercase tracking-wide text-zinc-500">Active paying workspaces</p><p class="mt-2 text-2xl font-semibold text-zinc-950">{{ $operator['active_paying_tenants'] ?? 0 }}</p></article><article class="rounded-xl border border-zinc-200 bg-zinc-50 p-4"><p class="text-xs font-semibold uppercase tracking-wide text-zinc-500">Weekly break-even</p><p class="mt-2 text-2xl font-semibold text-zinc-950">{{ $operator['break_even_clients'] ?? '—' }} clients</p></article></div>
                    @if(collect($operator['bud_pending'] ?? [])->isNotEmpty())<div class="rounded-2xl border border-amber-200 bg-amber-50 p-4"><p class="font-semibold text-amber-950">Bud activation needs review</p><div class="mt-3 flex flex-wrap gap-3">@foreach($operator['bud_pending'] as $setting)<form method="post" action="{{ route('landlord.bud-settings.review', $setting) }}" class="flex items-center gap-2 rounded-xl bg-white p-2">@csrf<input type="hidden" name="decision" value="approve"><span class="text-sm text-zinc-700">{{ $setting->tenant?->name }} · {{ $setting->requester?->name }}</span><button class="rounded-lg bg-emerald-800 px-3 py-1.5 text-xs font-semibold text-white">Approve Bud</button></form>@endforeach</div></div>@endif
                    <div class="grid gap-4 lg:grid-cols-[1.15fr_.85fr]"><div class="rounded-2xl border border-zinc-200 bg-white p-5"><div class="flex items-center justify-between"><h4 class="font-semibold text-zinc-950">Workspace messaging usage</h4><span class="text-xs text-zinc-500">Current period</span></div><div class="mt-3 divide-y divide-zinc-100">@forelse($operator['usage'] ?? [] as $usage)<div class="flex items-center justify-between py-3 text-sm"><span class="font-medium text-zinc-800">{{ $usage['tenant'] }}</span><span class="text-zinc-600">{{ number_format($usage['email_used']) }} emails · {{ number_format($usage['sms_used']) }} texts</span></div>@empty<p class="py-4 text-sm text-zinc-500">Usage will appear as workspace messaging begins.</p>@endforelse</div></div><form method="post" action="{{ route('landlord.operator-costs.store') }}" class="rounded-2xl border border-zinc-200 bg-white p-5">@csrf<h4 class="font-semibold text-zinc-950">Add a recurring cost</h4><p class="mt-1 text-xs leading-5 text-zinc-500">Use a receipt reference when you have one. Gmail receipt import can be connected later; this ledger keeps the weekly report honest now.</p><div class="mt-3 grid gap-2 sm:grid-cols-2"><input name="vendor" required placeholder="Vendor (Twilio, DigitalOcean…)" class="rounded-lg border-zinc-300 text-sm"><input name="amount" required type="number" min="0" step="0.01" placeholder="Amount" class="rounded-lg border-zinc-300 text-sm"><select name="cadence" class="rounded-lg border-zinc-300 text-sm"><option value="monthly">Monthly</option><option value="annual">Annual</option><option value="weekly">Weekly</option></select><select name="source" class="rounded-lg border-zinc-300 text-sm"><option value="manual">Manual</option><option value="gmail_receipt">Gmail receipt</option><option value="invoice">Invoice</option></select></div><input name="receipt_reference" placeholder="Receipt or invoice reference (optional)" class="mt-2 w-full rounded-lg border-zinc-300 text-sm"><button class="mt-3 rounded-lg border border-zinc-300 px-3 py-2 text-xs font-semibold text-zinc-800">Add cost</button></form></div>
                </section>

                <section id="owner-intake" class="space-y-4 scroll-mt-36">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <h3 class="text-lg font-semibold text-zinc-950">Owner intake</h3>
                            <p class="text-sm text-zinc-600">
                                Answers saved under the Google-authenticated user during first-login setup.
                            </p>
                        </div>
                        <div class="rounded-full border border-zinc-200 bg-zinc-50 px-3 py-1 text-xs font-semibold text-zinc-600">
                            {{ number_format($guideRows->count()) }} recent
                        </div>
                    </div>

                    <div class="grid gap-4 xl:grid-cols-2">
                        @forelse ($guideRows as $row)
                            @php
                                $guide = is_array($row['onboarding_guide'] ?? null) ? (array) $row['onboarding_guide'] : [];
                                $guideUser = is_array($guide['user'] ?? null) ? (array) $guide['user'] : [];
                                $answers = is_array($guide['answers'] ?? null) ? (array) $guide['answers'] : [];
                                $questions = is_array($answers['questions'] ?? null) ? (array) $answers['questions'] : [];
                                $hardest = is_array($questions['hardest_part'] ?? null) ? (array) $questions['hardest_part'] : [];
                                $teamSize = is_array($questions['team_size'] ?? null) ? (array) $questions['team_size'] : [];
                                $needs = collect(is_array($questions['owner_need'] ?? null) ? (array) $questions['owner_need'] : []);
                                $modules = collect(is_array($answers['selected_modules'] ?? null) ? (array) $answers['selected_modules'] : []);
                                $appointment = is_array($answers['appointment'] ?? null) ? (array) $answers['appointment'] : null;
                                $startPath = (string) ($answers['start_path'] ?? 'self');
                            @endphp
                            <article class="rounded-xl border border-zinc-200 bg-white p-5 shadow-sm">
                                <div class="flex flex-wrap items-start justify-between gap-3">
                                    <div>
                                        <p class="text-xs font-semibold uppercase tracking-[0.16em] text-zinc-500">{{ $row['name'] ?? 'Tenant' }}</p>
                                        <h4 class="mt-1 text-lg font-semibold text-zinc-950">{{ $guideUser['name'] ?? 'New owner' }}</h4>
                                        <p class="text-sm text-zinc-600">{{ $guideUser['email'] ?? 'No email captured' }}</p>
                                    </div>
                                    <span class="rounded-full px-3 py-1 text-xs font-semibold {{ $startPath === 'guided' ? 'bg-emerald-100 text-emerald-800' : 'bg-zinc-100 text-zinc-700' }}">
                                        {{ $startPath === 'guided' ? 'Wants help' : 'Self-guided' }}
                                    </span>
                                </div>

                                <dl class="mt-5 grid gap-3 sm:grid-cols-2">
                                    <div class="rounded-lg bg-zinc-50 p-3">
                                        <dt class="text-xs font-semibold uppercase tracking-wide text-zinc-500">Hardest part</dt>
                                        <dd class="mt-1 text-sm font-semibold text-zinc-900">{{ $hardest['label'] ?? 'Not answered' }}</dd>
                                    </div>
                                    <div class="rounded-lg bg-zinc-50 p-3">
                                        <dt class="text-xs font-semibold uppercase tracking-wide text-zinc-500">Team</dt>
                                        <dd class="mt-1 text-sm font-semibold text-zinc-900">{{ $teamSize['label'] ?? 'Not answered' }}</dd>
                                    </div>
                                </dl>

                                @if ($needs->isNotEmpty())
                                    <div class="mt-4">
                                        <p class="text-xs font-semibold uppercase tracking-wide text-zinc-500">Needs right now</p>
                                        <div class="mt-2 flex flex-wrap gap-2">
                                            @foreach ($needs as $need)
                                                <span class="rounded-full bg-emerald-50 px-3 py-1 text-xs font-semibold text-emerald-800">{{ $need['label'] ?? $need['value'] ?? 'Need' }}</span>
                                            @endforeach
                                        </div>
                                    </div>
                                @endif

                                @if ($appointment)
                                    <div class="mt-4 rounded-lg border border-emerald-200 bg-emerald-50 p-3">
                                        <p class="text-xs font-semibold uppercase tracking-wide text-emerald-800">Requested appointment</p>
                                        <p class="mt-1 text-sm font-semibold text-emerald-950">{{ $appointment['slot_label'] ?? $appointment['slot'] ?? 'Slot pending' }}</p>
                                        <p class="mt-1 text-xs text-emerald-900">{{ $appointment['phone'] ?? '' }}</p>
                                    </div>
                                @endif

                                @if ($modules->isNotEmpty())
                                    <div class="mt-4">
                                        <p class="text-xs font-semibold uppercase tracking-wide text-zinc-500">Clicked app modules</p>
                                        <div class="mt-2 flex flex-wrap gap-2">
                                            @foreach ($modules->take(10) as $module)
                                                <span class="rounded-full bg-zinc-100 px-3 py-1 text-xs font-semibold text-zinc-700">{{ $module['label'] ?? $module['key'] ?? 'Module' }}</span>
                                            @endforeach
                                            @if ($modules->count() > 10)
                                                <span class="rounded-full bg-zinc-100 px-3 py-1 text-xs font-semibold text-zinc-500">+{{ $modules->count() - 10 }} more</span>
                                            @endif
                                        </div>
                                    </div>
                                @endif
                            </article>
                        @empty
                            <div class="rounded-xl border border-dashed border-zinc-300 bg-zinc-50 p-5 text-sm text-zinc-600">
                                No first-login guide answers have been captured yet.
                            </div>
                        @endforelse
                    </div>
                </section>

                @php
                    $triage = is_array($onboardingTriage ?? null) ? (array) $onboardingTriage : [];
                    $triageCounts = is_array($triage['counts'] ?? null) ? (array) $triage['counts'] : [];
                    $triageLinks = [
                        'no_telemetry' => route('landlord.tenants.index', ['onboarding_filter' => 'no_telemetry']),
                        'waiting_for_first_open' => route('landlord.tenants.index', ['onboarding_filter' => 'waiting_for_first_open']),
                        'waiting_for_import' => route('landlord.tenants.index', ['onboarding_filter' => 'waiting_for_import']),
                        'waiting_for_activation' => route('landlord.tenants.index', ['onboarding_filter' => 'waiting_for_activation']),
                        'completed_first_value' => route('landlord.tenants.index', ['onboarding_filter' => 'completed_first_value']),
                    ];
                @endphp

                <section id="onboarding-triage" class="space-y-4 scroll-mt-36">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <h3 class="text-lg font-semibold text-zinc-950">Onboarding triage</h3>
                            <p class="text-sm text-zinc-600">
                                Queue-style view derived from onboarding activity. Click a card to open the filtered tenant directory.
                            </p>
                        </div>
                        <div class="text-right text-xs text-zinc-600">
                            <div><span class="font-semibold text-zinc-900">{{ number_format((int) data_get($triage, 'tenants_with_telemetry', 0)) }}</span> with telemetry</div>
                            <div><span class="font-semibold text-zinc-900">{{ number_format((int) data_get($triage, 'tenants_needing_onboarding_attention', 0)) }}</span> needing attention</div>
                        </div>
                    </div>

                    <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
                        @foreach ([
                            ['key' => 'no_telemetry', 'label' => 'No telemetry', 'tone' => 'zinc'],
                            ['key' => 'waiting_for_first_open', 'label' => 'Waiting for first open', 'tone' => 'amber'],
                            ['key' => 'waiting_for_import', 'label' => 'Waiting for import', 'tone' => 'amber'],
                            ['key' => 'waiting_for_activation', 'label' => 'Waiting for activation', 'tone' => 'amber'],
                            ['key' => 'completed_first_value', 'label' => 'Reached first value', 'tone' => 'emerald'],
                        ] as $card)
                            @php
                                $key = (string) $card['key'];
                                $count = (int) ($triageCounts[$key] ?? 0);
                                $tone = (string) ($card['tone'] ?? 'zinc');
                                $classes = match ($tone) {
                                    'emerald' => 'border-emerald-200 bg-emerald-50',
                                    'amber' => 'border-amber-200 bg-amber-50',
                                    default => 'border-zinc-200 bg-zinc-50',
                                };
                            @endphp
                            <a href="{{ $triageLinks[$key] ?? route('landlord.tenants.index') }}" class="block rounded-xl border {{ $classes }} p-4 hover:bg-white">
                                <p class="text-xs font-medium uppercase tracking-wide text-zinc-500">{{ $card['label'] }}</p>
                                <p class="mt-2 text-2xl font-semibold text-zinc-950">{{ number_format($count) }}</p>
                                <p class="mt-1 text-xs font-semibold text-zinc-700">View tenants</p>
                            </a>
                        @endforeach
                    </div>
                </section>

                <section id="recent-tenants" class="space-y-4 scroll-mt-36">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <div>
                            <h3 class="text-lg font-semibold text-zinc-950">Workspace switcher <span class="text-sm font-medium text-zinc-500">· Recent tenants</span></h3>
                            <p class="text-sm text-zinc-600">Jump directly to a workspace—no internal IDs, raw status, or creation details.</p>
                        </div>
                        <a
                            href="{{ route('landlord.tenants.index') }}"
                            class="inline-flex items-center rounded-lg border border-zinc-300 px-3 py-1.5 text-xs font-semibold text-zinc-700 hover:bg-zinc-100"
                        >
                            View full directory
                        </a>
                    </div>

                    <div class="rounded-xl border border-zinc-200 bg-zinc-50 p-4">
                        <p class="text-xs font-semibold uppercase tracking-[0.12em] text-zinc-600">Tenant Operations Selector</p>
                        <p class="mt-1 text-xs text-zinc-600">
                            Select tenant context explicitly before running export/restore/customer actions.
                        </p>
                        <form method="POST" action="{{ route('landlord.tenants.select') }}" class="mt-3 flex flex-wrap items-center gap-2">
                            @csrf
                            <select
                                name="tenant"
                                class="min-w-[18rem] rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900"
                                @if (collect($recent_tenants)->isEmpty()) disabled @endif
                            >
                                @foreach ($recent_tenants as $row)
                                    <option value="{{ $row['id'] }}">{{ $row['name'] }}</option>
                                @endforeach
                            </select>
                            <button
                                type="submit"
                                class="inline-flex items-center rounded-lg border border-zinc-300 bg-white px-4 py-2 text-xs font-semibold text-zinc-700 hover:bg-zinc-100 disabled:opacity-60"
                                @if (collect($recent_tenants)->isEmpty()) disabled @endif
                            >
                                Open Tenant Operations
                            </button>
                        </form>
                        @error('tenant')
                            <p class="mt-2 text-xs text-rose-600">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                        @forelse ($recent_tenants as $row)
                            <a href="{{ route('landlord.tenants.show', ['tenant' => $row['id']]) }}" class="flex items-center justify-between rounded-xl border border-zinc-200 bg-white p-4 shadow-sm transition hover:border-emerald-300 hover:bg-emerald-50/40">
                                <span class="font-semibold text-zinc-950">{{ $row['name'] }}</span>
                                <span class="text-sm font-semibold text-emerald-800">Open →</span>
                            </a>
                        @empty
                            <div class="rounded-xl border border-dashed border-zinc-300 bg-zinc-50 p-5 text-sm text-zinc-600 sm:col-span-2 xl:col-span-3">No workspaces found.</div>
                        @endforelse
                    </div>
                </section>
            </div>
        </section>
    </div>
</x-app-layout>

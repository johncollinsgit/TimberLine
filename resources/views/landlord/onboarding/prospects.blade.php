<x-app-layout>
    <x-slot name="header">
        <h1 class="text-xl font-semibold text-zinc-900">Launch Partner Onboarding</h1>
    </x-slot>

    @php
        $statusStyles = [
            'new' => 'border-zinc-200 bg-zinc-50 text-zinc-700',
            'draft_ready' => 'border-blue-200 bg-blue-50 text-blue-800',
            'contacted' => 'border-amber-200 bg-amber-50 text-amber-800',
            'replied' => 'border-emerald-200 bg-emerald-50 text-emerald-800',
            'meeting_scheduled' => 'border-violet-200 bg-violet-50 text-violet-800',
            'qualified' => 'border-teal-200 bg-teal-50 text-teal-800',
            'converted' => 'border-emerald-300 bg-emerald-100 text-emerald-900',
            'not_fit' => 'border-zinc-200 bg-zinc-100 text-zinc-600',
            'unsubscribed' => 'border-rose-200 bg-rose-50 text-rose-800',
        ];
    @endphp

    <div class="space-y-6">
        <section class="overflow-hidden rounded-3xl border border-zinc-200 bg-zinc-950 text-white shadow-sm">
            <div class="grid lg:grid-cols-[minmax(0,1.1fr)_minmax(320px,.9fr)]">
                <div class="p-6 lg:p-8">
                    <p class="text-xs font-semibold uppercase tracking-[0.24em] text-emerald-300">Evergrove launch partners</p>
                    <h2 class="mt-3 max-w-3xl text-3xl font-semibold tracking-tight sm:text-4xl">Find the right local businesses. Work every next step.</h2>
                    <p class="mt-3 max-w-3xl text-sm leading-6 text-zinc-300">
                        Discover trade businesses, prioritize a strong Google presence with no website, prepare relevant outreach, record every interaction, and carry qualified companies into Everbranch.
                    </p>
                    <div class="mt-5 flex flex-wrap gap-2">
                        <a href="#prospects" class="rounded-xl bg-emerald-400 px-4 py-2 text-xs font-semibold text-zinc-950 hover:bg-emerald-300">View prospect sheet</a>
                        <a href="#discovery" class="rounded-xl border border-white/20 px-4 py-2 text-xs font-semibold text-white hover:bg-white/10">Find prospects</a>
                        <a href="{{ route('landlord.onboarding.prospects.export', request()->query()) }}" class="rounded-xl border border-white/20 px-4 py-2 text-xs font-semibold text-white hover:bg-white/10">Export CSV</a>
                    </div>
                </div>
                <div class="relative min-h-64 overflow-hidden">
                    <img src="{{ $prospectingPhoto['url'] ?? '' }}" alt="{{ $prospectingPhoto['alt'] ?? 'Construction workers using a tablet' }}" class="absolute inset-0 h-full w-full object-cover" />
                    <div class="absolute inset-0 bg-gradient-to-r from-zinc-950/70 via-zinc-950/15 to-transparent"></div>
                    <div class="absolute bottom-4 right-4 rounded-xl bg-zinc-950/75 px-3 py-2 text-right backdrop-blur">
                        <p class="text-xs font-semibold text-white">{{ $metrics['launch_partner_spots_open'] }}/{{ $metrics['launch_partner_spots_total'] }} launch spots open</p>
                        <a href="{{ $prospectingPhoto['credit_url'] ?? '#' }}" target="_blank" rel="noopener" class="mt-1 block text-[10px] text-zinc-300 hover:text-white">{{ $prospectingPhoto['credit_label'] ?? 'Unsplash photo' }} ↗</a>
                    </div>
                </div>
            </div>
        </section>

        @if (session('status'))
            <div class="rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-900">
                {{ session('status') }}
            </div>
        @endif

        @if ($errors->any())
            <div class="rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-900">
                <p class="font-semibold">Please fix the highlighted information.</p>
                <ul class="mt-2 list-disc space-y-1 pl-5">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <section class="grid gap-3 sm:grid-cols-2 xl:grid-cols-7">
            @foreach ([
                ['label' => 'Prospects', 'value' => $metrics['total'], 'copy' => 'in the local pipeline'],
                ['label' => 'No website', 'value' => $metrics['website_missing'], 'copy' => 'Maps presence to verify'],
                ['label' => 'Drafts ready', 'value' => $metrics['draft_ready'], 'copy' => 'ready for review'],
                ['label' => 'Follow-up due', 'value' => $metrics['follow_up_due'], 'copy' => 'need a next touch'],
                ['label' => 'Replies', 'value' => $metrics['replied'], 'copy' => 'need a response'],
                ['label' => 'Meetings', 'value' => $metrics['meetings'], 'copy' => 'appointments booked'],
                ['label' => 'Customers', 'value' => $metrics['converted'], 'copy' => 'converted to tenants'],
            ] as $metric)
                <article class="rounded-2xl border border-zinc-200 bg-white p-4 shadow-sm">
                    <p class="text-xs font-semibold uppercase tracking-[0.15em] text-zinc-500">{{ $metric['label'] }}</p>
                    <p class="mt-2 text-3xl font-semibold text-zinc-950">{{ number_format((int) $metric['value']) }}</p>
                    <p class="mt-1 text-xs text-zinc-500">{{ $metric['copy'] }}</p>
                </article>
            @endforeach
        </section>

        <section id="discovery" class="grid scroll-mt-24 gap-5 xl:grid-cols-[minmax(0,1.15fr)_minmax(340px,.85fr)]">
            <div class="rounded-3xl border border-zinc-200 bg-white p-5 shadow-sm">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-[0.18em] text-emerald-700">Prospect finder</p>
                        <h3 class="mt-1 text-xl font-semibold text-zinc-950">Search Google Places</h3>
                        <p class="mt-1 max-w-2xl text-sm leading-6 text-zinc-600">One bounded search, up to 20 results. Exact Google Place matches are deduplicated, and nothing is contacted automatically.</p>
                    </div>
                    <span class="rounded-full border px-3 py-1 text-xs font-semibold {{ $discoveryConfigured ? 'border-emerald-200 bg-emerald-50 text-emerald-800' : 'border-amber-200 bg-amber-50 text-amber-800' }}">
                        {{ $discoveryConfigured ? 'Places API ready' : 'API key needed' }}
                    </span>
                </div>

                <form method="POST" action="{{ route('landlord.onboarding.prospects.discovery.store') }}" class="mt-5 grid gap-3 md:grid-cols-2">
                    @csrf
                    <label class="space-y-1 text-xs font-semibold text-zinc-600">
                        <span>Trade</span>
                        <input name="trade" required value="{{ old('trade', 'HVAC') }}" placeholder="HVAC, electrical, plumbing…" class="w-full rounded-xl border-zinc-300 text-sm font-normal text-zinc-900" />
                    </label>
                    <label class="space-y-1 text-xs font-semibold text-zinc-600">
                        <span>City, county, or market</span>
                        <input name="search_region" required value="{{ old('search_region', 'Powdersville, SC') }}" class="w-full rounded-xl border-zinc-300 text-sm font-normal text-zinc-900" />
                    </label>
                    <label class="space-y-1 text-xs font-semibold text-zinc-600">
                        <span>Website priority</span>
                        <select name="website_preference" class="w-full rounded-xl border-zinc-300 text-sm font-normal text-zinc-900">
                            <option value="missing_only">Only listings with no website URL</option>
                            <option value="all">All matching listings</option>
                        </select>
                    </label>
                    <label class="space-y-1 text-xs font-semibold text-zinc-600">
                        <span>Maximum results</span>
                        <select name="maximum_results" class="w-full rounded-xl border-zinc-300 text-sm font-normal text-zinc-900">
                            @foreach ([5, 10, 15, 20] as $maximum)
                                <option value="{{ $maximum }}" @selected((int) old('maximum_results', 10) === $maximum)>{{ $maximum }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="flex items-start gap-2 rounded-xl border border-zinc-200 bg-zinc-50 p-3 text-xs leading-5 text-zinc-700 md:col-span-2">
                        <input name="confirm_cost" type="checkbox" value="1" required class="mt-1 rounded border-zinc-300 text-emerald-700" />
                        <span>I approve one Places request, estimated at <strong>${{ number_format($estimatedDiscoveryCost, 4) }}</strong>. I will review public evidence before outreach.</span>
                    </label>
                    <div class="md:col-span-2">
                        <button @disabled(! $discoveryConfigured) class="rounded-xl bg-emerald-700 px-4 py-2.5 text-xs font-semibold text-white hover:bg-emerald-800 disabled:cursor-not-allowed disabled:bg-zinc-300">Find prospects</button>
                    </div>
                </form>

                @if ($recentDiscoveryRuns->isNotEmpty())
                    <div class="mt-5 border-t border-zinc-200 pt-4">
                        <p class="text-xs font-semibold uppercase tracking-[0.14em] text-zinc-500">Recent searches</p>
                        <div class="mt-2 space-y-2">
                            @foreach ($recentDiscoveryRuns as $run)
                                <div class="flex flex-wrap items-center justify-between gap-2 rounded-xl bg-zinc-50 px-3 py-2 text-xs text-zinc-600">
                                    <span><strong class="text-zinc-900">{{ $run->search_query }}</strong> · {{ \Illuminate\Support\Str::headline($run->status) }}</span>
                                    <span>{{ $run->results_created }} added · {{ $run->website_missing_count }} no website · ${{ number_format((float) $run->actual_api_cost, 4) }}</span>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif
            </div>

            <div class="rounded-3xl border border-zinc-200 bg-white p-5 shadow-sm">
                <p class="text-xs font-semibold uppercase tracking-[0.18em] text-zinc-500">Human-approved cadence</p>
                <h3 class="mt-1 text-xl font-semibold text-zinc-950">A workflow, not a blast</h3>
                <p class="mt-1 text-sm leading-6 text-zinc-600">Each touch adds context and one useful next step. Stop immediately on a decline or unsubscribe.</p>
                <ol class="mt-5 space-y-3">
                    @foreach ($outreachCadence as $step)
                        <li class="flex gap-3 rounded-2xl border border-zinc-200 p-3">
                            <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-zinc-950 text-xs font-semibold text-white">D{{ $step['day'] }}</span>
                            <div>
                                <p class="text-sm font-semibold text-zinc-950">{{ $step['label'] }}</p>
                                <p class="mt-0.5 text-xs text-zinc-500">{{ $step['channel'] }} · create, personalize, approve, log</p>
                            </div>
                        </li>
                    @endforeach
                </ol>
            </div>
        </section>

        <section class="rounded-3xl border border-zinc-200 bg-white p-5 shadow-sm">
            <details>
                <summary class="cursor-pointer list-none">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-[0.18em] text-zinc-500">Add a lead</p>
                            <h3 class="mt-1 text-lg font-semibold text-zinc-950">New prospect</h3>
                        </div>
                        <span class="rounded-xl bg-zinc-900 px-4 py-2 text-xs font-semibold text-white">Open form</span>
                    </div>
                </summary>

                <form method="POST" action="{{ route('landlord.onboarding.prospects.store') }}" class="mt-5 grid gap-4 border-t border-zinc-200 pt-5 md:grid-cols-2 xl:grid-cols-4">
                    @csrf
                    <label class="space-y-1 text-sm text-zinc-700">
                        <span class="font-semibold">Business name</span>
                        <input name="business_name" required value="{{ old('business_name') }}" class="w-full rounded-xl border-zinc-300 text-sm" />
                    </label>
                    <label class="space-y-1 text-sm text-zinc-700">
                        <span class="font-semibold">Contact name</span>
                        <input name="contact_name" value="{{ old('contact_name') }}" class="w-full rounded-xl border-zinc-300 text-sm" />
                    </label>
                    <label class="space-y-1 text-sm text-zinc-700">
                        <span class="font-semibold">Trade</span>
                        <input name="trade" required value="{{ old('trade') }}" placeholder="HVAC, plumbing, roofing…" class="w-full rounded-xl border-zinc-300 text-sm" />
                    </label>
                    <label class="space-y-1 text-sm text-zinc-700">
                        <span class="font-semibold">County</span>
                        <input name="county" required value="{{ old('county') }}" placeholder="Pickens or Greenville" class="w-full rounded-xl border-zinc-300 text-sm" />
                    </label>
                    <label class="space-y-1 text-sm text-zinc-700">
                        <span class="font-semibold">City</span>
                        <input name="city" value="{{ old('city') }}" class="w-full rounded-xl border-zinc-300 text-sm" />
                    </label>
                    <label class="space-y-1 text-sm text-zinc-700">
                        <span class="font-semibold">Email</span>
                        <input name="email" type="email" value="{{ old('email') }}" class="w-full rounded-xl border-zinc-300 text-sm" />
                    </label>
                    <label class="space-y-1 text-sm text-zinc-700">
                        <span class="font-semibold">Phone</span>
                        <input name="phone" value="{{ old('phone') }}" class="w-full rounded-xl border-zinc-300 text-sm" />
                    </label>
                    <label class="space-y-1 text-sm text-zinc-700">
                        <span class="font-semibold">Website</span>
                        <input name="website" type="url" value="{{ old('website') }}" class="w-full rounded-xl border-zinc-300 text-sm" />
                    </label>
                    <label class="space-y-1 text-sm text-zinc-700">
                        <span class="font-semibold">Stage</span>
                        <select name="status" class="w-full rounded-xl border-zinc-300 text-sm">
                            @foreach ($statusOptions as $value => $label)
                                <option value="{{ $value }}" @selected(old('status', 'new') === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="space-y-1 text-sm text-zinc-700">
                        <span class="font-semibold">Source</span>
                        <input name="source" value="{{ old('source', 'Company website') }}" class="w-full rounded-xl border-zinc-300 text-sm" />
                    </label>
                    <label class="space-y-1 text-sm text-zinc-700 md:col-span-2">
                        <span class="font-semibold">Notes</span>
                        <textarea name="notes" rows="2" class="w-full rounded-xl border-zinc-300 text-sm">{{ old('notes') }}</textarea>
                    </label>
                    <div class="flex items-end">
                        <button class="rounded-xl bg-zinc-900 px-4 py-2.5 text-xs font-semibold text-white hover:bg-zinc-800">Add prospect</button>
                    </div>
                </form>
            </details>
        </section>

        <section id="prospects" class="overflow-hidden rounded-3xl border border-zinc-200 bg-white shadow-sm scroll-mt-24">
            <div class="border-b border-zinc-200 p-5">
                <div class="flex flex-wrap items-end justify-between gap-4">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-[0.18em] text-zinc-500">Working sheet</p>
                        <h3 class="mt-1 text-xl font-semibold text-zinc-950">Pickens + Greenville trade prospects</h3>
                        <p class="mt-1 text-sm text-zinc-600">Open any row to edit notes, log emails and replies, schedule follow-up, or convert the business to an Everbranch customer.</p>
                    </div>
                    <p class="text-xs font-semibold text-zinc-500">{{ number_format($prospects->total()) }} matching</p>
                </div>

                <form method="GET" action="{{ route('landlord.onboarding.prospects.index') }}" class="mt-5 grid gap-3 md:grid-cols-2 xl:grid-cols-[minmax(220px,1fr)_160px_160px_180px_170px_auto]">
                    <input name="q" value="{{ $filters['q'] }}" placeholder="Search business, email, phone, or notes" class="rounded-xl border-zinc-300 text-sm" />
                    <select name="status" class="rounded-xl border-zinc-300 text-sm">
                        <option value="all">All stages</option>
                        @foreach ($statusOptions as $value => $label)
                            <option value="{{ $value }}" @selected($filters['status'] === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                    <select name="trade" class="rounded-xl border-zinc-300 text-sm">
                        <option value="all">All trades</option>
                        @foreach ($tradeOptions as $trade)
                            <option value="{{ $trade }}" @selected($filters['trade'] === $trade)>{{ $trade }}</option>
                        @endforeach
                    </select>
                    <select name="county" class="rounded-xl border-zinc-300 text-sm">
                        <option value="all">All counties</option>
                        @foreach ($countyOptions as $county)
                            <option value="{{ $county }}" @selected($filters['county'] === $county)>{{ $county }}</option>
                        @endforeach
                    </select>
                    <select name="website" class="rounded-xl border-zinc-300 text-sm">
                        <option value="all">Any website status</option>
                        <option value="missing" @selected($filters['website'] === 'missing')>No website URL</option>
                        <option value="present" @selected($filters['website'] === 'present')>Website present</option>
                    </select>
                    <button class="rounded-xl bg-zinc-900 px-4 py-2 text-xs font-semibold text-white hover:bg-zinc-800">Filter</button>
                </form>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full min-w-[1180px] divide-y divide-zinc-200 text-sm">
                    <thead class="bg-zinc-50 text-left text-xs uppercase tracking-[0.12em] text-zinc-500">
                        <tr>
                            <th class="px-4 py-3">Business</th>
                            <th class="px-4 py-3">Trade + market</th>
                            <th class="px-4 py-3">Contact</th>
                            <th class="px-4 py-3">Stage</th>
                            <th class="px-4 py-3">Last touch</th>
                            <th class="px-4 py-3">Next follow-up</th>
                            <th class="px-4 py-3 text-right">Work it</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-200 bg-white">
                        @forelse ($prospects as $prospect)
                            @php
                                $template = strtolower((string) $prospect->trade) === 'landscaping'
                                    ? 'landscaping'
                                    : (strtolower((string) $prospect->trade) === 'electrical' ? 'electrician' : 'generic');
                                $latestCommunication = $prospect->communications->first();
                            @endphp
                            <tr class="align-top">
                                <td class="px-4 py-4">
                                    <div class="font-semibold text-zinc-950">{{ $prospect->business_name }}</div>
                                    <div class="mt-1 text-xs text-zinc-500">{{ $prospect->contact_name ?: 'Contact not identified' }}</div>
                                    @if ($prospect->website)
                                        <a href="{{ $prospect->website }}" target="_blank" rel="noopener" class="mt-2 inline-flex text-xs font-semibold text-zinc-600 hover:text-zinc-950">Website ↗</a>
                                    @elseif (in_array($prospect->website_status, ['missing_verified', 'missing_unverified'], true))
                                        <span class="mt-2 inline-flex rounded-full border border-amber-200 bg-amber-50 px-2 py-1 text-[11px] font-semibold text-amber-800">
                                            {{ $prospect->website_status === 'missing_verified' ? 'No website · reviewed' : 'No website URL · verify' }}
                                        </span>
                                    @endif
                                    @if ($prospect->google_maps_url)
                                        <a href="{{ $prospect->google_maps_url }}" target="_blank" rel="noopener" class="mt-2 ml-2 inline-flex text-xs font-semibold text-blue-700 hover:text-blue-900">Maps ↗</a>
                                    @endif
                                </td>
                                <td class="px-4 py-4 text-zinc-700">
                                    <div class="font-semibold text-zinc-900">{{ $prospect->trade }}</div>
                                    <div class="mt-1 text-xs text-zinc-500">{{ collect([$prospect->city, $prospect->county ? $prospect->county.' County' : null])->filter()->implode(' · ') }}</div>
                                    @if ($prospect->google_review_count)
                                        <div class="mt-2 text-xs text-zinc-500">{{ number_format((float) $prospect->google_rating, 1) }} ★ · {{ number_format((int) $prospect->google_review_count) }} reviews</div>
                                    @endif
                                </td>
                                <td class="px-4 py-4">
                                    @if ($prospect->email)
                                        <a href="mailto:{{ $prospect->email }}" class="block font-semibold text-zinc-900 hover:underline">{{ $prospect->email }}</a>
                                    @else
                                        <span class="text-zinc-500">No email</span>
                                    @endif
                                    @if ($prospect->phone)
                                        <a href="tel:{{ $prospect->phone }}" class="mt-1 block text-xs text-zinc-600 hover:text-zinc-950">{{ $prospect->phone }}</a>
                                    @endif
                                </td>
                                <td class="px-4 py-4">
                                    <span class="inline-flex rounded-full border px-2.5 py-1 text-[11px] font-semibold {{ $statusStyles[$prospect->status] ?? $statusStyles['new'] }}">
                                        {{ $statusOptions[$prospect->status] ?? \Illuminate\Support\Str::headline($prospect->status) }}
                                    </span>
                                    <div class="mt-2 text-xs text-zinc-500">{{ $prospect->communications->count() }} timeline {{ \Illuminate\Support\Str::plural('item', $prospect->communications->count()) }}</div>
                                </td>
                                <td class="px-4 py-4 text-xs text-zinc-600">
                                    @if ($prospect->last_contacted_at)
                                        <div class="font-semibold text-zinc-900">{{ $prospect->last_contacted_at->format('M j, Y') }}</div>
                                        <div>{{ $prospect->last_contacted_at->diffForHumans() }}</div>
                                    @elseif ($latestCommunication)
                                        <div class="font-semibold text-zinc-900">{{ $latestCommunication->occurred_at?->format('M j, Y') }}</div>
                                        <div>{{ \Illuminate\Support\Str::headline($latestCommunication->direction) }} {{ $latestCommunication->channel }}</div>
                                    @else
                                        <span class="text-zinc-400">Not contacted</span>
                                    @endif
                                </td>
                                <td class="px-4 py-4 text-xs text-zinc-600">
                                    @if ($prospect->next_follow_up_at)
                                        <div class="font-semibold {{ $prospect->next_follow_up_at->isPast() ? 'text-rose-700' : 'text-zinc-900' }}">{{ $prospect->next_follow_up_at->format('M j, Y g:ia') }}</div>
                                        <div>{{ $prospect->next_follow_up_at->diffForHumans() }}</div>
                                    @else
                                        <span class="text-zinc-400">Not scheduled</span>
                                    @endif
                                </td>
                                <td class="px-4 py-4 text-right">
                                    <details class="inline-block text-left">
                                        <summary class="cursor-pointer list-none rounded-xl border border-zinc-300 px-3 py-2 text-xs font-semibold text-zinc-700 hover:bg-zinc-50">Open record</summary>
                                        <div class="fixed inset-0 z-50 overflow-y-auto bg-zinc-950/50 p-4 sm:p-8">
                                            <div class="mx-auto max-w-5xl rounded-3xl bg-white p-5 shadow-2xl sm:p-7">
                                                <div class="flex flex-wrap items-start justify-between gap-4 border-b border-zinc-200 pb-5">
                                                    <div>
                                                        <p class="text-xs font-semibold uppercase tracking-[0.18em] text-zinc-500">{{ $prospect->trade }} · {{ $prospect->county }} County</p>
                                                        <h4 class="mt-1 text-2xl font-semibold text-zinc-950">{{ $prospect->business_name }}</h4>
                                                        <p class="mt-1 text-sm text-zinc-600">{{ collect([$prospect->contact_name, $prospect->email, $prospect->phone])->filter()->implode(' · ') }}</p>
                                                    </div>
                                                    <button type="button" onclick="this.closest('details').removeAttribute('open')" class="rounded-xl border border-zinc-300 px-3 py-2 text-xs font-semibold text-zinc-700 hover:bg-zinc-50">Close</button>
                                                </div>

                                                <div class="mt-6 grid gap-6 xl:grid-cols-2">
                                                    <section class="rounded-2xl border border-zinc-200 p-4">
                                                        <h5 class="font-semibold text-zinc-950">Prospect details</h5>
                                                        @if ($prospect->formatted_address || $prospect->google_maps_url)
                                                            <div class="mt-3 rounded-xl border border-blue-100 bg-blue-50 p-3 text-xs leading-5 text-blue-900">
                                                                <p class="font-semibold">Public discovery evidence</p>
                                                                <p>{{ $prospect->formatted_address ?: 'Address not returned' }}</p>
                                                                <p class="mt-1">
                                                                    {{ $prospect->google_review_count ? number_format((float) $prospect->google_rating, 1).' stars from '.number_format((int) $prospect->google_review_count).' reviews' : 'No review count returned' }}
                                                                    · {{ $prospect->website_status === 'missing_verified' ? 'website absence manually reviewed' : ($prospect->website_status === 'missing_unverified' ? 'website URL missing; manual review required' : 'website returned') }}
                                                                </p>
                                                                @if ($prospect->google_maps_url)
                                                                    <a href="{{ $prospect->google_maps_url }}" target="_blank" rel="noopener" class="mt-1 inline-flex font-semibold underline">Recheck on Google Maps ↗</a>
                                                                @endif
                                                            </div>
                                                        @endif
                                                        <form method="POST" action="{{ route('landlord.onboarding.prospects.update', $prospect) }}" class="mt-4 grid gap-3 sm:grid-cols-2">
                                                            @csrf
                                                            @method('PATCH')
                                                            <label class="space-y-1 text-xs font-semibold text-zinc-600">
                                                                <span>Stage</span>
                                                                <select name="status" class="w-full rounded-xl border-zinc-300 text-sm font-normal text-zinc-900">
                                                                    @foreach ($statusOptions as $value => $label)
                                                                        <option value="{{ $value }}" @selected($prospect->status === $value)>{{ $label }}</option>
                                                                    @endforeach
                                                                </select>
                                                            </label>
                                                            <label class="space-y-1 text-xs font-semibold text-zinc-600">
                                                                <span>Next follow-up</span>
                                                                <input name="next_follow_up_at" type="datetime-local" value="{{ optional($prospect->next_follow_up_at)?->format('Y-m-d\TH:i') }}" class="w-full rounded-xl border-zinc-300 text-sm font-normal text-zinc-900" />
                                                            </label>
                                                            <label class="space-y-1 text-xs font-semibold text-zinc-600 sm:col-span-2">
                                                                <span>Notes</span>
                                                                <textarea name="notes" rows="5" class="w-full rounded-xl border-zinc-300 text-sm font-normal text-zinc-900">{{ $prospect->notes }}</textarea>
                                                            </label>
                                                            <div class="sm:col-span-2">
                                                                <button class="rounded-xl bg-zinc-900 px-4 py-2 text-xs font-semibold text-white hover:bg-zinc-800">Save record</button>
                                                            </div>
                                                        </form>

                                                        @if ($prospect->convertedTenant)
                                                            <div class="mt-4 rounded-xl border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-900">
                                                                Converted to
                                                                <a class="font-semibold underline" href="{{ route('landlord.tenants.show', $prospect->convertedTenant) }}">{{ $prospect->convertedTenant->name }}</a>.
                                                            </div>
                                                        @else
                                                            <form method="POST" action="{{ route('landlord.tenants.store') }}" class="mt-4 rounded-xl border border-emerald-200 bg-emerald-50 p-4">
                                                                @csrf
                                                                <input type="hidden" name="prospect_id" value="{{ $prospect->id }}" />
                                                                <input type="hidden" name="name" value="{{ $prospect->business_name }}" />
                                                                <input type="hidden" name="primary_contact_email" value="{{ $prospect->email }}" />
                                                                <input type="hidden" name="tenant_type" value="direct" />
                                                                <input type="hidden" name="operating_mode" value="direct" />
                                                                <input type="hidden" name="account_mode" value="production" />
                                                                <input type="hidden" name="data_source_preference" value="undecided" />
                                                                <input type="hidden" name="business_template" value="{{ $template }}" />
                                                                <input type="hidden" name="role" value="manager" />
                                                                <input type="hidden" name="status" value="active" />
                                                                <p class="text-xs font-semibold uppercase tracking-[0.14em] text-emerald-800">Ready to become a customer?</p>
                                                                <p class="mt-1 text-sm text-emerald-950">Create a production Everbranch workspace and carry this lead into the tenant directory.</p>
                                                                <button class="mt-3 rounded-xl bg-emerald-700 px-4 py-2 text-xs font-semibold text-white hover:bg-emerald-800">Convert to customer</button>
                                                            </form>
                                                        @endif
                                                    </section>

                                                    <section class="rounded-2xl border border-zinc-200 p-4">
                                                        <h5 class="font-semibold text-zinc-950">Prepare and log outreach</h5>
                                                        <form method="POST" action="{{ route('landlord.onboarding.prospects.drafts.store', $prospect) }}" class="mt-4 rounded-2xl border border-emerald-200 bg-emerald-50 p-4">
                                                            @csrf
                                                            <label class="space-y-1 text-xs font-semibold text-emerald-900">
                                                                <span>Evidence-informed template</span>
                                                                <select name="template" class="w-full rounded-xl border-emerald-200 bg-white text-sm font-normal text-zinc-900">
                                                                    @foreach ($outreachTemplateOptions as $value => $label)
                                                                        <option value="{{ $value }}" @selected($value === ($prospect->website ? 'first_touch' : 'website_gap'))>{{ $label }}</option>
                                                                    @endforeach
                                                                </select>
                                                            </label>
                                                            <p class="mt-2 text-xs leading-5 text-emerald-900">Creates a review-only draft using public facts and Everbranch’s real field-service capabilities. It does not send.</p>
                                                            <button class="mt-3 rounded-xl bg-emerald-700 px-4 py-2 text-xs font-semibold text-white hover:bg-emerald-800">Create draft</button>
                                                        </form>

                                                        <form method="POST" action="{{ route('landlord.onboarding.prospects.communications.store', $prospect) }}" class="mt-4 grid gap-3 sm:grid-cols-3">
                                                            @csrf
                                                            <label class="space-y-1 text-xs font-semibold text-zinc-600">
                                                                <span>Direction</span>
                                                                <select name="direction" class="w-full rounded-xl border-zinc-300 text-sm font-normal text-zinc-900">
                                                                    <option value="outbound">Outbound</option>
                                                                    <option value="inbound">Inbound response</option>
                                                                    <option value="note">Internal note</option>
                                                                </select>
                                                            </label>
                                                            <label class="space-y-1 text-xs font-semibold text-zinc-600">
                                                                <span>Channel</span>
                                                                <select name="channel" class="w-full rounded-xl border-zinc-300 text-sm font-normal text-zinc-900">
                                                                    <option value="email">Email</option>
                                                                    <option value="phone">Phone</option>
                                                                    <option value="meeting">Meeting</option>
                                                                    <option value="note">Note</option>
                                                                </select>
                                                            </label>
                                                            <label class="space-y-1 text-xs font-semibold text-zinc-600">
                                                                <span>Status</span>
                                                                <select name="communication_status" class="w-full rounded-xl border-zinc-300 text-sm font-normal text-zinc-900">
                                                                    <option value="draft">Draft</option>
                                                                    <option value="sent">Sent</option>
                                                                    <option value="received">Received</option>
                                                                    <option value="replied">Replied</option>
                                                                    <option value="scheduled">Scheduled</option>
                                                                    <option value="logged">Logged</option>
                                                                </select>
                                                            </label>
                                                            <label class="space-y-1 text-xs font-semibold text-zinc-600 sm:col-span-3">
                                                                <span>Subject</span>
                                                                <input name="subject" class="w-full rounded-xl border-zinc-300 text-sm font-normal text-zinc-900" />
                                                            </label>
                                                            <label class="space-y-1 text-xs font-semibold text-zinc-600 sm:col-span-3">
                                                                <span>Message, response, or call notes</span>
                                                                <textarea name="body" required rows="5" class="w-full rounded-xl border-zinc-300 text-sm font-normal text-zinc-900"></textarea>
                                                            </label>
                                                            <input type="hidden" name="from_address" value="{{ $prospect->email }}" />
                                                            <input type="hidden" name="to_address" value="john@evergrovesoftware.com" />
                                                            <div class="sm:col-span-3">
                                                                <button class="rounded-xl bg-zinc-900 px-4 py-2 text-xs font-semibold text-white hover:bg-zinc-800">Add to timeline</button>
                                                            </div>
                                                        </form>
                                                    </section>
                                                </div>

                                                <section class="mt-6">
                                                    <div class="flex items-center justify-between gap-3">
                                                        <h5 class="font-semibold text-zinc-950">Email and communication timeline</h5>
                                                        <span class="text-xs text-zinc-500">{{ $prospect->communications->count() }} items</span>
                                                    </div>
                                                    <div class="mt-3 space-y-3">
                                                        @forelse ($prospect->communications as $communication)
                                                            <article class="rounded-2xl border border-zinc-200 bg-zinc-50 p-4">
                                                                <div class="flex flex-wrap items-center justify-between gap-2">
                                                                    <div class="flex flex-wrap items-center gap-2">
                                                                        <span class="rounded-full bg-white px-2.5 py-1 text-[11px] font-semibold text-zinc-700">{{ \Illuminate\Support\Str::headline($communication->direction) }}</span>
                                                                        <span class="text-xs font-semibold text-zinc-700">{{ \Illuminate\Support\Str::headline($communication->channel) }} · {{ \Illuminate\Support\Str::headline($communication->status) }}</span>
                                                                    </div>
                                                                    <time class="text-xs text-zinc-500">{{ optional($communication->occurred_at)?->format('M j, Y g:ia') }}</time>
                                                                </div>
                                                                @if ($communication->subject)
                                                                    <h6 class="mt-3 font-semibold text-zinc-950">{{ $communication->subject }}</h6>
                                                                @endif
                                                                <p class="mt-2 whitespace-pre-line text-sm leading-6 text-zinc-700">{{ $communication->body }}</p>
                                                                @if ($communication->direction === 'outbound' && $communication->channel === 'email' && $communication->status === 'draft')
                                                                    <div class="mt-4 flex flex-wrap gap-2 border-t border-zinc-200 pt-3">
                                                                        @if ($prospect->email)
                                                                            <a href="mailto:{{ $prospect->email }}?subject={{ rawurlencode((string) $communication->subject) }}&body={{ rawurlencode((string) $communication->body) }}" class="rounded-xl bg-zinc-900 px-3 py-2 text-xs font-semibold text-white hover:bg-zinc-800">Open in email app</a>
                                                                        @else
                                                                            <span class="rounded-xl bg-amber-50 px-3 py-2 text-xs font-semibold text-amber-800">Add a public business email before emailing</span>
                                                                        @endif
                                                                        <form method="POST" action="{{ route('landlord.onboarding.prospects.communications.sent', [$prospect, $communication]) }}">
                                                                            @csrf
                                                                            @method('PATCH')
                                                                            <button class="rounded-xl border border-emerald-300 bg-white px-3 py-2 text-xs font-semibold text-emerald-800 hover:bg-emerald-50">Mark sent + schedule follow-up</button>
                                                                        </form>
                                                                    </div>
                                                                @endif
                                                            </article>
                                                        @empty
                                                            <div class="rounded-2xl border border-dashed border-zinc-300 bg-zinc-50 p-5 text-sm text-zinc-500">No communications logged yet. Gmail drafts can be copied here after review, and replies can be logged as they arrive.</div>
                                                        @endforelse
                                                    </div>
                                                </section>
                                            </div>
                                        </div>
                                    </details>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="px-4 py-12 text-center text-sm text-zinc-500">No prospects match the current filters.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="border-t border-zinc-200 px-4 py-3">
                {{ $prospects->links() }}
            </div>
        </section>
    </div>
</x-app-layout>

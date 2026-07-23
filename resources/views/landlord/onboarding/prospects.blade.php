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
            <div class="grid gap-8 p-6 lg:grid-cols-[minmax(0,1fr)_auto] lg:p-8">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.24em] text-emerald-300">Evergrove launch partners</p>
                    <h2 class="mt-3 max-w-3xl text-3xl font-semibold tracking-tight sm:text-4xl">Turn local conversations into long-term customers.</h2>
                    <p class="mt-3 max-w-3xl text-sm leading-6 text-zinc-300">
                        A focused pipeline for trade businesses in Pickens and Greenville counties. Keep every address, call, note, email, response, appointment, and conversion in one place.
                    </p>
                    <div class="mt-5 flex flex-wrap gap-2">
                        <a href="#prospects" class="rounded-xl bg-emerald-400 px-4 py-2 text-xs font-semibold text-zinc-950 hover:bg-emerald-300">View prospect sheet</a>
                        <a href="{{ route('landlord.onboarding.prospects.export', request()->query()) }}" class="rounded-xl border border-white/20 px-4 py-2 text-xs font-semibold text-white hover:bg-white/10">Export CSV</a>
                        <a href="{{ route('landlord.dashboard') }}" class="rounded-xl border border-white/20 px-4 py-2 text-xs font-semibold text-white hover:bg-white/10">Landlord dashboard</a>
                    </div>
                </div>
                <div class="min-w-56 rounded-2xl border border-emerald-300/30 bg-emerald-300/10 p-5">
                    <p class="text-xs font-semibold uppercase tracking-[0.18em] text-emerald-200">Launch availability</p>
                    <p class="mt-2 text-4xl font-semibold">{{ $metrics['launch_partner_spots_open'] }}/{{ $metrics['launch_partner_spots_total'] }}</p>
                    <p class="mt-1 text-sm text-emerald-100">spots currently open</p>
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

        <section class="grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
            @foreach ([
                ['label' => 'Prospects', 'value' => $metrics['total'], 'copy' => 'in the local pipeline'],
                ['label' => 'Drafts ready', 'value' => $metrics['draft_ready'], 'copy' => 'ready for review'],
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

                <form method="GET" action="{{ route('landlord.onboarding.prospects.index') }}" class="mt-5 grid gap-3 md:grid-cols-2 xl:grid-cols-[minmax(220px,1fr)_180px_180px_200px_auto]">
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
                                    @endif
                                </td>
                                <td class="px-4 py-4 text-zinc-700">
                                    <div class="font-semibold text-zinc-900">{{ $prospect->trade }}</div>
                                    <div class="mt-1 text-xs text-zinc-500">{{ collect([$prospect->city, $prospect->county ? $prospect->county.' County' : null])->filter()->implode(' · ') }}</div>
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
                                                        <h5 class="font-semibold text-zinc-950">Log communication or response</h5>
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

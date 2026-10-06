<x-app-layout>
    <x-slot name="header">
        <h1 class="text-xl font-semibold text-zinc-900">Prospect workspace</h1>
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
        $icon = static function (string $name): string {
            return match ($name) {
                'mail' => '<svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/></svg>',
                'template' => '<svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" aria-hidden="true"><path d="M6 3h9l4 4v14H6z"/><path d="M14 3v5h5M9 13h6M9 17h5"/></svg>',
                'details' => '<svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" aria-hidden="true"><circle cx="12" cy="12" r="8"/><path d="M12 10v5m0-8h.01"/></svg>',
                'close' => '<svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="m6 6 12 12M18 6 6 18"/></svg>',
            };
        };
    @endphp

    <style>[x-cloak] { display: none !important; }</style>

    <div x-data="{ composerId: {{ (int) session('open_composer_id', 0) }}, leadId: {{ (int) session('open_prospect_id', 0) }}, closeAll() { this.composerId = 0; this.leadId = 0; } }" class="space-y-5">
        <section class="overflow-hidden rounded-3xl border border-zinc-200 bg-zinc-950 text-white shadow-sm">
            <div class="flex flex-col gap-5 p-6 sm:p-8 lg:flex-row lg:items-end lg:justify-between">
                <div class="max-w-3xl">
                    <p class="text-xs font-semibold uppercase tracking-[.2em] text-emerald-300">Evergrove launch partners</p>
                    <h2 class="mt-2 text-3xl font-semibold tracking-tight sm:text-4xl">One lead sheet. One clear next action.</h2>
                    <p class="mt-3 text-sm leading-6 text-zinc-300">Replies and follow-ups stay on top. Start a new email, load a proven template, personalize it, and send it without leaving Everbranch.</p>
                </div>
                <div class="flex shrink-0 flex-wrap gap-2">
                    <a href="#prospects" class="rounded-xl bg-emerald-400 px-4 py-2.5 text-xs font-semibold text-zinc-950 hover:bg-emerald-300">Work priority leads</a>
                    <a href="#finder" class="rounded-xl border border-white/20 px-4 py-2.5 text-xs font-semibold hover:bg-white/10">Find leads</a>
                </div>
            </div>
        </section>

        @if (session('status'))
            <div class="rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-900">{{ session('status') }}</div>
        @endif
        @if ($errors->any())
            <div class="rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-900">
                <p class="font-semibold">Please fix the highlighted information.</p>
                <ul class="mt-2 list-disc space-y-1 pl-5">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
            </div>
        @endif

        <section class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            @foreach ([
                ['Replies to answer', $metrics['replied'], 'bg-emerald-50 text-emerald-900'],
                ['Follow-up due', $metrics['follow_up_due'], 'bg-rose-50 text-rose-900'],
                ['Drafts ready', $metrics['draft_ready'], 'bg-blue-50 text-blue-900'],
                ['New prospects', $metrics['total'], 'bg-zinc-50 text-zinc-900'],
            ] as [$label, $value, $style])
                <article class="rounded-2xl border border-zinc-200 p-4 shadow-sm {{ $style }}">
                    <p class="text-xs font-semibold uppercase tracking-[.14em] opacity-70">{{ $label }}</p>
                    <p class="mt-1 text-3xl font-semibold">{{ number_format((int) $value) }}</p>
                </article>
            @endforeach
        </section>

        <section id="prospects" class="overflow-hidden rounded-3xl border border-zinc-200 bg-white shadow-sm scroll-mt-24">
            <div class="border-b border-zinc-200 p-5">
                <div class="flex flex-wrap items-end justify-between gap-4">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-[.18em] text-zinc-500">Priority lead sheet</p>
                        <h3 class="mt-1 text-xl font-semibold text-zinc-950">Start with replies, then work the next touch</h3>
                        <p class="mt-1 text-sm text-zinc-600">The action rail keeps the three most common tasks in reach: new email, load a template, or open the full lead record.</p>
                    </div>
                    <a href="{{ route('landlord.onboarding.prospects.export', request()->query()) }}" class="rounded-xl border border-zinc-300 px-3 py-2 text-xs font-semibold text-zinc-700 hover:bg-zinc-50">Export sheet</a>
                </div>
                <form method="GET" action="{{ route('landlord.onboarding.prospects.index') }}" class="mt-5 grid gap-2 md:grid-cols-2 xl:grid-cols-[minmax(220px,1fr)_160px_160px_170px_auto]">
                    <input name="q" value="{{ $filters['q'] }}" placeholder="Search business, email, phone, or notes" class="rounded-xl border-zinc-300 text-sm" />
                    <select name="status" class="rounded-xl border-zinc-300 text-sm"><option value="all">All stages</option>@foreach ($statusOptions as $value => $label)<option value="{{ $value }}" @selected($filters['status'] === $value)>{{ $label }}</option>@endforeach</select>
                    <select name="trade" class="rounded-xl border-zinc-300 text-sm"><option value="all">All trades</option>@foreach ($tradeOptions as $trade)<option value="{{ $trade }}" @selected($filters['trade'] === $trade)>{{ $trade }}</option>@endforeach</select>
                    <select name="website" class="rounded-xl border-zinc-300 text-sm"><option value="all">Any website status</option><option value="missing" @selected($filters['website'] === 'missing')>No website URL</option><option value="present" @selected($filters['website'] === 'present')>Website present</option></select>
                    <button class="rounded-xl bg-zinc-900 px-4 py-2 text-xs font-semibold text-white hover:bg-zinc-800">Filter</button>
                </form>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full min-w-[1050px] divide-y divide-zinc-200 text-sm">
                    <thead class="bg-zinc-50 text-left text-[11px] font-semibold uppercase tracking-[.12em] text-zinc-500">
                        <tr>
                            <th class="w-36 px-3 py-3">Actions</th><th class="px-3 py-3">Lead</th><th class="px-3 py-3">Trade / market</th><th class="px-3 py-3">Stage</th><th class="px-3 py-3">Last touch</th><th class="px-3 py-3">Next step</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-200">
                        @forelse ($prospects as $prospect)
                            @php
                                $latestCommunication = $prospect->communications->first();
                                $defaultTemplate = $prospect->website ? 'first_touch' : 'website_gap';
                            @endphp
                            <tr class="align-middle transition hover:bg-zinc-50/80">
                                <td class="px-3 py-3">
                                    <div class="flex items-center gap-1.5">
                                        <form method="POST" action="{{ route('landlord.onboarding.prospects.email-drafts.store', $prospect) }}">@csrf
                                            <button title="New email" aria-label="New email for {{ $prospect->business_name }}" class="inline-flex h-9 w-9 items-center justify-center rounded-lg bg-zinc-900 text-white hover:bg-zinc-700">{!! $icon('mail') !!}</button>
                                        </form>
                                        <form method="POST" action="{{ route('landlord.onboarding.prospects.drafts.store', $prospect) }}">@csrf<input type="hidden" name="template" value="{{ $defaultTemplate }}" />
                                            <button title="Load recommended template" aria-label="Load recommended template for {{ $prospect->business_name }}" class="inline-flex h-9 w-9 items-center justify-center rounded-lg border border-zinc-300 text-zinc-700 hover:bg-white">{!! $icon('template') !!}</button>
                                        </form>
                                        <button type="button" @click="leadId = {{ $prospect->id }}" title="Open lead record" aria-label="Open {{ $prospect->business_name }} record" class="inline-flex h-9 w-9 items-center justify-center rounded-lg border border-zinc-300 text-zinc-700 hover:bg-white">{!! $icon('details') !!}</button>
                                    </div>
                                </td>
                                <td class="px-3 py-3"><p class="font-semibold text-zinc-950">{{ $prospect->business_name }}</p><p class="mt-0.5 text-xs text-zinc-500">{{ $prospect->contact_name ?: 'Contact not identified' }}@if($prospect->email) · {{ $prospect->email }}@endif</p></td>
                                <td class="px-3 py-3"><p class="font-medium text-zinc-900">{{ $prospect->trade }}</p><p class="mt-0.5 text-xs text-zinc-500">{{ collect([$prospect->city, $prospect->county ? $prospect->county.' County' : null])->filter()->implode(' · ') }}</p></td>
                                <td class="px-3 py-3"><span class="inline-flex rounded-full border px-2.5 py-1 text-[11px] font-semibold {{ $statusStyles[$prospect->status] ?? $statusStyles['new'] }}">{{ $statusOptions[$prospect->status] ?? \Illuminate\Support\Str::headline($prospect->status) }}</span></td>
                                <td class="px-3 py-3 text-xs text-zinc-600">@if ($prospect->last_contacted_at)<strong class="font-semibold text-zinc-900">{{ $prospect->last_contacted_at->format('M j') }}</strong><br>{{ $prospect->last_contacted_at->diffForHumans() }}@elseif ($latestCommunication){{ \Illuminate\Support\Str::headline($latestCommunication->direction) }} {{ $latestCommunication->channel }}@else<span class="text-zinc-400">Not contacted</span>@endif</td>
                                <td class="px-3 py-3 text-xs text-zinc-600">@if ($prospect->status === 'replied')<strong class="text-emerald-700">Reply now</strong>@elseif($prospect->next_follow_up_at)<strong class="{{ $prospect->next_follow_up_at->isPast() ? 'text-rose-700' : 'text-zinc-900' }}">{{ $prospect->next_follow_up_at->format('M j, g:ia') }}</strong><br>{{ $prospect->next_follow_up_at->diffForHumans() }}@elseif($prospect->website_status === 'missing_verified')<strong class="text-amber-700">Website opportunity</strong>@else<span class="text-zinc-400">Create a first touch</span>@endif</td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="px-4 py-12 text-center text-sm text-zinc-500">No prospects match the current filters.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="border-t border-zinc-200 px-4 py-3">{{ $prospects->links() }}</div>
        </section>

        <section id="finder" class="grid scroll-mt-24 gap-5 xl:grid-cols-[1.3fr_.7fr]">
            <div class="rounded-3xl border border-zinc-200 bg-white p-5 shadow-sm">
                <div class="flex flex-wrap items-start justify-between gap-3"><div><p class="text-xs font-semibold uppercase tracking-[.18em] text-zinc-500">Prospect finder</p><h3 class="mt-1 text-xl font-semibold text-zinc-950">Search Google Places</h3></div><span class="rounded-full border px-3 py-1 text-xs font-semibold {{ $discoveryConfigured ? 'border-emerald-200 bg-emerald-50 text-emerald-800' : 'border-amber-200 bg-amber-50 text-amber-800' }}">{{ $discoveryConfigured ? 'Places API ready' : 'API key needed' }}</span></div>
                <form method="POST" action="{{ route('landlord.onboarding.prospects.discovery.store') }}" class="mt-5 grid gap-3 sm:grid-cols-2">@csrf
                    <label class="space-y-1 text-xs font-semibold text-zinc-600"><span>Trade</span><input name="trade" required value="{{ old('trade', 'HVAC') }}" class="w-full rounded-xl border-zinc-300 text-sm font-normal" /></label>
                    <label class="space-y-1 text-xs font-semibold text-zinc-600"><span>City, county, or market</span><input name="search_region" required value="{{ old('search_region', 'Powdersville, SC') }}" class="w-full rounded-xl border-zinc-300 text-sm font-normal" /></label>
                    <label class="space-y-1 text-xs font-semibold text-zinc-600"><span>Website priority</span><select name="website_preference" class="w-full rounded-xl border-zinc-300 text-sm font-normal"><option value="missing_only">Only listings with no website URL</option><option value="all">All matching listings</option></select></label>
                    <label class="space-y-1 text-xs font-semibold text-zinc-600"><span>Maximum results</span><select name="maximum_results" class="w-full rounded-xl border-zinc-300 text-sm font-normal">@foreach ([5, 10, 15, 20] as $maximum)<option value="{{ $maximum }}" @selected((int) old('maximum_results', 10) === $maximum)>{{ $maximum }}</option>@endforeach</select></label>
                    <label class="flex items-start gap-2 rounded-xl bg-zinc-50 p-3 text-xs leading-5 text-zinc-700 sm:col-span-2"><input name="confirm_cost" type="checkbox" value="1" required class="mt-1 rounded border-zinc-300" /><span>I approve one Places request, estimated at <strong>${{ number_format($estimatedDiscoveryCost, 4) }}</strong>.</span></label>
                    <div class="sm:col-span-2"><button @disabled(! $discoveryConfigured) class="rounded-xl bg-emerald-700 px-4 py-2.5 text-xs font-semibold text-white hover:bg-emerald-800 disabled:bg-zinc-300">Find prospects</button></div>
                </form>
            </div>
            <details class="rounded-3xl border border-zinc-200 bg-white p-5 shadow-sm"><summary class="cursor-pointer list-none"><p class="text-xs font-semibold uppercase tracking-[.18em] text-zinc-500">Add a lead</p><h3 class="mt-1 text-xl font-semibold text-zinc-950">New prospect <span class="text-sm font-normal text-zinc-500">— open form</span></h3></summary>
                <form method="POST" action="{{ route('landlord.onboarding.prospects.store') }}" class="mt-5 grid gap-3 border-t border-zinc-200 pt-5">@csrf
                    <input name="business_name" required placeholder="Business name" class="rounded-xl border-zinc-300 text-sm" /><input name="contact_name" placeholder="Contact name" class="rounded-xl border-zinc-300 text-sm" /><input name="trade" required placeholder="Trade" class="rounded-xl border-zinc-300 text-sm" /><input name="county" required placeholder="County" class="rounded-xl border-zinc-300 text-sm" /><input name="email" type="email" placeholder="Public business email" class="rounded-xl border-zinc-300 text-sm" /><input name="phone" placeholder="Phone" class="rounded-xl border-zinc-300 text-sm" /><textarea name="notes" rows="3" placeholder="Notes" class="rounded-xl border-zinc-300 text-sm"></textarea><button class="w-fit rounded-xl bg-zinc-900 px-4 py-2 text-xs font-semibold text-white">Add prospect</button>
                </form>
            </details>
        </section>

        @foreach ($prospects as $prospect)
            @foreach ($prospect->communications->where('direction', 'outbound')->where('channel', 'email')->where('status', 'draft') as $communication)
                <div x-cloak x-show="composerId === {{ $communication->id }}" x-transition.opacity class="fixed inset-0 z-50 flex items-end justify-center bg-zinc-950/45 p-0 sm:items-center sm:p-5" @keydown.escape.window="composerId = 0">
                    <div x-data="{ subject: @js($communication->subject), body: @js($communication->body), templates: @js($outreachTemplatePayloads[$prospect->id] ?? []), templateMessage: '', loadTemplate(key) { const template = this.templates[key]; if (!template) return; this.subject = template.subject; this.body = template.body; this.templateMessage = 'Template loaded — review and save or send.'; } }" class="flex h-[min(720px,100dvh)] w-full max-w-3xl flex-col overflow-hidden rounded-t-2xl bg-white shadow-2xl sm:h-[min(720px,calc(100dvh-2.5rem))] sm:rounded-2xl">
                        <header class="flex items-center justify-between bg-zinc-900 px-4 py-3 text-white"><div><p class="text-sm font-semibold">New message</p><p class="text-xs text-zinc-300">{{ $prospect->business_name }}</p></div><button type="button" @click="composerId = 0" class="inline-flex h-8 w-8 items-center justify-center rounded-lg hover:bg-white/10">{!! $icon('close') !!}<span class="sr-only">Close composer</span></button></header>
                        <form method="POST" action="{{ route('landlord.onboarding.prospects.communications.send', [$prospect, $communication]) }}" class="flex min-h-0 flex-1 flex-col">@csrf
                            <div class="border-b border-zinc-200 px-4 text-sm"><div class="flex gap-3 border-b border-zinc-100 py-2"><span class="w-12 shrink-0 text-zinc-500">From</span><span class="font-medium text-zinc-800">john@evergrovesoftware.com</span></div><div class="flex gap-3 border-b border-zinc-100 py-2"><span class="w-12 shrink-0 text-zinc-500">To</span><span class="font-medium text-zinc-800">{{ $prospect->email ?: 'Add a public business email to send' }}</span></div><input name="subject" required x-model="subject" aria-label="Email subject" placeholder="Subject" class="w-full border-0 px-0 py-3 text-sm font-medium text-zinc-900 focus:ring-0" /></div>
                            <textarea name="body" required x-model="body" aria-label="Email body" class="min-h-0 flex-1 resize-none border-0 p-4 text-sm leading-6 text-zinc-800 focus:ring-0"></textarea>
                            <div class="flex flex-wrap items-center justify-between gap-3 border-t border-zinc-200 bg-zinc-50 px-4 py-3"><div class="flex flex-wrap gap-2"><button class="rounded-lg bg-emerald-700 px-4 py-2 text-xs font-semibold text-white hover:bg-emerald-800" @disabled(! $prospect->email || $prospect->status === 'unsubscribed') onclick="return confirm('Send this reviewed email from john@evergrovesoftware.com to {{ $prospect->email }}?')">Send</button><button type="submit" formaction="{{ route('landlord.onboarding.prospects.communications.save', [$prospect, $communication]) }}" class="rounded-lg border border-zinc-300 bg-white px-3 py-2 text-xs font-semibold text-zinc-700 hover:bg-zinc-100">Save draft</button></div><span class="text-xs text-zinc-500">Sending records the email and schedules follow-up.</span></div>
                        </form>
                        <footer class="border-t border-zinc-200 px-4 py-3"><div class="flex flex-wrap items-center gap-2"><span class="mr-1 text-xs font-semibold text-zinc-500">Load template:</span>@foreach ($outreachTemplateOptions as $value => $label)<button type="button" @click="loadTemplate(@js($value))" class="rounded-lg border border-zinc-300 bg-white px-2.5 py-1.5 text-xs font-semibold text-zinc-700 hover:bg-zinc-100">{{ $label }}</button>@endforeach</div><p x-cloak x-show="templateMessage" x-text="templateMessage" aria-live="polite" class="mt-2 text-xs font-medium text-emerald-800"></p></footer>
                    </div>
                </div>
            @endforeach

            <div x-cloak x-show="leadId === {{ $prospect->id }} && composerId === 0" x-transition.opacity class="fixed inset-0 z-40 flex items-end justify-center bg-zinc-950/45 p-0 sm:items-center sm:p-5" @keydown.escape.window="leadId = 0">
                <div @click.outside="leadId = 0" class="flex h-[min(760px,100dvh)] w-full max-w-3xl flex-col overflow-hidden rounded-t-2xl bg-white shadow-2xl sm:h-[min(760px,calc(100dvh-2.5rem))] sm:rounded-2xl">
                    <header class="flex items-start justify-between border-b border-zinc-200 px-5 py-4"><div><p class="text-xs font-semibold uppercase tracking-[.14em] text-zinc-500">{{ $prospect->trade }} · {{ $prospect->county }} County</p><h4 class="mt-1 text-xl font-semibold text-zinc-950">{{ $prospect->business_name }}</h4><p class="mt-1 text-sm text-zinc-600">{{ collect([$prospect->contact_name, $prospect->email, $prospect->phone])->filter()->implode(' · ') }}</p></div><button type="button" @click="leadId = 0" class="inline-flex h-9 w-9 items-center justify-center rounded-lg border border-zinc-300 text-zinc-700 hover:bg-zinc-50">{!! $icon('close') !!}<span class="sr-only">Close record</span></button></header>
                    <div class="min-h-0 flex-1 overflow-y-auto p-5"><div class="grid gap-5 md:grid-cols-2"><section><h5 class="font-semibold text-zinc-950">Lead details</h5><form method="POST" action="{{ route('landlord.onboarding.prospects.update', $prospect) }}" class="mt-3 grid gap-3">@csrf @method('PATCH')<label class="text-xs font-semibold text-zinc-600">Stage<select name="status" class="mt-1 w-full rounded-xl border-zinc-300 text-sm font-normal">@foreach ($statusOptions as $value => $label)<option value="{{ $value }}" @selected($prospect->status === $value)>{{ $label }}</option>@endforeach</select></label><label class="text-xs font-semibold text-zinc-600">Next follow-up<input name="next_follow_up_at" type="datetime-local" value="{{ optional($prospect->next_follow_up_at)?->format('Y-m-d\TH:i') }}" class="mt-1 w-full rounded-xl border-zinc-300 text-sm font-normal" /></label><label class="text-xs font-semibold text-zinc-600">Notes<textarea name="notes" rows="5" class="mt-1 w-full rounded-xl border-zinc-300 text-sm font-normal">{{ $prospect->notes }}</textarea></label><button class="w-fit rounded-lg bg-zinc-900 px-3 py-2 text-xs font-semibold text-white">Save lead</button></form></section>
                        <section><h5 class="font-semibold text-zinc-950">Record a response</h5><p class="mt-1 text-xs leading-5 text-zinc-600">Recording an inbound reply moves this lead to the top and sends you an SMS when alerts are configured.</p><form method="POST" action="{{ route('landlord.onboarding.prospects.communications.store', $prospect) }}" class="mt-3 grid gap-3">@csrf<input type="hidden" name="direction" value="inbound" /><input type="hidden" name="channel" value="email" /><input type="hidden" name="communication_status" value="received" /><input type="hidden" name="from_address" value="{{ $prospect->email }}" /><input type="hidden" name="to_address" value="john@evergrovesoftware.com" /><input name="subject" placeholder="Reply subject" class="rounded-xl border-zinc-300 text-sm" /><textarea name="body" required rows="5" placeholder="Paste the reply or add a note" class="rounded-xl border-zinc-300 text-sm"></textarea><button class="w-fit rounded-lg bg-emerald-700 px-3 py-2 text-xs font-semibold text-white">Record reply</button></form></section></div>
                        <section class="mt-6 border-t border-zinc-200 pt-5"><h5 class="font-semibold text-zinc-950">Timeline</h5><div class="mt-3 space-y-2">@forelse($prospect->communications as $communication)<article class="rounded-xl border border-zinc-200 p-3"><div class="flex flex-wrap items-center justify-between gap-2"><span class="text-xs font-semibold text-zinc-700">{{ \Illuminate\Support\Str::headline($communication->direction) }} · {{ \Illuminate\Support\Str::headline($communication->channel) }} · {{ \Illuminate\Support\Str::headline($communication->status) }}</span><time class="text-xs text-zinc-500">{{ optional($communication->occurred_at)?->format('M j, Y g:ia') }}</time></div>@if($communication->subject)<p class="mt-2 text-sm font-semibold text-zinc-950">{{ $communication->subject }}</p>@endif<p class="mt-1 whitespace-pre-line text-sm leading-6 text-zinc-700">{{ $communication->body }}</p></article>@empty<div class="rounded-xl border border-dashed border-zinc-300 p-4 text-sm text-zinc-500">No communication has been recorded yet.</div>@endforelse</div></section>
                    </div>
                </div>
            </div>
        @endforeach
    </div>
</x-app-layout>

<x-app-layout>
    <x-slot name="header"><h1 class="text-xl font-semibold text-zinc-950">Help</h1></x-slot>
    <div class="mx-auto max-w-6xl space-y-6">
        @if(session('success'))<div role="status" class="rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900">{{ session('success') }}</div>@endif
        @if($errors->any())<div role="alert" class="rounded-2xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-900">{{ $errors->first() }}</div>@endif
        <div class="grid gap-6 lg:grid-cols-2">
            <section class="rounded-[20px] border border-zinc-200 bg-white p-6">
                <h2 class="text-lg font-semibold">Ask Everbranch</h2>
                <p class="mt-2 text-sm text-zinc-600">Setup guidance, with a person ready when you need one.</p>
                <form method="post" action="{{ route('account-help.bud.ask') }}" class="mt-5 space-y-4">
                    @csrf
                    <label class="block text-sm font-medium" for="help-workflow">Working on</label>
                    <select id="help-workflow" name="workflow" class="w-full rounded-xl border-zinc-300 text-sm">
                        @foreach(['setup' => 'Getting started', 'branches' => 'Branches', 'website' => 'Website', 'domains' => 'Domain', 'payments' => 'Payments', 'integrations' => 'Connections'] as $key => $label)
                            <option value="{{ $key }}" @selected(old('workflow') === $key)>{{ $label }}</option>
                        @endforeach
                    </select>
                    <label class="block text-sm font-medium" for="help-question">Your question</label>
                    <textarea id="help-question" name="question" required maxlength="3000" rows="3" class="w-full rounded-xl border-zinc-300 text-sm" placeholder="What are you trying to do?">{{ old('question') }}</textarea>
                    <div class="flex flex-wrap gap-3">
                        <button class="rounded-xl bg-emerald-900 px-4 py-2.5 text-sm font-semibold text-white">Ask Everbranch</button>
                        <button type="submit" name="escalate" value="1" class="rounded-xl border border-zinc-300 px-4 py-2.5 text-sm font-semibold" formnovalidate>Talk to a person</button>
                    </div>
                </form>
                @unless($budCoreEnabled)<p class="mt-3 text-sm text-zinc-600">The guide is unavailable. Your question will go to the support team.</p>@endunless
                @if(session('bud_answer'))
                    <div role="status" class="mt-4 whitespace-pre-line rounded-2xl bg-emerald-50 p-4 text-sm leading-6 text-emerald-950">{{ session('bud_answer.reply') }}@if(session('bud_answer.ticket_id'))<a class="mt-2 block font-semibold underline" href="{{ route('account-help.show', session('bud_answer.ticket_id')) }}">View ticket →</a>@endif</div>
                @endif
                <details class="mt-5 border-t border-zinc-100 pt-4 text-sm">
                    <summary class="cursor-pointer font-semibold">Bud AI</summary>
                    <p class="mt-2 text-zinc-600">Model-assisted drafts use an approved monthly cap. Sending and publishing always need your confirmation.</p>
                    @if(($budSetting->ai_status ?? 'disabled') !== 'approved')
                        <form method="post" action="{{ route('account-help.bud.ai.request') }}" class="mt-3">@csrf<button class="rounded-xl border border-zinc-300 px-4 py-2 text-sm font-semibold">Request Bud AI</button></form>
                    @else
                        <p class="mt-3">Approved cap: ${{ number_format(($budSetting->ai_monthly_budget_cents ?? 0) / 100, 2) }}/month. Provider readiness is required.</p>
                    @endif
                </details>
            </section>
            <section class="rounded-[20px] border border-zinc-200 bg-white p-6">
                <h2 class="text-lg font-semibold">Support ticket</h2>
                <form method="post" action="{{ route('account-help.store') }}" class="mt-5 space-y-3">
                    @csrf
                    <label class="block text-sm font-medium" for="ticket-subject">Subject</label><input id="ticket-subject" name="subject" required maxlength="180" value="{{ old('subject') }}" class="w-full rounded-xl border-zinc-300 text-sm">
                    <div class="grid grid-cols-2 gap-3">
                        <div><label class="block text-sm font-medium" for="ticket-category">Topic</label><select id="ticket-category" name="category" class="mt-1 w-full rounded-xl border-zinc-300 text-sm"><option value="help">Setup help</option><option value="bug">Something is broken</option><option value="billing">Billing</option><option value="feature">Feature idea</option><option value="account">Account</option></select></div>
                        <div><label class="block text-sm font-medium" for="ticket-priority">Priority</label><select id="ticket-priority" name="priority" class="mt-1 w-full rounded-xl border-zinc-300 text-sm"><option value="normal">Normal</option><option value="high">High</option><option value="urgent">Urgent</option><option value="low">Low</option></select></div>
                    </div>
                    <label class="block text-sm font-medium" for="ticket-body">What happened?</label><textarea id="ticket-body" name="body" required maxlength="8000" rows="4" class="w-full rounded-xl border-zinc-300 text-sm" placeholder="What happened and what have you tried?">{{ old('body') }}</textarea>
                    <button class="rounded-xl bg-emerald-900 px-4 py-2.5 text-sm font-semibold text-white">Send to Everbranch</button>
                </form>
            </section>
        </div>
        <section class="rounded-[20px] border border-zinc-200 bg-white p-6">
            <div class="flex flex-wrap items-center justify-between gap-3"><h2 class="text-lg font-semibold">Your tickets</h2><label class="text-sm"><span class="sr-only">Search tickets</span><input id="ticket-search" type="search" placeholder="Search tickets" class="rounded-xl border-zinc-300 text-sm"></label></div>
            <div id="ticket-list" class="mt-4 divide-y divide-zinc-100">
                @forelse($tickets as $ticket)
                    <a data-ticket href="{{ route('account-help.show', $ticket['id']) }}" class="flex items-center justify-between gap-3 py-4"><div><p class="font-semibold text-zinc-950">{{ $ticket['subject'] }}</p><p class="mt-1 text-xs text-zinc-500">{{ ucfirst(str_replace('_', ' ', $ticket['status'])) }} · {{ $ticket['messages_count'] }} messages</p></div><span aria-hidden="true">→</span></a>
                @empty
                    <p class="py-5 text-sm text-zinc-600">No tickets yet.</p>
                @endforelse
            </div>
        </section>
    </div>
    <script>document.getElementById('ticket-search')?.addEventListener('input',e=>document.querySelectorAll('[data-ticket]').forEach(x=>x.hidden=!x.textContent.toLowerCase().includes(e.target.value.toLowerCase())));</script>
</x-app-layout>

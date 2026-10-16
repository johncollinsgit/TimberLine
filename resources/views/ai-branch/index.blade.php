<x-layouts::app.sidebar title="AI Branch">
    <flux:main class="bg-white">
        <div class="mx-auto max-w-6xl space-y-6 px-4 py-6">
            <header class="space-y-2 border-b border-zinc-200 pb-5">
                <p class="text-sm text-emerald-800">{{ $tenant->name }}</p>
                <h1 class="text-3xl font-semibold">AI Branch</h1>
                <p class="text-sm text-zinc-600">Ask about permitted workspace records, draft text, and review each proposed action.</p>
                <p class="text-sm">{{ $tier['display_name'] }} · ${{ number_format($tier['pricing']['recurring_price_cents'] / 100, 0) }}/month · {{ $usage['responses'] }} / {{ number_format($tier['ai_limits']['responses']) }} requests reserved or used this month.</p>
                <p class="text-sm text-zinc-600">Provider allowance reserved or used: ${{ number_format($usage['provider_micros'] / 1000000, 4) }}. Your cap: ${{ number_format(($account?->budget_cents ?? 0) / 100, 2) }}. No automatic overages. Phone service is a future option.</p>
            </header>
            @if(session('status'))<p role="status" class="rounded-lg bg-emerald-50 p-3 text-emerald-900">{{ session('status') }}</p>@endif
            @if($errors->any())<div role="alert" class="text-red-800">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
            <details @if(!$account?->enabled) open @endif class="border-b border-zinc-200 pb-5">
                <summary class="cursor-pointer font-semibold">Workspace AI settings</summary>
                <form method="post" action="{{ route('ai-branch.settings', ['tenant' => $tenant->slug]) }}" class="mt-4 flex flex-wrap items-end gap-4">@csrf
                    <label class="text-sm">AI access<select name="enabled" class="mt-1 block rounded-lg border-zinc-300"><option value="0">Off</option><option value="1" @selected($account?->enabled)>On</option></select></label>
                    <label class="text-sm">Monthly AI spending cap ($)<input type="number" name="budget_dollars" min="0" step="0.01" max="{{ $tier['ai_limits']['provider_budget_cents'] / 100 }}" value="{{ number_format(($account?->budget_cents ?? $tier['ai_limits']['provider_budget_cents']) / 100, 2, '.', '') }}" required class="mt-1 block rounded-lg border-zinc-300"></label>
                    <label class="text-sm"><input type="checkbox" name="confirmed" value="1" required> I approve sharing permitted context with the AI provider under this cap.</label>
                    <button class="fb-btn fb-btn-primary">Save settings</button>
                </form>
            </details>
            @if($account?->enabled)
            <div class="grid gap-6 lg:grid-cols-[220px_1fr]">
                <aside class="space-y-4">
                    <form method="post" action="{{ route('ai-branch.start', ['tenant' => $tenant->slug]) }}">@csrf<button class="fb-btn fb-btn-primary">New chat</button></form>
                    <nav aria-label="Your AI chats" class="space-y-2">@forelse($conversations as $row)<div class="flex items-center justify-between gap-2"><a class="text-sm underline" href="{{ route('ai-branch.index', ['tenant' => $tenant->slug, 'conversation' => $row->id]) }}">Chat {{ $row->id }}</a><form method="post" action="{{ route('ai-branch.conversations.delete', ['tenant' => $tenant->slug, 'conversation' => $row->id]) }}">@csrf @method('DELETE')<button class="text-xs underline" aria-label="Delete chat {{ $row->id }}">Delete</button></form></div>@empty<p class="text-sm text-zinc-500">Start a chat to ask your first question.</p>@endforelse</nav>
                    <p class="text-xs text-zinc-500">Chats expire after 30 days. Your chats and memory are private to your sign-in in this workspace.</p>
                </aside>
                <section aria-label="AI conversation" class="min-w-0 space-y-5">
                    @if($conversation)
                        @foreach($conversation->messages ?? [] as $message)
                            <article class="border-b border-zinc-100 pb-4"><h2 class="text-sm font-semibold">{{ $message['role'] === 'user' ? 'You' : 'Everbranch AI' }}</h2><p class="mt-2 whitespace-pre-wrap break-words text-sm">{{ $message['text'] }}</p>
                                @if(!empty($message['sources']))<details class="mt-3"><summary class="cursor-pointer text-xs text-zinc-600">Workspace evidence supplied for this answer</summary><ul class="mt-2 space-y-1 text-xs">@foreach($message['sources'] as $source)<li>[{{ $source['ref'] }}] {{ $source['title'] }} · {{ $source['status'] }}</li>@endforeach</ul></details>@endif
                            </article>
                        @endforeach
                        @if($conversation->pending_uuid)<p role="status">This request is pending or needs usage review. Start a new chat if needed.</p>@else
                        <form method="post" action="{{ route('ai-branch.ask', ['tenant' => $tenant->slug, 'conversation' => $conversation->id]) }}" class="space-y-3">@csrf<input type="hidden" name="request_id" value="{{ Illuminate\Support\Str::uuid() }}"><label class="block text-sm font-medium" for="ai-question">Your question</label><textarea id="ai-question" name="question" maxlength="3000" required rows="4" class="w-full rounded-xl border-zinc-300">{{ old('question') }}</textarea><button class="fb-btn fb-btn-primary">Ask AI</button><p class="text-xs text-zinc-500">Check the evidence before relying on an answer. Job evidence covers only ten recent permitted jobs.</p></form>
                        @endif
                    @else<p class="text-zinc-600">Choose a chat or start a new one.</p>@endif
                </section>
            </div>
            <section class="space-y-4 border-t border-zinc-200 pt-5"><h2 class="text-lg font-semibold">Your saved memory</h2><p class="text-sm text-zinc-600">Save preferences explicitly. Memory expires after 90 days. Changing memory starts a fresh context; old chats may still contain earlier replies until deleted or expired.</p>
                @foreach($memories as $memory)<div class="flex justify-between gap-3"><p class="whitespace-pre-wrap text-sm">{{ $memory->body }}</p><form method="post" action="{{ route('ai-branch.memory.delete', ['tenant' => $tenant->slug, 'memory' => $memory->id]) }}">@csrf @method('DELETE')<button class="text-sm underline">Delete memory</button></form></div>@endforeach
                <form method="post" action="{{ route('ai-branch.memory', ['tenant' => $tenant->slug]) }}" class="space-y-3">@csrf<label class="block text-sm" for="ai-memory">Preference to remember</label><textarea id="ai-memory" name="body" maxlength="500" rows="2" required class="w-full rounded-lg border-zinc-300"></textarea><label class="block text-sm"><input type="checkbox" name="confirmed" value="1" required> Save this preference for my future AI chats.</label><button class="fb-btn fb-btn-secondary">Save memory</button></form>
            </section>
            @if(config('ai_branch.actions_enabled') && $tier['ai_limits']['actions'])
            <section class="space-y-4 border-t border-zinc-200 pt-5"><h2 class="text-lg font-semibold">Review an internal job note</h2><p class="text-sm text-zinc-600">Copy or edit your draft here. Approval adds an owner-visible note. It sends no notification and does not change job status.</p>
                <form method="post" action="{{ route('ai-branch.actions', ['tenant' => $tenant->slug]) }}" class="space-y-3">@csrf<input type="hidden" name="request_id" value="{{ Illuminate\Support\Str::uuid() }}"><label class="block text-sm" for="ai-job">Job</label><select id="ai-job" name="job_id" required class="w-full rounded-lg border-zinc-300">@foreach($jobs as $job)<option value="{{ $job['id'] }}">{{ $job['title'] }}</option>@endforeach</select><label class="block text-sm" for="ai-note">Exact note</label><textarea id="ai-note" name="body" maxlength="3000" required rows="3" class="w-full rounded-lg border-zinc-300"></textarea><button class="fb-btn fb-btn-secondary">Prepare for review</button></form>
                @foreach($actions as $action)<article class="space-y-3 rounded-lg border border-zinc-200 p-4"><h3 class="font-semibold">Add note to {{ $action->payload['job_title'] }}</h3><p class="whitespace-pre-wrap break-words text-sm">{{ $action->payload['body'] }}</p><p class="text-xs text-zinc-500">Approval expires {{ $action->expires_at->diffForHumans() }}.</p><form method="post" action="{{ route('ai-branch.decide', ['tenant' => $tenant->slug, 'action' => $action->id]) }}" class="flex flex-wrap items-center gap-3">@csrf<input type="hidden" name="digest" value="{{ $action->digest }}"><label class="text-sm"><input type="checkbox" name="confirmed" value="1" required> I reviewed this exact note.</label><button name="decision" value="approve" class="fb-btn fb-btn-primary">Approve note</button><button name="decision" value="reject" class="fb-btn fb-btn-secondary">Reject</button></form></article>@endforeach
            </section>
            @endif
            @endif
        </div>
    </flux:main>
</x-layouts::app.sidebar>

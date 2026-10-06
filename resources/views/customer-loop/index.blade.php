<x-app-layout>
    <div class="mx-auto max-w-7xl space-y-5 px-4 py-6 sm:px-6 lg:px-8">
        <x-ui.operational-header eyebrow="Marketing" title="Customer Loop" description="Start a thoughtful next step, prepare the wording, and keep a person in control before anything leaves Everbranch.">
            <a href="{{ route('workflows.index') }}" class="fb-btn fb-btn-secondary">Build a custom rule</a>
        </x-ui.operational-header>
        @if(session('success'))<div class="border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-950" role="status">{{ session('success') }}</div>@endif

        <section class="border border-zinc-200 bg-white" aria-labelledby="customer-loop-launcher">
            <div class="border-b border-zinc-200 px-5 py-4"><p class="text-[11px] font-bold uppercase tracking-[.14em] text-emerald-800">Guided start</p><h2 id="customer-loop-launcher" class="mt-1 text-lg font-semibold tracking-tight text-zinc-950">What do you want to do next?</h2><p class="mt-1 text-sm text-zinc-600">Choose a useful outcome. Everbranch creates a reviewable draft only.</p></div>
            <form method="post" action="{{ route('customer-loop.store') }}" class="grid gap-4 p-5 lg:grid-cols-[1.1fr_1.2fr_1.2fr_auto]">@csrf
                <label class="text-sm font-medium text-zinc-800">1. Desired outcome<select name="template" class="mt-1 block w-full rounded-md border-zinc-300 text-sm">@foreach($templates as $key => $template)<option value="{{ $key }}">{{ $template['label'] }}</option>@endforeach</select><span class="mt-1 block text-xs font-normal text-zinc-500">Start simple; customize later if needed.</span></label>
                <label class="text-sm font-medium text-zinc-800">2. What happened?<input name="title" required maxlength="190" placeholder="Completed a good job, placed an order…" class="mt-1 block w-full rounded-md border-zinc-300 text-sm"><span class="mt-1 block text-xs font-normal text-zinc-500">A short internal description is enough.</span></label>
                <label class="text-sm font-medium text-zinc-800">3. Customer, if known<select name="marketing_profile_id" class="mt-1 block w-full rounded-md border-zinc-300 text-sm"><option value="">No customer selected yet</option>@foreach($profiles as $profile)<option value="{{ $profile->id }}">{{ trim($profile->first_name.' '.$profile->last_name) ?: $profile->email }}</option>@endforeach</select><span class="mt-1 block text-xs font-normal text-zinc-500">This does not enroll anyone in marketing.</span></label>
                <div class="flex items-end"><button class="fb-btn fb-btn-primary w-full justify-center" type="submit">Create review draft</button></div>
            </form>
        </section>

        <section class="overflow-hidden border border-zinc-200 bg-white" aria-labelledby="customer-loop-queue">
            <x-ui.operational-toolbar><div><h2 id="customer-loop-queue" class="text-sm font-semibold text-zinc-950">Needs attention</h2><p class="mt-0.5 text-xs text-zinc-500">Only drafts and items awaiting a person are shown.</p></div><span class="text-xs text-zinc-500">{{ $actions->total() }} open</span></x-ui.operational-toolbar>
            <div class="divide-y divide-zinc-100">
                @forelse($actions as $action)
                    <article class="grid gap-3 px-5 py-4 lg:grid-cols-[minmax(0,1fr)_auto] lg:items-center">
                        <div class="min-w-0"><div class="flex flex-wrap items-center gap-2"><x-ui.status-badge :tone="$action->status === 'prepared' ? 'success' : 'warning'">{{ str($action->action_type)->headline() }}</x-ui.status-badge><span class="text-xs text-zinc-500">{{ str($action->status)->headline() }}</span>@if($action->profile)<span class="text-xs text-zinc-500">{{ trim($action->profile->first_name.' '.$action->profile->last_name) ?: $action->profile->email }}</span>@endif</div><h3 class="mt-2 text-sm font-semibold text-zinc-950">{{ $action->title }}</h3><p class="mt-1 text-sm text-zinc-600">{{ $action->reason }}</p>@if($action->draft_body)<details class="mt-2"><summary class="cursor-pointer text-sm font-medium text-emerald-800">Review prepared wording</summary><p class="mt-2 max-w-3xl whitespace-pre-line border-l-2 border-emerald-200 pl-3 text-sm leading-6 text-zinc-700">{{ $action->draft_body }}</p></details>@endif</div>
                        <div class="flex flex-wrap gap-2 lg:justify-end">@if($action->status !== 'prepared')<form method="post" action="{{ route('customer-loop.prepare', $action) }}">@csrf<button class="fb-btn fb-btn-secondary" type="submit">Prepare</button></form>@endif<form method="post" action="{{ route('customer-loop.snooze', $action) }}">@csrf<button class="fb-btn fb-btn-secondary" type="submit">Snooze</button></form><form method="post" action="{{ route('customer-loop.complete', $action) }}">@csrf<button class="fb-btn fb-btn-primary" type="submit">Complete</button></form></div>
                    </article>
                @empty
                    <x-ui.operational-empty-state title="Nothing needs attention" description="Use the guided start above to prepare a follow-up, review request, update, or marketing draft." />
                @endforelse
            </div>
            <div class="border-t border-zinc-200 px-5 py-3">{{ $actions->links() }}</div>
        </section>
    </div>
</x-app-layout>

<x-layouts::app.sidebar title="Subscription pilot">
    <div class="w-full space-y-5 px-4 py-5 sm:px-6">
        <header class="flex flex-wrap items-center justify-between gap-3">
            <h1 class="text-2xl font-semibold tracking-tight text-zinc-950">Subscription pilot</h1>
            <a href="{{ route('subscriptions.workspace.index', ['tenant' => $tenant->slug]) }}" class="text-sm font-medium text-emerald-900">Subscription history</a>
        </header>
        @include('subscriptions.pilot-report', ['pilotTenant' => $tenant])
    </div>
</x-layouts::app.sidebar>

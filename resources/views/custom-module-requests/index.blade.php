<x-layouts::app.sidebar title="Custom Module Requests">
    <flux:main>
        <div class="fb-workflow-shell">
            <header class="fb-workflow-header">
                <div class="fb-eyebrow">Custom Modules</div>
                <h1 class="fb-title-xl">Custom module requests</h1>
                <p class="fb-subtitle">Request custom workflow ideas for Everbranch review. Requests do not create modules, install modules, or activate billing.</p>
                <div class="mt-4">
                    <a href="{{ route('custom-module-requests.create', ['tenant' => (string) ($tenant->slug ?? '')]) }}" class="fb-btn fb-btn-primary">Request something custom</a>
                </div>
            </header>

            @if (session('status'))
                <div class="fb-state fb-state--success text-sm">{{ session('status') }}</div>
            @endif

            <section class="fb-panel">
                <div class="fb-panel-head">
                    <div>
                        <div class="fb-panel-title">Your requests</div>
                        <div class="fb-panel-copy">Everbranch may follow up for discovery. Repeatable ideas may later become reusable modules, but that is a separate operator decision.</div>
                    </div>
                    <span class="fb-state text-xs">{{ $requests->count() }} requests</span>
                </div>
                <div class="fb-panel-body space-y-3">
                    @forelse($requests as $customRequest)
                        <article class="fb-state text-sm">
                            <div class="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
                                <div>
                                    <div class="font-semibold text-zinc-950">{{ $customRequest->title }}</div>
                                    <div class="mt-1 text-zinc-600">Status: {{ $statusLabels[$customRequest->status] ?? $customRequest->statusLabel() }}</div>
                                    @if(filled($customRequest->next_action))
                                        <div class="mt-1 text-zinc-600">Next action: {{ $customRequest->next_action }}</div>
                                    @endif
                                    <div class="mt-1 text-xs text-zinc-500">Submitted {{ optional($customRequest->created_at)->toFormattedDateString() }}</div>
                                </div>
                                <a href="{{ route('custom-module-requests.show', ['customModuleRequest' => $customRequest, 'tenant' => (string) ($tenant->slug ?? '')]) }}" class="fb-btn fb-btn-secondary">View request</a>
                            </div>
                        </article>
                    @empty
                        <div class="fb-state text-sm">No custom module requests yet.</div>
                    @endforelse
                </div>
            </section>
        </div>
    </flux:main>
</x-layouts::app.sidebar>

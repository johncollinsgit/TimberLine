<x-layouts::app.sidebar title="Custom Module Request">
    <flux:main>
        <div class="fb-workflow-shell">
            <header class="fb-workflow-header">
                <div class="fb-eyebrow">Custom Modules</div>
                <h1 class="fb-title-xl">{{ $customRequest->title }}</h1>
                <p class="fb-subtitle">This request is under Everbranch review. Status labels do not activate modules, billing, quotes, invoices, or implementation work.</p>
            </header>

            @if (session('status'))
                <div class="fb-state fb-state--success text-sm">{{ session('status') }}</div>
            @endif

            <section class="fb-panel">
                <div class="fb-panel-head">
                    <div>
                        <div class="fb-panel-title">Request summary</div>
                        <div class="fb-panel-copy">Submitted {{ optional($customRequest->created_at)->toDayDateTimeString() }}</div>
                    </div>
                    <span class="fb-state text-xs">{{ $statusLabels[$customRequest->status] ?? $customRequest->statusLabel() }}</span>
                </div>
                <div class="fb-panel-body space-y-4 text-sm text-zinc-700">
                    @if($relatedModuleLabel !== null)
                        <div class="fb-state">Related module: {{ $relatedModuleLabel }}</div>
                    @endif
                    <div>
                        <div class="font-semibold text-zinc-950">Problem</div>
                        <p class="mt-1 whitespace-pre-line">{{ $customRequest->problem_summary }}</p>
                    </div>
                    @foreach([
                        'current_workaround' => 'Current workaround',
                        'desired_outcome' => 'Desired outcome',
                        'tools_involved' => 'Tools involved',
                        'users_impacted' => 'Users impacted',
                    ] as $field => $label)
                        @if(filled($customRequest->{$field}))
                            <div>
                                <div class="font-semibold text-zinc-950">{{ $label }}</div>
                                <p class="mt-1 whitespace-pre-line">{{ $customRequest->{$field} }}</p>
                            </div>
                        @endif
                    @endforeach
                    <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                        <div class="fb-state">Frequency: {{ filled($customRequest->frequency) ? str($customRequest->frequency)->replace('_', ' ')->headline() : 'Undecided' }}</div>
                        <div class="fb-state">Urgency: {{ filled($customRequest->urgency) ? str($customRequest->urgency)->replace('_', ' ')->headline() : 'Undecided' }}</div>
                        <div class="fb-state">Budget: {{ filled($customRequest->budget_range) ? str($customRequest->budget_range)->replace('_', ' ')->headline() : 'Not sure' }}</div>
                        <div class="fb-state">Mobile: {{ $customRequest->mobileRelevanceLabel() }}</div>
                    </div>
                    <div class="fb-state">Reusable module interest: {{ $customRequest->reusable_module_interest ? 'Yes, this may fit other businesses later' : 'No or undecided' }}</div>
                    @if(filled($customRequest->next_action))
                        <div class="fb-state">Next action: {{ $customRequest->next_action }}</div>
                    @endif
                    <div class="flex flex-wrap gap-2">
                        <a href="{{ route('custom-module-requests.index', ['tenant' => (string) ($tenant->slug ?? '')]) }}" class="fb-btn fb-btn-secondary">Back to requests</a>
                    </div>
                </div>
            </section>
        </div>
    </flux:main>
</x-layouts::app.sidebar>

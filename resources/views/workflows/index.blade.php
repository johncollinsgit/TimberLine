@php
    $activeCount = $workflows->where('status', 'active')->count();
    $draftCount = $workflows->where('status', 'draft')->count();
    $pausedCount = $workflows->where('status', 'paused')->count();
    $totalRuns = (int) $workflows->sum('runs_count');
    $successfulRuns = (int) $workflows->sum('successful_runs_count');
    $successRate = $totalRuns > 0 ? round(($successfulRuns / $totalRuns) * 100) : null;
@endphp

<x-layouts::app :title="'Workflow Automations'">
    <div class="eb-workflow-home">
        <header class="eb-workflow-home__header">
            <div>
                <span class="eb-workflow-home__eyebrow">Workflow Automations</span>
                <h1>Workflows</h1>
                <p>Build, publish, and monitor the automations running this workspace.</p>
            </div>
            <a href="{{ route('workflows.create') }}" wire:navigate class="eb-primary-button">
                <span aria-hidden="true">＋</span>
                New workflow
            </a>
        </header>

        @unless($studioEnabled)
            <div class="eb-workflow-home__notice" role="status">
                This workspace is using the compatible workflow builder while Workflow Studio rolls out.
                Existing workflows, connections, runs, and launchable templates remain available.
            </div>
        @endunless

        <nav class="eb-workflow-home__tabs" aria-label="Workflow Automations">
            <a href="{{ route('workflows.index') }}" wire:navigate class="is-active" aria-current="page">Workflows</a>
            <a href="{{ route('workflows.history') }}" wire:navigate>Runs</a>
            <a href="{{ route('workflows.connections') }}" wire:navigate>Connections</a>
            <a href="{{ route('workflows.create', ['picker' => 'templates']) }}" wire:navigate>Templates</a>
        </nav>

        <section class="eb-workflow-home__metrics" aria-label="Workflow status">
            <article>
                <span class="eb-status-dot eb-status-dot--ready"></span>
                <div><strong>{{ $activeCount }}</strong><small>On</small></div>
            </article>
            <article>
                <span class="eb-status-dot eb-status-dot--draft"></span>
                <div><strong>{{ $draftCount }}</strong><small>Drafts</small></div>
            </article>
            <article>
                <span class="eb-status-dot eb-status-dot--paused"></span>
                <div><strong>{{ $pausedCount }}</strong><small>Paused</small></div>
            </article>
            <article>
                <span class="eb-workflow-home__metric-mark" aria-hidden="true">↗</span>
                <div><strong>{{ $successRate === null ? '—' : $successRate.'%' }}</strong><small>Successful runs</small></div>
            </article>
        </section>

        <form method="GET" class="eb-workflow-toolbar">
            <label class="eb-workflow-toolbar__search">
                <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="11" cy="11" r="7"></circle><path d="m20 20-4-4"></path></svg>
                <span class="sr-only">Search workflows</span>
                <input name="search" value="{{ $search }}" type="search" placeholder="Search workflows" />
            </label>
            <label>
                <span class="sr-only">Filter by status</span>
                <select name="status" onchange="this.form.submit()">
                    @foreach(['all' => 'All statuses', 'active' => 'On', 'paused' => 'Paused', 'draft' => 'Draft'] as $value => $label)
                        <option value="{{ $value }}" @selected($status === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </label>
            <button type="submit" class="eb-secondary-button">Filter</button>
        </form>

        @if($workflows->isEmpty())
            <section class="eb-workflow-empty-state">
                <div class="eb-workflow-empty-state__flow" aria-hidden="true">
                    <span><strong>1</strong></span>
                    <i></i>
                    <span><strong>2</strong></span>
                </div>
                <h2>{{ $search !== '' || $status !== 'all' ? 'No workflows match those filters' : 'Build your first workflow' }}</h2>
                <p>
                    {{ $search !== '' || $status !== 'all'
                        ? 'Clear the search or choose another status to see more workflows.'
                        : 'Start with a trigger, add actions and flow controls, test each step, then publish.' }}
                </p>
                @if($search !== '' || $status !== 'all')
                    <a href="{{ route('workflows.index') }}" wire:navigate class="eb-secondary-button">Clear filters</a>
                @else
                    <a href="{{ route('workflows.create') }}" wire:navigate class="eb-primary-button">Open Workflow Studio</a>
                @endif
            </section>
        @else
            <section class="eb-workflow-table" aria-label="Workflows">
                <div class="eb-workflow-table__head">
                    <span>Workflow</span>
                    <span>Status</span>
                    <span>Last run</span>
                    <span>Success</span>
                    <span>Updated</span>
                    <span></span>
                </div>
                @foreach($workflows as $workflow)
                    @php
                        $lastRun = $workflow->runs->first();
                        $definition = (array) $workflow->draft_definition;
                        $legacyTemplate = (array) ($templates[$workflow->template_key] ?? []);
                        $triggerComponent = (array) data_get(
                            $studioComponents,
                            (string) data_get($definition, 'trigger.component_key'),
                            [],
                        );
                        $findFirstAction = function (array $steps) use (&$findFirstAction): ?array {
                            foreach ($steps as $step) {
                                if (! is_array($step)) {
                                    continue;
                                }
                                if (($step['kind'] ?? null) === 'action') {
                                    return $step;
                                }
                                foreach ((array) data_get($step, 'config.branches', []) as $branch) {
                                    if (is_array($branch) && ($action = $findFirstAction((array) ($branch['steps'] ?? [])))) {
                                        return $action;
                                    }
                                }
                            }

                            return null;
                        };
                        $firstAction = $findFirstAction((array) ($definition['steps'] ?? []));
                        $actionComponent = (array) data_get(
                            $studioComponents,
                            (string) ($firstAction['component_key'] ?? ''),
                            [],
                        );
                        $triggerProvider = (string) data_get(
                            $triggerComponent,
                            'provider',
                            data_get(
                                $definition,
                                'trigger.provider',
                            data_get($definition, 'trigger.component_key', $legacyTemplate['trigger_provider'] ?? 'everbranch')
                            )
                        );
                        $actionProvider = (string) data_get(
                            $actionComponent,
                            'provider',
                            data_get(
                                $definition,
                                'action.provider',
                                $legacyTemplate['action_provider'] ?? 'everbranch',
                            ),
                        );
                        $triggerProvider = str($triggerProvider)->before('.')->toString();
                        $actionProvider = str($actionProvider)->before('.')->toString();
                        $triggerLabel = data_get(
                            $triggerComponent,
                            'label',
                            data_get($definition, 'trigger.event', $legacyTemplate['trigger_event'] ?? 'Trigger'),
                        );
                        $actionLabel = data_get(
                            $actionComponent,
                            'label',
                            data_get($definition, 'action.event', $legacyTemplate['action_event'] ?? 'No action yet'),
                        );
                        $workflowSuccessRate = $workflow->runs_count > 0
                            ? round(($workflow->successful_runs_count / $workflow->runs_count) * 100)
                            : null;
                    @endphp
                    <a href="{{ route('workflows.show', $workflow) }}" wire:navigate class="eb-workflow-table__row">
                        <span class="eb-workflow-table__identity">
                            <span class="eb-workflow-table__marks">
                                <x-workflows.partials.provider-icon :provider="$triggerProvider" :providers="$providers" size="sm" />
                                <x-workflows.partials.provider-icon :provider="$actionProvider" :providers="$providers" size="sm" />
                            </span>
                            <span>
                                <strong>{{ $workflow->name }}</strong>
                                <small>{{ $triggerLabel }} → {{ $actionLabel }}</small>
                            </span>
                        </span>
                        <span>
                            <span class="eb-workflow-status is-{{ $workflow->status }}">
                                <i></i>
                                {{ $workflow->status === 'active' ? 'On' : str($workflow->status)->headline() }}
                            </span>
                        </span>
                        <span>
                            <strong class="eb-mobile-column-label">Last run</strong>
                            {{ $lastRun?->finished_at?->diffForHumans() ?? 'Not run yet' }}
                        </span>
                        <span>
                            <strong class="eb-mobile-column-label">Success</strong>
                            {{ $workflowSuccessRate === null ? '—' : $workflowSuccessRate.'%' }}
                        </span>
                        <span>
                            <strong class="eb-mobile-column-label">Updated</strong>
                            {{ $workflow->updated_at->diffForHumans() }}
                        </span>
                        <span class="eb-workflow-table__open" aria-hidden="true">›</span>
                    </a>
                @endforeach
            </section>
        @endif
    </div>
</x-layouts::app>

<x-layouts::app.sidebar title="New Project Request">
    <flux:main>
        <div class="fb-workflow-shell">
            <header class="fb-workflow-header">
                <div class="fb-eyebrow">Client Portal</div>
                <h1 class="fb-title-xl">New request for {{ $project->title }}</h1>
                <p class="fb-subtitle">Attach a feature idea, app request, scope change, or question to this project so it can be reviewed with the right context.</p>
            </header>

            @if ($errors->any())
                <section class="fb-state text-sm">
                    <div class="font-semibold text-zinc-950">Please fix the highlighted fields.</div>
                    <ul class="mt-2 list-disc space-y-1 pl-5 text-zinc-600">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </section>
            @endif

            <section class="fb-panel">
                <div class="fb-panel-head">
                    <div>
                        <div class="fb-panel-title">Request details</div>
                        <div class="fb-panel-copy">Evergrove will review the request, clarify scope, and add tasks or references as needed.</div>
                    </div>
                </div>
                <div class="fb-panel-body">
                    <form method="POST" action="{{ route('client.projects.requests.store', ['project' => $project]) }}" class="space-y-5">
                        @csrf

                        <div class="grid gap-4 lg:grid-cols-3">
                            <label class="block text-sm text-zinc-700">
                                Type
                                <select name="type" class="fb-input mt-2">
                                    @foreach($typeLabels as $key => $label)
                                        <option value="{{ $key }}" @selected(old('type', 'feature') === $key)>{{ $label }}</option>
                                    @endforeach
                                </select>
                            </label>
                            <label class="block text-sm text-zinc-700">
                                Urgency
                                <select name="urgency" class="fb-input mt-2">
                                    @foreach($urgencyLabels as $key => $label)
                                        <option value="{{ $key }}" @selected(old('urgency', 'normal') === $key)>{{ $label }}</option>
                                    @endforeach
                                </select>
                            </label>
                            <label class="block text-sm text-zinc-700">
                                Priority
                                <select name="priority" class="fb-input mt-2">
                                    @foreach($priorityLabels as $key => $label)
                                        <option value="{{ $key }}" @selected(old('priority', 'normal') === $key)>{{ $label }}</option>
                                    @endforeach
                                </select>
                            </label>
                        </div>

                        <label class="block text-sm text-zinc-700">
                            Request title
                            <input name="title" value="{{ old('title') }}" required class="fb-input mt-2" placeholder="Example: Add quote approval notifications">
                        </label>

                        <div class="grid gap-4 lg:grid-cols-2">
                            <label class="block text-sm text-zinc-700">
                                Project phase
                                <select name="client_project_phase_id" class="fb-input mt-2">
                                    <option value="">No specific phase</option>
                                    @foreach($project->phases as $phase)
                                        <option value="{{ $phase->id }}" @selected((string) old('client_project_phase_id') === (string) $phase->id)>{{ $phase->name }}</option>
                                    @endforeach
                                </select>
                            </label>
                            <label class="block text-sm text-zinc-700">
                                Milestone
                                <select name="client_project_milestone_id" class="fb-input mt-2">
                                    <option value="">No specific milestone</option>
                                    @foreach($project->milestones as $milestone)
                                        <option value="{{ $milestone->id }}" @selected((string) old('client_project_milestone_id') === (string) $milestone->id)>{{ $milestone->title }}</option>
                                    @endforeach
                                </select>
                            </label>
                        </div>

                        <label class="block text-sm text-zinc-700">
                            What problem are you solving?
                            <textarea name="problem_summary" required rows="4" class="fb-input mt-2">{{ old('problem_summary') }}</textarea>
                        </label>

                        <div class="grid gap-4 lg:grid-cols-2">
                            <label class="block text-sm text-zinc-700">
                                Desired outcome
                                <textarea name="desired_outcome" rows="4" class="fb-input mt-2">{{ old('desired_outcome') }}</textarea>
                            </label>
                            <label class="block text-sm text-zinc-700">
                                Scope notes
                                <textarea name="scope_notes" rows="4" class="fb-input mt-2">{{ old('scope_notes') }}</textarea>
                            </label>
                        </div>

                        <label class="block text-sm text-zinc-700">
                            Initial tasks
                            <textarea name="task_titles" rows="4" class="fb-input mt-2" placeholder="One task per line">{{ old('task_titles') }}</textarea>
                        </label>

                        <div class="grid gap-4 lg:grid-cols-2">
                            <label class="block text-sm text-zinc-700">
                                Reference label
                                <input name="reference_label" value="{{ old('reference_label') }}" class="fb-input mt-2" placeholder="Example screenshot, Loom, document">
                            </label>
                            <label class="block text-sm text-zinc-700">
                                Reference URL
                                <input name="reference_url" value="{{ old('reference_url') }}" class="fb-input mt-2" placeholder="https://...">
                            </label>
                        </div>

                        <label class="block text-sm text-zinc-700">
                            Reference notes
                            <textarea name="reference_notes" rows="3" class="fb-input mt-2">{{ old('reference_notes') }}</textarea>
                        </label>

                        <div class="flex flex-wrap gap-2">
                            <button type="submit" class="fb-btn fb-btn-primary">Submit request</button>
                            <a href="{{ route('client.projects.show', ['project' => $project]) }}" class="fb-btn fb-btn-secondary">Cancel</a>
                        </div>
                    </form>
                </div>
            </section>
        </div>
    </flux:main>
</x-layouts::app.sidebar>

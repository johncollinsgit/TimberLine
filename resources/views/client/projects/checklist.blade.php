<x-layouts::app.sidebar title="Launch checklist">
    <flux:main>
        <style>
            .launch-shell{max-width:1280px;margin:0 auto;color:var(--fb-text,#24332d)}
            .launch-hero{padding:clamp(24px,4vw,48px);border-radius:24px;background:#183d32;color:#fff;margin-bottom:24px}
            .launch-shell .launch-hero h1{color:#fff!important;font-size:clamp(30px,4vw,46px);line-height:1.1;font-weight:650;letter-spacing:-.035em;margin:10px 0 14px}
            .launch-hero p{max-width:660px;color:#d4e2dc;line-height:1.65}
            .launch-kicker{font-size:11px;font-weight:700;letter-spacing:.18em;text-transform:uppercase;color:#b9d4c7}
            .launch-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:24px;align-items:start}
            .launch-column{min-width:0;background:var(--fb-surface,#fff);border:1px solid var(--fb-border,#dce3df);border-radius:20px;overflow:hidden}
            .launch-column-head{padding:25px 24px 20px;border-bottom:1px solid var(--fb-border,#dce3df)}
            .launch-column h2{font-size:24px;font-weight:650;letter-spacing:-.025em}
            .launch-copy{font-size:14px;line-height:1.6;color:var(--fb-muted,#65736b);margin-top:7px}
            .launch-count{display:flex;justify-content:space-between;gap:12px;font-size:12px;margin:18px 0 8px;font-weight:600}
            .launch-progress{height:5px;border-radius:9px;background:#e8eeea;overflow:hidden}
            .launch-progress span{display:block;background:#477b5c;height:100%;transition:width .2s}
            .launch-group{border-bottom:1px solid var(--fb-border,#dce3df)}
            .launch-group:last-child{border-bottom:0}
            .launch-group summary{padding:19px 24px;cursor:pointer;font-weight:600;font-size:15px;list-style-position:inside;line-height:1.5}
            .launch-group summary small{float:right;font-size:12px;font-weight:400;color:var(--fb-muted,#65736b)}
            .launch-task{padding:17px 24px;border-top:1px solid var(--fb-border,#edf0ee);scroll-margin-top:24px}
            .launch-task-label{display:flex;gap:12px;align-items:flex-start;cursor:pointer;font-size:14px;font-weight:600;line-height:1.55}
            .launch-task input{margin-top:3px;flex:0 0 19px;width:19px;height:19px;accent-color:#477b5c;cursor:pointer}
            .launch-task input:disabled{cursor:default}
            .launch-task input:focus-visible{outline:3px solid #b9d4c7;outline-offset:4px}
            .launch-task.is-done .launch-task-title{color:#63806c;text-decoration:line-through}
            .launch-details{margin:7px 0 0 31px;font-size:13px;line-height:1.65;color:var(--fb-muted,#65736b);white-space:pre-line}
            .launch-error{margin-left:31px;color:#b42318;font-size:13px}
            .launch-project-name{font-size:20px;font-weight:650;margin:28px 0 16px}
            .launch-footer{font-size:12px;line-height:1.7;color:var(--fb-muted,#65736b);margin-top:22px}
            @media(max-width:850px){.launch-grid{grid-template-columns:1fr}.launch-column-head,.launch-task{padding:20px}.launch-group summary{padding:18px 20px}}
        </style>
        <div class="launch-shell">
            <header class="launch-hero">
                <div class="launch-kicker">{{ $tenant->name }} · Website & sales</div>
                <h1>One place. One step at a time.</h1>
                <p>Your launch checklist keeps what we need from you alongside the work Evergrove is doing. Start with the first section and come back whenever you have time.</p>
            </header>
            <div role="status" aria-live="polite" id="checklist-status" class="sr-only"></div>
            @foreach($projects as $project)
                @php
                    $allTasks = $project->tickets->flatMap->tasks;
                    $ownerLabels = ['client' => data_get($project->metadata, 'client_checklist_label', 'From you'), 'evergrove' => data_get($project->metadata, 'provider_checklist_label', 'Evergrove is working on')];
                @endphp
                @if($projects->count() > 1)<h2 class="launch-project-name">{{ $project->title }}</h2>@endif
                <div class="launch-grid">
                    @foreach($ownerLabels as $owner => $label)
                        @php $owned = $allTasks->where('owner_type', $owner); $done = $owned->where('status', 'done')->count(); @endphp
                        <section class="launch-column" data-checklist-column>
                            <div class="launch-column-head">
                                <h2>{{ $label }}</h2>
                                <p class="launch-copy">{{ $owner === 'client' ? 'Details, decisions, and approvals that help us move forward.' : 'Website work, launch checks, and new ways to reach customers.' }}</p>
                                <div class="launch-count"><span data-count>{{ $done }} of {{ $owned->count() }} complete</span><span data-percent>{{ $owned->count() ? round(100 * $done / $owned->count()) : 0 }}%</span></div>
                                <div class="launch-progress" aria-hidden="true"><span data-bar style="width:{{ $owned->count() ? 100 * $done / $owned->count() : 0 }}%"></span></div>
                            </div>
                            @foreach($project->tickets as $ticket)
                                @php $tasks = $ticket->tasks->where('owner_type', $owner); @endphp
                                @if($tasks->isNotEmpty())
                                    <details class="launch-group" @if($loop->first) open @endif>
                                        <summary>{{ $ticket->title }} <small data-group-count>{{ $tasks->where('status', 'done')->count() }}/{{ $tasks->count() }}</small></summary>
                                        @foreach($tasks as $task)
                                            @php $complete = $task->status === 'done'; $canEdit = $owner === 'client' || $isOperator; @endphp
                                            <form id="task-{{ $task->id }}" class="launch-task {{ $complete ? 'is-done' : '' }}" method="POST" action="{{ route('client.projects.checklist.update', ['task' => $task->id, 'tenant' => $tenant->slug]) }}">
                                                @csrf @method('PATCH')
                                                <input type="hidden" name="completed" value="0">
                                                <label class="launch-task-label" for="check-{{ $task->id }}">
                                                    <input id="check-{{ $task->id }}" type="checkbox" name="completed" value="1" @checked($complete) @disabled(!$canEdit) aria-describedby="details-{{ $task->id }}">
                                                    <span class="launch-task-title">{{ $task->title }}</span>
                                                </label>
                                                <p id="details-{{ $task->id }}" class="launch-details">{{ $task->details }}</p>
                                                <p class="launch-error" role="alert" hidden></p>
                                                @if($canEdit)<noscript><button class="fb-btn fb-btn-secondary" type="submit">Save checkbox</button></noscript>@endif
                                            </form>
                                        @endforeach
                                    </details>
                                @endif
                            @endforeach
                        </section>
                    @endforeach
                </div>
            @endforeach
            @if($projects->isEmpty())<p class="launch-copy">Your launch checklist will appear here when your project is ready.</p>@endif
            <p class="launch-footer">Checkboxes save automatically. {{ $isOperator ? 'You can update both lists.' : 'You can update your list; Evergrove updates its own progress.' }} A completed task records progress; account approvals and publishing happen separately.</p>
        </div>
        <script>
            (() => {
                const refresh = (column) => {
                    const boxes = [...column.querySelectorAll('input[type="checkbox"]')];
                    const done = boxes.filter(box => box.checked).length;
                    const percent = boxes.length ? Math.round(100 * done / boxes.length) : 0;
                    column.querySelector('[data-count]').textContent = `${done} of ${boxes.length} complete`;
                    column.querySelector('[data-percent]').textContent = `${percent}%`;
                    column.querySelector('[data-bar]').style.width = `${percent}%`;
                    column.querySelectorAll('details').forEach(group => {
                        const items = [...group.querySelectorAll('input[type="checkbox"]')];
                        group.querySelector('[data-group-count]').textContent = `${items.filter(box => box.checked).length}/${items.length}`;
                    });
                };
                document.querySelectorAll('.launch-task').forEach(form => {
                    const checkbox = form.querySelector('input[type="checkbox"]');
                    checkbox.addEventListener('change', async () => {
                        const completed = checkbox.checked;
                        const error = form.querySelector('.launch-error');
                        error.hidden = true;
                        const data = new FormData(form);
                        checkbox.disabled = true;
                        try {
                            const response = await fetch(form.action, {method: 'POST', body: data, headers: {'Accept': 'application/json'}, credentials: 'same-origin'});
                            if (!response.ok) throw new Error('Save failed');
                            const result = await response.json();
                            checkbox.checked = result.completed;
                            form.classList.toggle('is-done', result.completed);
                            refresh(form.closest('[data-checklist-column]'));
                            document.querySelector('#checklist-status').textContent = 'Checklist saved.';
                        } catch (_) {
                            checkbox.checked = !completed;
                            error.textContent = 'Could not save this change. Please try again.';
                            error.hidden = false;
                        } finally { checkbox.disabled = false; }
                    });
                });
                if (location.hash.startsWith('#task-')) document.getElementById(location.hash.slice(1))?.closest('details')?.setAttribute('open', '');
            })();
        </script>
    </flux:main>
</x-layouts::app.sidebar>

@php
    $definition = (array) $workflow->draft_definition;
    $trigger = (array) ($definition['trigger'] ?? []);
    $action = (array) ($definition['action'] ?? []);
    $sourceProvider = (string) ($trigger['provider'] ?? 'asana');
    $commerceSource = in_array($sourceProvider, ['shopify', 'square', 'squarespace', 'wix'], true);
    $projects = (array) ($asanaConnection['projects'] ?? []);
    $calendars = (array) ($googleConnection['calendars'] ?? []);
    $testState = (array) $workflow->test_state;
    $presentation = (array) $calendarAppearance;
    $descriptionFields = (array) ($presentation['description_fields'] ?? []);
    $calendarId = (string) ($action['calendar_id'] ?? '');
    $projectGid = (string) ($trigger['project_gid'] ?? '');
    $sourceLabel = data_get($providers, $sourceProvider.'.label', str($sourceProvider)->headline());
    $defaultTitleTemplate = $commerceSource
        ? '{{source}} #{{order_number}}'
        : '{{task_name}}';
@endphp

<x-layouts::app :title="$workflow->name">
    <div class="mx-auto max-w-6xl space-y-5 px-4 py-6 sm:px-6">
        <header class="flex flex-wrap items-start justify-between gap-4 border-b border-zinc-200 pb-5">
            <div>
                <a href="{{ route('workflows.index') }}" wire:navigate class="text-sm font-semibold text-emerald-800 hover:text-emerald-950">← Back to workflows</a>
                <h1 class="mt-3 text-2xl font-bold text-zinc-950">{{ $workflow->name }}</h1>
                <p class="mt-1 text-sm text-zinc-600">{{ $template['name'] ?? 'Compatible workflow' }} · {{ $workflow->status === 'active' ? 'On' : str($workflow->status)->headline() }}</p>
            </div>
            <a href="{{ route('workflows.connections', ['return_path' => route('workflows.show', $workflow, absolute: false)]) }}" wire:navigate class="fb-btn-soft px-4 py-2 text-sm font-bold">Manage connections</a>
        </header>

        <div class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-950" role="status">
            This workspace is using the compatible v1 builder. Its published workflow continues to run on the existing engine.
        </div>

        @if($errors->any())
            <div class="rounded-lg border border-rose-200 bg-rose-50 p-4 text-sm text-rose-900" role="alert">
                <strong>Check the highlighted setup fields.</strong>
                <ul class="mt-2 list-disc space-y-1 pl-5">
                    @foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach
                </ul>
            </div>
        @endif

        <form method="POST" action="{{ route('workflows.update', $workflow) }}" class="grid gap-5 lg:grid-cols-[minmax(0,1fr)_320px]">
            @csrf
            @method('PUT')

            <div class="space-y-5">
                <section class="rounded-lg border border-zinc-200 bg-white p-5 shadow-sm">
                    <p class="text-xs font-bold uppercase tracking-wider text-zinc-500">Workflow</p>
                    <label class="mt-4 block text-sm font-semibold text-zinc-900">
                        Name
                        <input name="name" value="{{ old('name', $workflow->name) }}" required maxlength="160" class="mt-1 block w-full rounded-md border-zinc-300 text-sm">
                    </label>
                </section>

                <section class="rounded-lg border border-zinc-200 bg-white p-5 shadow-sm">
                    <div class="flex items-center gap-3">
                        <x-workflows.partials.provider-icon :provider="$sourceProvider" :providers="$providers" size="sm" />
                        <div><p class="text-xs font-bold uppercase tracking-wider text-zinc-500">Trigger</p><h2 class="font-bold text-zinc-950">{{ $sourceLabel }}</h2></div>
                    </div>

                    @if($commerceSource)
                        <label class="mt-4 block text-sm font-semibold text-zinc-900">
                            Connected account
                            <select name="trigger_connection_id" required class="mt-1 block w-full rounded-md border-zinc-300 text-sm">
                                <option value="">Choose an account</option>
                                @foreach($commerceConnections as $connection)
                                    <option value="{{ $connection->id }}" @selected((int) old('trigger_connection_id', $trigger['connection_id'] ?? 0) === (int) $connection->id)>
                                        {{ $connection->external_account_label ?: $sourceLabel.' account' }}
                                    </option>
                                @endforeach
                            </select>
                        </label>
                        <label class="mt-4 block text-sm font-semibold text-zinc-900">
                            Date source
                            <select name="schedule_source" class="mt-1 block w-full rounded-md border-zinc-300 text-sm">
                                @foreach(['source_date' => 'Source date', 'order_created' => 'Order created', 'fulfillment' => 'Fulfillment', 'delivery' => 'Delivery', 'pickup' => 'Pickup'] as $value => $label)
                                    <option value="{{ $value }}" @selected(old('schedule_source', $trigger['schedule_source'] ?? 'fulfillment') === $value)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </label>
                    @else
                        <label class="mt-4 block text-sm font-semibold text-zinc-900">
                            Asana project
                            <select name="project_gid" required class="mt-1 block w-full rounded-md border-zinc-300 text-sm">
                                <option value="">Choose a project</option>
                                @if($projectGid !== '' && ! collect($projects)->contains(fn (array $project): bool => (string) ($project['gid'] ?? '') === $projectGid))
                                    <option value="{{ $projectGid }}" selected>Currently selected project</option>
                                @endif
                                @foreach($projects as $project)
                                    <option value="{{ $project['gid'] }}" @selected(old('project_gid', $projectGid) === (string) $project['gid'])>
                                        {{ $project['name'] }}{{ filled($project['workspace_name'] ?? null) ? ' · '.$project['workspace_name'] : '' }}
                                    </option>
                                @endforeach
                            </select>
                        </label>
                    @endif
                </section>

                <section class="rounded-lg border border-zinc-200 bg-white p-5 shadow-sm">
                    <div class="flex items-center gap-3">
                        <x-workflows.partials.provider-icon provider="google_calendar" :providers="$providers" size="sm" />
                        <div><p class="text-xs font-bold uppercase tracking-wider text-zinc-500">Action</p><h2 class="font-bold text-zinc-950">Google Calendar</h2></div>
                    </div>

                    <div class="mt-4 grid gap-4 sm:grid-cols-2">
                        <label class="text-sm font-semibold text-zinc-900 sm:col-span-2">
                            Calendar
                            <select name="calendar_id" required class="mt-1 block w-full rounded-md border-zinc-300 text-sm">
                                <option value="">Choose a calendar</option>
                                @if($calendarId !== '' && ! collect($calendars)->contains(fn (array $calendar): bool => (string) ($calendar['id'] ?? '') === $calendarId))
                                    <option value="{{ $calendarId }}" selected>Currently selected calendar</option>
                                @endif
                                @foreach($calendars as $calendar)
                                    <option value="{{ $calendar['id'] }}" @selected(old('calendar_id', $calendarId) === (string) $calendar['id'])>{{ $calendar['summary'] }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label class="text-sm font-semibold text-zinc-900">
                            Timezone
                            <input name="timezone" value="{{ old('timezone', $action['timezone'] ?? config('app.timezone')) }}" required class="mt-1 block w-full rounded-md border-zinc-300 text-sm">
                        </label>
                        <label class="text-sm font-semibold text-zinc-900">
                            Default duration (minutes)
                            <input type="number" name="default_duration_minutes" value="{{ old('default_duration_minutes', $action['default_duration_minutes'] ?? 60) }}" min="1" max="1440" required class="mt-1 block w-full rounded-md border-zinc-300 text-sm">
                        </label>
                        <label class="flex items-center gap-2 text-sm font-semibold text-zinc-900 sm:col-span-2">
                            <input type="checkbox" name="skip_completed_tasks" value="1" @checked(old('skip_completed_tasks', $action['skip_completed_tasks'] ?? true)) class="rounded border-zinc-300 text-emerald-700">
                            Skip completed source records
                        </label>
                    </div>
                </section>

                <section class="rounded-lg border border-zinc-200 bg-white p-5 shadow-sm">
                    <p class="text-xs font-bold uppercase tracking-wider text-zinc-500">Calendar presentation</p>
                    <div class="mt-4 grid gap-4 sm:grid-cols-2">
                        <label class="text-sm font-semibold text-zinc-900 sm:col-span-2">
                            Event title template
                            <input name="event_title_template" value="{{ old('event_title_template', $presentation['title_template'] ?? $defaultTitleTemplate) }}" required maxlength="160" class="mt-1 block w-full rounded-md border-zinc-300 text-sm">
                        </label>
                        <fieldset class="sm:col-span-2">
                            <legend class="text-sm font-semibold text-zinc-900">Description fields</legend>
                            <div class="mt-2 grid gap-2 sm:grid-cols-3">
                                @foreach(['notes' => 'Notes', 'items' => 'Items', 'total' => 'Total', 'status' => 'Status', 'customer_contact' => 'Customer contact', 'source_link' => 'Source link'] as $value => $label)
                                    <label class="flex items-center gap-2 text-sm text-zinc-700">
                                        <input type="checkbox" name="event_description_fields[]" value="{{ $value }}" @checked(in_array($value, old('event_description_fields', $descriptionFields), true)) class="rounded border-zinc-300 text-emerald-700">
                                        {{ $label }}
                                    </label>
                                @endforeach
                            </div>
                        </fieldset>
                        <label class="text-sm font-semibold text-zinc-900">
                            Location
                            <select name="event_location_source" required class="mt-1 block w-full rounded-md border-zinc-300 text-sm">
                                @foreach(['none' => 'None', 'shipping_address' => 'Shipping address', 'billing_address' => 'Billing address', 'pickup_location' => 'Pickup location'] as $value => $label)
                                    <option value="{{ $value }}" @selected(old('event_location_source', $presentation['location_source'] ?? 'none') === $value)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label class="text-sm font-semibold text-zinc-900">
                            Color
                            <select name="event_color_id" class="mt-1 block w-full rounded-md border-zinc-300 text-sm">
                                <option value="">Calendar default</option>
                                @foreach(range(1, 11) as $color)
                                    <option value="{{ $color }}" @selected((string) old('event_color_id', $presentation['color_id'] ?? '') === (string) $color)>Color {{ $color }}</option>
                                @endforeach
                            </select>
                        </label>
                        @foreach([
                            'event_availability' => ['label' => 'Availability', 'default' => $presentation['availability'] ?? 'busy', 'options' => ['busy' => 'Busy', 'free' => 'Free']],
                            'event_visibility' => ['label' => 'Visibility', 'default' => $presentation['visibility'] ?? 'default', 'options' => ['default' => 'Calendar default', 'private' => 'Private']],
                            'event_reminders' => ['label' => 'Reminders', 'default' => $presentation['reminders'] ?? 'default', 'options' => ['default' => 'Calendar default', 'none' => 'None']],
                            'cancelled_order_behavior' => ['label' => 'Cancelled records', 'default' => $presentation['cancelled_order_behavior'] ?? 'mark_cancelled', 'options' => ['mark_cancelled' => 'Mark cancelled', 'leave_unchanged' => 'Leave unchanged']],
                        ] as $field => $setup)
                            <label class="text-sm font-semibold text-zinc-900">
                                {{ $setup['label'] }}
                                <select name="{{ $field }}" required class="mt-1 block w-full rounded-md border-zinc-300 text-sm">
                                    @foreach($setup['options'] as $value => $label)
                                        <option value="{{ $value }}" @selected(old($field, $setup['default']) === $value)>{{ $label }}</option>
                                    @endforeach
                                </select>
                            </label>
                        @endforeach
                    </div>
                </section>

                <button class="rounded-md bg-emerald-900 px-5 py-3 text-sm font-bold text-white hover:bg-emerald-950">Save draft</button>
            </div>

            <aside class="space-y-4">
                <section class="rounded-lg border border-zinc-200 bg-white p-5 shadow-sm">
                    <h2 class="font-bold text-zinc-950">Test and publish</h2>
                    <p class="mt-1 text-sm leading-6 text-zinc-600">Save first, test both steps, then publish the verified draft.</p>
                    <dl class="mt-4 space-y-2 text-sm">
                        <div class="flex justify-between gap-3"><dt>Trigger</dt><dd class="font-semibold">{{ data_get($testState, 'trigger.ok') ? 'Passed' : 'Needs test' }}</dd></div>
                        <div class="flex justify-between gap-3"><dt>Action</dt><dd class="font-semibold">{{ data_get($testState, 'action.ok') ? 'Passed' : 'Needs test' }}</dd></div>
                    </dl>
                </section>
            </aside>
        </form>

        <aside class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <form method="POST" action="{{ route('workflows.test-trigger', $workflow) }}">@csrf<button class="w-full rounded-md border border-zinc-300 bg-white px-4 py-2.5 text-sm font-bold text-zinc-900">Test trigger</button></form>
            <form method="POST" action="{{ route('workflows.test-action', $workflow) }}">@csrf<button class="w-full rounded-md border border-zinc-300 bg-white px-4 py-2.5 text-sm font-bold text-zinc-900">Test action</button></form>
            <form method="POST" action="{{ route('workflows.publish', $workflow) }}">@csrf<button class="w-full rounded-md bg-emerald-900 px-4 py-2.5 text-sm font-bold text-white">Publish</button></form>
            @if($workflow->status === 'active')
                <form method="POST" action="{{ route('workflows.pause', $workflow) }}">@csrf<button class="w-full rounded-md border border-amber-300 bg-amber-50 px-4 py-2.5 text-sm font-bold text-amber-950">Pause</button></form>
            @else
                <form method="POST" action="{{ route('workflows.resume', $workflow) }}">@csrf<button class="w-full rounded-md border border-emerald-300 bg-emerald-50 px-4 py-2.5 text-sm font-bold text-emerald-950">Turn on</button></form>
            @endif
        </aside>
    </div>
</x-layouts::app>

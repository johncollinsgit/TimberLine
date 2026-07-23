@php
    $definition = (array) $workflow->draft_definition;
    $trigger = (array) ($definition['trigger'] ?? []);
    $action = (array) ($definition['action'] ?? []);
    $testState = (array) $workflow->test_state;
    $triggerTested = (bool) data_get($testState, 'trigger.ok', false);
    $actionTested = (bool) data_get($testState, 'action.ok', false);
    $projects = (array) ($asanaConnection['projects'] ?? []);
    $calendars = (array) ($googleConnection['calendars'] ?? []);
    $asanaConnected = (bool) ($asanaConnection['oauth_connected'] ?? false) || (bool) ($asanaConnection['token_ready'] ?? false);
    $googleConnected = (bool) ($googleConnection['connected'] ?? false);
    $sourceProvider = (string) ($trigger['provider'] ?? 'asana');
    $commerceSource = in_array($sourceProvider, ['shopify', 'square', 'squarespace', 'wix'], true);
    $sourceLabel = data_get($providers, $sourceProvider.'.label', str($sourceProvider)->headline());
    $triggerConnected = $commerceSource ? (bool)($commerceConnectionStatus['connected'] ?? false) : $asanaConnected;
    $presentation = (array) $calendarAppearance;
    $descriptionFields = (array) ($presentation['description_fields'] ?? []);
    $defaultTitleTemplate = $commerceSource ? '{{source}} #{{order_number}}' : '{{task_name}}';
    $primaryMappingChip = $commerceSource ? '{{order_number}}' : '{{task_name}}';
    $customerMappingChip = '{{customer_name}}';
    $sourceMappingChip = '{{source}}';
    $calendarColors = [
        '1' => ['Lavender', '#7986cb'], '2' => ['Sage', '#33b679'], '3' => ['Grape', '#8e24aa'],
        '4' => ['Flamingo', '#e67c73'], '5' => ['Banana', '#f6c026'], '6' => ['Tangerine', '#f4511e'],
        '7' => ['Peacock', '#039be5'], '8' => ['Graphite', '#616161'], '9' => ['Blueberry', '#3f51b5'],
        '10' => ['Basil', '#0b8043'], '11' => ['Tomato', '#d50000'],
    ];
@endphp
<x-layouts::app :title="$workflow->name">
    <div
        class="min-h-full bg-zinc-50"
        x-data="{
            stepPicker: false,
            pickerTab: 'apps',
            stepSearch: '',
            matchesStep(...values) {
                const term = this.stepSearch.trim().toLowerCase();

                return !term || values.join(' ').toLowerCase().includes(term);
            },
        }"
        data-workflow-editor-root
    >
        <header class="sticky top-0 z-20 border-b border-zinc-200 bg-white/95 backdrop-blur">
            <div class="mx-auto flex max-w-[1560px] flex-wrap items-center gap-2.5 px-4 py-2.5 sm:px-6">
                <a href="{{ route('workflows.index') }}" wire:navigate class="inline-flex h-9 w-9 items-center justify-center rounded-lg text-lg text-zinc-500 transition hover:bg-zinc-100 hover:text-zinc-900" aria-label="Back to workflows">←</a>
                <div class="min-w-0 flex-1"><div class="truncate text-sm font-bold text-zinc-950">{{ $workflow->name }}</div><div class="flex items-center gap-2 text-[11px] text-zinc-500"><span data-autosave-status>Draft saved</span><span>•</span><span>Version {{ $workflow->publishedVersion?->version ?? '—' }}</span></div></div>
                <span class="inline-flex items-center gap-1.5 rounded-md border px-2 py-1 text-[11px] font-bold {{ $workflow->status === 'active' ? 'border-emerald-200 bg-emerald-50 text-emerald-800' : ($workflow->status === 'paused' ? 'border-amber-200 bg-amber-50 text-amber-800' : 'border-zinc-200 bg-zinc-50 text-zinc-600') }}"><span class="h-1.5 w-1.5 rounded-full {{ $workflow->status === 'active' ? 'bg-emerald-500' : ($workflow->status === 'paused' ? 'bg-amber-500' : 'bg-zinc-400') }}"></span>{{ $workflow->status === 'active' ? 'On' : ucfirst($workflow->status) }}</span>
                @if($workflow->published_version_id)
                    <form method="POST" action="{{ $workflow->status === 'active' ? route('workflows.pause', $workflow) : route('workflows.resume', $workflow) }}">@csrf<button class="rounded-lg border border-zinc-300 bg-white px-3 py-2 text-xs font-bold text-zinc-800 hover:bg-zinc-50">{{ $workflow->status === 'active' ? 'Pause' : 'Turn on' }}</button></form>
                    <form method="POST" action="{{ route('workflows.run', $workflow) }}">@csrf<button class="rounded-lg border border-zinc-300 bg-white px-3 py-2 text-xs font-bold text-zinc-800 hover:bg-zinc-50">Run now</button></form>
                @endif
                <form method="POST" action="{{ route('workflows.publish', $workflow) }}">@csrf<button @disabled(!($triggerTested && $actionTested)) class="rounded-lg px-3.5 py-2 text-xs font-bold {{ $triggerTested && $actionTested ? 'bg-zinc-950 text-white hover:bg-zinc-800' : 'cursor-not-allowed bg-zinc-200 text-zinc-500' }}">Publish</button></form>
            </div>
        </header>

        <div class="mx-auto grid max-w-[1560px] gap-4 px-4 py-4 sm:px-6 xl:grid-cols-[180px_minmax(0,1fr)_400px]">
            <aside class="rounded-xl border border-zinc-200 bg-white p-2.5 shadow-sm xl:sticky xl:top-[4.5rem] xl:h-fit" aria-label="Workflow building blocks">
                <div class="px-2 pb-2 pt-1">
                    <p class="text-[10px] font-bold uppercase tracking-[0.14em] text-zinc-400">Build</p>
                    <p class="mt-1 text-xs leading-4 text-zinc-500">Add and organize workflow steps.</p>
                </div>
                <div class="grid gap-1 sm:grid-cols-3 xl:grid-cols-1">
                    <button type="button" @click="pickerTab = 'apps'; stepPicker = true" class="group flex items-center gap-2.5 rounded-lg px-2 py-2 text-left transition hover:bg-zinc-50">
                        <span class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-lg border border-zinc-200 bg-white text-zinc-700 shadow-sm">⚡</span>
                        <span><strong class="block text-xs font-semibold text-zinc-900">Trigger</strong><span class="block text-[10px] text-zinc-500">Starts the flow</span></span>
                    </button>
                    <button type="button" @click="pickerTab = 'apps'; stepPicker = true" class="group flex items-center gap-2.5 rounded-lg px-2 py-2 text-left transition hover:bg-zinc-50">
                        <span class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-lg border border-zinc-200 bg-white text-zinc-700 shadow-sm">▶</span>
                        <span><strong class="block text-xs font-semibold text-zinc-900">Action</strong><span class="block text-[10px] text-zinc-500">Does the work</span></span>
                    </button>
                    <button type="button" @click="pickerTab = 'controls'; stepPicker = true" class="group flex items-center gap-2.5 rounded-lg px-2 py-2 text-left transition hover:bg-zinc-50">
                        <span class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-lg border border-zinc-200 bg-white text-zinc-700 shadow-sm">⑂</span>
                        <span><strong class="block text-xs font-semibold text-zinc-900">Flow controls</strong><span class="block text-[10px] text-zinc-500">Filter, wait, branch</span></span>
                    </button>
                </div>
                <button type="button" @click="pickerTab = 'apps'; stepPicker = true" class="mt-2 w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-xs font-bold text-zinc-800 transition hover:bg-zinc-50">+ Add step</button>
            </aside>

            <main class="relative min-h-[720px] overflow-hidden rounded-xl border border-zinc-200 bg-zinc-100 p-4 sm:p-6" data-workflow-canvas>
                <div class="absolute inset-0 opacity-75 [background-image:radial-gradient(#c4c4c8_0.8px,transparent_0.8px)] [background-size:18px_18px]"></div>
                <div class="relative mx-auto max-w-xl">
                    <div class="mb-5 flex items-center justify-between gap-4 text-xs text-zinc-500">
                        <span class="font-semibold text-zinc-700">Workflow</span>
                        <span>Checks every 10 minutes</span>
                    </div>

                    <article class="rounded-xl border {{ $triggerTested ? 'border-emerald-300' : 'border-zinc-300' }} bg-white shadow-sm">
                        <div class="flex items-center gap-3 border-b border-zinc-100 px-4 py-3">
                            <x-workflows.partials.provider-icon :provider="$sourceProvider" :providers="$providers" size="sm" class="!rounded-lg !shadow-none" />
                            <div class="min-w-0 flex-1">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="text-[10px] font-bold uppercase tracking-[0.12em] text-zinc-400">1 · Trigger</span>
                                    @if($triggerTested)<span data-test-passed class="inline-flex items-center gap-1 text-[10px] font-bold text-emerald-700"><span class="h-1.5 w-1.5 rounded-full bg-emerald-500"></span>Test passed</span>@endif
                                </div>
                                <h2 class="truncate text-sm font-semibold text-zinc-950">{{ $template['trigger_event'] }}</h2>
                            </div>
                            <span class="text-zinc-300" aria-hidden="true">•••</span>
                        </div>
                        <div class="grid gap-3 px-4 py-3 sm:grid-cols-[1fr_auto] sm:items-center">
                            <div>
                                <p class="text-xs leading-5 text-zinc-500">{{ $commerceSource ? 'Watch the selected store and pair each order with one calendar event.' : 'Watch one Asana project. Tasks without dates are skipped.' }}</p>
                                <p class="mt-2 text-xs"><span class="font-semibold text-zinc-700">{{ $commerceSource ? 'Account' : 'Project' }}:</span> <span class="text-zinc-500">{{ $commerceSource ? ($commerceConnections->firstWhere('id', (int)($trigger['connection_id'] ?? 0))?->external_account_label ?? 'Choose in Setup') : data_get(collect($projects)->firstWhere('gid', $trigger['project_gid'] ?? null), 'name', 'Choose in Setup') }}</span></p>
                            </div>
                            <span class="rounded-md border border-zinc-200 bg-zinc-50 px-2 py-1 text-[10px] font-bold text-zinc-500">{{ $triggerConnected ? 'Connected' : 'Needs connection' }}</span>
                        </div>
                    </article>

                    <div class="mx-auto flex h-14 w-8 flex-col items-center justify-center">
                        <span class="h-4 w-px bg-zinc-300"></span>
                        <button type="button" @click="stepPicker = true" class="flex h-6 w-6 items-center justify-center rounded-md border border-zinc-300 bg-white text-sm text-zinc-500 shadow-sm transition hover:border-zinc-500 hover:text-zinc-900" aria-label="Add a workflow step">+</button>
                        <span class="h-4 w-px bg-zinc-300"></span>
                    </div>

                    <article class="rounded-xl border {{ $actionTested ? 'border-emerald-300' : 'border-zinc-300' }} bg-white shadow-sm">
                        <div class="flex items-center gap-3 border-b border-zinc-100 px-4 py-3">
                            <x-workflows.partials.provider-icon provider="google_calendar" :providers="$providers" size="sm" class="!rounded-lg !shadow-none" />
                            <div class="min-w-0 flex-1">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="text-[10px] font-bold uppercase tracking-[0.12em] text-zinc-400">2 · Action</span>
                                    @if($actionTested)<span data-test-passed class="inline-flex items-center gap-1 text-[10px] font-bold text-emerald-700"><span class="h-1.5 w-1.5 rounded-full bg-emerald-500"></span>Test passed</span>@endif
                                </div>
                                <h2 class="truncate text-sm font-semibold text-zinc-950">Create or update calendar event</h2>
                            </div>
                            <span class="text-zinc-300" aria-hidden="true">•••</span>
                        </div>
                        <div class="px-4 py-3">
                            <p class="text-xs"><span class="font-semibold text-zinc-700">Calendar:</span> <span class="text-zinc-500">{{ data_get(collect($calendars)->firstWhere('id', $action['calendar_id'] ?? null), 'summary', 'Choose in Setup') }}</span></p>
                            <div class="mt-3 overflow-hidden rounded-lg border border-zinc-200 bg-zinc-50" aria-label="Calendar event preview">
                                <div class="flex items-center justify-between border-b border-zinc-200 px-3 py-2"><span class="text-[10px] font-bold uppercase tracking-[0.12em] text-zinc-500">Event preview</span><span class="text-[10px] text-zinc-400">Sample data</span></div>
                                <div class="flex gap-3 bg-white p-3"><span data-preview-color class="mt-0.5 h-10 w-1 shrink-0 rounded-full" style="background: {{ data_get($calendarColors, ($presentation['color_id'] ?? '').'.1', '#0b8043') }}"></span><div class="min-w-0"><h3 data-preview-title class="truncate text-sm font-semibold text-zinc-950">{{ $calendarPreview['summary'] ?? 'Everbranch event' }}</h3><p class="mt-0.5 text-xs text-zinc-500">Tue, Jul 21 · {{ ($action['default_duration_minutes'] ?? 60) }} minutes · {{ ($presentation['availability'] ?? 'busy') === 'free' ? 'Free' : 'Busy' }}</p>@if(filled($calendarPreview['location'] ?? null))<p data-preview-location class="mt-1.5 text-xs text-zinc-600">⌖ {{ $calendarPreview['location'] }}</p>@else<p data-preview-location class="mt-1.5 hidden text-xs text-zinc-600"></p>@endif<p data-preview-description class="mt-1.5 line-clamp-3 whitespace-pre-line text-xs leading-5 text-zinc-500">{{ $calendarPreview['description'] ?? '' }}</p></div></div>
                            </div>
                        </div>
                    </article>

                    <div class="mt-5 flex flex-wrap items-center justify-center gap-x-5 gap-y-2 text-[11px] text-zinc-500">
                        <span class="inline-flex items-center gap-1.5"><span class="text-emerald-600">✓</span> Duplicate safe</span>
                        <span class="inline-flex items-center gap-1.5"><span class="text-emerald-600">✓</span> Failure safe</span>
                        <span class="inline-flex items-center gap-1.5"><span class="text-emerald-600">✓</span> Completion aware</span>
                    </div>
                </div>
            </main>

            <aside class="space-y-3">
                <form method="POST" action="{{ route('workflows.update', $workflow) }}" class="space-y-4 rounded-xl border border-zinc-200 bg-white p-4 shadow-sm" data-autosave-form>
                    @csrf @method('PUT')
                    <div class="flex items-center justify-between"><div><div class="text-[10px] font-bold uppercase tracking-[0.14em] text-zinc-400">Setup</div><h2 class="mt-0.5 text-base font-bold text-zinc-950">Configure your steps</h2></div><span class="rounded-md bg-zinc-100 px-2 py-1 text-[10px] font-bold text-zinc-500">Autosaves</span></div>
                    <div><label for="workflow-name" class="text-xs font-bold text-zinc-600">Workflow name</label><input id="workflow-name" name="name" value="{{ old('name', $workflow->name) }}" class="mt-1.5 w-full rounded-lg border-zinc-200 bg-zinc-50 text-sm focus:border-emerald-500 focus:ring-emerald-500" /></div>

                    <fieldset class="space-y-3 rounded-lg border border-zinc-200 bg-white p-3.5"><legend class="px-1 text-xs font-bold text-zinc-800">1 · {{ $sourceLabel }} trigger</legend>
                        @if(!$triggerConnected)<div class="rounded-lg border border-amber-200 bg-amber-50 p-3 text-xs text-amber-900">Connect {{ $sourceLabel }} before configuring the trigger. <a href="{{ route('workflows.connections') }}" wire:navigate class="font-bold underline">Open Connections</a></div>@endif
                        @if($commerceSource)
                            <label class="block text-xs font-bold text-zinc-700" for="trigger-connection">Account</label><select id="trigger-connection" name="trigger_connection_id" class="w-full rounded-xl border-zinc-200 bg-white text-sm" @disabled(!$triggerConnected)><option value="">Choose an account</option>@foreach($commerceConnections as $connection)<option value="{{ $connection->id }}" @selected((int)old('trigger_connection_id', $trigger['connection_id'] ?? 0) === $connection->id)>{{ $connection->external_account_label ?: $sourceLabel.' account' }}</option>@endforeach</select>
                            <label class="block text-xs font-bold text-zinc-700" for="schedule-source">Place the event at</label><select id="schedule-source" name="schedule_source" class="w-full rounded-xl border-zinc-200 bg-white text-sm"><option value="fulfillment" @selected(($trigger['schedule_source'] ?? 'fulfillment') === 'fulfillment')>Fulfillment or ready time</option><option value="delivery" @selected(($trigger['schedule_source'] ?? '') === 'delivery')>Scheduled delivery time</option><option value="pickup" @selected(($trigger['schedule_source'] ?? '') === 'pickup')>Scheduled pickup time</option><option value="order_created" @selected(($trigger['schedule_source'] ?? '') === 'order_created')>When the order was created</option></select><p class="text-[11px] leading-4 text-zinc-500">If the chosen time is missing, the order is held for review instead of guessing.</p>
                        @else
                            <label class="block text-xs font-bold text-zinc-700" for="project-gid">Project</label><select id="project-gid" name="project_gid" class="w-full rounded-xl border-zinc-200 bg-white text-sm" @disabled(!$asanaConnected)><option value="">Choose a project</option>@foreach($projects as $project)<option value="{{ $project['gid'] }}" @selected(old('project_gid', $trigger['project_gid'] ?? '') === $project['gid'])>{{ $project['name'] }} · {{ $project['workspace_name'] }}</option>@endforeach</select><input type="hidden" name="schedule_source" value="source_date" />
                        @endif
                    </fieldset>

                    <fieldset class="space-y-3 rounded-lg border border-zinc-200 bg-white p-3.5"><legend class="px-1 text-xs font-bold text-zinc-800">2 · Google Calendar action</legend>
                        @if(!$googleConnected)<div class="rounded-lg border border-amber-200 bg-amber-50 p-3 text-xs text-amber-900">Connect Google Calendar before selecting a calendar. <a href="{{ route('workflows.connections') }}" wire:navigate class="font-bold underline">Open Connections</a></div>@endif
                        <label class="block text-xs font-bold text-zinc-700" for="calendar-id">Calendar</label><select id="calendar-id" name="calendar_id" class="w-full rounded-xl border-zinc-200 bg-white text-sm" @disabled(!$googleConnected)><option value="">Choose a writable calendar</option>@foreach($calendars as $calendar)<option value="{{ $calendar['id'] }}" @selected(old('calendar_id', $action['calendar_id'] ?? '') === $calendar['id'])>{{ $calendar['summary'] }}{{ $calendar['primary'] ? ' · Primary' : '' }}</option>@endforeach</select>
                        <div class="grid gap-3 sm:grid-cols-2"><div><label class="text-xs font-bold text-zinc-700" for="timezone">Timezone</label><select id="timezone" name="timezone" class="mt-1 w-full rounded-xl border-zinc-200 bg-white text-sm">@foreach(['America/New_York','America/Chicago','America/Denver','America/Los_Angeles','UTC'] as $timezone)<option value="{{ $timezone }}" @selected(($action['timezone'] ?? config('app.timezone')) === $timezone)>{{ str_replace('_', ' ', $timezone) }}</option>@endforeach</select></div><div><label class="text-xs font-bold text-zinc-700" for="duration">Timed duration</label><select id="duration" name="default_duration_minutes" class="mt-1 w-full rounded-xl border-zinc-200 bg-white text-sm">@foreach([15,30,45,60,90,120] as $minutes)<option value="{{ $minutes }}" @selected((int)($action['default_duration_minutes'] ?? 60) === $minutes)>{{ $minutes }} minutes</option>@endforeach</select></div></div>
                        <label class="flex items-start gap-3 rounded-xl bg-white p-3 text-sm text-zinc-700"><input type="checkbox" name="skip_completed_tasks" value="1" class="mt-0.5 rounded border-zinc-300 text-emerald-600 focus:ring-emerald-500" @checked((bool)($action['skip_completed_tasks'] ?? true)) /><span><strong class="block text-zinc-950">Skip completed tasks</strong><span class="text-xs text-zinc-500">Existing events remain unchanged when a task is completed.</span></span></label>

                        <details open class="group rounded-lg border border-zinc-200 bg-white">
                            <summary class="flex cursor-pointer list-none items-center justify-between gap-3 px-3 py-2.5 text-sm font-bold text-zinc-950"><span>Calendar appearance</span><span class="text-zinc-400 transition group-open:rotate-180" aria-hidden="true">⌄</span></summary>
                            <div class="space-y-4 border-t border-zinc-200 p-3">
                                <div><label class="text-xs font-bold text-zinc-700" for="event-title-template">Event title</label><input id="event-title-template" name="event_title_template" value="{{ old('event_title_template', $presentation['title_template'] ?? $defaultTitleTemplate) }}" class="mt-1 w-full rounded-xl border-zinc-200 bg-zinc-50 text-sm" data-event-title-template /><p class="mt-1.5 text-[11px] leading-4 text-zinc-500">Use mapping chips like <code class="rounded bg-zinc-100 px-1">{{ $primaryMappingChip }}</code>@if($commerceSource), <code class="rounded bg-zinc-100 px-1">{{ $customerMappingChip }}</code>, and <code class="rounded bg-zinc-100 px-1">{{ $sourceMappingChip }}</code>@endif.</p></div>
                                <fieldset><legend class="text-xs font-bold text-zinc-700">Show in description</legend><div class="mt-2 grid gap-2 sm:grid-cols-2">
                                    @foreach(($commerceSource ? ['items' => 'Order items', 'total' => 'Order total', 'status' => 'Order status', 'customer_contact' => 'Customer contact', 'source_link' => 'Source link'] : ['notes' => 'Task notes', 'status' => 'Task status', 'source_link' => 'Asana link']) as $field => $label)
                                        <label class="flex items-center gap-2 rounded-lg border border-zinc-100 px-2.5 py-2 text-xs font-semibold text-zinc-700"><input type="checkbox" name="event_description_fields[]" value="{{ $field }}" class="rounded border-zinc-300 text-emerald-600" @checked(in_array($field, $descriptionFields, true)) data-description-field />{{ $label }}</label>
                                    @endforeach
                                </div>@if($commerceSource)<p class="mt-2 text-[11px] text-zinc-500">Customer email and phone stay off by default for privacy.</p>@endif</fieldset>
                                @if($commerceSource)<div><label class="text-xs font-bold text-zinc-700" for="event-location-source">Event location</label><select id="event-location-source" name="event_location_source" class="mt-1 w-full rounded-xl border-zinc-200 bg-white text-sm" data-event-location-source>@foreach(['shipping_address' => 'Shipping address', 'billing_address' => 'Billing address', 'pickup_location' => 'Pickup location', 'none' => 'No location'] as $value => $label)<option value="{{ $value }}" @selected(($presentation['location_source'] ?? 'shipping_address') === $value)>{{ $label }}</option>@endforeach</select></div>@else<input type="hidden" name="event_location_source" value="none" />@endif
                                <div class="grid gap-3 sm:grid-cols-2"><div><label class="text-xs font-bold text-zinc-700" for="event-color">Event color</label><select id="event-color" name="event_color_id" class="mt-1 w-full rounded-xl border-zinc-200 bg-white text-sm" data-event-color><option value="">Calendar default</option>@foreach($calendarColors as $id => [$label, $hex])<option value="{{ $id }}" data-color="{{ $hex }}" @selected((string)($presentation['color_id'] ?? '') === $id)>{{ $label }}</option>@endforeach</select></div><div><label class="text-xs font-bold text-zinc-700" for="event-availability">Show me as</label><select id="event-availability" name="event_availability" class="mt-1 w-full rounded-xl border-zinc-200 bg-white text-sm"><option value="busy" @selected(($presentation['availability'] ?? 'busy') === 'busy')>Busy</option><option value="free" @selected(($presentation['availability'] ?? 'busy') === 'free')>Free</option></select></div></div>
                                <div class="grid gap-3 sm:grid-cols-2"><div><label class="text-xs font-bold text-zinc-700" for="event-visibility">Visibility</label><select id="event-visibility" name="event_visibility" class="mt-1 w-full rounded-xl border-zinc-200 bg-white text-sm"><option value="default" @selected(($presentation['visibility'] ?? 'default') === 'default')>Calendar default</option><option value="private" @selected(($presentation['visibility'] ?? 'default') === 'private')>Private</option></select></div><div><label class="text-xs font-bold text-zinc-700" for="event-reminders">Reminders</label><select id="event-reminders" name="event_reminders" class="mt-1 w-full rounded-xl border-zinc-200 bg-white text-sm"><option value="default" @selected(($presentation['reminders'] ?? 'default') === 'default')>Calendar default</option><option value="none" @selected(($presentation['reminders'] ?? 'default') === 'none')>None</option></select></div></div>
                                @if($commerceSource)<div><label class="text-xs font-bold text-zinc-700" for="cancelled-order-behavior">Cancelled orders</label><select id="cancelled-order-behavior" name="cancelled_order_behavior" class="mt-1 w-full rounded-xl border-zinc-200 bg-white text-sm"><option value="mark_cancelled" @selected(($presentation['cancelled_order_behavior'] ?? 'mark_cancelled') === 'mark_cancelled')>Keep event and mark “Cancelled”</option><option value="leave_unchanged" @selected(($presentation['cancelled_order_behavior'] ?? 'mark_cancelled') === 'leave_unchanged')>Leave existing event unchanged</option></select></div>@else<input type="hidden" name="cancelled_order_behavior" value="mark_cancelled" />@endif
                            </div>
                        </details>
                    </fieldset>
                    <button class="w-full rounded-lg border border-zinc-300 bg-white px-4 py-2.5 text-sm font-bold text-zinc-800 hover:bg-zinc-50">Save draft</button>
                </form>

                <section class="rounded-xl border border-zinc-200 bg-white p-4 shadow-sm"><h2 class="text-sm font-bold text-zinc-950">Test before publishing</h2><p class="mt-1 text-xs leading-5 text-zinc-500">Tests are tied to this exact draft. Changing setup clears them.</p><div class="mt-3 grid gap-2 sm:grid-cols-2 xl:grid-cols-1 2xl:grid-cols-2"><form method="POST" action="{{ route('workflows.test-trigger', $workflow) }}">@csrf<button class="w-full rounded-lg border px-3 py-2.5 text-left text-sm font-bold {{ $triggerTested ? 'border-emerald-200 bg-emerald-50 text-emerald-800' : 'border-zinc-200 bg-zinc-50 text-zinc-800' }}">{{ $triggerTested ? '✓' : '1.' }} Test trigger<span class="mt-1 block text-xs font-normal opacity-75">{{ data_get($testState, 'trigger.summary', 'Verify project access') }}</span></button></form><form method="POST" action="{{ route('workflows.test-action', $workflow) }}">@csrf<button class="w-full rounded-lg border px-3 py-2.5 text-left text-sm font-bold {{ $actionTested ? 'border-emerald-200 bg-emerald-50 text-emerald-800' : 'border-zinc-200 bg-zinc-50 text-zinc-800' }}">{{ $actionTested ? '✓' : '2.' }} Test action<span class="mt-1 block text-xs font-normal opacity-75">{{ data_get($testState, 'action.summary', 'Create and remove a test event') }}</span></button></form></div></section>

                @if($workflow->runs->isNotEmpty())<section class="rounded-xl border border-zinc-200 bg-white p-4 shadow-sm"><div class="flex items-center justify-between"><h2 class="text-sm font-bold text-zinc-950">Recent runs</h2><a href="{{ route('workflows.history', ['workflow' => $workflow->id]) }}" wire:navigate class="text-xs font-bold text-emerald-700">View all</a></div><div class="mt-3 divide-y divide-zinc-100">@foreach($workflow->runs as $run)<a href="{{ route('workflows.runs.show', $run) }}" wire:navigate class="flex items-center justify-between gap-3 py-3 text-sm"><span class="font-semibold text-zinc-700">{{ $run->created_at->format('M j, g:i A') }}</span><span class="rounded-full px-2 py-1 text-[10px] font-bold {{ $run->status === 'success' ? 'bg-emerald-100 text-emerald-800' : 'bg-rose-100 text-rose-800' }}">{{ str($run->status)->headline() }}</span></a>@endforeach</div></section>@endif
            </aside>
        </div>

        <div
            x-show="stepPicker"
            x-cloak
            x-transition.opacity
            class="fixed inset-0 z-50 flex items-center justify-center bg-zinc-950/45 p-4"
            role="dialog"
            aria-modal="true"
            aria-labelledby="step-picker-title"
            @keydown.escape.window="stepPicker = false"
            @click.self="stepPicker = false"
        >
            <div class="grid max-h-[min(760px,90vh)] w-full max-w-4xl overflow-hidden rounded-2xl border border-zinc-200 bg-white shadow-2xl lg:grid-cols-[210px_minmax(0,1fr)]">
                <aside class="border-b border-zinc-200 bg-zinc-50 p-3 lg:border-b-0 lg:border-r">
                    <div class="flex items-center justify-between px-2 py-2">
                        <p class="text-sm font-bold text-zinc-950">Add a step</p>
                        <button type="button" @click="stepPicker = false" class="rounded-md p-1 text-zinc-400 hover:bg-zinc-200 hover:text-zinc-800 lg:hidden" aria-label="Close">×</button>
                    </div>
                    <nav class="mt-2 grid grid-cols-3 gap-1 lg:grid-cols-1" aria-label="Step categories">
                        <button type="button" @click="pickerTab = 'apps'" :class="pickerTab === 'apps' ? 'bg-white text-zinc-950 shadow-sm' : 'text-zinc-600 hover:bg-white/70'" class="rounded-lg px-3 py-2 text-left text-sm font-semibold">Apps</button>
                        <button type="button" @click="pickerTab = 'controls'" :class="pickerTab === 'controls' ? 'bg-white text-zinc-950 shadow-sm' : 'text-zinc-600 hover:bg-white/70'" class="rounded-lg px-3 py-2 text-left text-sm font-semibold">Flow controls</button>
                        <button type="button" @click="pickerTab = 'utilities'" :class="pickerTab === 'utilities' ? 'bg-white text-zinc-950 shadow-sm' : 'text-zinc-600 hover:bg-white/70'" class="rounded-lg px-3 py-2 text-left text-sm font-semibold">Utilities</button>
                    </nav>
                </aside>

                <section class="min-w-0 overflow-y-auto p-5 sm:p-6">
                    <div class="flex items-center justify-between gap-4">
                        <div>
                            <h2 id="step-picker-title" class="text-lg font-bold text-zinc-950">Choose a workflow step</h2>
                            <p class="mt-1 text-sm text-zinc-500">Triggers start a workflow. Actions and controls decide what happens next.</p>
                        </div>
                        <button type="button" @click="stepPicker = false" class="hidden rounded-lg p-2 text-xl leading-none text-zinc-400 transition hover:bg-zinc-100 hover:text-zinc-800 lg:block" aria-label="Close">×</button>
                    </div>

                    <div class="relative mt-5">
                        <svg class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-zinc-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="7"></circle><path d="m20 20-3.6-3.6"></path></svg>
                        <input x-model="stepSearch" type="search" placeholder="Search apps, triggers, actions, and controls" class="w-full rounded-lg border-zinc-200 bg-zinc-50 py-2.5 pl-9 pr-3 text-sm focus:border-emerald-600 focus:ring-emerald-600">
                    </div>

                    <div x-show="pickerTab === 'apps'" class="mt-6">
                        <div class="flex items-center justify-between"><h3 class="text-xs font-bold uppercase tracking-[0.12em] text-zinc-400">Available apps</h3><span class="text-xs text-zinc-400">2 connected step types</span></div>
                        <div class="mt-3 grid gap-3 sm:grid-cols-2">
                            <button x-show="matchesStep(@js($sourceLabel), @js($template['trigger_event']), 'trigger app')" type="button" @click="stepPicker = false; (document.getElementById('project-gid') || document.getElementById('trigger-connection'))?.focus()" class="flex items-center gap-3 rounded-xl border border-zinc-200 p-4 text-left transition hover:border-zinc-400 hover:bg-zinc-50">
                                <x-workflows.partials.provider-icon :provider="$sourceProvider" :providers="$providers" size="sm" class="!rounded-lg !shadow-none" />
                                <span class="min-w-0"><span class="block text-[10px] font-bold uppercase tracking-[0.1em] text-zinc-400">Trigger</span><strong class="block truncate text-sm text-zinc-950">{{ $sourceLabel }}</strong><span class="block truncate text-xs text-zinc-500">{{ $template['trigger_event'] }}</span></span>
                            </button>
                            <button x-show="matchesStep('Google Calendar', 'create update event action app')" type="button" @click="stepPicker = false; document.getElementById('calendar-id')?.focus()" class="flex items-center gap-3 rounded-xl border border-zinc-200 p-4 text-left transition hover:border-zinc-400 hover:bg-zinc-50">
                                <x-workflows.partials.provider-icon provider="google_calendar" :providers="$providers" size="sm" class="!rounded-lg !shadow-none" />
                                <span class="min-w-0"><span class="block text-[10px] font-bold uppercase tracking-[0.1em] text-zinc-400">Action</span><strong class="block truncate text-sm text-zinc-950">Google Calendar</strong><span class="block truncate text-xs text-zinc-500">Create or update event</span></span>
                            </button>
                        </div>
                    </div>

                    <div x-show="pickerTab === 'controls'" x-cloak class="mt-6">
                        <div class="flex items-center justify-between"><h3 class="text-xs font-bold uppercase tracking-[0.12em] text-zinc-400">Flow controls</h3><span class="rounded-md bg-zinc-100 px-2 py-1 text-[10px] font-bold uppercase tracking-[0.1em] text-zinc-500">Engine support planned</span></div>
                        <div class="mt-3 divide-y divide-zinc-100 overflow-hidden rounded-xl border border-zinc-200">
                            @foreach([
                                ['Filter', 'Continue only when conditions match', '⌁'],
                                ['Delay', 'Wait for a time or until a date', '◷'],
                                ['Paths', 'Send work down different branches', '⑂'],
                                ['Loop', 'Repeat actions for each item', '↻'],
                            ] as [$label, $copy, $icon])
                                <div x-show="matchesStep(@js($label), @js($copy), 'flow control')" class="flex items-center gap-3 bg-white px-4 py-3.5">
                                    <span class="inline-flex h-9 w-9 items-center justify-center rounded-lg border border-zinc-200 bg-zinc-50 text-zinc-600">{{ $icon }}</span>
                                    <span class="min-w-0 flex-1"><strong class="block text-sm text-zinc-900">{{ $label }}</strong><span class="block text-xs text-zinc-500">{{ $copy }}</span></span>
                                    <span class="text-[10px] font-bold uppercase tracking-[0.1em] text-zinc-400">Coming soon</span>
                                </div>
                            @endforeach
                        </div>
                    </div>

                    <div x-show="pickerTab === 'utilities'" x-cloak class="mt-6">
                        <h3 class="text-xs font-bold uppercase tracking-[0.12em] text-zinc-400">Utilities</h3>
                        <div class="mt-3 divide-y divide-zinc-100 overflow-hidden rounded-xl border border-zinc-200">
                            @foreach([
                                ['Formatter', 'Transform dates, text, and numbers'],
                                ['Webhook', 'Send structured data to another service'],
                                ['Schedule', 'Run at a specific time or interval'],
                            ] as [$label, $copy])
                                <div x-show="matchesStep(@js($label), @js($copy), 'utility')" class="flex items-center gap-3 bg-white px-4 py-3.5">
                                    <span class="inline-flex h-9 w-9 items-center justify-center rounded-lg border border-zinc-200 bg-zinc-50 text-zinc-600">◇</span>
                                    <span class="min-w-0 flex-1"><strong class="block text-sm text-zinc-900">{{ $label }}</strong><span class="block text-xs text-zinc-500">{{ $copy }}</span></span>
                                    <span class="text-[10px] font-bold uppercase tracking-[0.1em] text-zinc-400">Coming soon</span>
                                </div>
                            @endforeach
                        </div>
                    </div>
                </section>
            </div>
        </div>
    </div>
</x-layouts::app>

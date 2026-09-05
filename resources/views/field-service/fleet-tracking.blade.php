@php
    $isConnected = $bouncieConnection?->isConnected() ?? false;
    $mappedCount = $devices->count();
    $policyReady = $settings->legal_reviewed_at && $settings->counsel_review_reference && $settings->policy_version && $settings->policy_sha256;
@endphp

<x-layouts::app.sidebar title="Location tracker">
    <flux:main>
        <div class="fb-workflow-shell space-y-5">
            <section class="relative overflow-hidden rounded-3xl bg-zinc-950 px-6 py-7 text-white shadow-xl shadow-zinc-900/10 md:px-8">
                <div class="pointer-events-none absolute -right-20 -top-24 h-64 w-64 rounded-full bg-emerald-500/20 blur-3xl"></div>
                <div class="relative flex flex-wrap items-start justify-between gap-5">
                    <div class="max-w-2xl">
                        <div class="mb-4 flex flex-wrap items-center gap-2 text-xs font-semibold uppercase tracking-[.16em] text-emerald-300"><span>{{ $tenant->name }}</span><span class="text-zinc-600">/</span><span>Location tracker</span></div>
                        <h1 class="text-3xl font-semibold tracking-tight md:text-4xl">Your crew and company vehicles, in one live view.</h1>
                        <p class="mt-3 max-w-xl text-sm leading-6 text-zinc-300">Connect the company Bouncie account, bring in the work vehicles, and control exactly when location sharing is active.</p>
                    </div>
                    <a href="{{ route('field-service.index') }}" class="rounded-xl bg-white/10 px-4 py-2.5 text-sm font-semibold text-white backdrop-blur transition hover:bg-white/15">Back to field service</a>
                </div>
                <div class="relative mt-7 grid gap-2 sm:grid-cols-3">
                    <div class="rounded-2xl bg-white/10 px-4 py-3 backdrop-blur"><div class="flex items-center gap-2 text-sm font-semibold"><span class="grid h-6 w-6 place-items-center rounded-full {{ $isConnected ? 'bg-emerald-400 text-zinc-950' : 'bg-white/10 text-white' }}">1</span>Connect Bouncie</div><p class="mt-1 pl-8 text-xs text-zinc-300">{{ $isConnected ? 'Connected and secure' : 'Waiting for authorization' }}</p></div>
                    <div class="rounded-2xl bg-white/10 px-4 py-3 backdrop-blur"><div class="flex items-center gap-2 text-sm font-semibold"><span class="grid h-6 w-6 place-items-center rounded-full {{ $mappedCount ? 'bg-emerald-400 text-zinc-950' : 'bg-white/10 text-white' }}">2</span>Import vehicles</div><p class="mt-1 pl-8 text-xs text-zinc-300">{{ $mappedCount }} linked · {{ count($bouncieVehicles) }} available</p></div>
                    <div class="rounded-2xl bg-white/10 px-4 py-3 backdrop-blur"><div class="flex items-center gap-2 text-sm font-semibold"><span class="grid h-6 w-6 place-items-center rounded-full {{ $policyReady && $settings->bouncie_tracking_enabled ? 'bg-emerald-400 text-zinc-950' : 'bg-white/10 text-white' }}">3</span>Turn on live view</div><p class="mt-1 pl-8 text-xs text-zinc-300">{{ $policyReady ? ($settings->bouncie_tracking_enabled ? 'Company vans are enabled' : 'Ready when you are') : 'Policy approval required' }}</p></div>
                </div>
            </section>

            @if(session('status'))<div class="rounded-2xl bg-emerald-50 px-5 py-4 text-sm font-medium text-emerald-900 shadow-sm">{{ session('status') }}</div>@endif
            @if($errors->any())<div class="rounded-2xl bg-red-50 px-5 py-4 text-sm font-medium text-red-800 shadow-sm">{{ $errors->first() }}</div>@endif
            @if(! $globalEnabled)<div class="rounded-2xl bg-amber-50 px-5 py-4 text-sm text-amber-900 shadow-sm">Location Tracker is staged but the Everbranch-wide rollout switch is still off. No location is being collected.</div>@endif

            <div class="grid gap-5 lg:grid-cols-5">
                <section class="rounded-3xl bg-white p-6 shadow-lg shadow-zinc-950/5 ring-1 ring-black/5 lg:col-span-3">
                    <div class="flex flex-wrap items-start justify-between gap-4">
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-[.16em] text-emerald-700">Bouncie account</p>
                            @if($isConnected)
                                <div class="mt-2 flex items-center gap-2"><span class="h-2.5 w-2.5 rounded-full bg-emerald-500 shadow-[0_0_0_5px_rgba(16,185,129,.12)]"></span><h2 class="text-2xl font-semibold text-zinc-950">Connected</h2></div>
                                <p class="mt-2 text-sm text-zinc-600">{{ $bouncieConnection->external_account_label }} · {{ count($bouncieVehicles) }} vehicle{{ count($bouncieVehicles) === 1 ? '' : 's' }} found</p>
                            @else
                                <h2 class="mt-2 text-2xl font-semibold text-zinc-950">Connect your fleet</h2><p class="mt-2 text-sm leading-6 text-zinc-600">Authorize this workspace without sharing the Bouncie password with Everbranch.</p>
                            @endif
                            @if($bouncieConnectionError)<p class="mt-3 text-sm font-medium text-red-700">{{ $bouncieConnectionError }}</p>@endif
                        </div>
                        @if($canManageBouncie)
                            <div class="flex flex-wrap gap-2"><a class="rounded-xl bg-zinc-950 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-zinc-800" href="{{ route('field-service.fleet-tracking.bouncie.connect') }}">{{ $isConnected ? 'Reconnect' : 'Connect Bouncie' }}</a>@if($isConnected)<form method="POST" action="{{ route('field-service.fleet-tracking.bouncie.disconnect') }}">@csrf<button class="rounded-xl bg-zinc-100 px-4 py-2.5 text-sm font-semibold text-zinc-700 transition hover:bg-zinc-200">Disconnect</button></form>@endif</div>
                        @endif
                    </div>
                    @if($isConnected && count($bouncieVehicles))
                        <div class="mt-6 rounded-2xl bg-emerald-50 p-5"><div class="flex flex-wrap items-center justify-between gap-4"><div><h3 class="font-semibold text-emerald-950">Bring in every company vehicle</h3><p class="mt-1 text-sm text-emerald-800">Everbranch securely matches each Bouncie tracker and keeps future imports idempotent.</p></div>@if($canManageBouncie)<form method="POST" action="{{ route('field-service.fleet-tracking.bouncie.sync-vehicles') }}">@csrf<button class="rounded-xl bg-emerald-700 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-emerald-800">{{ $mappedCount ? 'Refresh vehicles' : 'Import all vehicles' }}</button></form>@endif</div></div>
                    @endif
                    <div class="mt-5 grid grid-cols-3 gap-3">
                        <div class="rounded-2xl bg-zinc-50 p-4"><div class="text-2xl font-semibold text-zinc-950">{{ count($bouncieVehicles) }}</div><div class="mt-1 text-xs font-medium text-zinc-500">Bouncie vehicles</div></div>
                        <div class="rounded-2xl bg-zinc-50 p-4"><div class="text-2xl font-semibold text-zinc-950">{{ $mappedCount }}</div><div class="mt-1 text-xs font-medium text-zinc-500">Linked vehicles</div></div>
                        <div class="rounded-2xl bg-zinc-50 p-4"><div class="text-2xl font-semibold text-zinc-950">{{ $points->count() }}</div><div class="mt-1 text-xs font-medium text-zinc-500">Recent updates</div></div>
                    </div>
                </section>
                <section class="rounded-3xl bg-zinc-50 p-6 shadow-sm ring-1 ring-black/5 lg:col-span-2">
                    <p class="text-xs font-semibold uppercase tracking-[.16em] text-zinc-500">Privacy controls</p><h2 class="mt-2 text-xl font-semibold text-zinc-950">Built to stop at the job.</h2>
                    <div class="mt-5 space-y-4 text-sm text-zinc-700">
                        <div class="flex gap-3"><span class="grid h-7 w-7 shrink-0 place-items-center rounded-full bg-white font-semibold text-emerald-700 shadow-sm">✓</span><p><span class="font-semibold text-zinc-950">Company vans stay separate.</span><br>No personal vehicle tracking.</p></div>
                        <div class="flex gap-3"><span class="grid h-7 w-7 shrink-0 place-items-center rounded-full bg-white font-semibold text-emerald-700 shadow-sm">✓</span><p><span class="font-semibold text-zinc-950">Phones share only on the clock.</span><br>Employees can stop sharing manually.</p></div>
                        <div class="flex gap-3"><span class="grid h-7 w-7 shrink-0 place-items-center rounded-full bg-white font-semibold text-emerald-700 shadow-sm">✓</span><p><span class="font-semibold text-zinc-950">Retention is limited.</span><br>Location history is automatically pruned.</p></div>
                    </div>
                </section>
            </div>

            <section class="rounded-3xl bg-white p-6 shadow-lg shadow-zinc-950/5 ring-1 ring-black/5">
                <div class="flex flex-wrap items-end justify-between gap-3"><div><p class="text-xs font-semibold uppercase tracking-[.16em] text-emerald-700">Activation</p><h2 class="mt-1 text-2xl font-semibold text-zinc-950">Set the rules once</h2><p class="mt-1 text-sm text-zinc-600">These controls must be approved before the first live location is accepted.</p></div><span class="rounded-full px-3 py-1.5 text-xs font-semibold {{ $policyReady ? 'bg-emerald-100 text-emerald-800' : 'bg-amber-100 text-amber-900' }}">{{ $policyReady ? 'Policy approved' : 'Approval needed' }}</span></div>
                <form method="POST" action="{{ route('field-service.fleet-tracking.settings.update') }}" class="mt-6 grid gap-4 md:grid-cols-6">@csrf
                    <label class="flex items-center gap-3 rounded-2xl bg-zinc-50 px-4 py-3 text-sm font-semibold md:col-span-3"><input type="checkbox" name="bouncie_tracking_enabled" value="1" @checked($settings->bouncie_tracking_enabled) class="rounded border-zinc-300 text-emerald-700"> Company-van Bouncie feed</label>
                    <label class="flex items-center gap-3 rounded-2xl bg-zinc-50 px-4 py-3 text-sm font-semibold md:col-span-3"><input type="checkbox" name="phone_tracking_enabled" value="1" @checked($settings->phone_tracking_enabled) class="rounded border-zinc-300 text-emerald-700"> On-duty phone sharing</label>
                    <label class="text-sm font-semibold text-zinc-800 md:col-span-1">Retention<input type="number" name="retention_days" min="1" max="30" value="{{ $settings->retention_days }}" class="mt-1.5 block w-full rounded-xl border-0 bg-zinc-100 px-3 py-2.5 ring-1 ring-inset ring-zinc-200"><span class="mt-1 block text-xs font-normal text-zinc-500">1–30 days</span></label>
                    <label class="text-sm font-semibold text-zinc-800 md:col-span-2">Policy version<input name="policy_version" required value="{{ $settings->policy_version }}" placeholder="Example: 2026-09" class="mt-1.5 block w-full rounded-xl border-0 bg-zinc-100 px-3 py-2.5 ring-1 ring-inset ring-zinc-200"></label>
                    <label class="text-sm font-semibold text-zinc-800 md:col-span-3">Counsel review reference<input name="counsel_review_reference" required value="{{ $settings->counsel_review_reference }}" placeholder="Matter, date, or review reference" class="mt-1.5 block w-full rounded-xl border-0 bg-zinc-100 px-3 py-2.5 ring-1 ring-inset ring-zinc-200"></label>
                    <label class="text-sm font-semibold text-zinc-800 md:col-span-6">Approved employee policy<textarea name="policy_text" required rows="4" class="mt-1.5 block w-full resize-y rounded-xl border-0 bg-zinc-100 px-3 py-2.5 ring-1 ring-inset ring-zinc-200" placeholder="State the company-vehicle scope, active-shift-only phone sharing, manual stop, retention, access, and how employees can ask questions."></textarea></label>
                    <label class="flex items-start gap-3 rounded-2xl bg-amber-50 px-4 py-3 text-sm text-amber-950 md:col-span-4"><input type="checkbox" name="counsel_reviewed" value="1" required class="mt-0.5 rounded border-amber-300 text-emerald-700"><span>I confirm this workspace’s location policy was reviewed by counsel before enabling collection.</span></label>
                    <div class="flex items-stretch md:col-span-2"><button class="w-full rounded-2xl bg-zinc-950 px-5 py-3 text-sm font-semibold text-white shadow-sm transition hover:bg-zinc-800">Save and activate controls</button></div>
                </form>
            </section>

            <section class="rounded-3xl bg-white p-6 shadow-lg shadow-zinc-950/5 ring-1 ring-black/5">
                <div class="flex flex-wrap items-center justify-between gap-3"><div><p class="text-xs font-semibold uppercase tracking-[.16em] text-emerald-700">Company vehicles</p><h2 class="mt-1 text-2xl font-semibold text-zinc-950">Tracker assignments</h2></div><span class="rounded-full bg-zinc-100 px-3 py-1.5 text-xs font-semibold text-zinc-600">{{ $mappedCount }} linked</span></div>
                @if($devices->isNotEmpty())
                    <div class="mt-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">@foreach($devices as $device)<div class="rounded-2xl bg-zinc-50 p-4"><div class="flex items-center gap-3"><span class="grid h-10 w-10 place-items-center rounded-xl bg-emerald-100 text-emerald-800"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-5 w-5" aria-hidden="true"><path d="M3 14h18v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4Z"/><path d="m5 14 2-7h8.5L21 14M8 14V7m8 7V7"/><circle cx="7" cy="18" r="1.5" fill="currentColor"/><circle cx="17" cy="18" r="1.5" fill="currentColor"/></svg></span><div class="min-w-0"><p class="truncate font-semibold text-zinc-950">{{ $device->vehicle?->name }}</p><p class="truncate text-xs text-zinc-500">{{ $device->label ?: 'Bouncie tracker' }}</p></div></div></div>@endforeach</div>
                @elseif($isConnected)
                    <div class="mt-5 rounded-2xl bg-zinc-50 px-5 py-8 text-center"><p class="font-semibold text-zinc-900">Your Bouncie vehicles are ready to import.</p><p class="mt-1 text-sm text-zinc-500">Use “Import all vehicles” above to create and link them automatically.</p></div>
                @else
                    <div class="mt-5 rounded-2xl bg-zinc-50 px-5 py-8 text-center"><p class="font-semibold text-zinc-900">Connect Bouncie to see company vehicles.</p></div>
                @endif
                @if($isConnected && count($bouncieVehicles) && $vehicles->isNotEmpty() && $canManageBouncie)
                    <details class="mt-5 rounded-2xl bg-zinc-50 p-4"><summary class="cursor-pointer text-sm font-semibold text-zinc-800">Adjust one tracker manually</summary><form method="POST" action="{{ route('field-service.fleet-tracking.devices.store') }}" class="mt-4 grid gap-3 md:grid-cols-4">@csrf
                        <label class="text-sm font-semibold">Company vehicle<select name="field_service_vehicle_id" required class="mt-1 block w-full rounded-xl border-0 bg-white px-3 py-2.5 ring-1 ring-inset ring-zinc-200">@foreach($vehicles as $vehicle)<option value="{{ $vehicle->id }}">{{ $vehicle->name }}{{ $vehicle->identifier ? ' · '.$vehicle->identifier : '' }}</option>@endforeach</select></label>
                        <label class="text-sm font-semibold">Bouncie tracker<select name="external_device_id" required class="mt-1 block w-full rounded-xl border-0 bg-white px-3 py-2.5 ring-1 ring-inset ring-zinc-200">@foreach($bouncieVehicles as $providerVehicle)<option value="{{ $providerVehicle['imei'] }}">{{ $providerVehicle['nickName'] ?: trim(data_get($providerVehicle, 'model.year').' '.data_get($providerVehicle, 'model.make').' '.data_get($providerVehicle, 'model.name')) }}</option>@endforeach</select></label>
                        <label class="text-sm font-semibold">Display label<input name="label" class="mt-1 block w-full rounded-xl border-0 bg-white px-3 py-2.5 ring-1 ring-inset ring-zinc-200" placeholder="Service van"></label>
                        <div class="flex items-end"><button class="w-full rounded-xl bg-zinc-900 px-4 py-2.5 text-sm font-semibold text-white">Save assignment</button></div>
                    </form></details>
                @endif
            </section>

            <section class="overflow-hidden rounded-3xl bg-white shadow-lg shadow-zinc-950/5 ring-1 ring-black/5">
                <div class="flex flex-wrap items-center justify-between gap-3 px-6 py-5"><div><p class="text-xs font-semibold uppercase tracking-[.16em] text-emerald-700">Live activity</p><h2 class="mt-1 text-xl font-semibold text-zinc-950">Latest location updates</h2></div><span class="text-xs text-zinc-500">Crew phones and company vans remain separate</span></div>
                <div class="overflow-x-auto"><table class="min-w-full text-sm"><thead class="bg-zinc-50 text-left text-xs uppercase tracking-wide text-zinc-500"><tr><th class="px-6 py-3">Source</th><th class="px-6 py-3">Recorded</th><th class="px-6 py-3">Location</th><th class="px-6 py-3">Map</th></tr></thead><tbody class="divide-y divide-zinc-100">@forelse($points as $point)<tr><td class="px-6 py-4 font-semibold text-zinc-900">{{ $point->source === 'bouncie' ? 'Company van' : 'Crew phone · on duty' }}</td><td class="px-6 py-4 text-zinc-600">{{ $point->recorded_at?->format('M j, g:i A') }}</td><td class="px-6 py-4 text-zinc-600">{{ $point->latitude }}, {{ $point->longitude }}@if($point->accuracy_meters)<span class="ml-1 text-xs text-zinc-400">±{{ $point->accuracy_meters }}m</span>@endif</td><td class="px-6 py-4"><a class="font-semibold text-emerald-700" target="_blank" rel="noreferrer" href="https://www.google.com/maps?q={{ $point->latitude }},{{ $point->longitude }}">Open map</a></td></tr>@empty<tr><td colspan="4" class="px-6 py-12 text-center"><p class="font-semibold text-zinc-800">No live updates yet</p><p class="mt-1 text-sm text-zinc-500">Locations will appear here after vehicle import and policy activation.</p></td></tr>@endforelse</tbody></table></div>
            </section>
        </div>
    </flux:main>
</x-layouts::app.sidebar>

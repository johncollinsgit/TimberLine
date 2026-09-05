<x-layouts::app.sidebar title="Fleet tracking">
    <flux:main><div class="fb-workflow-shell space-y-6">
        <header class="flex flex-wrap justify-between gap-4 border-b border-zinc-200 pb-5">
            <div><p class="text-xs font-semibold uppercase tracking-[.18em] text-emerald-700">{{ $tenant->name }} · Fleet</p><h1 class="mt-1 text-3xl font-semibold text-zinc-950">Location tracking</h1><p class="mt-2 text-sm text-zinc-600">Company vehicles and on-duty phones stay separate. No personal-vehicle, off-duty, route-scoring, or automated employment-decision tracking.</p></div>
            <a href="{{ route('field-service.index') }}" class="fb-btn fb-btn-secondary">Field service</a>
        </header>
        @if(session('status'))<div class="fb-state fb-state-success">{{ session('status') }}</div>@endif
        @if($errors->any())<div class="rounded-lg bg-red-50 p-3 text-sm text-red-800">{{ $errors->first() }}</div>@endif
        @if(! $globalEnabled)<div class="rounded-lg bg-amber-50 p-4 text-sm text-amber-900">The global fleet-tracking switch is off. This workspace cannot collect or show locations until an operator completes the controlled rollout.</div>@endif

        <section class="fb-panel">
            <div class="fb-panel-head"><div><div class="fb-panel-title">Bouncie account</div><p class="mt-1 text-sm text-zinc-600">Each workspace connects its own Bouncie account. Credentials are encrypted and never shared with another workspace.</p></div></div>
            <div class="fb-panel-body flex flex-wrap items-center justify-between gap-4">
                <div>
                    @if($bouncieConnection?->isConnected())
                        <p class="font-semibold text-emerald-800">Connected · {{ $bouncieConnection->external_account_label }}</p>
                        <p class="text-sm text-zinc-600">{{ count($bouncieVehicles) }} vehicle{{ count($bouncieVehicles) === 1 ? '' : 's' }} available from Bouncie.</p>
                    @else
                        <p class="font-semibold text-zinc-900">Not connected</p><p class="text-sm text-zinc-600">An owner or administrator can securely authorize the workspace.</p>
                    @endif
                    @if($bouncieConnectionError)<p class="mt-1 text-sm text-red-700">{{ $bouncieConnectionError }}</p>@endif
                </div>
                @if($canManageBouncie)
                    <div class="flex gap-2">
                        <a class="fb-btn fb-btn-primary" href="{{ route('field-service.fleet-tracking.bouncie.connect') }}">{{ $bouncieConnection?->isConnected() ? 'Reconnect Bouncie' : 'Connect Bouncie' }}</a>
                        @if($bouncieConnection?->isConnected())<form method="POST" action="{{ route('field-service.fleet-tracking.bouncie.disconnect') }}">@csrf<button class="fb-btn fb-btn-secondary">Disconnect</button></form>@endif
                    </div>
                @endif
            </div>
        </section>

        <section class="fb-panel"><div class="fb-panel-head"><div class="fb-panel-title">Policy, legal review, and retention</div></div><div class="fb-panel-body"><form method="POST" action="{{ route('field-service.fleet-tracking.settings.update') }}" class="grid gap-3 md:grid-cols-3">@csrf
            <label class="flex items-center gap-2 text-sm font-semibold"><input type="checkbox" name="bouncie_tracking_enabled" value="1" @checked($settings->bouncie_tracking_enabled)> Enable company-van Bouncie feed</label>
            <label class="flex items-center gap-2 text-sm font-semibold"><input type="checkbox" name="phone_tracking_enabled" value="1" @checked($settings->phone_tracking_enabled)> Enable on-duty phone sharing</label>
            <label class="text-sm font-semibold">Retention days<input type="number" name="retention_days" min="1" max="30" value="{{ $settings->retention_days }}" class="mt-1 block w-full rounded border-zinc-300"></label>
            <label class="text-sm font-semibold">Policy version<input name="policy_version" required value="{{ $settings->policy_version }}" class="mt-1 block w-full rounded border-zinc-300"></label>
            <label class="text-sm font-semibold md:col-span-2">Counsel review reference<input name="counsel_review_reference" required value="{{ $settings->counsel_review_reference }}" placeholder="Matter, date, or retained review reference" class="mt-1 block w-full rounded border-zinc-300"></label>
            <label class="text-sm font-semibold md:col-span-3">Approved employee policy text<textarea name="policy_text" required rows="5" class="mt-1 block w-full rounded border-zinc-300" placeholder="State company-vehicle scope, active-shift-only phone sharing, manual stop, retention, access, and how employees can ask questions."></textarea></label>
            <label class="flex items-center gap-2 text-sm md:col-span-2"><input type="checkbox" name="counsel_reviewed" value="1" required> I confirm this tenant’s applicable location-tracking policy was reviewed by counsel before enabling collection.</label>
            <div class="flex items-end"><button class="fb-btn fb-btn-primary w-full justify-center">Save controlled settings</button></div>
        </form></div></section>

        <section class="fb-panel"><div class="fb-panel-head"><div class="fb-panel-title">Company vehicle hardware</div></div><div class="fb-panel-body space-y-4">
            @if($bouncieConnection?->isConnected() && count($bouncieVehicles))
                <form method="POST" action="{{ route('field-service.fleet-tracking.devices.store') }}" class="grid gap-3 md:grid-cols-3">@csrf
                    <label class="text-sm font-semibold">Company vehicle<select name="field_service_vehicle_id" required class="mt-1 block w-full rounded border-zinc-300">@foreach($vehicles as $vehicle)<option value="{{ $vehicle->id }}">{{ $vehicle->name }}{{ $vehicle->identifier ? ' · '.$vehicle->identifier : '' }}</option>@endforeach</select></label>
                    <label class="text-sm font-semibold">Bouncie tracker<select name="external_device_id" required class="mt-1 block w-full rounded border-zinc-300">@foreach($bouncieVehicles as $providerVehicle)<option value="{{ $providerVehicle['imei'] }}">{{ $providerVehicle['nickName'] ?: trim(data_get($providerVehicle, 'model.year').' '.data_get($providerVehicle, 'model.make').' '.data_get($providerVehicle, 'model.name')) }}</option>@endforeach</select></label>
                    <label class="text-sm font-semibold">Everbranch label<input name="label" class="mt-1 block w-full rounded border-zinc-300" placeholder="Van 1"></label>
                    <div><button class="fb-btn fb-btn-secondary">Map vehicle</button></div>
                </form>
            @else
                <p class="text-sm text-zinc-600">Connect Bouncie to choose trackers from this workspace’s authorized account. Manual tracker IDs are not accepted.</p>
            @endif
            <div class="grid gap-2 md:grid-cols-2">@foreach($devices as $device)<div class="rounded-lg bg-zinc-50 px-3 py-2 text-sm"><span class="font-semibold">{{ $device->vehicle?->name }}</span> · {{ $device->label ?: 'Bouncie tracker' }}</div>@endforeach</div>
        </div></section>

        <section class="fb-panel"><div class="fb-panel-head"><div class="fb-panel-title">Latest separate source events</div></div><div class="fb-panel-body overflow-x-auto"><table class="min-w-full text-sm"><thead><tr class="text-left text-xs uppercase text-zinc-500"><th class="py-2 pr-3">Source</th><th class="py-2 pr-3">Recorded</th><th class="py-2 pr-3">Coordinates</th><th class="py-2">Map</th></tr></thead><tbody class="divide-y divide-zinc-100">@forelse($points as $point)<tr><td class="py-3 pr-3 font-semibold">{{ $point->source === 'bouncie' ? 'Company van · Bouncie' : 'Crew phone · active timer' }}</td><td class="py-3 pr-3">{{ $point->recorded_at?->format('M j, g:i A') }}</td><td class="py-3 pr-3">{{ $point->latitude }}, {{ $point->longitude }}@if($point->accuracy_meters)<span class="ml-1 text-xs text-zinc-500">±{{ $point->accuracy_meters }}m</span>@endif</td><td class="py-3"><a class="text-xs font-semibold text-emerald-700" target="_blank" rel="noreferrer" href="https://www.google.com/maps?q={{ $point->latitude }},{{ $point->longitude }}">Open Google Maps</a></td></tr>@empty<tr><td colspan="4" class="py-8 text-center text-zinc-500">No location events have been accepted. Tracking remains fail-closed until every rollout gate is complete.</td></tr>@endforelse</tbody></table></div></section>
    </div></flux:main>
</x-layouts::app.sidebar>

@php
    $lowStockCount = $items->filter(fn ($item) => (float) $item->quantity_on_hand <= (float) $item->reorder_level)->count();
@endphp

<x-layouts::app.sidebar title="Inventory & Work Vans">
    <flux:main>
        <div class="mx-auto w-full max-w-[1700px] space-y-6 px-3 py-4 sm:px-5 lg:px-7">
            <header class="rounded-3xl border border-zinc-200 bg-white p-5 shadow-sm sm:p-7">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <div class="text-xs font-semibold uppercase tracking-[0.22em] text-blue-800">{{ $tenant->name }}</div>
                        <h1 class="mt-2 text-3xl font-semibold tracking-tight text-zinc-950">Inventory & work vans</h1>
                        <p class="mt-2 max-w-3xl text-sm leading-6 text-zinc-600">Create stock items, load vans, assign employees and vans to jobs, and record what the crew uses in the field.</p>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <a href="{{ route('field-service.index') }}" class="fb-btn fb-btn-secondary">Back to Work</a>
                        <a href="#inventory" class="rounded-xl border border-blue-200 bg-blue-50 px-4 py-2.5 text-sm font-semibold text-blue-950">Inventory</a>
                        <a href="#vans" class="rounded-xl border border-blue-200 bg-blue-50 px-4 py-2.5 text-sm font-semibold text-blue-950">Work vans</a>
                        <a href="#deployments" class="rounded-xl border border-blue-200 bg-blue-50 px-4 py-2.5 text-sm font-semibold text-blue-950">Job deployments</a>
                    </div>
                </div>
            </header>

            @if(session('status'))
                <div class="rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-900">{{ session('status') }}</div>
            @endif

            <section class="grid gap-3 sm:grid-cols-3">
                <div class="rounded-2xl border border-zinc-200 bg-white p-4 shadow-sm"><div class="text-2xl font-semibold text-zinc-950">{{ $items->count() }}</div><div class="text-sm text-zinc-600">Inventory items</div></div>
                <div class="rounded-2xl border border-zinc-200 bg-white p-4 shadow-sm"><div class="text-2xl font-semibold {{ $lowStockCount > 0 ? 'text-amber-700' : 'text-zinc-950' }}">{{ $lowStockCount }}</div><div class="text-sm text-zinc-600">At or below reorder level</div></div>
                <div class="rounded-2xl border border-zinc-200 bg-white p-4 shadow-sm"><div class="text-2xl font-semibold text-zinc-950">{{ $vehicles->count() }}</div><div class="text-sm text-zinc-600">Work vans</div></div>
            </section>

            <section id="inventory" class="scroll-mt-6 rounded-3xl border border-zinc-200 bg-white p-5 shadow-sm sm:p-7">
                <div class="flex flex-wrap items-end justify-between gap-3">
                    <div><div class="text-xs font-semibold uppercase tracking-[0.18em] text-blue-800">Warehouse</div><h2 class="mt-1 text-2xl font-semibold text-zinc-950">Inventory</h2><p class="mt-1 text-sm text-zinc-600">Warehouse quantities exclude material already loaded on vans.</p></div>
                </div>

                @if($canManage)
                    <form method="POST" action="{{ route('field-service.resources.inventory.store') }}" class="mt-5 grid gap-3 rounded-2xl border border-blue-100 bg-blue-50/60 p-4 md:grid-cols-2 xl:grid-cols-8">
                        @csrf
                        <input name="name" required class="rounded-xl border-zinc-300 bg-white text-sm xl:col-span-2" placeholder="Item name">
                        <input name="sku" class="rounded-xl border-zinc-300 bg-white text-sm" placeholder="SKU / part #">
                        <input name="unit" class="rounded-xl border-zinc-300 bg-white text-sm" placeholder="Unit (each, ft)">
                        <input name="quantity_on_hand" type="number" min="0" step="0.01" class="rounded-xl border-zinc-300 bg-white text-sm" placeholder="Opening qty">
                        <input name="reorder_level" type="number" min="0" step="0.01" class="rounded-xl border-zinc-300 bg-white text-sm" placeholder="Reorder at">
                        <input name="unit_cost" type="number" min="0" step="0.01" class="rounded-xl border-zinc-300 bg-white text-sm" placeholder="Unit cost">
                        <button class="rounded-xl border border-blue-300 bg-blue-100 px-4 py-2 text-sm font-semibold text-blue-950 hover:bg-blue-200">Create item</button>
                    </form>
                @endif

                <div class="mt-5 overflow-x-auto rounded-2xl border border-zinc-200">
                    <table class="min-w-full divide-y divide-zinc-200 text-sm">
                        <thead class="bg-zinc-50 text-left text-xs font-semibold uppercase tracking-wide text-zinc-600"><tr><th class="px-4 py-3">Item</th><th class="px-4 py-3">Warehouse</th><th class="px-4 py-3">On vans</th><th class="px-4 py-3">Reorder</th><th class="px-4 py-3">Unit cost</th>@if($canManage)<th class="px-4 py-3">Quick adjustment</th>@endif</tr></thead>
                        <tbody class="divide-y divide-zinc-200 bg-white">
                            @forelse($items as $item)
                                @php($low = (float) $item->quantity_on_hand <= (float) $item->reorder_level)
                                <tr>
                                    <td class="px-4 py-3"><div class="font-semibold text-zinc-950">{{ $item->name }}</div><div class="text-xs text-zinc-500">{{ $item->sku ?: 'No SKU' }} · {{ $item->unit ?: 'each' }}</div></td>
                                    <td class="px-4 py-3"><span class="font-semibold {{ $low ? 'text-amber-800' : 'text-zinc-950' }}">{{ number_format((float) $item->quantity_on_hand, 2) }}</span>@if($low)<span class="ml-2 rounded-full bg-amber-100 px-2 py-1 text-[10px] font-bold uppercase text-amber-900">Low</span>@endif</td>
                                    <td class="px-4 py-3 text-zinc-700">{{ number_format((float) ($item->van_quantity ?? 0), 2) }}</td>
                                    <td class="px-4 py-3 text-zinc-700">{{ number_format((float) $item->reorder_level, 2) }}</td>
                                    <td class="px-4 py-3 text-zinc-700">{{ $item->unit_cost !== null ? '$'.number_format((float) $item->unit_cost, 2) : '—' }}</td>
                                    @if($canManage)<td class="px-4 py-3"><form method="POST" action="{{ route('field-service.resources.inventory.adjust', $item) }}" class="flex min-w-72 gap-2">@csrf<select name="action" class="rounded-lg border-zinc-300 text-xs"><option value="receive">Receive</option><option value="set">Set total</option></select><input name="quantity" required type="number" min="0" step="0.01" class="w-24 rounded-lg border-zinc-300 text-xs" placeholder="Qty"><button class="rounded-lg border border-blue-200 bg-blue-50 px-3 text-xs font-semibold text-blue-950">Save</button></form></td>@endif
                                </tr>
                            @empty
                                <tr><td colspan="6" class="px-4 py-10 text-center text-sm text-zinc-500">No inventory yet. Create the first commonly stocked part above.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </section>

            <section id="vans" class="scroll-mt-6 rounded-3xl border border-zinc-200 bg-white p-5 shadow-sm sm:p-7">
                <div><div class="text-xs font-semibold uppercase tracking-[0.18em] text-blue-800">Fleet stock</div><h2 class="mt-1 text-2xl font-semibold text-zinc-950">Work vans</h2><p class="mt-1 text-sm text-zinc-600">A van’s stock follows it to every assigned job.</p></div>
                @if($canManage)
                    <form method="POST" action="{{ route('field-service.vehicles.store') }}" class="mt-5 grid gap-3 rounded-2xl border border-zinc-200 bg-zinc-50 p-4 md:grid-cols-[1fr_1fr_2fr_auto]">
                        @csrf
                        <input name="name" required class="rounded-xl border-zinc-300 bg-white text-sm" placeholder="Van name">
                        <input name="identifier" class="rounded-xl border-zinc-300 bg-white text-sm" placeholder="Plate / unit #">
                        <input name="notes" class="rounded-xl border-zinc-300 bg-white text-sm" placeholder="Notes">
                        <button class="rounded-xl border border-blue-300 bg-blue-100 px-4 py-2 text-sm font-semibold text-blue-950">Create van</button>
                    </form>
                @endif

                <div class="mt-5 grid gap-4 xl:grid-cols-2">
                    @forelse($vehicles as $vehicle)
                        <article class="rounded-2xl border border-zinc-200 p-4">
                            <div class="flex items-start justify-between gap-3"><div><h3 class="text-lg font-semibold text-zinc-950">{{ $vehicle->name }}</h3><p class="text-sm text-zinc-500">{{ $vehicle->identifier ?: 'No unit number' }}</p></div><span class="rounded-full bg-emerald-50 px-3 py-1 text-xs font-semibold text-emerald-800">{{ ucfirst($vehicle->status) }}</span></div>
                            @if($canManage && $items->isNotEmpty())
                                <form method="POST" action="{{ route('field-service.resources.vans.stock', $vehicle) }}" class="mt-4 grid gap-2 sm:grid-cols-[1fr_auto_6rem_auto]">
                                    @csrf
                                    <select name="field_material_catalog_item_id" required class="rounded-lg border-zinc-300 text-xs"><option value="">Choose item</option>@foreach($items as $item)<option value="{{ $item->id }}">{{ $item->name }} ({{ number_format((float) $item->quantity_on_hand, 2) }} warehouse)</option>@endforeach</select>
                                    <select name="direction" class="rounded-lg border-zinc-300 text-xs"><option value="load">Load</option><option value="unload">Unload</option></select>
                                    <input name="quantity" required type="number" min="0.01" step="0.01" class="rounded-lg border-zinc-300 text-xs" placeholder="Qty">
                                    <button class="rounded-lg border border-blue-200 bg-blue-50 px-3 text-xs font-semibold text-blue-950">Move</button>
                                </form>
                            @endif
                            <div class="mt-4 space-y-2">
                                @forelse($vehicle->stocks as $stock)<div class="flex items-center justify-between rounded-xl bg-zinc-50 px-3 py-2 text-sm"><span class="font-medium text-zinc-800">{{ $stock->catalogItem?->name ?? 'Inventory item' }}</span><span class="font-semibold text-zinc-950">{{ number_format((float) $stock->quantity, 2) }} {{ $stock->catalogItem?->unit }}</span></div>@empty<div class="rounded-xl border border-dashed border-zinc-200 px-3 py-4 text-sm text-zinc-500">No stock loaded.</div>@endforelse
                            </div>
                        </article>
                    @empty
                        <div class="rounded-2xl border border-dashed border-zinc-300 p-6 text-sm text-zinc-500 xl:col-span-2">No work vans yet. Create a van above, then load its starting stock.</div>
                    @endforelse
                </div>
            </section>

            <section id="deployments" class="scroll-mt-6 rounded-3xl border border-zinc-200 bg-white p-5 shadow-sm sm:p-7">
                <div><div class="text-xs font-semibold uppercase tracking-[0.18em] text-blue-800">Dispatch</div><h2 class="mt-1 text-2xl font-semibold text-zinc-950">Job deployments</h2><p class="mt-1 text-sm text-zinc-600">Connect the job, van, employees, and the inventory already riding with them.</p></div>

                @if($canManage && $jobs->isNotEmpty() && $vehicles->isNotEmpty() && $team->isNotEmpty())
                    <form method="POST" action="{{ route('field-service.resources.deployments.store') }}" class="mt-5 grid gap-4 rounded-2xl border border-blue-100 bg-blue-50/60 p-4 lg:grid-cols-2">
                        @csrf
                        <label class="text-sm font-semibold text-zinc-800">Job<select name="field_service_job_id" required class="mt-1 block w-full rounded-xl border-zinc-300 bg-white text-sm"><option value="">Choose a job</option>@foreach($jobs as $job)<option value="{{ $job->id }}">{{ $job->title }}</option>@endforeach</select></label>
                        <label class="text-sm font-semibold text-zinc-800">Van<select name="field_service_vehicle_id" required class="mt-1 block w-full rounded-xl border-zinc-300 bg-white text-sm"><option value="">Choose a van</option>@foreach($vehicles as $vehicle)<option value="{{ $vehicle->id }}">{{ $vehicle->name }}</option>@endforeach</select></label>
                        <fieldset class="lg:col-span-2"><legend class="text-sm font-semibold text-zinc-800">Employees riding in this van</legend><div class="mt-2 grid gap-2 sm:grid-cols-2 lg:grid-cols-4">@foreach($team as $member)<label class="flex min-h-11 items-center gap-2 rounded-xl border border-zinc-200 bg-white px-3 text-sm"><input type="checkbox" name="employee_ids[]" value="{{ $member->id }}" class="rounded border-zinc-300 text-blue-700"><span>{{ $member->name ?: $member->email }}</span></label>@endforeach</div></fieldset>
                        <div class="lg:col-span-2"><button class="rounded-xl border border-blue-300 bg-blue-100 px-5 py-2.5 text-sm font-semibold text-blue-950">Assign crew and van</button></div>
                    </form>
                @endif

                <div class="mt-5 space-y-4">
                    @forelse($jobs as $job)
                        <article class="rounded-2xl border border-zinc-200 p-4">
                            <div class="flex flex-wrap items-start justify-between gap-3"><div><h3 class="font-semibold text-zinc-950">{{ $job->title }}</h3><p class="text-sm text-zinc-500">{{ $job->customer_name ?: 'Customer not named' }} · {{ ucfirst(str_replace('_', ' ', $job->operational_status)) }}</p></div><a href="{{ route('field-service.jobs.show', $job) }}" class="rounded-lg border border-zinc-200 px-3 py-2 text-xs font-semibold text-zinc-700">Full job</a></div>
                            <div class="mt-4 grid gap-3 xl:grid-cols-2">
                                @forelse($job->vehicles as $vehicle)
                                    @php($crew = $job->vehicleCrewAssignments->where('field_service_vehicle_id', $vehicle->id)->pluck('user')->filter())
                                    <div class="rounded-xl bg-zinc-50 p-4">
                                        <div class="flex items-center justify-between gap-3"><div class="font-semibold text-zinc-950">{{ $vehicle->name }}</div><div class="text-xs text-zinc-500">{{ $vehicle->identifier }}</div></div>
                                        <div class="mt-2 flex flex-wrap gap-1.5">@forelse($crew as $person)<span class="rounded-full bg-emerald-100 px-2.5 py-1 text-xs font-semibold text-emerald-900">{{ $person->name }}</span>@empty<span class="text-xs text-amber-700">No employees assigned to this van.</span>@endforelse</div>
                                        <div class="mt-3 text-xs font-semibold uppercase tracking-wide text-zinc-500">Onboard inventory</div>
                                        <div class="mt-2 flex flex-wrap gap-1.5">@forelse($vehicle->stocks->where('quantity', '>', 0) as $stock)<span class="rounded-full bg-blue-100 px-2.5 py-1 text-xs font-semibold text-blue-950">{{ $stock->catalogItem?->name }} · {{ number_format((float) $stock->quantity, 2) }}</span>@empty<span class="text-xs text-zinc-500">Van is empty.</span>@endforelse</div>
                                        @if($canManage && $vehicle->stocks->where('quantity', '>', 0)->isNotEmpty())
                                            <form method="POST" action="{{ route('field-service.resources.deployments.use-stock') }}" class="mt-3 grid gap-2 sm:grid-cols-[1fr_6rem_auto]">@csrf<input type="hidden" name="field_service_job_id" value="{{ $job->id }}"><input type="hidden" name="field_service_vehicle_id" value="{{ $vehicle->id }}"><select name="field_material_catalog_item_id" required class="rounded-lg border-zinc-300 text-xs">@foreach($vehicle->stocks->where('quantity', '>', 0) as $stock)<option value="{{ $stock->field_material_catalog_item_id }}">Use {{ $stock->catalogItem?->name }}</option>@endforeach</select><input name="quantity" required type="number" min="0.01" step="0.01" class="rounded-lg border-zinc-300 text-xs" placeholder="Qty"><button class="rounded-lg border border-blue-200 bg-blue-50 px-3 text-xs font-semibold text-blue-950">Use on job</button></form>
                                        @endif
                                    </div>
                                @empty
                                    <div class="rounded-xl border border-dashed border-zinc-200 p-4 text-sm text-zinc-500 xl:col-span-2">No van or crew assigned yet.</div>
                                @endforelse
                            </div>
                        </article>
                    @empty
                        <div class="rounded-2xl border border-dashed border-zinc-300 p-6 text-sm text-zinc-500">Create an active job before deploying a crew and van.</div>
                    @endforelse
                </div>
            </section>

            <section class="rounded-3xl border border-zinc-200 bg-white p-5 shadow-sm sm:p-7">
                <h2 class="text-xl font-semibold text-zinc-950">Recent inventory activity</h2>
                <div class="mt-4 divide-y divide-zinc-200">
                    @forelse($movements as $movement)<div class="grid gap-1 py-3 text-sm sm:grid-cols-[1fr_auto] sm:items-center"><div><span class="font-semibold text-zinc-950">{{ $movement->catalogItem?->name ?? 'Inventory item' }}</span><span class="text-zinc-500"> · {{ ucfirst(str_replace('_', ' ', $movement->movement_type)) }}@if($movement->vehicle) · {{ $movement->vehicle->name }}@endif @if($movement->job) · {{ $movement->job->title }}@endif</span></div><div class="font-semibold {{ (float) $movement->quantity < 0 ? 'text-rose-700' : 'text-zinc-800' }}">{{ (float) $movement->quantity > 0 ? '+' : '' }}{{ number_format((float) $movement->quantity, 2) }}</div></div>@empty<div class="py-6 text-sm text-zinc-500">Inventory movements will appear here.</div>@endforelse
                </div>
            </section>
        </div>
    </flux:main>
</x-layouts::app.sidebar>

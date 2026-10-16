<x-layouts::app.sidebar title="Subscription source comparison">
    <div class="mx-auto w-full max-w-7xl space-y-6 px-4 py-6 sm:px-6">
        <header><a href="{{ $backUrl }}" class="text-sm font-medium text-emerald-900">Back to Recharge preparation</a><p class="mt-4 text-sm text-zinc-600">{{ $tenant->name }}</p><h1 class="mt-1 break-words text-3xl font-semibold tracking-tight">Source {{ ucfirst(str_replace('_', ' ', $resource->resource_type)) }} #{{ $resource->provider_id }}</h1></header>
        <section class="rounded-2xl border border-amber-200 bg-amber-50 p-5 text-sm leading-6 text-amber-950"><h2 class="font-semibold">Private draft comparison — rollout is off</h2><p class="mt-2">These values belong to an isolated source capture and candidate draft. They have not created or changed any live customer, subscription, address or payment method. A field match does not establish full settings parity or transfer card-vault authority.</p></section>
        @if(!$hasCandidate)<p class="rounded-2xl border border-zinc-200 bg-white p-5 text-sm">Local draft preparation has not run for this record.</p>@elseif($issues)<section class="rounded-2xl border border-zinc-200 bg-white p-5"><h2 class="font-semibold">Review needed</h2><ul class="mt-3 space-y-2 text-sm text-zinc-700">@foreach($issues as $issue)<li>{{ ucfirst(str_replace('_', ' ', $issue)) }}</li>@endforeach</ul></section>@endif
        <section class="overflow-hidden rounded-2xl border border-zinc-200 bg-white">
            <div class="border-b border-zinc-200 p-5"><h2 class="text-lg font-semibold">Source fields and proposed draft values</h2><p class="mt-2 text-sm text-zinc-600">Missing values and explicit nulls stay distinct. Money, source dates and offsets are preserved without rounding or timezone conversion.</p></div>
            <div class="overflow-x-auto"><table class="w-full min-w-[650px] table-fixed text-left text-sm"><thead class="bg-zinc-50 text-zinc-600"><tr><th class="w-1/4 px-5 py-3 font-medium">Field</th><th class="px-5 py-3 font-medium">Recharge source</th><th class="px-5 py-3 font-medium">Everbranch candidate</th><th class="w-28 px-5 py-3 font-medium">Comparison</th></tr></thead>
                <tbody class="divide-y divide-zinc-100">@foreach($source as $key=>$value)
                    @php($sourceValue = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))
                    @php($present = array_key_exists($key, $candidate))
                    @php($targetValue = $present ? json_encode($candidate[$key], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null)
                    <tr><th class="break-words px-5 py-4 align-top font-medium">{{ $key }}</th><td class="break-words px-5 py-4 align-top">{{ $sourceValue }}</td><td class="break-words px-5 py-4 align-top">{{ $present ? $targetValue : 'Not prepared' }}</td><td class="px-5 py-4 align-top">{{ $present ? ($sourceValue === $targetValue ? 'Same value' : 'Different') : 'Pending' }}</td></tr>
                @endforeach</tbody>
            </table></div>
        </section>
    </div>
</x-layouts::app.sidebar>

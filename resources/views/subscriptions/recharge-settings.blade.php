<x-layouts::app.sidebar title="Recharge settings comparison">
    <div class="mx-auto w-full max-w-7xl space-y-6 px-4 py-6 sm:px-6">
        <header>
            <a href="{{ $backUrl }}" class="text-sm font-medium text-emerald-900">Back to Recharge preparation</a>
            <p class="mt-4 text-sm text-zinc-600">{{ $tenant->name }}</p>
            <h1 class="mt-1 break-words text-3xl font-semibold tracking-tight">{{ ucfirst(str_replace('_', ' ', $record['category'])) }} settings comparison</h1>
        </header>
        <section class="rounded-2xl border border-amber-200 bg-amber-50 p-5 text-sm leading-6 text-amber-950">
            <h2 class="font-semibold">Saved settings draft — rollout is off</h2>
            <p class="mt-2">These manually observed values are retained for comparison. They have not changed Recharge or live Everbranch configuration. Matching fields do not establish complete coverage or matching runtime behavior.</p>
        </section>
        <section class="rounded-2xl border border-zinc-200 bg-white p-5 text-sm text-zinc-700">
            <h2 class="font-semibold text-zinc-950">Source observation</h2>
            <p class="mt-2 break-words">{{ $record['source_url'] }}</p>
            <p class="mt-2 break-words">Observed {{ $record['observed_at'] }} · Version {{ $record['version_id'] }}</p>
            <p class="mt-2">Merchant-reviewed page attribution; full source coverage remains unverified.</p>
            @if($record['missing_fields'])<p class="mt-3 text-amber-900">Missing required fields: {{ implode(', ', $record['missing_fields']) }}</p>@endif
            @if($record['null_fields'])<p class="mt-3 text-amber-900">Explicit nulls retained for review: {{ implode(', ', $record['null_fields']) }}</p>@endif
        </section>
        <section class="overflow-hidden rounded-2xl border border-zinc-200 bg-white">
            <div class="border-b border-zinc-200 p-5"><h2 class="text-lg font-semibold">Source settings and proposed draft values</h2></div>
            <div class="overflow-x-auto"><table class="w-full min-w-[650px] table-fixed text-left text-sm">
                <thead class="bg-zinc-50 text-zinc-600"><tr><th class="w-1/4 px-5 py-3 font-medium">Field</th><th class="px-5 py-3 font-medium">Recharge observation</th><th class="px-5 py-3 font-medium">Everbranch draft</th><th class="w-28 px-5 py-3 font-medium">Comparison</th></tr></thead>
                <tbody class="divide-y divide-zinc-100">@foreach($record['source_fields'] as $key => $value)
                    @php($sourceValue = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))
                    @php($present = array_key_exists($key, $record['draft_fields']))
                    @php($draftValue = $present ? json_encode($record['draft_fields'][$key], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null)
                    <tr><th class="break-words px-5 py-4 align-top font-medium">{{ $key }}</th><td class="break-words px-5 py-4 align-top">{{ $sourceValue }}</td><td class="break-words px-5 py-4 align-top">{{ $present ? $draftValue : 'Not prepared' }}</td><td class="px-5 py-4 align-top">{{ $present && $sourceValue === $draftValue ? 'Same value' : 'Needs review' }}</td></tr>
                @endforeach</tbody>
            </table></div>
        </section>
    </div>
</x-layouts::app.sidebar>

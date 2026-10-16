<details class="rounded-xl border border-zinc-200 p-4">
    <summary class="cursor-pointer font-semibold">{{ ($current ?? false) ? 'Context used' : 'Context used for this answer' }}</summary>
    <div class="mt-3 space-y-3 break-words text-sm">
        <p>{{ $context['workspace']['name'] }} · {{ ucfirst($context['workspace']['role']) }} · {{ Illuminate\Support\Str::headline($context['workspace']['business_template']) }} · {{ Illuminate\Support\Str::headline($context['workspace']['operating_mode']) }}</p>
        <p>Checked {{ \Carbon\CarbonImmutable::parse($context['generated_at'])->setTimezone($context['workspace']['timezone'])->format('M j, Y g:i a') }} ({{ $context['workspace']['timezone'] }}).</p>
        <p>Opened from: {{ $context['page']['title'] }}.</p>
        <p>Enabled Branches: {{ implode(', ', array_column($context['workspace']['branches'], 'name')) ?: 'None' }}.</p>
        <p>Permitted capabilities: {{ implode(', ', array_map(fn ($capability) => Illuminate\Support\Str::headline(str_replace('.', ' / ', $capability)), $context['workspace']['capabilities'])) ?: 'None' }}.</p>
        @foreach($context['connections'] as $connection)
            <p>{{ $connection['title'] }}: {{ $connection['status'] }}. Last successful sync: {{ $connection['last_synced_at'] ? \Carbon\CarbonImmutable::parse($connection['last_synced_at'])->setTimezone($context['workspace']['timezone'])->format('M j, Y g:i a') : 'Not recorded' }} · {{ $connection['freshness'] }}.</p>
        @endforeach
        <p class="text-zinc-600">Bounded samples: at most five records per source and twenty overall. This is not a complete workspace report. No external calendar, financial records, or Website customer/order data is checked.</p>
        <ul class="space-y-1 text-xs text-zinc-600">
            @foreach($context['coverage'] as $family => $coverage)
                <li>{{ ucfirst(str_replace('_', ' ', $family)) }}: {{ match($coverage['status']) { 'empty' => 'Nothing found in the checked sources', 'restricted' => 'Unavailable with current permissions or Branch access', 'missing' => 'Source unavailable', 'unsupported' => 'Not supported in this phase', 'stale' => 'Sync information stale or freshness unknown', default => 'Sample checked' } }} · {{ $coverage['returned'] }} records{{ ($coverage['truncated'] ?? false) ? ' · Sample limit reached; additional records may exist' : '' }}.</li>
            @endforeach
        </ul>
    </div>
</details>

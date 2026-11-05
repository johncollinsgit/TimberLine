@if(isset($reportingStatus))
    @if(($reportingStatus['legacy_unverified_count'] ?? 0) > 0)
        <div class="mb-5 rounded-2xl border border-amber-300 bg-amber-50 px-5 py-4 text-sm text-amber-900" role="status">
            <strong>Legacy history needs review.</strong> {{ number_format($reportingStatus['legacy_unverified_count']) }} records from other stores need duplicate and payment review. They are excluded from verified store figures, and new recommendations are paused.
        </div>
    @elseif(! $reportingStatus['complete'] || $reportingStatus['stale'])
        <div class="mb-5 rounded-2xl border border-amber-300 bg-amber-50 px-5 py-4 text-sm text-amber-900" role="status">
            <strong>{{ $reportingStatus['complete'] ? 'Wholesale history is out of date.' : 'Wholesale history is incomplete.' }}</strong>
            {{ $reportingStatus['complete'] ? 'These figures use the last verified history. New recommendations wait for a successful refresh.' : 'These figures cover imported records only. Full-history reporting must refresh before recommendations can be generated.' }}
        </div>
    @else
        <p class="mb-4 text-xs text-zinc-500">Verified against {{ number_format($reportingStatus['source_order_count']) }} Shopify source orders · {{ \Carbon\CarbonImmutable::parse($reportingStatus['refreshed_at'])->timezone('America/New_York')->format('M j, g:i a T') }} · {{ $reportingStatus['currency'] }}</p>
    @endif
@endif

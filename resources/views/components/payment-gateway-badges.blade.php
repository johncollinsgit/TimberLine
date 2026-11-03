@props(['gateways' => []])
<span class="inline-flex flex-wrap items-center gap-2" aria-label="Payment gateway">
    @forelse($gateways as $gateway)
        <span class="inline-flex items-center gap-1.5 rounded-md border border-zinc-200 bg-white px-2 py-1 text-xs font-medium text-zinc-700" title="{{ $gateway['label'] }}">
            @if(in_array($gateway['id'] ?? '', ['stripe', 'braintree', 'paypal', 'shopify', 'square'], true))
                <img src="{{ asset('brand/payments/'.$gateway['id'].'.svg') }}" alt="" aria-hidden="true" width="16" height="16" class="h-4 w-4 shrink-0" loading="lazy">
            @else
                <svg aria-hidden="true" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" class="h-4 w-4 shrink-0 {{ ($gateway['id'] ?? '') === 'shop-pay' ? 'text-violet-700' : '' }}"><rect x="2.5" y="5" width="19" height="14" rx="3"/><path d="M3 10h18M6 15h4"/></svg>
            @endif
            <span>{{ $gateway['label'] }}</span>
        </span>
    @empty
        <span class="text-xs text-zinc-500">Not recorded</span>
    @endforelse
</span>

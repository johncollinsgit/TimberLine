@php($shippingUpdates = app(\App\Services\ManagedWebsite\WebsiteShipmentPresenter::class)->forOrder($order))
@if($shippingUpdates->isNotEmpty())
<section style="margin-top:32px" aria-label="Shipping updates">
    <h2 style="font-size:24px;margin-bottom:16px">Shipping updates</h2>
    @foreach($shippingUpdates as $update)
        <article style="border-top:1px solid #d9ded6;padding:16px 0">
            <p><strong>{{ $update['status'] }}</strong>@if($update['test']) <span>· Test shipment</span>@endif</p>
            @if($update['tracking_number'])<p style="overflow-wrap:anywhere;margin-top:8px">{{ $update['carrier'] }} · {{ $update['tracking_number'] }}</p>@endif
            @if($update['tracking_url'])<a href="{{ $update['tracking_url'] }}" target="_blank" rel="noopener noreferrer" style="display:inline-block;text-decoration:underline;margin-top:8px">Track with carrier ↗</a>@endif
        </article>
    @endforeach
</section>
@elseif($order->fulfillment_method === 'ship')
<p style="margin-top:24px">Shipping updates will appear here when the store prepares your package.</p>
@endif

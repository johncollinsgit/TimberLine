<section class="border border-zinc-200 bg-white p-4">
    <h2 class="text-sm font-semibold text-zinc-950">Fulfillment</h2>
    <p class="mt-3 text-sm text-zinc-700">{{ str($order->fulfillment_method)->headline() }}</p>
    @if($fulfillmentAddress)<p class="mt-2 text-sm leading-5 text-zinc-600">{{ data_get($fulfillmentAddress, 'name') }}<br>{{ data_get($fulfillmentAddress, 'street1') }}@if(data_get($fulfillmentAddress, 'street2'))<br>{{ data_get($fulfillmentAddress, 'street2') }}@endif<br>{{ data_get($fulfillmentAddress, 'city') }}, {{ data_get($fulfillmentAddress, 'state') }} {{ data_get($fulfillmentAddress, 'zip') }}</p>@endif
    @if($canManageShipping ?? false)<a class="mt-3 inline-block text-sm font-semibold text-emerald-800 underline" href="{{ route('managed-website.printing.settings') }}">Printer setup and test page</a>@endif
    @foreach($order->shipments as $shipment)
        @php($native = data_get($shipment->parcel, '_native', []))
        @php($tracking = app(\App\Services\ManagedWebsite\WebsiteShipmentPresenter::class)->present($shipment))
        <article class="mt-4 space-y-3 border-t border-zinc-200 pt-4 text-sm" data-shipment="{{ $shipment->id }}">
            <p class="font-semibold">{{ $shipment->carrier ?: 'Carrier' }} · {{ str($shipment->status)->headline() }}</p>
            @if($tracking['test'])<p class="font-semibold text-amber-800">Test shipment</p>@endif
            @if($shipment->tracking_number)<p class="break-all">{{ $shipment->tracking_number }}</p>@endif
            @if($tracking['tracking_url'])<a class="inline-block font-semibold text-emerald-800 underline" href="{{ $tracking['tracking_url'] }}" target="_blank" rel="noopener noreferrer">Carrier tracking</a>@endif
            @if(isset($native['version']))
                <p>Postage: {{ str($native['buy_state'] ?? 'review')->headline() }}@if($shipment->label_cost_cents !== null) · ${{ number_format($shipment->label_cost_cents / 100, 2) }}@endif</p>
                @if($native['refund_state'] ?? null)<p class="text-amber-900">Unused-label refund: {{ str($native['refund_state'])->headline() }}. Retired labels must not be printed or used.</p>@endif
                @if($native['handed_over_at'] ?? $native['carrier_accepted_at'] ?? null)<p>Carrier handoff recorded.</p>@elseif($native['packed_at'] ?? null)<p>Packed. Carrier handoff is pending.</p>@endif
                @if($canManageShipping ?? false)
                    <form method="POST" action="{{ route('managed-website.shipments.check', $shipment) }}">@csrf<button class="fb-btn fb-btn-secondary" type="submit">Check shipment</button></form>
                    @if(($native['buy_state'] ?? '') === 'prepared' && ($shippingEnabled ?? false))<form method="POST" action="{{ route('managed-website.shipments.resume', $shipment) }}">@csrf<button class="fb-btn fb-btn-secondary" type="submit">Resume approved postage (${{ number_format(($native['approved_cost_cents'] ?? 0) / 100, 2) }})</button></form>@endif
                    @if(($native['buy_state'] ?? '') === 'purchased' && !($native['retired'] ?? false))
                        <a class="fb-btn fb-btn-secondary" href="{{ route('managed-website.labels.show', $shipment) }}">Print or download label</a>
                        @if(!($native['handed_over_at'] ?? $native['carrier_accepted_at'] ?? null))
                            @if(!($native['packed_at'] ?? null))<form method="POST" action="{{ route('managed-website.shipments.pack', $shipment) }}">@csrf<button class="fb-btn fb-btn-secondary" type="submit">Mark packed</button></form>
                            @else<form class="space-y-2" method="POST" action="{{ route('managed-website.shipments.handoff', $shipment) }}">@csrf<label class="flex items-start gap-2"><input required type="checkbox" name="confirm_handoff" value="1"><span>I handed this package to the carrier.</span></label><button class="fb-btn fb-btn-secondary" type="submit">Record carrier handoff</button></form>@endif
                            <form class="space-y-2 border-t border-zinc-100 pt-3" method="POST" action="{{ route('managed-website.shipments.void', $shipment) }}">@csrf<label class="flex items-start gap-2"><input required type="checkbox" name="confirm_unused" value="1"><span>This label is unused and will not be used.</span></label><button class="text-sm font-semibold text-rose-800 underline" type="submit">Request unused-label refund</button></form>
                        @endif
                    @endif
                    @if(($native['retired'] ?? false) && !($native['replacement_id'] ?? null) && !($native['handed_over_at'] ?? $native['carrier_accepted_at'] ?? null) && ($shippingEnabled ?? false))<form class="space-y-2" method="POST" action="{{ route('managed-website.shipments.replacement', $shipment) }}">@csrf<label class="flex items-start gap-2"><input required type="checkbox" name="confirm_retired_label" value="1"><span>The original label remains unused. Prepare separate replacement postage rates.</span></label><button class="fb-btn fb-btn-secondary" type="submit">Prepare replacement rates</button></form>@endif
                    @if(($native['buy_state'] ?? '') === 'approval_required' && ($shippingEnabled ?? false))
                        <p>Replacement postage is a separate charge. The original refund may still be pending.</p>
                        @foreach(($native['replacement_rates'] ?? []) as $rate)<form class="space-y-2 rounded-lg border p-3" method="POST" action="{{ route('managed-website.shipments.replacement.approve', $shipment) }}">@csrf<input type="hidden" name="rate_id" value="{{ $rate['id'] }}"><input type="hidden" name="cost_cents" value="{{ $rate['amount_cents'] }}"><label class="flex items-start gap-2"><input required type="checkbox" name="confirm_cost" value="1"><span>Approve {{ $rate['service'] }} postage: ${{ number_format($rate['amount_cents'] / 100, 2) }}</span></label><button class="fb-btn fb-btn-primary" type="submit">Buy replacement label</button></form>@endforeach
                    @endif
                @endif
            @else<p class="text-zinc-600">{{ $shipment->provider === 'pirate_ship' ? 'Imported carrier tracking.' : 'This shipment needs provider review before native label actions.' }}</p>@endif
        </article>
    @endforeach
    @if(($canManageShipping ?? false) && ($shippingEnabled ?? false) && $order->fulfillment_method === 'ship' && $order->payment_status === 'paid' && $order->shipments->isEmpty() && data_get($order->shipping_rate_snapshot, 'quote_id'))<form class="mt-4" method="POST" action="{{ route('managed-website.orders.labels.purchase', $order) }}">@csrf<input type="hidden" name="shipping_rate_quote_id" value="{{ data_get($order->shipping_rate_snapshot, 'quote_id') }}"><p class="mb-2 text-sm">Approved postage: ${{ number_format(data_get($order->shipping_rate_snapshot, 'amount_cents', 0) / 100, 2) }}. Packing and carrier handoff remain separate.</p><button class="fb-btn fb-btn-primary" type="submit">Purchase label</button></form>@endif
</section>

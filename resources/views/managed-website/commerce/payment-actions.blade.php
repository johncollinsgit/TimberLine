@if($canManageOrders || ($canServicePayments ?? false))
<section class="border border-zinc-200 bg-white p-4">
    <h2 class="text-sm font-semibold text-zinc-950">Staff actions</h2>
    @if(in_array($order->payment_status, ['paid_review', 'payment_review']))<p class="mt-2 text-sm text-amber-800">Payment needs review. Fulfillment is blocked.</p>@endif
    @if(!$canManageOrders)<p class="mt-2 text-sm text-amber-800">New checkout is paused. Existing payments can still be cancelled, checked, or refunded.</p>@endif
    <p class="mt-2 text-xs text-zinc-600">Confirmed refunds appear in the timeline. Unconfirmed refunds reserve their balance. Refunds do not restock inventory.</p>
    <div class="mt-3 grid gap-3">
        @foreach(($pendingRefunds ?? collect()) as $operation)
            <div class="border border-amber-200 bg-amber-50 p-3 text-sm" data-refund-request="{{ $operation['request_id'] }}">
                <p>${{ number_format($operation['amount_cents'] / 100, 2) }} refund · {{ str($operation['status'])->headline() }}</p>
                @if(isset($operation['provider_id']))
                    <form method="POST" action="{{ route('managed-website.orders.refunds.check', $order) }}" class="mt-2">@csrf
                        <button class="fb-btn fb-btn-secondary" type="submit">Check refund</button>
                    </form>
                @elseif($operation['can_retry'])
                    <form method="POST" action="{{ route('managed-website.orders.refund', $order) }}" class="mt-2">@csrf
                        <input type="hidden" name="request_id" value="{{ $operation['request_id'] }}">
                        <input type="hidden" name="amount" value="{{ number_format($operation['amount_cents'] / 100, 2, '.', '') }}">
                        <button class="fb-btn fb-btn-secondary" type="submit">Retry unconfirmed refund</button>
                    </form>
                @else
                    <p class="mt-2 text-amber-900">This refund needs provider review before another attempt. Its balance remains reserved.</p>
                @endif
            </div>
        @endforeach
        @if($canManageOrders && $order->payment_status === 'paid' && $order->fulfillment_status !== 'fulfilled' && $order->fulfillment_method !== 'ship' && ($pendingRefunds ?? collect())->isEmpty())
            <form method="POST" action="{{ route('managed-website.orders.fulfill', $order) }}">@csrf<button class="fb-btn fb-btn-secondary w-full justify-center" type="submit">Mark fulfilled</button></form>
        @endif
        @if(($refundableCents ?? 0) > 0 && in_array($order->payment_status, ['paid', 'partially_refunded', 'paid_review']))
            <form method="POST" action="{{ route('managed-website.orders.refund', $order) }}" class="flex gap-2">@csrf
                <input type="hidden" name="request_id" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
                <input class="min-w-0 rounded-md border-zinc-300 text-sm" name="amount" type="number" min="0.01" max="{{ $refundableCents / 100 }}" step=".01" value="{{ number_format($refundableCents / 100, 2, '.', '') }}" aria-label="Refund amount">
                <button class="fb-btn fb-btn-secondary" type="submit">Refund</button>
            </form>
        @elseif($order->cancelled_at === null && in_array($order->payment_status, ['pending', 'expired']))
            <form method="POST" action="{{ route('managed-website.orders.cancel', $order) }}">@csrf<button class="text-sm font-medium text-rose-700 hover:underline" type="submit">Cancel order</button></form>
        @endif
    </div>
    <form method="POST" action="{{ route('managed-website.orders.notes.store', $order) }}" class="mt-4 border-t border-zinc-200 pt-4">@csrf
        <label class="text-sm font-medium text-zinc-800">Internal note<textarea required name="note" rows="3" class="mt-1 block w-full rounded-md border-zinc-300 text-sm" placeholder="Visible to staff only"></textarea></label>
        <button class="mt-2 text-sm font-medium text-emerald-800 hover:underline" type="submit">Add note</button>
    </form>
</section>
@endif

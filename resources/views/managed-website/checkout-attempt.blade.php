@if($checkoutAttempt ?? null)
<div class="store-notice sn-alert" role="status" style="max-width:1120px;margin:20px auto;padding:18px">
    @if(in_array($checkoutAttempt->status, ['paid_review', 'payment_review']))
        <p>This payment needs staff review. Contact the store before paying again.</p>
    @else
        <p>A secure checkout is open for this bag. Resume it, or cancel it before changing items or delivery details.</p>
        <form method="POST" action="{{ route('managed-website.store.checkout.resume') }}" style="display:inline-block">@csrf<button class="store-button sn-btn" type="submit">Resume secure checkout</button></form>
        <form method="POST" action="{{ route('managed-website.store.checkout.cancel') }}" style="display:inline-block;margin:8px 0 0 12px">@csrf<button class="store-button sn-btn sn-btn-light" style="background:transparent;color:inherit;border:1px solid currentColor" type="submit">Cancel open checkout</button></form>
    @endif
</div>
@endif

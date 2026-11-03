@if($shopPayReady ?? false)
<label style="display:block;margin:18px 0">Payment method<select name="payment_provider" style="display:block;width:100%;padding:12px;margin-top:6px"><option value="stripe">Cards, Apple Pay or Google Pay</option><option value="shop_pay">Shop Pay · shipping or pickup</option></select></label>
@endif

@if($storefront['can_cart'])<a href="{{ route('managed-website.store.cart') }}">Cart</a>@endif
@if($storefront['can_account'])<a href="{{ route('managed-website.store.account') }}">Account</a>@endif

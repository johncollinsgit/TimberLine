@if($quoteRequestAllowed ?? false)
@if(session('quote_status'))<p class="store-notice">{{ session('quote_status') }}</p>@endif
<form class="store-checkout" method="POST" action="{{ route('managed-website.store.products.quote', ['handle' => $product->handle]) }}">
    @csrf
    <input type="text" name="website" tabindex="-1" autocomplete="off" hidden>
    <label>Name<input required name="name" maxlength="190"></label>
    <label>Email<input required type="email" name="email" maxlength="190"></label>
    <label>Phone<input required name="phone" maxlength="80"></label>
    <label>Service address<input required name="service_address" maxlength="500"></label>
    <label>Service needed<input required name="service_needed" value="{{ $product->title }}" maxlength="500"></label>
    <label>Message<textarea required name="message" maxlength="4000"></textarea></label>
    <button class="store-button" type="submit">Request a quote</button>
</form>
@else
    <p class="retail-shopping-note">To request a quote, @if($contactUrl ?? null)<a href="{{ $contactUrl }}">contact us.</a>@else contact the business directly.@endif</p>
@endif

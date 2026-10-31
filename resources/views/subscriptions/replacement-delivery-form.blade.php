<section class="paper-card delivery-card" aria-labelledby="delivery-title">
<p class="eyebrow">MAKE IT YOURS</p><h2 id="delivery-title">Your next delivery</h2>
<p>Update your delivery address or choose an offered club scent. Review the total before you save.</p>
<details class="terms-details" @if(old('shipping_address')) open @endif><summary>Change delivery{{ $deliveryOptions['scents'] ? ' or scent' : ' address' }}</summary>
<form method="POST" action="{{ route('subscriptions.replacement.customer.delivery.review', ['token' => $token]) }}" class="delivery-form">
@csrf
<input type="hidden" name="request_id" value="{{ $deliveryRequestId }}"><input type="hidden" name="revision" value="{{ $agreement->revision }}">
<fieldset><legend>Delivery address</legend><div class="delivery-grid">
@foreach(['firstName' => ['First name', 'given-name'], 'lastName' => ['Last name', 'family-name'], 'company' => ['Company (optional)', 'organization'], 'address1' => ['Street address', 'address-line1'], 'address2' => ['Apartment or suite (optional)', 'address-line2'], 'city' => ['City', 'address-level2']] as $field => [$label, $autocomplete])
<div class="delivery-field {{ in_array($field, ['company','address1','address2']) ? 'delivery-field-wide' : '' }}"><label for="delivery-{{ $field }}">{{ $label }}</label><input id="delivery-{{ $field }}" name="shipping_address[{{ $field }}]" value="{{ old('shipping_address.'.$field, $terms['shipping_address'][$field] ?? '') }}" autocomplete="{{ $autocomplete }}" @if(!in_array($field, ['company','address2'])) required @endif maxlength="{{ in_array($field, ['firstName','lastName','city']) ? 100 : 255 }}"></div>
@endforeach
<div class="delivery-field"><label for="delivery-province">State</label><select id="delivery-province" name="shipping_address[provinceCode]" autocomplete="address-level1" required>
@php($selectedProvince = old('shipping_address.provinceCode', $terms['shipping_address']['provinceCode']))
@if(!in_array($selectedProvince, $deliveryOptions['provinces'], true))<option value="" selected disabled>Choose a delivery state</option>@endif
@foreach($deliveryOptions['provinces'] as $province)<option value="{{ $province }}" @selected($province === $selectedProvince)>{{ $province }}</option>@endforeach
</select></div>
<div class="delivery-field"><label for="delivery-zip">ZIP code</label><input id="delivery-zip" name="shipping_address[zip]" value="{{ old('shipping_address.zip', $terms['shipping_address']['zip']) }}" autocomplete="postal-code" required maxlength="10"></div>
<div class="delivery-field"><label for="delivery-country">Country</label><input id="delivery-country" value="United States" readonly autocomplete="country-name"><input type="hidden" name="shipping_address[countryCode]" value="US"></div>
<div class="delivery-field delivery-field-wide"><label for="delivery-phone">Phone (optional)</label><input id="delivery-phone" name="shipping_address[phone]" type="tel" value="{{ old('shipping_address.phone', $terms['shipping_address']['phone'] ?? '') }}" autocomplete="tel" maxlength="30"></div>
</div></fieldset>
@if($deliveryOptions['scents'])<fieldset><legend>Club scents</legend><p class="fine-print">Your membership price stays the same. We’ll check scent availability when you review.</p>
@foreach($terms['lines'] as $index => $line)<div class="delivery-field"><label for="delivery-scent-{{ $index }}">Scent for {{ $line['quantity'] }} {{ $line['quantity'] === 1 ? 'candle' : 'candles' }}</label>
<input type="hidden" name="swaps[{{ $index }}][subscription_id]" value="{{ $line['subscription_id'] }}">
<select id="delivery-scent-{{ $index }}" name="swaps[{{ $index }}][variant_id]">
<option value="{{ $line['variant_id'] }}">Keep {{ $line['label'] }}</option>
@foreach($deliveryOptions['scents'] as $scent)@if($scent['variant_id'] !== $line['variant_id'])<option value="{{ $scent['variant_id'] }}" @selected(old('swaps.'.$index.'.variant_id') === $scent['variant_id'])>{{ $scent['label'] }}</option>@endif@endforeach
</select></div>@endforeach</fieldset>@endif
<p class="fine-print">Changes apply to a future cycle. For an order already placed or a destination outside these options, contact our team.</p>
<div class="button-row"><button class="club-button primary-button">Review future delivery</button></div>
</form></details></section>

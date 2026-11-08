@if($canManageShipping)
<div class="mt-2 flex flex-wrap items-center gap-3">
    <a class="font-semibold text-emerald-800 underline" href="{{ route('managed-website.commerce.shipping', [$kind === 'packages' ? 'package' : 'location' => $preset->id]) }}#{{ $kind === 'packages' ? 'shipping-packages' : 'shipping-origin' }}">Edit</a>
    <form method="POST" action="{{ route('managed-website.commerce.shipping.presets.status', ['kind' => $kind, 'preset' => $preset->id]) }}">@csrf
        <input type="hidden" name="active" value="{{ $preset->active ? '0' : '1' }}">
        <button class="font-semibold underline" type="submit">{{ $preset->active ? 'Deactivate' : 'Restore' }}</button>
    </form>
</div>
@endif

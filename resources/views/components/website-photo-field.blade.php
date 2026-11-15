@props(['value' => [], 'multiple' => false, 'name' => 'image_url', 'altName' => 'image_alt', 'label' => 'Image', 'photoLibrary' => [], 'relatedPhotos' => [], 'connectedOrigin' => ''])
<div data-website-photo-field
     data-value='@json($value)'
     data-multiple="{{ $multiple ? 'true' : 'false' }}"
     data-name="{{ $name }}"
     data-alt-name="{{ $altName }}"
     data-label="{{ $label }}"
     data-connected-origin="{{ $connectedOrigin }}"
     data-photos='@json($photoLibrary)'
     data-related-photos='@json($relatedPhotos)'
     data-max-bytes="{{ config('managed_website.media_max_bytes', 10485760) }}"
     data-upload-url="{{ route('managed-website.media.store') }}">
    @foreach($value as $photo)
        @if(filled(data_get($photo, 'url')))
            <input type="hidden" name="{{ $name }}" value="{{ $photo['url'] }}">
            <img src="{{ $photo['url'] }}" alt="{{ $photo['alt'] ?? '' }}" class="mb-3 max-h-40 rounded-xl object-contain">
        @endif
    @endforeach
    @unless($connectedOrigin)<input type="hidden" name="{{ $altName }}" value="{{ data_get($value, '0.alt', '') }}">@endunless
    <p class="text-sm text-zinc-600">Loading photo controls…</p>
    <noscript><p class="text-sm">Enable JavaScript to choose from your photos.</p>@if($multiple)<input type="file" name="images[]" multiple accept="image/jpeg,image/png,image/webp,image/avif">@endif</noscript>
</div>

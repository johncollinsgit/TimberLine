@if(! empty($retailCards['collections']))
<section aria-label="Browse collections" class="store-grid">
    @foreach($retailCards['collections'] as $card)
        <article class="store-card">
            @if($card['image'])<div class="store-card__image"><img src="{{ $card['image'] }}" alt="{{ $card['alt'] }}" width="720" height="560" loading="lazy" decoding="async"></div>@endif
            @if(($storePreview ?? false) && $card['status'] !== 'active')<p>Draft collection</p>@endif
            <h3>{{ $card['title'] }}</h3>
            @if($card['url'])<a href="{{ $card['url'] }}">View collection</a>@endif
        </article>
    @endforeach
</section>
@endif

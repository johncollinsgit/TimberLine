@php
    $content = is_array($contact ?? null) ? $contact : [];
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    @include('partials.head', ['title' => 'Contact Forestry Backstage'])
</head>
<body class="fb-public-body" data-premium-motion="public">
    @include('platform.partials.premium-motion')

    <main class="fb-public-shell fb-contact-shell">
        <a href="{{ route('platform.promo') }}" class="fb-btn fb-btn-secondary fb-contact-back">Back to homepage</a>

        <section class="fb-card fb-contact-overview" aria-label="Contact overview" data-reveal data-premium-surface>
            <p class="fb-section-kicker">Contact</p>
            <h1 class="fb-contact-title">{{ $content['headline'] ?? 'Contact Forestry Backstage' }}</h1>
            <p class="fb-contact-summary">{{ $content['summary'] ?? 'Book a demo, ask about plans, or get rollout guidance for your team.' }}</p>
        </section>

        <section class="fb-section fb-contact-grid-section" aria-label="Contact channels" data-reveal>
            <div class="fb-grid fb-grid-2">
                @foreach((array) ($content['channels'] ?? []) as $channel)
                    <article class="fb-card fb-contact-item" data-premium-surface>
                        <h2 class="fb-contact-item-title">{{ $channel['label'] ?? 'Contact' }}</h2>
                        @if(filled($channel['href'] ?? null))
                            <a href="{{ $channel['href'] }}" class="fb-contact-link">{{ $channel['value'] ?? 'Open' }}</a>
                        @else
                            <p class="fb-contact-value">{{ $channel['value'] ?? '' }}</p>
                        @endif
                    </article>
                @endforeach
            </div>
        </section>
    </main>
</body>
</html>

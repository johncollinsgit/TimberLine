@php
    $content = is_array($contact ?? null) ? $contact : [];
    $brandAssets = (array) config('everbranch.brand_assets', []);
    $brandAssetVersion = (string) ($brandAssets['cache_tag'] ?? 'eb1');
    $brandLockupPath = (string) ($brandAssets['lockup'] ?? 'brand/everbranch-lockup.svg');
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    @include('partials.head', ['title' => 'Contact '.config('everbranch.product_name', 'Everbranch')])
</head>
<body class="fb-public-body" data-premium-motion="public">
    @include('platform.partials.premium-motion')

    <main class="fb-public-shell fb-contact-shell">
        <nav class="fb-site-nav fb-site-nav--premium" aria-label="Everbranch contact navigation">
            <a href="{{ route('platform.promo') }}" class="fb-site-brand fb-site-brand--lockup">
                <img src="{{ asset($brandLockupPath) }}?v={{ $brandAssetVersion }}" alt="{{ config('everbranch.product_name', 'Everbranch') }}" />
            </a>
            <div class="fb-hero-cta fb-hero-cta--nav">
                <a href="{{ route('platform.promo') }}" class="fb-btn fb-btn-secondary">Home</a>
                <a href="{{ route('login') }}" class="fb-btn fb-btn-secondary">Login</a>
            </div>
        </nav>

        <section class="fb-contact-panel fb-contact-panel--page" aria-label="Contact Everbranch" data-reveal data-premium-surface>
            <div>
                <p class="fb-section-kicker">Contact</p>
                <h1 class="fb-contact-title">Tell Everbranch what keeps getting lost.</h1>
                <p class="fb-contact-summary">Send the messy version. We will help you find the clean starting point for your business.</p>
            </div>

            @include('platform.partials.contact-form', ['sourcePage' => 'everbranch_contact'])
        </section>
    </main>
</body>
</html>

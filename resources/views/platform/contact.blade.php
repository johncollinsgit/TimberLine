@php
    $content = is_array($contact ?? null) ? $contact : [];
    $intentValue = in_array(($intent ?? ''), ['contact', 'sales', 'walkthrough'], true) ? (string) $intent : 'contact';
    $isWalkthrough = $intentValue === 'walkthrough';
    $brandAssets = (array) config('everbranch.brand_assets', []);
    $brandAssetVersion = (string) ($brandAssets['cache_tag'] ?? 'eb1');
    $brandLockupPath = (string) ($brandAssets['lockup'] ?? 'brand/everbranch-lockup.svg');
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    @include('partials.head', ['title' => 'Contact '.config('everbranch.product_name', 'Everbranch')])
</head>
<body class="fb-public-body eb-studio-support-body" data-premium-motion="public">
    @include('platform.partials.studio-support-nav')

    <main class="fb-public-shell fb-contact-shell eb-studio-support-shell">


        <section class="fb-contact-panel fb-contact-panel--page" aria-label="Contact Everbranch" data-reveal data-premium-surface>
            <div>
                <p class="fb-section-kicker">Contact</p>
                <h1 class="fb-contact-title">{{ $isWalkthrough ? 'Let’s take a look.' : 'Let’s talk.' }}</h1>
                <p class="fb-contact-summary">
                    {{ $isWalkthrough
                        ? 'Choose what you would like to explore. We will arrange a walkthrough.'
                        : 'Tell us what you need. We will help with the next step.' }}
                </p>
            </div>

            @include('platform.partials.contact-form', [
                'sourcePage' => $isWalkthrough ? 'everbranch_walkthrough' : 'everbranch_contact',
                'submitLabel' => $isWalkthrough ? 'Request a meeting' : 'Send message',
            ])
        </section>
    </main>
</body>
</html>

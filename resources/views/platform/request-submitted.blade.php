@php
    $intentValue = in_array(($intent ?? ''), ['demo', 'production'], true) ? (string) $intent : 'production';
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    @include('partials.head', ['title' => 'Request received'])
</head>
<body class="fb-public-body" data-premium-motion="public">
    @include('platform.partials.premium-motion')

    <main class="fb-public-shell fb-contact-shell">
        <a href="{{ route('platform.promo') }}" class="fb-btn fb-btn-secondary fb-contact-back">Back to homepage</a>

        <section class="fb-card fb-contact-overview" aria-label="Request received" data-reveal data-premium-surface>
            <p class="fb-section-kicker">Request received</p>
            <h1 class="fb-contact-title">You’re in the queue.</h1>
            <p class="fb-contact-summary">
                We received your {{ $intentValue === 'demo' ? 'demo' : 'production' }} access request.
                You will receive an email with a password setup link once approved.
            </p>
            <div class="mt-4 flex flex-wrap gap-2">
                <a href="{{ route('login') }}" class="fb-btn fb-btn-primary">Log in</a>
                <a href="{{ route('platform.plans') }}" class="fb-btn fb-btn-secondary">Compare plans</a>
                <a href="{{ route('platform.contact') }}" class="fb-btn fb-btn-secondary">Talk to sales</a>
            </div>
        </section>
    </main>
</body>
</html>


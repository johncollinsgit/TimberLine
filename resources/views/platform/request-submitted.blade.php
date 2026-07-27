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
            <h1 class="fb-contact-title">{{ $intentValue === 'demo' ? 'Demo request received.' : 'Production request received.' }}</h1>
            <p class="fb-contact-summary">
                @if($intentValue === 'demo')
                    We received your demo request. You’ll get an activation email for a safe sample workspace once approved.
                @else
                    We received your production request. After approval, your activation email will route you to the correct tenant host.
                @endif
            </p>
            <div class="mt-4 grid gap-3 md:grid-cols-3">
                <article class="fb-state text-sm">
                    <div class="font-semibold">1. Review</div>
                    <div class="mt-1">Operator review confirms request intent and routing context.</div>
                </article>
                <article class="fb-state text-sm">
                    <div class="font-semibold">2. Activation</div>
                    <div class="mt-1">Approval sends one password-setup email with a secure link.</div>
                </article>
                <article class="fb-state text-sm">
                    <div class="font-semibold">3. Start Here</div>
                    <div class="mt-1">First login lands in tenant-aware Start Here with next actions.</div>
                </article>
            </div>
            <div class="mt-5 flex flex-wrap gap-2">
                <a href="{{ route('login') }}" class="fb-btn fb-btn-primary">Log in</a>
                @if($intentValue === 'demo')
                    <a href="{{ route('platform.start') }}" class="fb-btn fb-btn-secondary">Start as a client</a>
                @else
                    <a href="{{ route('platform.demo') }}" class="fb-btn fb-btn-secondary">See a live demo</a>
                @endif
                <a href="{{ route('platform.plans') }}" class="fb-btn fb-btn-secondary">Compare plans</a>
                <a href="{{ route('platform.contact') }}" class="fb-btn fb-btn-secondary">Talk to sales</a>
            </div>
        </section>
    </main>
</body>
</html>

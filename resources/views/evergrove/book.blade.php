@php
    $content = is_array($content ?? null) ? $content : [];
    $brandAssets = (array) ($content['brand_assets'] ?? []);
    $assetVersion = (string) ($brandAssets['cache_tag'] ?? 'eg3');
    $mark = asset((string) ($brandAssets['mark'] ?? 'brand/evergrove-mark.svg')).'?v='.$assetVersion;
    $bookingUrl = is_string($bookingUrl ?? null) && $bookingUrl !== '' ? $bookingUrl : null;
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    @include('partials.head', [
        'app_name' => 'Evergrove Software',
        'title' => 'Book a Consultation | Evergrove Software',
        'exact_title' => true,
        'description' => 'Schedule a consultation with Evergrove Software to discuss websites, custom software, automation, AI, and digital business solutions.',
        'brand_assets' => $brandAssets,
    ])
</head>
<body class="eg-public-body eg-public-body--launch">
    @include('evergrove.partials.nav')

    <main id="main-content" class="eg-booking-main">
        <section class="eg-booking-hero" aria-labelledby="booking-title">
            <div class="eg-booking-copy">
                <p class="eg-kicker">A practical first conversation</p>
                <h1 id="booking-title">Book a Consultation</h1>
                <p class="eg-booking-lede">Let’s talk about where your business is going and how the right digital tools can help you get there.</p>
                <p>Schedule a conversation with Evergrove Software to discuss websites, custom software, connected business systems, automation, artificial intelligence, or another technology need.</p>
                <p>There is no obligation. The first conversation is intended to understand your business, identify the main problem, and determine whether Evergrove Software is a good fit.</p>

                <ul class="eg-booking-topics" aria-label="Consultation topics">
                    <li>Websites</li>
                    <li>Custom software</li>
                    <li>Automation</li>
                    <li>AI</li>
                    <li>Digital business systems</li>
                </ul>
            </div>

            <aside class="eg-booking-card" aria-labelledby="booking-card-title">
                <div class="eg-booking-mark" aria-hidden="true">
                    <img src="{{ $mark }}" alt="" />
                </div>
                <p class="eg-booking-card__eyebrow">Evergrove Software</p>
                <h2 id="booking-card-title">Choose a time that works for you.</h2>
                <p>Meetings are scheduled directly with John Collins of Evergrove Software.</p>

                @if ($bookingUrl)
                    <a
                        href="{{ $bookingUrl }}"
                        class="eg-button eg-button-primary eg-booking-cta"
                        target="_blank"
                        rel="noopener noreferrer"
                    >
                        Choose a Time
                        <span aria-hidden="true">↗</span>
                    </a>
                    <p class="eg-booking-card__note">Google Calendar will open in a new tab so you can select an available appointment.</p>
                @else
                    <div class="eg-booking-unavailable" role="status">
                        <p>Online scheduling is being finalized. Please contact us directly to arrange a consultation.</p>
                        <a href="mailto:john@evergrovesoftware.com" class="eg-button eg-button-primary">
                            Email John
                        </a>
                        <a href="mailto:john@evergrovesoftware.com" class="eg-booking-email">john@evergrovesoftware.com</a>
                    </div>
                @endif
            </aside>
        </section>
    </main>

    @include('evergrove.partials.footer')
</body>
</html>

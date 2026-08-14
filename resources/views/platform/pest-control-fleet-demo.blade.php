@php
    $brandAssets = (array) config('everbranch.brand_assets', []);
    $brandAssetVersion = (string) ($brandAssets['cache_tag'] ?? 'eb1');
    $brandLockupPath = (string) ($brandAssets['lockup'] ?? 'brand/everbranch-lockup.svg');
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    @include('partials.head', [
        'title' => 'Green Shield Pest Control demo | Everbranch',
        'description' => 'A fictional Green Shield Pest Control demonstration of Everbranch scheduling, timecards, and controlled vehicle tracking.',
    ])
    <style>
        .gs-demo { min-height:100vh; color:#173e3b; background:#f6f3ec; font-family:Inter,ui-sans-serif,system-ui,sans-serif; }
        .gs-demo__wrap { width:min(1120px,calc(100% - 40px)); margin:0 auto; }
        .gs-demo__nav { display:flex; align-items:center; justify-content:space-between; gap:20px; min-height:76px; }
        .gs-demo__nav img { width:148px; height:auto; }
        .gs-demo__nav a { color:#173e3b; font-size:14px; font-weight:800; text-decoration:none; }
        .gs-demo__hero { padding:44px 0 70px; }
        .gs-demo__eyebrow { margin:0; color:#a45338; font-size:12px; font-weight:850; letter-spacing:.15em; text-transform:uppercase; }
        .gs-demo h1 { max-width:790px; margin:16px 0; font-family:Fraunces,Georgia,serif; font-size:clamp(42px,6vw,74px); font-weight:500; letter-spacing:-.06em; line-height:.98; }
        .gs-demo__lede { max-width:720px; color:#65716d; font-size:19px; line-height:1.55; }
        .gs-demo__grid { display:grid; grid-template-columns:minmax(0,1.5fr) minmax(285px,.8fr); gap:28px; align-items:start; }
        .gs-demo__video, .gs-demo__access, .gs-demo__rule { overflow:hidden; border:1px solid rgba(23,62,59,.14); border-radius:22px; background:#fffdf7; box-shadow:0 18px 48px rgba(23,62,59,.08); }
        .gs-demo__video video { display:block; width:100%; background:#0d2827; }
        .gs-demo__video p { margin:0; padding:15px 20px; color:#65716d; font-size:13px; line-height:1.45; }
        .gs-demo__access { padding:25px; }
        .gs-demo__access h2, .gs-demo__rule h2 { margin:0; font-family:Fraunces,Georgia,serif; font-size:28px; font-weight:500; letter-spacing:-.04em; }
        .gs-demo__credential { margin-top:18px; padding:14px; border-radius:14px; background:#edf3ed; }
        .gs-demo__credential small { display:block; color:#65716d; font-size:11px; font-weight:800; letter-spacing:.1em; text-transform:uppercase; }
        .gs-demo__credential code { display:block; margin-top:5px; color:#173e3b; font-size:14px; font-weight:800; overflow-wrap:anywhere; }
        .gs-demo__login { display:inline-flex; margin-top:20px; padding:12px 16px; border-radius:999px; color:#fffdf7; background:#173e3b; font-size:14px; font-weight:800; text-decoration:none; }
        .gs-demo__note { margin-top:15px; color:#65716d; font-size:12px; line-height:1.5; }
        .gs-demo__rules { display:grid; grid-template-columns:repeat(3,1fr); gap:18px; padding:44px 0 74px; }
        .gs-demo__rule { padding:22px; box-shadow:none; }
        .gs-demo__rule p { margin:11px 0 0; color:#65716d; font-size:14px; line-height:1.5; }
        @media (max-width:800px) { .gs-demo__grid,.gs-demo__rules { grid-template-columns:1fr; }.gs-demo__hero { padding-top:28px; } }
    </style>
</head>
<body class="gs-demo">
    <main class="gs-demo__wrap">
        <nav class="gs-demo__nav" aria-label="Demo navigation"><a href="{{ route('platform.promo') }}" aria-label="Everbranch home"><img src="{{ asset($brandLockupPath) }}?v={{ $brandAssetVersion }}" alt="Everbranch" /></a><a href="{{ route('platform.industry-demo', ['discipline' => 'field']) }}">Field-service example</a></nav>
        <section class="gs-demo__hero">
            <p class="gs-demo__eyebrow">Fictional demonstration · no real people, vehicles, or location data</p>
            <h1>Green Shield Pest Control keeps a clearer eye on the day.</h1>
            <p class="gs-demo__lede">See how scheduled work, the time clock, a company-van hardware feed, and an on-duty phone feed stay separate and controlled in Everbranch.</p>
            <div class="gs-demo__grid">
                <div class="gs-demo__video"><video controls playsinline preload="metadata" poster="{{ asset('media/green-shield-fleet-demo-poster.jpg') }}"><source src="{{ asset('media/green-shield-fleet-demo.mp4') }}" type="video/mp4" />Your browser does not support this demo video.</video><p>30-second silent product tour. Demonstration data is fictional; Everbranch’s production controls still require tenant access, policy/legal evidence, and the global rollout switch.</p></div>
                <aside class="gs-demo__access"><p class="gs-demo__eyebrow">Demo workspace access</p><h2>Try the fictional workspace</h2><div class="gs-demo__credential"><small>Login</small><code>{{ $demoEmail }}</code></div><div class="gs-demo__credential"><small>Password</small><code>{{ $demoPassword }}</code></div><a class="gs-demo__login" href="{{ route('platform.pest-control-fleet-demo.login') }}">Log in to the demo</a><p class="gs-demo__note">The login email is prefilled and sign-in returns to the Green Shield workspace. This account is isolated for demonstration only. Never enter real customer, employee, vehicle, or location data.</p></aside>
            </div>
        </section>
        <section class="gs-demo__rules" aria-label="Demo controls"><article class="gs-demo__rule"><p class="gs-demo__eyebrow">Separate sources</p><h2>Van ≠ person</h2><p>Bouncie hardware is mapped to a company vehicle. Crew-phone points are labeled separately and are never inferred from the van.</p></article><article class="gs-demo__rule"><p class="gs-demo__eyebrow">Approved window</p><h2>Work starts the boundary</h2><p>An assigned shift can control clock-in. Phone sharing is accepted only during an actively running timer and stops when the timer pauses or ends.</p></article><article class="gs-demo__rule"><p class="gs-demo__eyebrow">Short retention</p><h2>Controls remain visible</h2><p>Only owner/admin users can see tracking. Raw points are limited to 30 days; V1 excludes route scoring, alerts, and automated employment decisions.</p></article></section>
    </main>
</body>
</html>

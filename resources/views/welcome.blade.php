<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    @php
        $brandAssets = (array) config('everbranch.brand_assets', []);
        $brandAssetVersion = (string) ($brandAssets['cache_tag'] ?? 'eb1');
        $brandFaviconSvg = asset((string) ($brandAssets['favicon_svg'] ?? 'brand/everbranch-favicon.svg')).'?v='.$brandAssetVersion;
        $brandFaviconPng = asset((string) ($brandAssets['favicon_png'] ?? 'favicon.png')).'?v='.$brandAssetVersion;
        $brandFaviconIco = asset((string) ($brandAssets['favicon_ico'] ?? 'favicon.ico')).'?v='.$brandAssetVersion;
        $brandAppleTouchIcon = asset((string) ($brandAssets['apple_touch_icon'] ?? 'apple-touch-icon.png')).'?v='.$brandAssetVersion;
        $brandOgImage = asset((string) ($brandAssets['og_image'] ?? 'og-image.png')).'?v='.$brandAssetVersion;
        $brandMark = asset((string) ($brandAssets['mark'] ?? 'brand/everbranch-mark.svg')).'?v='.$brandAssetVersion;
    @endphp
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('everbranch.product_name', 'Everbranch') }}</title>
    <meta name="application-name" content="{{ config('everbranch.product_name', 'Everbranch') }}">
    <meta property="og:site_name" content="{{ config('everbranch.product_name', 'Everbranch') }}">
    <meta property="og:title" content="{{ config('everbranch.product_name', 'Everbranch') }}">
    <meta property="og:type" content="website">
    <meta property="og:url" content="{{ url('/') }}">
    <meta property="og:image" content="{{ $brandOgImage }}">
    <meta name="twitter:card" content="summary">
    <meta name="twitter:title" content="{{ config('everbranch.product_name', 'Everbranch') }}">
    <meta name="twitter:image" content="{{ $brandOgImage }}">
    <link rel="icon" href="{{ $brandFaviconSvg }}" type="image/svg+xml">
    <link rel="icon" href="{{ $brandFaviconPng }}" type="image/png" sizes="512x512">
    <link rel="icon" href="{{ $brandFaviconIco }}" type="image/x-icon" sizes="16x16 32x32 48x48">
    <link rel="shortcut icon" href="{{ $brandFaviconIco }}">
    <link rel="apple-touch-icon" href="{{ $brandAppleTouchIcon }}" sizes="180x180">
    <style>
        :root {
            color-scheme: dark;
            --bg: #07110d;
            --surface: rgba(14, 26, 20, 0.74);
            --surface-2: rgba(12, 22, 18, 0.88);
            --border: rgba(110, 231, 183, 0.14);
            --text: rgba(236, 253, 245, 0.95);
            --muted: rgba(209, 250, 229, 0.68);
            --accent: #34d399;
            --accent-2: #f59e0b;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            font-family: ui-sans-serif, system-ui, sans-serif;
            color: var(--text);
            background:
                radial-gradient(1000px 560px at 8% 0%, rgba(52, 211, 153, 0.18), transparent 60%),
                radial-gradient(900px 520px at 100% 10%, rgba(245, 158, 11, 0.10), transparent 58%),
                linear-gradient(180deg, #050b09, var(--bg));
            display: grid;
            place-items: center;
            padding: 1.25rem;
        }
        .shell {
            width: min(100%, 980px);
            border-radius: 1.5rem;
            border: 1px solid var(--border);
            background:
                radial-gradient(560px 220px at 12% 0%, rgba(52, 211, 153, 0.06), transparent 70%),
                radial-gradient(420px 180px at 90% 8%, rgba(245, 158, 11, 0.05), transparent 70%),
                linear-gradient(180deg, var(--surface), var(--surface-2));
            box-shadow:
                inset 0 0 0 1px rgba(255,255,255,0.03),
                0 30px 80px -50px rgba(0,0,0,0.9);
            overflow: hidden;
        }
        .hero {
            padding: 2rem;
            display: grid;
            gap: 1.25rem;
        }
        .brand {
            display: flex;
            align-items: center;
            gap: 0.9rem;
            min-width: 0;
        }
        .brand-badge {
            display: grid;
            place-items: center;
            width: 3rem;
            height: 3rem;
            border-radius: 0.9rem;
            background: linear-gradient(180deg, rgba(52,211,153,0.24), rgba(52,211,153,0.12));
            border: 1px solid rgba(52,211,153,0.25);
            color: #ecfdf5;
            box-shadow: 0 14px 30px -20px rgba(16,185,129,.6);
            flex: 0 0 auto;
        }
        .kicker {
            font-size: 0.72rem;
            letter-spacing: 0.22em;
            text-transform: uppercase;
            color: var(--muted);
            margin: 0;
        }
        h1 {
            margin: 0.35rem 0 0;
            font-size: clamp(1.7rem, 4vw, 2.6rem);
            line-height: 1.03;
            letter-spacing: -0.03em;
            font-weight: 700;
        }
        .lead {
            margin: 0;
            color: var(--muted);
            max-width: 60ch;
            line-height: 1.5;
        }
        .actions {
            display: flex;
            flex-wrap: wrap;
            gap: 0.75rem;
            margin-top: 0.25rem;
        }
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 2.7rem;
            padding: 0 1rem;
            border-radius: 999px;
            border: 1px solid rgba(255,255,255,0.1);
            text-decoration: none;
            color: var(--text);
            font-weight: 600;
            white-space: nowrap;
        }
        .btn-primary {
            background: linear-gradient(180deg, rgba(52,211,153,.3), rgba(16,185,129,.18));
            border-color: rgba(52,211,153,.35);
        }
        .btn-secondary {
            background: rgba(255,255,255,.03);
        }
        .trees {
            padding: 0 2rem 2rem;
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 0.85rem;
        }
        .tree-card {
            border-radius: 1rem;
            border: 1px solid rgba(255,255,255,.06);
            background: linear-gradient(180deg, rgba(255,255,255,.02), rgba(255,255,255,.01));
            padding: 0.9rem;
            display: flex;
            align-items: center;
            gap: 0.7rem;
            min-width: 0;
        }
        .tree-card svg { flex: 0 0 auto; opacity: 0.92; }
        .tree-card p {
            margin: 0;
            color: var(--muted);
            font-size: 0.82rem;
            line-height: 1.35;
        }
        @media (max-width: 768px) {
            .hero { padding: 1.25rem; }
            .trees {
                padding: 0 1.25rem 1.25rem;
                grid-template-columns: 1fr;
            }
            .brand-badge { width: 2.5rem; height: 2.5rem; border-radius: 0.75rem; }
        }
    </style>
</head>
<body>
    <main class="shell" role="main">
        <section class="hero">
            <div class="brand">
                <div class="brand-badge" aria-hidden="true">
                    <img src="{{ $brandMark }}" alt="" width="26" height="26" />
                </div>
                <div>
                    <p class="kicker">{{ config('everbranch.ecosystem_name', 'Evergrove') }}</p>
                    <h1>{{ config('everbranch.product_name', 'Everbranch') }}</h1>
                </div>
            </div>

            <p class="lead">
                A modular operating workspace for customers, work, money, materials, and next steps.
                Sign in to continue to Everbranch.
            </p>

            <div class="actions">
                <a class="btn btn-primary" href="{{ route('login') }}">Go to Login</a>
                @if (Route::has('wiki.index'))
                    <a class="btn btn-secondary" href="{{ route('wiki.index') }}">Workspace Wiki</a>
                @endif
            </div>
        </section>

        <section class="trees" aria-label="Brand motif">
            @for ($i = 0; $i < 3; $i++)
                <div class="tree-card">
                    <img src="{{ $brandMark }}" alt="" width="28" height="28" aria-hidden="true" />
                    <p>
                        {{ ['Shipping + fulfillment operations', 'Pour room planning + production flow', 'Retail, wholesale, and market coordination'][$i] }}
                    </p>
                </div>
            @endfor
        </section>
    </main>
</body>
</html>

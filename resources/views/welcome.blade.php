<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Modern Forestry Backstage</title>
    <meta name="application-name" content="Modern Forestry Backstage">
    <meta property="og:site_name" content="Modern Forestry Backstage">
    <meta property="og:title" content="Modern Forestry Backstage">
    <meta property="og:type" content="website">
    <meta property="og:url" content="{{ url('/') }}">
    <meta property="og:image" content="{{ asset('apple-touch-icon.png') }}?v=mf2">
    <meta name="twitter:card" content="summary">
    <meta name="twitter:title" content="Modern Forestry Backstage">
    <meta name="twitter:image" content="{{ asset('apple-touch-icon.png') }}?v=mf2">
    <link rel="icon" href="{{ asset('favicon.ico') }}?v=mf2" sizes="any">
    <link rel="icon" href="{{ asset('favicon.svg') }}?v=mf2" type="image/svg+xml">
    <link rel="shortcut icon" href="{{ asset('favicon.ico') }}?v=mf2">
    <link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}?v=mf2">
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
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 48 24" width="26" height="13" fill="currentColor">
                        <path d="M8 4L2 14h4L1 22h6l-2 2h8l-2-2h6l-5-8h4L8 4z"/>
                        <path d="M24 2l-8 10h4l-6 8h8l-3 4h10l-3-4h8l-6-8h4L24 2z"/>
                        <path d="M40 6l-6 8h4l-5 7h6l-2 3h8l-2-3h6l-5-7h4l-6-8z"/>
                    </svg>
                </div>
                <div>
                    <p class="kicker">Modern Forestry</p>
                    <h1>Backstage</h1>
                </div>
            </div>

            <p class="lead">
                Internal operations hub for shipping, pouring, inventory, and market production workflows.
                Sign in to access the Backstage tools.
            </p>

            <div class="actions">
                <a class="btn btn-primary" href="{{ route('login') }}">Go to Login</a>
                @if (Route::has('wiki.index'))
                    <a class="btn btn-secondary" href="{{ route('wiki.index') }}">Backstage Wiki</a>
                @endif
            </div>
        </section>

        <section class="trees" aria-label="Brand motif">
            @for ($i = 0; $i < 3; $i++)
                <div class="tree-card">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 48 24" width="36" height="18" fill="none" aria-hidden="true">
                        <path d="M8 4L2 14h4L1 22h6l-2 2h8l-2-2h6l-5-8h4L8 4z" fill="rgba(52,211,153,.88)"/>
                        <path d="M24 2l-8 10h4l-6 8h8l-3 4h10l-3-4h8l-6-8h4L24 2z" fill="rgba(16,185,129,.92)"/>
                        <path d="M40 6l-6 8h4l-5 7h6l-2 3h8l-2-3h6l-5-7h4l-6-8z" fill="rgba(245,158,11,.68)"/>
                    </svg>
                    <p>
                        {{ ['Shipping + fulfillment operations', 'Pour room planning + production flow', 'Retail, wholesale, and market coordination'][$i] }}
                    </p>
                </div>
            @endfor
        </section>
    </main>
</body>
</html>

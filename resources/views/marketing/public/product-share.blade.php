<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    @php
        $headline = trim((string) ($headline ?? 'Modern Forestry candle')) ?: 'Modern Forestry candle';
        $title = trim((string) ($title ?? 'Modern Forestry candle')) ?: 'Modern Forestry candle';
        $description = trim((string) ($description ?? 'A hand-poured candle from Modern Forestry.')) ?: 'A hand-poured candle from Modern Forestry.';
        $shareImageUrl = trim((string) ($shareImageUrl ?? asset((string) config('everbranch.brand_assets.og_image', 'og-image.png'))));
        $productUrl = trim((string) ($productUrl ?? 'https://theforestrystudio.com/'));
        $product = is_array($product ?? null) ? $product : [];
        $price = trim((string) ($product['price'] ?? ''));
        $productType = trim((string) ($product['productType'] ?? ''));
        $notes = array_values(array_filter((array) ($product['scentNotes'] ?? [])));
    @endphp
    <title>{{ $headline }}</title>
    <meta name="description" content="{{ $description }}">
    <meta property="og:title" content="{{ $headline }}">
    <meta property="og:description" content="{{ $description }}">
    <meta property="og:type" content="website">
    <meta property="og:url" content="{{ url()->current() }}">
    <meta property="og:image" content="{{ $shareImageUrl }}">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="{{ $headline }}">
    <meta name="twitter:description" content="{{ $description }}">
    <meta name="twitter:image" content="{{ $shareImageUrl }}">
    @vite(['resources/css/app.css'])
    <style>
        body {
            min-height: 100vh;
            background:
                radial-gradient(circle at 16% 16%, rgba(20, 83, 45, 0.24), transparent 28%),
                radial-gradient(circle at 84% 12%, rgba(245, 158, 11, 0.16), transparent 28%),
                linear-gradient(135deg, #121814, #244133 52%, #f7f2ea 52%, #fbfaf8);
        }
        .share-shell {
            width: min(980px, calc(100vw - 2rem));
            margin: 0 auto;
            padding: 42px 0;
        }
        .share-card {
            overflow: hidden;
            border-radius: 34px;
            border: 1px solid rgba(255, 255, 255, 0.24);
            background: rgba(255, 255, 255, 0.95);
            box-shadow: 0 30px 90px rgba(15, 23, 42, 0.24);
        }
        .share-hero {
            display: grid;
            gap: 1.5rem;
            padding: clamp(1.4rem, 4vw, 2rem);
        }
        @media (min-width: 900px) {
            .share-hero {
                grid-template-columns: minmax(0, 1fr) minmax(0, 0.92fr);
                align-items: center;
            }
        }
        .share-copy {
            background:
                radial-gradient(circle at top right, rgba(16, 185, 129, 0.24), transparent 30%),
                linear-gradient(135deg, #10241a, #244f38);
            color: #f8fafc;
            border-radius: 28px;
            padding: clamp(1.5rem, 4vw, 3rem);
        }
        .share-kicker {
            font-size: 12px;
            font-weight: 900;
            letter-spacing: 0.18em;
            text-transform: uppercase;
            color: rgba(248, 250, 252, 0.7);
        }
        .share-copy h1 {
            margin-top: 1rem;
            font-size: clamp(2.4rem, 8vw, 5.2rem);
            line-height: 0.95;
            letter-spacing: -0.06em;
        }
        .share-image {
            border-radius: 28px;
            overflow: hidden;
            border: 1px solid rgba(15, 23, 42, 0.08);
            background: #f4efe7;
            min-height: 320px;
        }
        .share-image img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
        }
        .share-body {
            display: grid;
            gap: 1.5rem;
            padding: clamp(1.25rem, 4vw, 2rem);
        }
        .share-chip {
            display: inline-flex;
            align-items: center;
            border-radius: 999px;
            background: rgba(236, 253, 245, 0.95);
            border: 1px solid rgba(5, 150, 105, 0.18);
            color: #064e3b;
            padding: 0.55rem 0.85rem;
            font-size: 12px;
            font-weight: 900;
        }
        .share-action {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 999px;
            background: #111827;
            color: #fff;
            padding: 0.95rem 1.25rem;
            font-size: 14px;
            font-weight: 900;
            text-decoration: none;
        }
    </style>
</head>
<body class="text-slate-900">
<main class="share-shell">
    <article class="share-card">
        <section class="share-hero">
            <div class="share-copy">
                <div class="share-kicker">Modern Forestry candle share</div>
                <h1>{{ $title }}</h1>
                <p class="mt-5 max-w-2xl text-base leading-7 text-slate-100/85">{{ $description }}</p>
                <div class="mt-5 flex flex-wrap gap-2">
                    @if($price !== '')
                        <span class="share-chip">${{ ltrim($price, '$') }}</span>
                    @endif
                    @if($productType !== '')
                        <span class="share-chip">{{ $productType }}</span>
                    @endif
                </div>
            </div>
            <div class="share-image">
                <img src="{{ $shareImageUrl }}" alt="{{ $title }}">
            </div>
        </section>
        <section class="share-body">
            @if(count($notes) > 0)
                <div>
                    <div class="text-sm font-black uppercase tracking-[0.16em] text-emerald-800">Scent notes</div>
                    <div class="mt-4 flex flex-wrap gap-2">
                        @foreach($notes as $note)
                            <span class="share-chip">{{ \Illuminate\Support\Str::headline((string) $note) }}</span>
                        @endforeach
                    </div>
                </div>
            @endif

            <div class="rounded-3xl border border-emerald-100 bg-emerald-50/80 p-5">
                <h2 class="text-xl font-black tracking-tight text-slate-950">See the full candle</h2>
                <p class="mt-2 max-w-2xl text-sm leading-6 text-slate-600">Open the product page to shop this candle, save it to your wishlist, or explore more scents from Modern Forestry.</p>
                <a href="{{ $productUrl }}" class="share-action mt-4">Shop this candle</a>
            </div>
        </section>
    </article>
</main>
</body>
</html>

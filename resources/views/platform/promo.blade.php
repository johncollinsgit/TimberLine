@php
    $content = is_array($promo ?? null) ? $promo : [];
    $cta = is_array($content['ctas'] ?? null) ? $content['ctas'] : [];
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $content['headline'] ?? 'Fire Forge Tech Platform' }}</title>
    <style>
        :root {
            --promo-bg: #081311;
            --promo-bg-alt: #10201b;
            --promo-panel: rgba(12, 31, 26, 0.78);
            --promo-border: rgba(120, 255, 201, 0.2);
            --promo-text: #f2fbf8;
            --promo-muted: rgba(226, 245, 239, 0.78);
            --promo-accent: #37d79d;
            --promo-accent-soft: rgba(55, 215, 157, 0.2);
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            min-height: 100vh;
            color: var(--promo-text);
            font-family: "Manrope", "Avenir Next", "Segoe UI", sans-serif;
            background:
                radial-gradient(90vw 60vw at -10% -20%, rgba(55, 215, 157, 0.18), transparent 62%),
                radial-gradient(80vw 70vw at 120% 0%, rgba(90, 149, 255, 0.14), transparent 66%),
                linear-gradient(165deg, var(--promo-bg), var(--promo-bg-alt));
        }

        .promo-shell {
            width: min(1120px, 100% - 40px);
            margin: 0 auto;
            padding: 34px 0 64px;
            display: grid;
            gap: 22px;
        }

        .promo-header,
        .promo-section {
            border-radius: 22px;
            border: 1px solid var(--promo-border);
            background: var(--promo-panel);
            backdrop-filter: blur(7px);
            box-shadow: 0 28px 64px -38px rgba(0, 0, 0, 0.62);
        }

        .promo-header {
            padding: 28px 24px 26px;
            display: grid;
            gap: 14px;
        }

        .promo-eyebrow {
            margin: 0;
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 0.24em;
            text-transform: uppercase;
            color: rgba(191, 245, 224, 0.82);
        }

        .promo-headline {
            margin: 0;
            font-family: "Fraunces", "Iowan Old Style", "Times New Roman", serif;
            font-size: clamp(2rem, 3.4vw, 3.2rem);
            line-height: 1.08;
            letter-spacing: -0.02em;
            max-width: 17ch;
        }

        .promo-summary {
            margin: 0;
            color: var(--promo-muted);
            line-height: 1.62;
            max-width: 66ch;
            font-size: 1rem;
        }

        .promo-cta-row {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            padding-top: 4px;
        }

        .promo-cta {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 40px;
            padding: 0 14px;
            border-radius: 999px;
            border: 1px solid rgba(182, 246, 224, 0.3);
            color: var(--promo-text);
            text-decoration: none;
            font-size: 12px;
            font-weight: 700;
            letter-spacing: 0.05em;
            text-transform: uppercase;
            background: rgba(23, 44, 36, 0.7);
            transition: transform 0.18s ease, background 0.18s ease, border-color 0.18s ease;
        }

        .promo-cta:hover {
            transform: translateY(-1px);
            border-color: rgba(203, 255, 235, 0.5);
            background: rgba(33, 64, 53, 0.8);
        }

        .promo-cta--primary {
            background: var(--promo-accent-soft);
            border-color: rgba(90, 237, 184, 0.55);
        }

        .promo-section {
            padding: 22px;
            display: grid;
            gap: 14px;
        }

        .promo-section h2 {
            margin: 0;
            font-size: 1.02rem;
            letter-spacing: 0.14em;
            text-transform: uppercase;
            color: rgba(203, 247, 229, 0.92);
        }

        .promo-work-grid {
            display: grid;
            gap: 10px;
            grid-template-columns: repeat(3, minmax(0, 1fr));
        }

        .promo-work-card {
            border-radius: 15px;
            border: 1px solid rgba(181, 244, 223, 0.16);
            background: rgba(13, 35, 29, 0.8);
            padding: 14px;
            display: grid;
            gap: 7px;
        }

        .promo-work-card h3 {
            margin: 0;
            font-size: 13px;
            font-weight: 700;
            color: #dff9ef;
        }

        .promo-work-card p {
            margin: 0;
            font-size: 12px;
            line-height: 1.5;
            color: rgba(218, 241, 232, 0.82);
        }

        .promo-plan-grid {
            display: grid;
            gap: 12px;
            grid-template-columns: repeat(3, minmax(0, 1fr));
        }

        .promo-plan-card {
            border-radius: 15px;
            border: 1px solid rgba(181, 244, 223, 0.16);
            background: rgba(14, 37, 30, 0.84);
            padding: 14px;
            display: grid;
            gap: 8px;
        }

        .promo-plan-title {
            margin: 0;
            font-size: 14px;
            font-weight: 700;
            color: #effdf6;
        }

        .promo-plan-price {
            margin: 0;
            font-family: "Fraunces", "Iowan Old Style", "Times New Roman", serif;
            font-size: 1.35rem;
            color: var(--promo-accent);
        }

        .promo-plan-summary {
            margin: 0;
            font-size: 12px;
            line-height: 1.55;
            color: rgba(214, 241, 230, 0.82);
        }

        .promo-plan-list {
            margin: 0;
            padding: 0;
            list-style: none;
            display: grid;
            gap: 5px;
        }

        .promo-plan-list li {
            font-size: 12px;
            color: rgba(220, 246, 235, 0.78);
            line-height: 1.45;
        }

        .promo-plan-foot {
            margin-top: 4px;
        }

        .promo-note {
            margin: 0;
            font-size: 12px;
            line-height: 1.5;
            color: rgba(200, 231, 220, 0.78);
        }

        @media (max-width: 980px) {
            .promo-work-grid,
            .promo-plan-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (max-width: 660px) {
            .promo-shell {
                width: min(1120px, 100% - 24px);
                padding-top: 20px;
            }

            .promo-header,
            .promo-section {
                border-radius: 16px;
                padding: 16px;
            }

            .promo-work-grid,
            .promo-plan-grid {
                grid-template-columns: 1fr;
            }

            .promo-cta {
                width: 100%;
            }
        }
    </style>
</head>
<body>
    <main class="promo-shell">
        <header class="promo-header" aria-label="Product promo hero">
            @if(filled($content['eyebrow'] ?? null))
                <p class="promo-eyebrow">{{ $content['eyebrow'] }}</p>
            @endif

            <h1 class="promo-headline">{{ $content['headline'] ?? 'Fire Forge Tech Platform' }}</h1>
            <p class="promo-summary">{{ $content['summary'] ?? '' }}</p>

            <div class="promo-cta-row" aria-label="Primary calls to action">
                @if(is_array($cta['install'] ?? null) && filled($cta['install']['href'] ?? null))
                    <a class="promo-cta promo-cta--primary" href="{{ $cta['install']['href'] }}">{{ $cta['install']['label'] ?? 'Install' }}</a>
                @endif
                @if(is_array($cta['demo'] ?? null) && filled($cta['demo']['href'] ?? null))
                    <a class="promo-cta" href="{{ $cta['demo']['href'] }}">{{ $cta['demo']['label'] ?? 'Book Demo' }}</a>
                @endif
                @if(is_array($cta['contact'] ?? null) && filled($cta['contact']['href'] ?? null))
                    <a class="promo-cta" href="{{ $cta['contact']['href'] }}">{{ $cta['contact']['label'] ?? 'Contact Sales' }}</a>
                @endif
            </div>
        </header>

        <section class="promo-section" aria-label="How it works">
            <h2>How It Works</h2>
            <div class="promo-work-grid">
                @foreach((array) ($content['how_it_works'] ?? []) as $item)
                    <article class="promo-work-card">
                        <h3>{{ $item['title'] ?? 'Step' }}</h3>
                        <p>{{ $item['description'] ?? '' }}</p>
                    </article>
                @endforeach
            </div>
        </section>

        <section class="promo-section" aria-label="Plans">
            <h2>Plans</h2>
            <div class="promo-plan-grid">
                @foreach($plan_cards as $card)
                    <article class="promo-plan-card" data-plan-key="{{ $card['plan_key'] }}">
                        <h3 class="promo-plan-title">{{ $card['label'] }}</h3>
                        <p class="promo-plan-price">{{ $card['price_display'] }}</p>
                        <p class="promo-plan-summary">{{ $card['summary'] }}</p>

                        @if(($card['highlights'] ?? []) !== [])
                            <ul class="promo-plan-list">
                                @foreach($card['highlights'] as $line)
                                    <li>{{ $line }}</li>
                                @endforeach
                            </ul>
                        @endif

                        @if(is_array($card['cta'] ?? null) && filled($card['cta']['href'] ?? null))
                            <div class="promo-plan-foot">
                                <a class="promo-cta" href="{{ $card['cta']['href'] }}">{{ $card['cta']['label'] ?? 'Learn More' }}</a>
                            </div>
                        @endif
                    </article>
                @endforeach
            </div>

            @if(filled($content['disclaimer'] ?? null))
                <p class="promo-note">{{ $content['disclaimer'] }}</p>
            @endif
        </section>
    </main>
</body>
</html>

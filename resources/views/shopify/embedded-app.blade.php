<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Forestry Rewards</title>
    @vite(['resources/css/app.css'])
    @if($authorized && filled($shopifyApiKey))
        <meta name="shopify-api-key" content="{{ $shopifyApiKey }}">
    @endif
    @if($authorized && filled($shopDomain))
        <meta name="shopify-shop-domain" content="{{ $shopDomain }}">
    @endif
    @if($authorized && filled($host))
        <meta name="shopify-host" content="{{ $host }}">
    @endif
    <style>
        :root {
            color-scheme: dark;
        }

        body {
            min-height: 100vh;
            background:
                radial-gradient(circle at top, rgba(245, 48, 3, 0.12), transparent 38%),
                linear-gradient(180deg, #0b0a10 0%, #09080d 100%);
        }

        .shopify-shell {
            max-width: 1080px;
            margin: 0 auto;
            padding: 32px 20px 48px;
        }

        .shopify-panel {
            border: 1px solid rgba(255, 255, 255, 0.09);
            background: linear-gradient(180deg, rgba(18, 18, 24, 0.94), rgba(10, 10, 14, 0.98));
            box-shadow: 0 30px 90px rgba(0, 0, 0, 0.38);
            border-radius: 28px;
        }

        .shopify-status-pill {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            border-radius: 999px;
            padding: 8px 14px;
            font-size: 11px;
            letter-spacing: 0.18em;
            text-transform: uppercase;
        }

        .shopify-status-pill--ok {
            background: rgba(52, 211, 153, 0.12);
            border: 1px solid rgba(52, 211, 153, 0.32);
            color: #9ef2cf;
        }

        .shopify-status-pill--warning {
            background: rgba(251, 191, 36, 0.12);
            border: 1px solid rgba(251, 191, 36, 0.28);
            color: #fde68a;
        }

        .shopify-status-pill--info {
            background: rgba(125, 211, 252, 0.12);
            border: 1px solid rgba(125, 211, 252, 0.24);
            color: #bae6fd;
        }

        .shopify-card {
            border: 1px solid rgba(255, 255, 255, 0.08);
            background: rgba(255, 255, 255, 0.03);
            border-radius: 22px;
            padding: 22px;
        }

        .shopify-card-meta {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 12px;
        }

        .shopify-card-meta dt {
            font-size: 11px;
            letter-spacing: 0.14em;
            text-transform: uppercase;
            color: rgba(255, 255, 255, 0.46);
        }

        .shopify-card-meta dd {
            margin-top: 4px;
            color: rgba(255, 255, 255, 0.9);
            font-size: 14px;
        }

        @media (max-width: 768px) {
            .shopify-shell {
                padding: 20px 14px 32px;
            }

            .shopify-card-meta {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body class="text-white antialiased">
    <main class="shopify-shell">
        <section class="shopify-panel overflow-hidden px-6 py-8 sm:px-8 sm:py-10">
            <div class="flex flex-col gap-5 sm:flex-row sm:items-start sm:justify-between">
                <div class="max-w-3xl">
                    <div class="shopify-status-pill {{ $authorized ? 'shopify-status-pill--ok' : 'shopify-status-pill--warning' }}">
                        <span>{{ $storeLabel }}</span>
                    </div>
                    <h1 class="mt-5 text-3xl font-semibold tracking-tight text-white sm:text-4xl">{{ $headline }}</h1>
                    <p class="mt-3 max-w-2xl text-base leading-7 text-white/70">{{ $subheadline }}</p>
                </div>

                @if(! empty($quickLinks))
                    <div class="grid gap-3 sm:min-w-[260px]">
                        @foreach($quickLinks as $link)
                            <a href="{{ $link['href'] }}" target="_blank" rel="noopener" class="inline-flex items-center justify-center rounded-full border border-white/10 bg-white/5 px-4 py-3 text-sm font-medium text-white transition hover:border-white/20 hover:bg-white/10">
                                {{ $link['label'] }}
                            </a>
                        @endforeach
                    </div>
                @endif
            </div>

            @if(filled($setupNote))
                <div class="mt-6 rounded-3xl border border-white/10 bg-white/5 px-5 py-4 text-sm leading-6 text-white/72">
                    {{ $setupNote }}
                </div>
            @endif

            @if($authorized && ! empty($cards))
                <div class="mt-8 grid gap-4 lg:grid-cols-3">
                    @foreach($cards as $card)
                        <article class="shopify-card">
                            <div class="shopify-status-pill shopify-status-pill--{{ $card['tone'] }}">
                                {{ $card['status'] }}
                            </div>
                            <h2 class="mt-5 text-xl font-semibold text-white">{{ $card['label'] }}</h2>
                            <p class="mt-3 text-sm leading-6 text-white/68">{{ $card['body'] }}</p>
                            @if(! empty($card['meta']))
                                <dl class="shopify-card-meta mt-6">
                                    @foreach($card['meta'] as $label => $value)
                                        <div>
                                            <dt>{{ $label }}</dt>
                                            <dd>{{ $value }}</dd>
                                        </div>
                                    @endforeach
                                </dl>
                            @endif
                        </article>
                    @endforeach
                </div>
            @endif
        </section>
    </main>
</body>
</html>

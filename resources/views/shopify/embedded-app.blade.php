<x-shopify-embedded-shell
    :authorized="$authorized"
    :shopify-api-key="$shopifyApiKey"
    :shop-domain="$shopDomain"
    :host="$host"
    :store-label="$storeLabel"
    :headline="$headline"
    :subheadline="$subheadline"
    :app-navigation="$appNavigation"
    :page-actions="$pageActions"
>
    <style>
        .dashboard-setup-note {
            border-radius: 20px;
            border: 1px solid rgba(15, 23, 42, 0.1);
            background: rgba(255, 255, 255, 0.85);
            padding: 20px 24px;
            font-size: 14px;
            line-height: 1.6;
            color: rgba(15, 23, 42, 0.8);
            box-shadow: 0 18px 40px rgba(15, 23, 42, 0.08);
        }

        .dashboard-card-grid {
            margin-top: 20px;
            display: grid;
            gap: 20px;
            grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
        }

        .dashboard-card {
            border-radius: 22px;
            border: 1px solid rgba(15, 23, 42, 0.08);
            background: rgba(255, 255, 255, 0.9);
            padding: 24px;
            box-shadow: 0 24px 50px rgba(15, 23, 42, 0.1);
            min-height: 220px;
            display: flex;
            flex-direction: column;
            gap: 16px;
        }

        .dashboard-card-status {
            display: inline-flex;
            align-items: center;
            border-radius: 999px;
            padding: 6px 14px;
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 0.2em;
            text-transform: uppercase;
        }

        .dashboard-card-status.ok {
            background: rgba(16, 185, 129, 0.12);
            color: #047857;
        }

        .dashboard-card-status.warning {
            background: rgba(245, 158, 11, 0.15);
            color: #92400e;
        }

        .dashboard-card-status.info {
            background: rgba(59, 130, 246, 0.12);
            color: #1d4ed8;
        }

        .dashboard-card h3 {
            margin: 0;
            font-family: 'Fraunces', ui-serif, Georgia, serif;
            font-size: 1.7rem;
            color: #111827;
        }

        .dashboard-card p {
            margin: 0;
            font-size: 15px;
            line-height: 1.6;
            color: rgba(15, 23, 42, 0.72);
        }

        .dashboard-card-meta {
            margin-top: auto;
            display: grid;
            gap: 12px;
            grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
        }

        .dashboard-card-meta div {
            border-radius: 14px;
            border: 1px solid rgba(15, 23, 42, 0.06);
            background: rgba(245, 247, 242, 0.94);
            padding: 10px 12px;
        }

        .dashboard-card-meta dt {
            font-size: 10px;
            letter-spacing: 0.18em;
            text-transform: uppercase;
            color: rgba(22, 34, 29, 0.5);
        }

        .dashboard-card-meta dd {
            margin: 4px 0 0;
            font-size: 13px;
            font-weight: 600;
            color: rgba(15, 23, 42, 0.85);
        }
    </style>

    @if(filled($setupNote))
        <section class="dashboard-setup-note">
            {{ $setupNote }}
        </section>
    @endif

    @if($authorized && ! empty($cards))
        <section class="dashboard-card-grid" aria-label="Storefront rewards overview">
            @foreach($cards as $card)
                <article class="dashboard-card">
                    <div class="dashboard-card-status {{ $card['tone'] ?? 'info' }}">
                        {{ $card['status'] }}
                    </div>
                    <h3>{{ $card['label'] }}</h3>
                    <p>{{ $card['body'] }}</p>

                    @if(! empty($card['meta']))
                        <dl class="dashboard-card-meta">
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
        </section>
    @endif
</x-shopify-embedded-shell>

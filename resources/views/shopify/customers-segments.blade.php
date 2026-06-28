<x-shopify.customers-layout
    :authorized="$authorized"
    :shopify-api-key="$shopifyApiKey"
    :shop-domain="$shopDomain"
    :host="$host"
    :store-label="$storeLabel"
    :headline="$headline"
    :subheadline="$subheadline"
    :app-navigation="$appNavigation"
    :customer-subnav="$pageSubnav"
    :page-actions="$pageActions"
    :merchant-journey="$merchantJourney ?? []"
>
    @php
        /** @var \App\Services\Shopify\ShopifyEmbeddedUrlGenerator $embeddedUrls */
        $embeddedUrls = app(\App\Services\Shopify\ShopifyEmbeddedUrlGenerator::class);
        $embeddedContext = $embeddedUrls->contextQuery(
            request(),
            filled($host ?? null) ? (string) $host : null
        );
        $embeddedUrl = static fn (string $url): string => $embeddedUrls->append($url, $embeddedContext);
        $scentAudienceDefinitions = collect($scentAudienceDefinitions ?? []);
        $savedScentSegments = collect($savedScentSegments ?? []);
    @endphp

    <style>
        .customers-segments-root {
            display: grid;
            gap: 16px;
        }

        .customers-segments-card {
            border: 1px solid rgba(15, 23, 42, 0.1);
            border-radius: 16px;
            background: #fff;
            padding: 16px;
            display: grid;
            gap: 12px;
            box-shadow: 0 14px 30px rgba(15, 23, 42, 0.05);
        }

        .customers-segments-card h2,
        .customers-segments-card h3,
        .customers-segments-card p {
            margin: 0;
        }

        .customers-segments-muted {
            font-size: 13px;
            line-height: 1.5;
            color: rgba(15, 23, 42, 0.62);
        }

        .customers-segments-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 12px;
        }

        .customers-segment-audience {
            border: 1px solid rgba(15, 23, 42, 0.08);
            border-radius: 14px;
            background: rgba(248, 250, 252, 0.84);
            padding: 14px;
            display: grid;
            gap: 10px;
        }

        .customers-segment-kicker {
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 0.12em;
            text-transform: uppercase;
            color: rgba(15, 23, 42, 0.48);
        }

        .customers-segment-actions {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        .customers-segment-actions form {
            margin: 0;
        }

        .customers-segment-button,
        .customers-segment-link {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 34px;
            border-radius: 999px;
            border: 1px solid rgba(15, 23, 42, 0.12);
            padding: 0 12px;
            text-decoration: none;
            font-size: 12px;
            font-weight: 700;
            color: #0f172a;
            background: #fff;
            cursor: pointer;
        }

        .customers-segment-button[data-tone="primary"] {
            background: rgba(209, 250, 229, 0.95);
            border-color: rgba(16, 185, 129, 0.28);
            color: #065f46;
        }

        .customers-segment-table {
            width: 100%;
            border-collapse: collapse;
            min-width: 680px;
        }

        .customers-segment-table th,
        .customers-segment-table td {
            padding: 10px 0;
            border-bottom: 1px solid rgba(15, 23, 42, 0.08);
            text-align: left;
            vertical-align: top;
            font-size: 13px;
        }

        .customers-segment-table th {
            font-size: 11px;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: rgba(15, 23, 42, 0.5);
        }

        .customers-segment-table-wrap {
            overflow-x: auto;
        }

        @media (max-width: 860px) {
            .customers-segments-grid {
                grid-template-columns: minmax(0, 1fr);
            }
        }
    </style>

    <section class="customers-segments-root" aria-label="Customer segments">
        <article class="customers-segments-card">
            <h2>Scent audiences</h2>
            <p class="customers-segments-muted">
                Turn candle personality results into reusable customer segments and prefilled discount campaign drafts without rebuilding the audience logic by hand.
            </p>

            <div class="customers-segments-grid">
                @foreach($scentAudienceDefinitions as $audience)
                    <section class="customers-segment-audience">
                        <div class="customers-segment-kicker">{{ (string) ($audience['label'] ?? 'Audience') }}</div>
                        <h3>{{ (string) ($audience['segment_name'] ?? 'Scent audience') }}</h3>
                        <p class="customers-segments-muted">{{ (string) ($audience['segment_description'] ?? '') }}</p>
                        <div class="customers-segment-actions">
                            <form method="POST" action="{{ $embeddedUrl(route('shopify.app.customers.segments.scent-audiences.segment', [], false)) }}">
                                @csrf
                                <input type="hidden" name="trait" value="{{ (string) ($audience['trait'] ?? '') }}">
                                <button type="submit" class="customers-segment-button" data-tone="primary">Save Segment</button>
                            </form>
                            <form method="POST" action="{{ $embeddedUrl(route('shopify.app.customers.segments.scent-audiences.campaign', [], false)) }}">
                                @csrf
                                <input type="hidden" name="trait" value="{{ (string) ($audience['trait'] ?? '') }}">
                                <button type="submit" class="customers-segment-button">Create Discount Draft</button>
                            </form>
                        </div>
                    </section>
                @endforeach
            </div>
        </article>

        <article class="customers-segments-card">
            <h2>Saved scent segments</h2>
            <p class="customers-segments-muted">
                These saved segments are tied to the scent quiz result on each customer profile, so future quiz retakes keep the audience fresh automatically.
            </p>

            @if($savedScentSegments->isNotEmpty())
                <div class="customers-segment-table-wrap">
                    <table class="customers-segment-table" aria-label="Saved scent segments">
                        <thead>
                            <tr>
                                <th>Segment</th>
                                <th>Status</th>
                                <th>Rules</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($savedScentSegments as $segment)
                                <tr>
                                    <td>
                                        <strong>{{ $segment->name }}</strong>
                                        @if(filled($segment->description))
                                            <div class="customers-segments-muted">{{ $segment->description }}</div>
                                        @endif
                                    </td>
                                    <td>{{ ucfirst((string) ($segment->status ?? 'draft')) }}</td>
                                    <td>{{ collect((array) data_get($segment->rules_json, 'conditions', []))->pluck('value')->filter()->map(fn ($value) => \Illuminate\Support\Str::headline((string) $value))->implode(', ') ?: 'Trait rules' }}</td>
                                    <td>
                                        <div class="customers-segment-actions">
                                            <a class="customers-segment-link" href="{{ route('marketing.segments.preview', $segment) }}">Open Segment</a>
                                            <a class="customers-segment-link" href="{{ route('marketing.campaigns.create', ['segment_id' => $segment->id]) }}">Start Campaign</a>
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <p class="customers-segments-muted">No scent-driven saved segments yet. Create one from the audience cards above.</p>
            @endif
        </article>
    </section>
</x-shopify.customers-layout>

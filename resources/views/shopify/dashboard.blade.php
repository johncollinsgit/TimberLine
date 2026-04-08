@if(! ($authorized ?? false))
    @include('shopify.embedded-app')
@else
    <x-shopify-embedded-shell
        :authorized="$authorized"
        :shopify-api-key="$shopifyApiKey"
        :shop-domain="$shopDomain"
        :host="$host"
        :store-label="$storeLabel"
        :headline="$headline"
        :subheadline="$subheadline"
        :app-navigation="$appNavigation"
        :page-subnav="$pageSubnav ?? []"
        :page-actions="$pageActions"
    >
        @php
            $bootstrap = is_array($dashboardBootstrap ?? null) ? $dashboardBootstrap : [];
        @endphp

        <style>
            .shopify-dashboard-loading-shell {
                border: 1px solid rgba(15, 23, 42, 0.08);
                border-radius: 18px;
                background:
                    radial-gradient(circle at top right, rgba(15, 143, 97, 0.1), transparent 28%),
                    linear-gradient(180deg, rgba(255, 255, 255, 0.98), rgba(247, 249, 246, 0.98));
                box-shadow: 0 20px 48px rgba(15, 23, 42, 0.08);
                padding: 1.5rem;
            }

            .shopify-dashboard-loading-grid {
                display: grid;
                gap: 1rem;
                margin-top: 1rem;
            }

            .shopify-dashboard-loading-row {
                display: grid;
                gap: 1rem;
                grid-template-columns: repeat(4, minmax(0, 1fr));
            }

            .shopify-dashboard-loading-block {
                min-height: 110px;
                border-radius: 16px;
                background:
                    linear-gradient(
                        90deg,
                        rgba(226, 232, 240, 0.72) 0%,
                        rgba(241, 245, 249, 0.96) 40%,
                        rgba(226, 232, 240, 0.72) 100%
                    );
                background-size: 220% 100%;
                animation: shopify-dashboard-loading 1.25s ease-in-out infinite;
            }

            .shopify-dashboard-loading-block--hero {
                min-height: 320px;
            }

            .shopify-dashboard-loading-block--panel {
                min-height: 220px;
            }

            @keyframes shopify-dashboard-loading {
                0% {
                    background-position: 100% 0;
                }

                100% {
                    background-position: -100% 0;
                }
            }

            @media (max-width: 980px) {
                .shopify-dashboard-loading-row {
                    grid-template-columns: repeat(2, minmax(0, 1fr));
                }
            }

            @media (max-width: 700px) {
                .shopify-dashboard-loading-row {
                    grid-template-columns: 1fr;
                }
            }
        </style>

        <div id="shopify-dashboard-root">
            <section class="shopify-dashboard-loading-shell" aria-label="Loading dashboard">
                <strong>Loading dashboard</strong>
                <p style="margin: 0.45rem 0 0; color: rgba(15, 23, 42, 0.62);">
                    Backstage is loading fresh Shopify data in the background.
                </p>

                <div class="shopify-dashboard-loading-grid" aria-hidden="true">
                    <div class="shopify-dashboard-loading-row">
                        <div class="shopify-dashboard-loading-block"></div>
                        <div class="shopify-dashboard-loading-block"></div>
                        <div class="shopify-dashboard-loading-block"></div>
                        <div class="shopify-dashboard-loading-block"></div>
                    </div>

                    <div class="shopify-dashboard-loading-block shopify-dashboard-loading-block--hero"></div>

                    <div class="shopify-dashboard-loading-row">
                        <div class="shopify-dashboard-loading-block shopify-dashboard-loading-block--panel"></div>
                        <div class="shopify-dashboard-loading-block shopify-dashboard-loading-block--panel"></div>
                    </div>
                </div>
            </section>
        </div>

        <script id="shopify-dashboard-bootstrap" type="application/json">
            {!! json_encode($bootstrap, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) !!}
        </script>
    </x-shopify-embedded-shell>
@endif

<x-shopify-embedded-shell
    :authorized="$authorized"
    :shopify-api-key="$shopifyApiKey"
    :shop-domain="$shopDomain"
    :host="$host"
    :headline="$headline"
    :subheadline="$subheadline"
    :app-navigation="$appNavigation"
    :page-actions="$pageActions"
>
    <style>
        .settings-panel {
            border-radius: 12px;
            border: 1px solid rgba(15, 23, 42, 0.08);
            background: #ffffff;
            padding: 26px 28px;
            box-shadow: 0 14px 28px rgba(15, 23, 42, 0.04);
            max-width: 760px;
        }

        .settings-panel h2 {
            margin: 0 0 10px;
            font-size: 1.6rem;
            font-weight: 650;
            color: #111827;
        }

        .settings-panel p {
            margin: 0 0 14px;
            color: rgba(15, 23, 42, 0.72);
            line-height: 1.7;
            font-size: 15px;
        }

        .settings-sender-list {
            display: grid;
            gap: 12px;
            margin-top: 22px;
        }

        .settings-sender-card {
            border-radius: 12px;
            border: 1px solid rgba(15, 23, 42, 0.08);
            background: rgba(248, 250, 252, 0.9);
            padding: 16px 18px;
        }

        .settings-sender-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-top: 8px;
        }

        .settings-sender-pill {
            display: inline-flex;
            align-items: center;
            border-radius: 999px;
            border: 1px solid rgba(15, 23, 42, 0.1);
            padding: 4px 8px;
            font-size: 11px;
            font-weight: 620;
            color: rgba(15, 23, 42, 0.72);
        }
    </style>

    <section class="settings-panel">
        <h2>Messaging settings</h2>
        <p>
            Shopify now mirrors the active SMS sender configuration so support and marketing can see which Twilio
            numbers are live, which one is the default, and which numbers are staged for later.
        </p>
        <p>
            The editable default lives in Backstage Settings so we reuse the current admin control pattern instead of
            creating a second config flow inside Shopify.
        </p>

        <div class="settings-sender-list">
            @forelse($smsSenders as $sender)
                <article class="settings-sender-card">
                    <strong>{{ $sender['label'] }}</strong>
                    <div style="margin-top: 6px; color: rgba(15, 23, 42, 0.72); font-size: 14px;">
                        {{ $sender['identity_label'] ?? 'Not configured yet' }}
                    </div>
                    <div class="settings-sender-meta">
                        <span class="settings-sender-pill">{{ $sender['type'] }}</span>
                        <span class="settings-sender-pill">{{ $sender['status'] }}</span>
                        @if(!empty($sender['is_default']))
                            <span class="settings-sender-pill">default sender</span>
                        @endif
                        @if(empty($sender['sendable']))
                            <span class="settings-sender-pill">not sendable yet</span>
                        @endif
                    </div>
                </article>
            @empty
                <article class="settings-sender-card">
                    No SMS sender is configured yet. Add one in environment config before enabling sends.
                </article>
            @endforelse
        </div>
    </section>
</x-shopify-embedded-shell>

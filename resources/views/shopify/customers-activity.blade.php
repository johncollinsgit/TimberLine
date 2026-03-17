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
>
    <style>
        .customers-activity-shell {
            border-radius: 12px;
            border: 1px solid rgba(15, 23, 42, 0.08);
            background: #ffffff;
            overflow: hidden;
            box-shadow: 0 10px 20px rgba(15, 23, 42, 0.04);
        }

        .customers-activity-shell table {
            width: 100%;
            border-collapse: collapse;
        }

        .customers-activity-shell th,
        .customers-activity-shell td {
            padding: 12px 14px;
            border-bottom: 1px solid rgba(15, 23, 42, 0.06);
            text-align: left;
            font-size: 12px;
            color: rgba(15, 23, 42, 0.66);
            white-space: nowrap;
        }

        .customers-activity-shell th {
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: rgba(15, 23, 42, 0.5);
            background: rgba(247, 248, 246, 0.96);
            font-weight: 640;
        }
    </style>

    <section class="customers-surface">
        <h2>Activity</h2>
        <p>
            Review customer-facing reward lifecycle events, admin touches, and operational outcomes in one timeline.
            This shell prepares the activity feed structure without inventing new data sources.
        </p>
    </section>

    <section class="customers-activity-shell" aria-label="Customers activity shell">
        <table>
            <thead>
                <tr>
                    <th>When</th>
                    <th>Customer</th>
                    <th>Channel</th>
                    <th>Event</th>
                    <th>Outcome</th>
                    <th>Actor</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td colspan="6" style="padding: 30px 18px; text-align: center; font-size: 14px; color: rgba(15, 23, 42, 0.6);">
                        Activity feed wiring is planned for Phase 2.
                    </td>
                </tr>
            </tbody>
        </table>
    </section>
</x-shopify.customers-layout>

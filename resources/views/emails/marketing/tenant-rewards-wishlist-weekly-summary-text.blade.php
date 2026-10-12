EVERBRANCH WEEKLY RECAP
{{ data_get($report, 'tenant.name') }} · {{ data_get($report, 'window.label') }} · Eastern time

Customers added: {{ data_get($report, 'recap.customers_added', 0) }}
Customers unsubscribed: {{ data_get($report, 'recap.unsubscribed_count', 0) }}
Birthday gifts issued: {{ data_get($report, 'recap.birthday_issued', 0) }}
Birthday gifts redeemed: {{ data_get($report, 'recap.birthday_redeemed', 0) }}
Candle Cash added: ${{ number_format((float) data_get($report, 'candle_cash.earned.amount', 0), 2) }}
Candle Cash codes issued: {{ data_get($report, 'candle_cash.issued.count', 0) }} · ${{ number_format((float) data_get($report, 'candle_cash.issued.amount', 0), 2) }}
Candle Cash codes redeemed: {{ data_get($report, 'candle_cash.redeemed.count', 0) }} · ${{ number_format((float) data_get($report, 'candle_cash.redeemed.amount', 0), 2) }}
Emails sent / failed: {{ data_get($report, 'recap.emails_sent', 0) }} / {{ data_get($report, 'recap.emails_failed', 0) }}
Wishlist items added: {{ data_get($report, 'wishlist.adds', 0) }}

UNSUBSCRIBES
@forelse((array) data_get($report, 'recap.unsubscribed_customers', []) as $customer)
{{ $customer['name'] ?: 'Customer' }} · {{ $customer['email'] }} · {{ $customer['at'] }}
@empty
No email unsubscribe requests were recorded this week.
@endforelse

STOREFRONT HEALTH
{{ data_get($report, 'health.label') }}: {{ data_get($report, 'health.summary') }}

Open Everbranch: {{ rtrim(config('app.url'), '/') }}/marketing/customers

Unsubscribes take effect when processed. This is an internal recap containing customer details.
Figures reflect activity recorded in Everbranch. Applying a code is not a checkout redemption.

TRAJECTORY

Hi {{ $recipientName }},

You have {{ number_format($reviewCount) }} {{ $reviewCount === 1 ? 'transaction' : 'transactions' }} to review in {{ $spaceName }}.
{{ $reviewCount > count($rows) ? 'Here are the four most recent:' : 'Here’s what’s waiting:' }}

@foreach($rows as $row)
{{ $row['merchant'] }} — {{ $row['amount'] }} USD
{{ \Illuminate\Support\Str::headline($row['category']) }} · {{ \Illuminate\Support\Str::headline($row['flow']) }}
{{ \Carbon\CarbonImmutable::parse($row['date'])->format('F j, Y') }}

@endforeach
Positive amounts are money in; negative amounts are money out. Categories are awaiting your review.

Review transactions: {!! $reviewUrl !!}

Make room for what matters.

You enabled review reminders for this finance space. Change frequency or turn off emails in Accounts → Email reminders (sign in required):
{!! $settingsUrl !!}

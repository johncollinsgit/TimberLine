<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="referrer" content="no-referrer"><meta name="robots" content="noindex,nofollow"><meta name="csrf-token" content="{{ csrf_token() }}"><title>Manage subscription | {{ $tenant->name }}</title>@vite('resources/css/app.css')</head>
<body class="min-h-screen bg-stone-50 font-sans text-zinc-900">
<main class="mx-auto max-w-2xl space-y-6 px-5 py-10">
    <header><p class="text-sm font-medium text-emerald-900">{{ $tenant->name }}</p><h1 class="mt-2 text-3xl font-semibold">Your subscription</h1><p class="mt-2 text-zinc-600">{{ $enrollment->terms['name'] }}</p></header>
    @if($errors->any())<p role="alert" class="rounded-xl border border-amber-300 bg-amber-50 p-4">{{ $errors->first() }}</p>@endif
    @if($notice || session('pilot_customer_status'))<p role="status" class="rounded-xl border border-emerald-200 bg-white p-4">{{ $notice ?? session('pilot_customer_status') }}</p>@endif
    <section class="space-y-3 rounded-xl border border-zinc-200 bg-white p-6">
        @foreach($enrollment->terms['lines'] as $line)<p>{{ $line['quantity'] }} × {{ $line['label'] }}</p>@endforeach
        <p class="text-xl font-semibold">{{ $enrollment->terms['currency'] }} {{ number_format($enrollment->terms['amount_cents']/100,2) }} every {{ $enrollment->terms['billing_months'] }} month(s)</p>
        <p class="text-sm text-zinc-600">Includes shipping {{ number_format($enrollment->terms['shipping_cents']/100,2) }} and tax {{ number_format($enrollment->terms['tax_cents']/100,2) }}.</p>
        <p class="text-sm">First charge: {{ \Carbon\CarbonImmutable::parse($enrollment->terms['first_billing_date'])->format('F j, Y') }}. {{ $enrollment->next_billing_date ? 'Next charge: '.$enrollment->next_billing_date->format('F j, Y').'.' : '' }}</p>
        <p class="text-sm">Ship to {{ $enrollment->terms['shipping_address']['address1'] }}, {{ $enrollment->terms['shipping_address']['city'] }}, {{ $enrollment->terms['shipping_address']['provinceCode'] }} {{ $enrollment->terms['shipping_address']['zip'] }}.</p>
        <p class="text-sm text-zinc-600">{{ $enrollment->terms['cancellation_policy'] }}</p>
        <p class="text-sm text-zinc-600">Candle Cash cannot pay for or discount subscriptions.</p>
    </section>
    @if($enrollment->status==='draft')
        <form method="POST" action="{{ route('subscriptions.pilot.customer.authorize',['token'=>$token]) }}" class="space-y-4 rounded-xl border border-zinc-200 bg-white p-6">@csrf<input type="hidden" name="terms_hash" value="{{ $enrollment->terms_hash }}">
            <h2 class="text-lg font-semibold">Authorize recurring payments</h2><p class="text-sm text-zinc-600">Secure setup saves your card with Stripe. It does not charge you or start billing. {{ $tenant->name }} will activate this subscription only after confirming that the previous billing has stopped.</p>
            <label class="flex items-start gap-3 text-sm"><input type="checkbox" name="accepted" value="1" required class="mt-1 rounded">I authorize {{ $tenant->name }} to save my payment method with Stripe and charge the amount and frequency above, starting on the stated date, until I pause or cancel. I agree to the cancellation policy.</label>
            <button class="w-full rounded-lg bg-emerald-900 px-4 py-3 font-medium text-white">Continue to secure Stripe setup</button>
        </form>
    @else
        <section class="space-y-3 rounded-xl border border-zinc-200 bg-white p-6"><h2 class="text-lg font-semibold">{{ $enrollment->status==='authorized' ? 'Payment method authorized' : ucfirst($enrollment->status) }}</h2>
            @if($enrollment->status==='authorized')<p class="text-sm text-zinc-600">Stripe verified your payment setup. Billing is awaiting the separate source-billing safety checks.</p>@endif
            @if(in_array($enrollment->status,['authorized','active','paused'],true))<form method="POST" action="{{ route('subscriptions.pilot.customer.stop',['token'=>$token]) }}" class="flex flex-wrap gap-3">@csrf
                @if($enrollment->status==='active')<button name="action" value="pause" class="rounded-lg border border-zinc-300 px-4 py-2 text-sm">Pause future charges</button>@endif
                <button name="action" value="cancel" class="rounded-lg border border-zinc-300 px-4 py-2 text-sm">Cancel future charges</button>
            </form>@endif
        </section>
    @endif
    @if($cycles->isNotEmpty())<section class="space-y-3"><h2 class="text-lg font-semibold">Payments and orders</h2>
        @foreach($cycles as $cycle)<div class="space-y-2 rounded-xl border border-zinc-200 bg-white p-5"><p class="font-medium">{{ $cycle->cycle_date->format('F j, Y') }} · {{ $cycle->currency }} {{ number_format($cycle->amount_cents/100,2) }}</p>
            <p class="text-sm">Payment: {{ str_replace('_',' ',$cycle->payment_status) }}. Shopify order: {{ $cycle->handoff_status==='confirmed'?'received for fulfillment':'awaiting confirmation' }}.</p>
            @if($cycle->payment_status==='requires_action')<button class="rounded-lg bg-emerald-900 px-4 py-2 text-sm text-white" data-pilot-authentication data-prepare="{{ route('subscriptions.pilot.customer.authentication',['token'=>$token,'cycle'=>$cycle->id]) }}" data-confirm="{{ route('subscriptions.pilot.customer.confirm',['token'=>$token,'cycle'=>$cycle->id]) }}">Authenticate this payment</button><p role="status" class="text-sm" data-authentication-status></p>@endif
        </div>@endforeach
    </section>@endif
</main>
@vite('resources/js/subscriptions-pilot-customer.js')
</body></html>

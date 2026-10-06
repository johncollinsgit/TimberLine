@php
    $product = config('everbranch.product_name', 'Everbranch');
    $support = config('everbranch.support_email', 'john@evergrovesoftware.com');
    $version = $agreement->currentVersion;
    $content = (array) ($version?->content_payload ?? []);
    $accepted = (bool) $agreement->acceptance;
    $paid = $billingOrder?->status === 'paid';
    $receipt = $billingOrder?->receipts->where('status', 'paid')->sortByDesc('paid_at')->first();
    $lines = $billingOrder ? collect((array) $billingOrder->line_items) : collect((array) data_get($version?->pricing_payload, 'cards', []))->where('collectible_by_everbranch', true);
    $dueLines = $lines->whereIn('payment_timing', ['due_on_acceptance', 'recurring_current']);
    $futureLines = $lines->where('payment_timing', 'recurring_future');
    $due = $dueLines->sum(fn ($line) => (int) ($line['amount_cents'] ?? 0) * (int) ($line['quantity'] ?? 1));
@endphp
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow"><meta name="referrer" content="no-referrer">
    <title>{{ $agreement->tenant->name }} · Agreement · {{ $product }}</title>
    <link rel="icon" href="{{ asset(config('everbranch.brand_assets.favicon_svg')) }}" type="image/svg+xml">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @include('agreements.styles')
</head>
<body class="ep-page">
<a href="#proposal-main" class="ep-skip">Skip to agreement</a>
<header class="ep-header"><div class="ep-container ep-header-inner">
    <a href="https://theeverbranch.com" aria-label="{{ $product }} home"><img class="ep-logo" src="{{ asset(config('everbranch.brand_assets.lockup')) }}" alt="{{ $product }}"></a>
    <a class="ep-help" href="mailto:{{ $support }}">Questions? Get in touch <span aria-hidden="true">↗</span></a>
</div></header>
<main id="proposal-main" class="ep-container ep-main">
    @if(request()->boolean('session_expired'))<div role="status" class="ep-notice">Your session expired or changed. This page is refreshed; please try again. Your last submission was not processed.</div>@endif
    @if(session('status'))<div role="status" class="ep-notice ep-notice-success">{{ session('status') }}</div>@endif
    @if(session('status_error'))<div role="alert" class="ep-notice ep-notice-error">{{ session('status_error') }}</div>@endif
    @if($agreement->agreement_type === \App\Models\Agreement::TYPE_SANDBOX_VALIDATION)<div class="ep-notice">Test mode only. This agreement does not activate service or replace a client agreement.</div>@endif
    @if($paid)
    <section class="ep-card ep-confirmation" aria-label="Payment confirmation">
        <span class="ep-confirmation-check" aria-hidden="true">✓</span>
        <div><p class="ep-eyebrow">Payment confirmed</p><h2>Thank you for choosing Everbranch.</h2><p>Your payment is complete and your signed agreement is on file.@if($receipt) <strong>${{ number_format($receipt->total_amount_cents / 100, 2) }} {{ strtoupper($receipt->currency) }}</strong> received.@endif</p></div>
        @if($receipt?->receipt_url ?: $receipt?->hosted_invoice_url)<a class="ep-button ep-button-secondary" href="{{ $receipt->receipt_url ?: $receipt->hosted_invoice_url }}" rel="noopener noreferrer">View your receipt ↗</a>@endif
    </section>
    @endif
    <div class="ep-intro">
        <p class="ep-eyebrow">Your {{ $product }} agreement</p>
        <h1>{{ $agreement->tenant->name }}</h1>
        <p class="ep-lead">{{ $accepted ? 'Your agreement, payment details, and permanent copy in one place.' : 'Review your scope and pricing, then make it official when you’re ready.' }}</p>
        <div class="ep-meta"><span class="ep-badge">{{ $paid ? 'Payment confirmed' : ($accepted ? 'Agreement accepted' : 'Ready for your review') }}</span><span>Version {{ $version?->version_number }}</span>@if($agreement->access_expires_at)<span>Link available through {{ $agreement->access_expires_at->format('M j, Y') }}</span>@endif</div>
    </div>
    <div class="ep-grid">
        <div class="ep-content">
            <section class="ep-card ep-overview"><p class="ep-eyebrow">Built around your business</p><h2>{{ $agreement->title }}</h2><p>{{ $content['purpose'] ?? '' }}</p></section>
            <details id="agreement-terms" class="ep-card ep-terms" open>
                <summary><span><span class="ep-eyebrow">Scope &amp; terms</span><strong>The complete agreement</strong></span><span class="ep-expand" aria-hidden="true">+</span></summary>
                <div class="ep-document">{!! $version->rendered_content !!}</div>
            </details>
            @if(!$accepted)
            <section id="acceptance" class="ep-card ep-acceptance"><p class="ep-eyebrow">Make it official</p><h2>Accept your agreement</h2><p>Review the complete agreement above. Your signature applies to this version and its stated terms.</p>
                @if($errors->any())<div role="alert" class="ep-notice ep-notice-error"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
                <form method="post" action="{{ route('proposals.accept', ['token' => $token]) }}" class="ep-sign-form mt-6 space-y-5">@csrf
                    <div class="grid gap-4 sm:grid-cols-2"><label class="text-sm font-semibold text-zinc-800">Full legal name<input name="signer_legal_name" value="{{ old('signer_legal_name') }}" required placeholder="Full legal name" class="mt-2 block w-full rounded-2xl border border-zinc-300 bg-white px-4 py-3 text-base shadow-inner focus:border-emerald-700 focus:ring-4 focus:ring-emerald-900/10"></label><label class="text-sm font-semibold text-zinc-800">Title / authority<input name="signer_title" value="{{ old('signer_title') }}" required placeholder="Owner / authorized signer" class="mt-2 block w-full rounded-2xl border border-zinc-300 bg-white px-4 py-3 text-base shadow-inner focus:border-emerald-700 focus:ring-4 focus:ring-emerald-900/10"></label><label class="text-sm font-semibold text-zinc-800">Email<input name="signer_email" type="email" value="{{ old('signer_email') }}" required placeholder="you@example.com" class="mt-2 block w-full rounded-2xl border border-zinc-300 bg-white px-4 py-3 text-base shadow-inner focus:border-emerald-700 focus:ring-4 focus:ring-emerald-900/10"></label></div>
                    <div class="rounded-2xl border border-emerald-100 bg-emerald-50/60 p-4 text-sm leading-6 text-emerald-950"><a href="#agreement-terms" class="font-semibold underline decoration-emerald-500 underline-offset-4">Read the complete agreement, scope, pricing, subscription, $50/hour approval rule, termination, and electronic-record terms.</a><p class="mt-1 text-emerald-900">The link takes you to the exact version you are signing.</p></div>
                    <label class="flex gap-3 rounded-2xl border border-zinc-200 bg-white p-4 text-sm leading-6 shadow-sm"><input type="checkbox" name="agreement_confirmation" value="1" required class="mt-0.5 size-5 rounded border-zinc-300 text-emerald-700 focus:ring-emerald-700"><span><strong class="block text-zinc-950">I have read and agree to this complete agreement.</strong><span class="mt-1 block text-zinc-600">I confirm I am authorized to sign, accept the scope and all stated pricing, authorize the applicable subscription or one-time charge, accept the written-approval rule for additional hourly work, accept termination terms, and consent to electronic records and signatures.</span></span></label>
                    <label class="block text-sm font-semibold text-zinc-800">Typed electronic signature<input name="electronic_signature_value" value="{{ old('electronic_signature_value') }}" required placeholder="Type the full legal name exactly" class="mt-2 block w-full rounded-2xl border border-zinc-300 bg-white px-4 py-3 font-serif text-xl italic shadow-inner focus:border-emerald-700 focus:ring-4 focus:ring-emerald-900/10"></label>
                    <button class="w-full rounded-xl bg-emerald-800 px-5 py-3 font-semibold text-white hover:bg-emerald-900">Accept agreement</button>
                </form>
                <details class="ep-record"><summary>Agreement record</summary><p>Version {{ $version->version_number }} · Content reference <span class="break-all">{{ $version->content_hash }}</span></p></details>
            </section>
            @else
            <section id="acceptance" class="ep-card ep-acceptance"><p class="ep-eyebrow">Accepted and locked</p><h2>Your agreement is on file.</h2><p>Accepted {{ $agreement->acceptance->accepted_at->format('F j, Y') }}. This exact version is read-only.</p><a class="ep-button ep-button-secondary" href="{{ route('proposals.download', ['token' => $token]) }}">Download permanent agreement copy <span aria-hidden="true">↓</span></a></section>
            @endif
        </div>
        <aside class="ep-summary" aria-label="Pricing and next step">
            <section class="ep-card ep-payment"><p class="ep-eyebrow">{{ $paid ? 'Payment received' : 'Your payment summary' }}</p><h2>{{ $paid ? 'You’re all set.' : ($accepted ? 'Finish your setup.' : 'A clear starting point.') }}</h2>
                @if($dueLines->isNotEmpty())
                <div class="ep-total">${{ number_format(($paid && $receipt ? $receipt->total_amount_cents : $due) / 100, 2) }}<span>{{ $paid ? 'Amount paid' : 'Initial payment · before applicable tax' }}</span></div>
                <div class="ep-line-items">@foreach($dueLines as $line)<div class="ep-line"><div><strong>{{ $line['label'] }}</strong><small>{{ ($line['frequency'] ?? '') === 'month' ? 'Monthly service' : 'One-time' }}</small></div><span>${{ number_format(($line['amount_cents'] ?? 0) * ($line['quantity'] ?? 1) / 100, 2) }}</span></div>@endforeach</div>
                @endif
                @foreach($futureLines as $line)<p class="ep-recurring">{{ $line['label'] }}: <strong>${{ number_format(($line['amount_cents'] ?? 0) / 100, 2) }}/month</strong> from billing cycle {{ $line['starts_cycle'] ?? data_get($version->subscription_payload, 'promotional_cycles', 6) + 1 }}.</p>@endforeach
                @if($paid)<div class="ep-notice ep-notice-success">Payment confirmed by Stripe.</div>@if($receipt?->receipt_url ?: $receipt?->hosted_invoice_url)<a class="ep-button" href="{{ $receipt->receipt_url ?: $receipt->hosted_invoice_url }}" rel="noopener noreferrer">View payment receipt ↗</a>@endif
                @elseif(!$accepted)<a class="ep-button" href="#acceptance">Review &amp; accept <span aria-hidden="true">→</span></a><p class="ep-caption">Payment is a separate step after you accept.</p>
                @elseif($billingOrder && $checkoutAvailable && !in_array($billingOrder->status, ['refunded','void']))<form method="post" action="{{ route('proposals.checkout', ['token' => $token]) }}">@csrf<button class="ep-button" type="submit">Continue to secure payment <span aria-hidden="true">→</span></button></form><p class="ep-caption">Card or US bank account · Powered by Stripe</p><p class="ep-caption">Bank payments remain processing until Stripe confirms settlement.</p>
                @else<p class="ep-recurring">{{ $billingOrder && in_array($billingOrder->status, ['refunded','void']) ? 'This payment is no longer available. Contact us for the next step.' : 'Your agreement is accepted. We’ll help you with the next payment step.' }}</p><a class="ep-button ep-button-secondary" href="mailto:{{ $support }}">Contact your team ↗</a>@endif
                <p class="ep-fine">Amounts follow your agreement. Provider costs and separately approved work are excluded unless listed here.</p>
            </section>
            <div class="ep-support"><p>We're here to help.</p><a href="mailto:{{ $support }}">{{ $support }}</a><span>Everbranch by Evergrove Software</span></div>
        </aside>
    </div>
</main>
<footer class="ep-footer ep-container"><span>© {{ date('Y') }} Evergrove Software</span><span>This private link is intended for the recipient.</span></footer>
</body></html>

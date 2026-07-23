@php
    $agreementContent = (array) ($agreement->currentVersion?->content_payload ?? []);
    $proposalSummary = trim((string) ($agreementContent['purpose'] ?? ''));
    $scopeSections = collect((array) ($agreementContent['scope_sections'] ?? data_get($agreement->currentVersion?->scope_payload, 'sections', [])));
    $dataSection = $scopeSections->first(function (mixed $section): bool {
        if (! is_array($section)) {
            return false;
        }

        $title = strtolower(trim((string) ($section['title'] ?? '')));

        return str_contains($title, 'data') || str_contains($title, 'privacy');
    });
    $dataAssurance = is_array($dataSection) ? trim((string) ($dataSection['body'] ?? '')) : '';

    if ($proposalSummary === '') {
        $proposalSummary = 'Review the scope, pricing, responsibilities, and authorization terms for '.$agreement->tenant->name.'.';
    }

    if ($dataAssurance === '') {
        $dataAssurance = 'Evergrove will use '.$agreement->tenant->name.' data only for approved service delivery, support, reporting, security, legal compliance, and client-authorized integrations, and will not sell it or share it with unrelated third parties.';
    }
@endphp
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>{{ $agreement->title }}</title>@vite(['resources/css/app.css', 'resources/js/app.js'])</head>
<body class="min-h-screen bg-[#f7f4ea] text-zinc-900">
<header class="border-b border-emerald-900/10 bg-[#0f4f3f] text-white"><div class="mx-auto flex max-w-6xl items-center justify-between px-4 py-5 sm:px-6"><div class="flex items-center gap-4"><div class="flex size-12 items-center justify-center rounded-full bg-white text-2xl">🍃</div><div><p class="text-xs font-semibold uppercase tracking-[0.24em] text-emerald-100">Evergrove Software</p><p class="font-semibold">Secure {{ $agreement->tenant->name }} proposal</p></div></div><div class="text-right text-xs text-emerald-50"><p>{{ $agreement->tenant->name }}</p><p>Version {{ $agreement->currentVersion?->version_number }} · {{ strtoupper(substr((string) $agreement->currentVersion?->content_hash, 0, 12)) }}</p></div></div></header>
<main class="mx-auto max-w-6xl px-4 py-8 sm:px-6">
    @if($agreement->agreement_type === \App\Models\Agreement::TYPE_SANDBOX_VALIDATION)<div class="mb-6 rounded-2xl border-2 border-amber-500 bg-amber-100 px-5 py-4 text-amber-950"><p class="font-bold uppercase tracking-[0.18em]">Test mode only</p><p class="mt-1 text-sm">Disposable Stripe validation agreement. It does not activate service, grant workspace access, or replace the real client agreement.</p></div>@endif
    @if(session('status'))<div class="mb-6 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900">{{ session('status') }}</div>@endif
    @if(session('status_error'))<div class="mb-6 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-900">{{ session('status_error') }}</div>@endif
    @if(!$unlocked)
        <section class="mx-auto mt-12 max-w-md rounded-[2rem] border border-emerald-100 bg-white p-6 shadow-sm"><h1 class="text-2xl font-semibold">Open secure proposal</h1><p class="mt-2 text-sm leading-6 text-zinc-600">Enter the password Evergrove shared with you. Failed attempts are rate-limited and recorded without storing the password.</p><form method="post" action="{{ route('proposals.unlock', ['token' => $token]) }}" class="mt-6 space-y-4">@csrf<label class="block text-sm font-semibold text-zinc-800">Proposal password<input name="password" type="password" required autocomplete="current-password" class="mt-2 block w-full rounded-2xl border border-zinc-300 bg-white px-4 py-3 text-base shadow-inner focus:border-emerald-700 focus:ring-4 focus:ring-emerald-900/10"></label>@error('password')<p class="text-sm text-red-700">{{ $message }}</p>@enderror<button class="w-full rounded-2xl bg-emerald-800 px-4 py-3 font-semibold text-white shadow-sm hover:bg-emerald-900">View proposal</button></form></section>
    @else
        <section class="mb-6 overflow-hidden rounded-[2rem] border border-emerald-100 bg-gradient-to-br from-[#fbf6e6] via-white to-[#dcefe2] p-5 shadow-sm sm:p-8">
            <p class="text-xs font-semibold uppercase tracking-[0.24em] text-emerald-800">{{ $agreement->agreement_type === \App\Models\Agreement::TYPE_SANDBOX_VALIDATION ? 'Sandbox validation agreement' : 'Client agreement' }}</p>
            <h1 class="mt-3 text-3xl font-semibold tracking-tight text-zinc-950">{{ $agreement->title }}</h1>
            <p class="mt-3 max-w-3xl text-sm leading-6 text-zinc-700">{{ $proposalSummary }}</p>
            <div class="mt-5 rounded-2xl border border-emerald-100 bg-white/80 p-4 text-sm leading-6 text-emerald-950">
                {{ $dataAssurance }}
            </div>
        </section>
        <section id="agreement-terms" class="rounded-3xl border border-zinc-200 bg-white p-5 shadow-sm sm:p-8">{!! $agreement->currentVersion->rendered_content !!}</section>
        @if($agreement->acceptance)
            <section class="mt-6 rounded-2xl border border-emerald-200 bg-emerald-50 p-6"><h2 class="text-xl font-semibold text-emerald-950">Accepted and locked</h2><p class="mt-2 text-sm text-emerald-900">Accepted {{ $agreement->acceptance->accepted_at->toDayDateTimeString() }}. This exact version is read-only.</p><a class="mt-4 inline-flex rounded-lg bg-emerald-800 px-4 py-2 text-base font-semibold !text-white shadow-sm transition hover:bg-emerald-900 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-800" style="color: #fff !important;" href="{{ route('proposals.download', ['token' => $token]) }}">Download permanent agreement copy</a></section>
            @if($billingOrder)
                @php
                    $payableLines = collect((array) $billingOrder->line_items)->whereIn('payment_timing', ['due_on_acceptance', 'recurring_current']);
                    $futureLines = collect((array) $billingOrder->line_items)->where('payment_timing', 'recurring_future');
                @endphp
                <section class="mt-6 rounded-3xl border border-zinc-200 bg-white p-5 shadow-sm sm:p-8"><div class="flex flex-wrap items-start justify-between gap-4"><div><p class="text-xs font-semibold uppercase tracking-[0.2em] text-emerald-700">Secure Stripe payment</p><h2 class="mt-1 text-2xl font-semibold">Locked payment summary</h2><p class="mt-2 text-sm text-zinc-600">Amounts come from the accepted agreement. Checkout includes only charges marked due now. Third-party costs and separately approved work are excluded unless they appear in this locked summary.</p></div><span class="rounded-full bg-zinc-100 px-3 py-1 text-sm font-medium">{{ str_replace('_', ' ', $billingOrder->status) }}</span></div>
                    <div class="mt-6 divide-y divide-zinc-100 rounded-2xl border border-zinc-200">@foreach($payableLines as $line)<div class="flex items-start justify-between gap-4 p-4"><div><p class="font-medium">{{ $line['label'] }}</p><p class="mt-1 text-xs text-zinc-500">{{ str_replace('_', ' ', $line['cost_category']) }} · {{ $line['frequency'] === 'month' ? 'monthly' : 'one time' }}</p></div><p class="font-semibold">${{ number_format($line['amount_cents'] / 100, 2) }}</p></div>@endforeach @foreach($futureLines as $line)<div class="flex items-start justify-between gap-4 bg-zinc-50 p-4"><div><p class="font-medium">{{ $line['label'] }}</p><p class="mt-1 text-xs text-zinc-500">Future recurring phase beginning with cycle {{ $line['starts_cycle'] ?? '' }}</p></div><p class="font-semibold">${{ number_format($line['amount_cents'] / 100, 2) }}/month</p></div>@endforeach</div>
                    @if($billingOrder->status === 'paid')<div class="mt-5 rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-900">Payment confirmed by Stripe.@if($billingOrder->receipts->first()?->hosted_invoice_url) <a class="font-semibold underline" href="{{ $billingOrder->receipts->first()->hosted_invoice_url }}" target="_blank" rel="noopener noreferrer">Open invoice</a>@endif</div>
                    @elseif($checkoutAvailable && !in_array($billingOrder->status, ['refunded','void']))<form method="post" action="{{ route('proposals.checkout', ['token' => $token]) }}" class="mt-5">@csrf<button class="w-full rounded-xl bg-zinc-950 px-5 py-3 font-semibold text-white">Pay securely with card or bank account</button></form><p class="mt-2 text-center text-xs text-zinc-500">Bank payments remain processing until Stripe confirms settlement.</p>
                    @else<div class="mt-5 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-950">Live payment is not available yet. No charge has been created.</div>@endif
                </section>
            @endif
        @else
            <section id="acceptance" class="mt-6 rounded-3xl border border-zinc-200 bg-white p-5 shadow-sm sm:p-8"><h2 class="text-2xl font-semibold">Electronic acceptance</h2><p class="mt-2 text-sm leading-6 text-zinc-600">Your signature binds this exact content hash: <span class="font-mono">{{ $agreement->currentVersion->content_hash }}</span></p>
                @if($errors->any())<div class="mt-4 rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-800"><ul class="list-disc pl-5">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
                <form method="post" action="{{ route('proposals.accept', ['token' => $token]) }}" class="mt-6 space-y-5">@csrf
                    <div class="grid gap-4 md:grid-cols-3"><label class="text-sm font-semibold text-zinc-800">Full legal name<input name="signer_legal_name" value="{{ old('signer_legal_name') }}" required placeholder="Full legal name" class="mt-2 block w-full rounded-2xl border border-zinc-300 bg-white px-4 py-3 text-base shadow-inner focus:border-emerald-700 focus:ring-4 focus:ring-emerald-900/10"></label><label class="text-sm font-semibold text-zinc-800">Title / authority<input name="signer_title" value="{{ old('signer_title') }}" required placeholder="Owner / authorized signer" class="mt-2 block w-full rounded-2xl border border-zinc-300 bg-white px-4 py-3 text-base shadow-inner focus:border-emerald-700 focus:ring-4 focus:ring-emerald-900/10"></label><label class="text-sm font-semibold text-zinc-800">Email<input name="signer_email" type="email" value="{{ old('signer_email') }}" required placeholder="you@example.com" class="mt-2 block w-full rounded-2xl border border-zinc-300 bg-white px-4 py-3 text-base shadow-inner focus:border-emerald-700 focus:ring-4 focus:ring-emerald-900/10"></label></div>
                    <div class="rounded-2xl border border-emerald-100 bg-emerald-50/60 p-4 text-sm leading-6 text-emerald-950"><a href="#agreement-terms" class="font-semibold underline decoration-emerald-500 underline-offset-4">Read the complete agreement, scope, pricing, subscription, $50/hour approval rule, termination, and electronic-record terms.</a><p class="mt-1 text-emerald-900">The link takes you to the exact version you are signing.</p></div>
                    <label class="flex gap-3 rounded-2xl border border-zinc-200 bg-white p-4 text-sm leading-6 shadow-sm"><input type="checkbox" name="agreement_confirmation" value="1" required class="mt-0.5 size-5 rounded border-zinc-300 text-emerald-700 focus:ring-emerald-700"><span><strong class="block text-zinc-950">I have read and agree to this complete agreement.</strong><span class="mt-1 block text-zinc-600">I confirm I am authorized to sign, accept the scope and all stated pricing, authorize the applicable subscription or one-time charge, accept the written-approval rule for additional hourly work, accept termination terms, and consent to electronic records and signatures.</span></span></label>
                    <label class="block text-sm font-semibold text-zinc-800">Typed electronic signature<input name="electronic_signature_value" value="{{ old('electronic_signature_value') }}" required placeholder="Type the full legal name exactly" class="mt-2 block w-full rounded-2xl border border-zinc-300 bg-white px-4 py-3 font-serif text-xl italic shadow-inner focus:border-emerald-700 focus:ring-4 focus:ring-emerald-900/10"></label>
                    <button class="w-full rounded-xl bg-emerald-800 px-5 py-3 font-semibold text-white hover:bg-emerald-900">Sign and accept this exact agreement</button>
                </form>
            </section>
        @endif
    @endif
</main></body></html>

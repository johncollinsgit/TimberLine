<x-layouts::app :title="'Review customer draft'">
    <main class="mx-auto max-w-5xl space-y-5 p-5">
        <a href="{{ route('customers.profile', ['tenant' => $tenant->slug, 'profile' => $profile->id]) }}">Back to customer profile</a>
        <h1 class="text-2xl font-semibold">Review personal {{ $review['channel'] === 'email' ? 'email' : 'text' }} draft</h1>
        <p>Customer: {{ trim($profile->first_name.' '.$profile->last_name) }} · Campaign #{{ $campaign->id }}</p>
        <p>Campaign: <strong>{{ $review['campaign_status'] }}</strong> · Original recipient: <strong>{{ $review['recipient_status'] }}</strong>.</p>
        @if(session('status'))<p role="status" class="rounded border p-3">{{ session('status') }}</p>@endif
        @if($errors->getBag('outreachReview')->any())<ul role="alert" class="list-disc pl-5">@foreach($errors->getBag('outreachReview')->all() as $error)<li>{{ $error }}</li>@endforeach</ul>@endif
        <section class="space-y-3 rounded border p-4" aria-label="Frozen customer message">
            <h2 class="text-xl font-semibold">Prepared content</h2>
            <p>{{ $review['content_valid'] ? 'Stored content matches its frozen preparation hashes.' : 'Stored content no longer matches its original preparation. Prepare a new draft; this record cannot be approved or sent here.' }}</p>
            @if($review['channel'] === 'email')<h3 class="font-semibold">{{ $review['subject'] }}</h3>@endif
            <p class="whitespace-pre-wrap">{{ $review['body'] }}</p>
            @if($review['channel'] === 'email' && $review['html'])<iframe title="Frozen formatted email preview" sandbox="" referrerpolicy="no-referrer" class="h-96 w-full rounded border" srcdoc="{{ $review['html'] }}"></iframe>@endif
            <p class="text-sm">This surface reviews the original prepared message. It does not rewrite personalization or substitute a new customer.</p>
        </section>
        <section class="space-y-3 rounded border p-4" aria-label="Current delivery checks">
            <h2 class="text-xl font-semibold">Current delivery checks</h2>
            <p>Approval reviews content. Consent, preferences, coverage, frequency limits, sender readiness and the existing delivery controls still apply.</p>
            @if($review['send_blockers'])<ul class="list-disc pl-5">@foreach($review['send_blockers'] as $reason)<li>{{ str($reason)->replace('_', ' ') }}</li>@endforeach</ul>@else<p>No current policy blocker is recorded. The existing sender rechecks all admission controls before its original delivery attempt.</p>@endif
            <p><a href="{{ route('customers.outreach.policy', ['tenant' => $tenant->slug]) }}">Review this business’s contact policy and limits</a></p>
            @if($review['send_handoff_unconfirmed'])<p role="alert">The original send handoff is unconfirmed. Reconcile that request and its original delivery. Repeating this form cannot create a replacement attempt.</p>@endif
            @if($review['can_approve'])
                <form method="POST" action="{{ route('customers.outreach.review', ['tenant' => $tenant->slug, 'profile' => $profile->id, 'campaign' => $campaign->id]) }}" class="space-y-3">
                    @csrf<input type="hidden" name="action" value="approve"><input type="hidden" name="request_id" value="{{ old('action') === 'approve' ? old('request_id', $review['approval_request_id']) : $review['approval_request_id'] }}"><input type="hidden" name="reviewed_content_hash" value="{{ $review['content_hash'] }}">
                    <label class="block"><input type="checkbox" name="confirm_approval" value="1" required> I reviewed this exact prepared content for this customer. Approval does not send or grant marketing consent.</label>
                    <button class="rounded border p-2" type="submit">Approve frozen content</button>
                </form>
            @endif
            @if($review['can_send'])
                <form method="POST" action="{{ route('customers.outreach.review', ['tenant' => $tenant->slug, 'profile' => $profile->id, 'campaign' => $campaign->id]) }}" class="space-y-3">
                    @csrf<input type="hidden" name="action" value="send"><input type="hidden" name="request_id" value="{{ old('action') === 'send' ? old('request_id', $review['send_request_id']) : $review['send_request_id'] }}"><input type="hidden" name="reviewed_content_hash" value="{{ $review['content_hash'] }}">
                    <label class="block"><input type="checkbox" name="confirm_send" value="1" required> Send this approved message to this customer through the existing {{ $review['channel'] === 'email' ? 'email' : 'SMS' }} sender now.</label>
                    <button class="rounded border p-2" type="submit">Send approved message</button>
                </form>
            @else<p class="text-sm">Sending is unavailable in the current state. No message is sent by opening this page or approving content.</p>@endif
        </section>
        <section class="space-y-3 rounded border p-4" aria-label="Original approval and delivery history">
            <h2 class="text-xl font-semibold">Original approval and delivery history</h2>
            <ul>@forelse($review['approvals'] as $approval)<li>Approval #{{ $approval['id'] }}: {{ $approval['status'] }} · {{ $approval['approved_at'] ?? $approval['rejected_at'] ?? 'Date not recorded' }}</li>@empty<li>No content approval recorded.</li>@endforelse</ul>
            <ul>@forelse($review['deliveries'] as $delivery)<li>{{ ucfirst($delivery['channel']) }} delivery #{{ $delivery['id'] }}: <strong>{{ $delivery['status'] }}</strong> · {{ $delivery['created_at'] ?? 'Date not recorded' }}@if($delivery['delivered_at']) · Delivered {{ $delivery['delivered_at'] }}@elseif($delivery['sent_at']) · Sender recorded {{ $delivery['sent_at'] }}@endif</li>@empty<li>No original delivery row is recorded.</li>@endforelse</ul>
            <p class="text-sm">Sending, unknown, rejected, completed and existing-delivery states are preserved. This page does not provide force retry or replace an uncertain delivery.</p>
        </section>
        <details class="rounded border p-4"><summary>Original preparation evidence</summary>
            @if($review['prepared_evidence'])<p>Prepared {{ data_get($review, 'prepared_evidence.prepared_at', 'at an unknown date') }}. Basis: {{ str(data_get($review, 'prepared_evidence.why_prepared', 'unknown'))->replace('_', ' ') }}.</p>
                <p>Personalization fields: {{ implode(', ', data_get($review, 'prepared_evidence.personalization_variable_names', [])) ?: 'None recorded' }}.</p>
                <ul class="list-disc pl-5">@foreach(data_get($review, 'prepared_evidence.quality.issues', []) as $issue)<li>{{ str($issue)->replace('_', ' ') }}</li>@endforeach</ul>
                <ul>@forelse(data_get($review, 'prepared_evidence.purchase_evidence', []) as $purchase)<li>{{ $purchase['source'] ?? 'Unknown source' }} {{ $purchase['source_id'] ?? '' }} · {{ ($purchase['confirmed'] ?? false) ? 'Confirmed original purchase' : 'Not confirmed' }} · {{ $purchase['occurred_at'] ?? 'Date unknown' }}</li>@empty<li>No linked purchase evidence was included.</li>@endforelse</ul>
            @else<p>Original preparation evidence is unavailable. Prepare a new reviewed draft.</p>@endif
        </details>
    </main>
</x-layouts::app>

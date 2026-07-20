<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-xl font-semibold text-zinc-900">{{ $agreement->title }}</h1>
                <p class="mt-1 text-sm text-zinc-600">{{ $agreement->tenant->name }} ·
                    {{ str_replace('_', ' ', $agreement->status) }} · version
                    {{ $agreement->currentVersion?->version_number }}</p>
            </div><a href="{{ route('landlord.agreements.index') }}"
                class="rounded-lg border border-zinc-300 px-4 py-2 text-sm font-semibold">All agreements</a>
        </div>
    </x-slot>
    <div class="space-y-6">
        @if (session('status'))
            <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-900">
                {{ session('status') }}</div>
        @endif
        @if (session('proposal_access'))
            <div class="rounded-2xl border-2 border-amber-400 bg-amber-50 p-5">
                <h2 class="font-semibold text-amber-950">Copy access details now</h2>
                <p class="mt-2 break-all text-sm"><strong>URL:</strong> {{ session('proposal_access.url') }}</p>
                <p class="mt-1 break-all text-sm"><strong>Password:</strong> {{ session('proposal_access.password') }}
                </p>
                <p class="mt-2 text-xs text-amber-800">The plaintext password is not stored and will not be shown again.
                </p>
            </div>
        @endif
        <div class="grid gap-4 lg:grid-cols-3">
            <section class="rounded-2xl border border-zinc-200 bg-white p-5">
                <h2 class="font-semibold">Version evidence</h2>
                <p class="mt-2 break-all font-mono text-xs text-zinc-600">
                    {{ $agreement->currentVersion?->content_hash }}</p>
                <p class="mt-2 text-sm text-zinc-600">{{ $agreement->versions->count() }} immutable version(s)</p>
                @if (!in_array($agreement->status, ['active', 'accepted', 'termination_pending', 'terminated']))
                    <a href="{{ route('landlord.agreements.edit', $agreement) }}"
                        class="mt-4 inline-flex rounded-lg border px-3 py-2 text-sm font-semibold">Edit pricing /
                        version</a>
                @endif
            </section>
            <section class="rounded-2xl border border-zinc-200 bg-white p-5">
                <h2 class="font-semibold">Send agreement</h2>
                <p class="mt-2 text-sm text-zinc-600">
                    {{ $agreement->access_revoked_at ? 'Revoked' : ($agreement->access_expires_at ? 'Expires ' . $agreement->access_expires_at->toDayDateTimeString() : 'Not sent') }}
                </p>
                @if ($agreement->email_sent_at)
                    <p class="mt-1 text-xs text-zinc-500">Last emailed to {{ $agreement->recipient_email }} ·
                        {{ $agreement->email_sent_at->toDayDateTimeString() }}</p>
                    @endif @if ($proposalUrl)
                        <a href="{{ $proposalUrl }}" target="_blank" rel="noopener noreferrer"
                            class="mt-2 block break-all text-xs font-medium text-emerald-800 hover:underline">{{ $proposalUrl }}</a>
                        @endif @if (!in_array($agreement->status, ['active', 'accepted', 'termination_pending', 'terminated']))
                            <form method="post" action="{{ route('landlord.agreements.send', $agreement) }}"
                                class="mt-4 space-y-2">@csrf<input name="recipient_email" type="email"
                                    value="{{ old('recipient_email', $agreement->recipient_email ?: $ownerEmail) }}" required
                                    placeholder="Recipient email"
                                    class="w-full rounded-lg border-zinc-300 text-sm"><input name="password"
                                    placeholder="Optional 10+ character password"
                                    class="w-full rounded-lg border-zinc-300 text-sm"><input name="expires_in_days"
                                    type="number" min="1" max="90" value="14"
                                    class="w-full rounded-lg border-zinc-300 text-sm"><button
                                    class="w-full rounded-lg bg-zinc-950 px-3 py-2 text-sm font-semibold text-white">Send
                                    agreement email</button>
                                <p class="text-xs text-zinc-500">Sending rotates the secure link and password, then
                                    emails both to the recipient.</p>
                            </form>
                            <form method="post" action="{{ route('landlord.agreements.send-text', $agreement) }}" class="mt-3 space-y-2 border-t border-zinc-100 pt-3">
                                @csrf
                                <input name="recipient_phone" type="tel" value="{{ old('recipient_phone', $agreement->recipient_phone) }}" required placeholder="Recipient mobile number" class="w-full rounded-lg border-zinc-300 text-sm">
                                <input name="expires_in_days" type="number" min="1" max="90" value="14" class="w-full rounded-lg border-zinc-300 text-sm">
                                <button class="w-full rounded-lg border border-emerald-700 px-3 py-2 text-sm font-semibold text-emerald-800">Text agreement link + code</button>
                                <p class="text-xs text-zinc-500">Use this only for the person the agreement is made for. It sends the secure link and one-time access code together.</p>
                            </form>
                            @if (!$agreement->access_revoked_at && $agreement->sent_at)
                                <form method="post" action="{{ route('landlord.agreements.revoke', $agreement) }}"
                                    class="mt-2">@csrf<button
                                        class="w-full rounded-lg border border-red-300 px-3 py-2 text-sm font-semibold text-red-700">Revoke
                                        link</button></form>
                            @endif
                        @endif
            </section>
            <section class="rounded-2xl border border-zinc-200 bg-white p-5">
                <h2 class="font-semibold">Acceptance</h2>
                @if ($agreement->acceptance)
                    <p class="mt-2 text-sm text-zinc-600">{{ $agreement->acceptance->signer_legal_name }} ·
                        {{ $agreement->acceptance->accepted_at->toDayDateTimeString() }}</p><a
                        href="{{ route('landlord.agreements.download', $agreement) }}"
                        class="mt-4 inline-flex rounded-lg bg-emerald-800 px-3 py-2 text-sm font-semibold text-white">Download
                    snapshot</a>@else<p class="mt-2 text-sm text-zinc-600">Not accepted. Billing remains disabled.
                    </p>
                @endif
            </section>
        </div>
        <section class="rounded-3xl border border-zinc-200 bg-white p-6 shadow-sm">{!! $agreement->currentVersion?->rendered_content !!}</section>
        @if ($agreement->agreement_type === \App\Models\Agreement::TYPE_SANDBOX_VALIDATION)
            <div
                class="mb-5 rounded-xl border-2 border-amber-500 bg-amber-100 p-4 text-sm font-semibold text-amber-950">
                TEST MODE ONLY — validation evidence is visible to operators but hidden from the tenant workspace.</div>
        @endif
        <div class="grid gap-5 lg:grid-cols-2">
            <section class="rounded-2xl border border-zinc-200 bg-white p-5">
                <h2 class="font-semibold">Internal notes</h2>
                <form method="post" action="{{ route('landlord.agreements.notes', $agreement) }}" class="mt-3">@csrf
                    <textarea name="internal_notes" rows="5" class="w-full rounded-lg border-zinc-300 text-sm">{{ $agreement->internal_notes }}</textarea><button
                        class="mt-2 rounded-lg border px-3 py-2 text-sm font-semibold">Save private notes</button>
                </form>
                <p class="mt-2 text-xs text-zinc-500">Never visible in tenant User Agreements.</p>
            </section>
            <section class="rounded-2xl border border-zinc-200 bg-white p-5">
                <h2 class="font-semibold">Termination and export</h2>
                @if (in_array($agreement->status, ['active', 'accepted']))
                    <form method="post" action="{{ route('landlord.agreements.termination', $agreement) }}"
                        class="mt-3 space-y-2">@csrf
                        <textarea name="reason" rows="2" placeholder="Reason or operational note"
                            class="w-full rounded-lg border-zinc-300 text-sm"></textarea><input name="effective_at" type="date"
                            class="w-full rounded-lg border-zinc-300 text-sm"><button
                            class="rounded-lg border border-red-300 px-3 py-2 text-sm font-semibold text-red-700">Schedule
                            termination</button>
                    </form>
                @elseif($agreement->termination)
                    <p class="mt-2 text-sm text-zinc-600">Effective
                        {{ $agreement->termination->effective_at?->toFormattedDateString() }} · export window ends
                        {{ $agreement->termination->export_window_ends_at?->toFormattedDateString() }}</p>
                    <form method="post" action="{{ route('landlord.agreements.export', $agreement) }}"
                        class="mt-3 flex gap-2">@csrf<select name="export_status"
                            class="rounded-lg border-zinc-300 text-sm">
                            <option value="requested">Requested</option>
                            <option value="completed">Completed</option>
                        </select><input name="export_reference" placeholder="Export reference"
                            class="min-w-0 flex-1 rounded-lg border-zinc-300 text-sm"><button
                        class="rounded-lg border px-3 py-2 text-sm font-semibold">Update</button></form>@else<p
                        class="mt-2 text-sm text-zinc-500">Available after acceptance.</p>
                    @endif @if (in_array($agreement->status, ['active', 'termination_pending']))
                        <form method="post" action="{{ route('landlord.agreements.amendment', $agreement) }}"
                            class="mt-4">@csrf<button class="rounded-lg border px-3 py-2 text-sm font-semibold">Create
                                amendment</button></form>
                    @endif
            </section>
        </div>
        @if (in_array($agreement->status, ['active', 'termination_pending']) &&
                !in_array($agreement->agreement_type, [
                    'supplemental_work',
                    'milestone',
                    \App\Models\Agreement::TYPE_SANDBOX_VALIDATION,
                ]))
            <section class="rounded-2xl border border-zinc-200 bg-white p-5">
                <h2 class="font-semibold">Separate approved work</h2>
                <p class="mt-2 text-sm text-zinc-600">Create a child work order. The customer must sign it and complete
                    a separate payment; no saved method is charged automatically.</p>
                @if (
                    (int) data_get(
                        $agreement->currentVersion?->pricing_payload,
                        'implementation_payment_schedule.due_before_launch_cents',
                        0) > 0)
                    <form method="post" action="{{ route('landlord.agreements.milestone', $agreement) }}"
                        class="mt-4 rounded-xl border border-emerald-200 bg-emerald-50 p-4">@csrf<p
                            class="text-sm text-emerald-950">Accepted due-before-launch milestone:
                            <strong>${{ number_format(data_get($agreement->currentVersion?->pricing_payload, 'implementation_payment_schedule.due_before_launch_cents', 0) / 100, 2) }}</strong>
                        </p><button
                            class="mt-3 rounded-lg bg-emerald-800 px-4 py-2 text-sm font-semibold text-white">Prepare
                            milestone agreement</button></form>
                @endif
                <form method="post" action="{{ route('landlord.agreements.supplemental-work', $agreement) }}"
                    class="mt-4 space-y-3">
                    @csrf
                    <textarea name="description" required rows="4" placeholder="Exact additional scope and deliverable"
                        class="w-full rounded-lg border-zinc-300 text-sm"></textarea>
                    <div class="grid gap-3 sm:grid-cols-3"><select name="pricing_type"
                            class="rounded-lg border-zinc-300 text-sm">
                            <option value="fixed">Fixed price</option>
                            <option value="hourly">Approved hours × $50</option>
                        </select><input name="fixed_amount" type="number" min="0.01" step="0.01"
                            placeholder="Fixed amount ($)" class="rounded-lg border-zinc-300 text-sm"><input
                            name="approved_hours" type="number" min="0.01" step="0.01"
                            placeholder="Approved hours" class="rounded-lg border-zinc-300 text-sm"></div><button
                        class="rounded-lg bg-zinc-950 px-4 py-2 text-sm font-semibold text-white">Prepare work
                        order</button>
                </form>
            </section>
        @endif
        <section class="rounded-2xl border border-zinc-200 bg-white p-5">
            <h2 class="font-semibold">Billing orders</h2>
            <div class="mt-3 space-y-2">
                @forelse($agreement->billingOrders as $order)
                    <div class="rounded-lg border border-zinc-200 p-3 text-sm">
                        <div class="flex justify-between gap-3"><span
                                class="font-medium">{{ str_replace('_', ' ', $order->order_type) }}</span><span>{{ str_replace('_', ' ', $order->status) }}</span>
                        </div>
                        <p class="mt-1 text-zinc-600">Authorized
                            ${{ number_format($order->authorized_subtotal_cents / 100, 2) }} ·
                            {{ strtoupper($order->currency) }}</p>
                </div>@empty<p class="text-sm text-zinc-500">Created after the exact agreement version is accepted.
                    </p>
                @endforelse
            </div>
        </section>
        <section class="rounded-2xl border border-zinc-200 bg-white p-5">
            <h2 class="font-semibold">Append-only event history</h2>
            <div class="mt-3 space-y-2">
                @foreach ($agreement->events->sortByDesc('id') as $event)
                    <div class="flex justify-between gap-4 border-t border-zinc-100 pt-2 text-sm">
                        <span>{{ str_replace('_', ' ', $event->event_type) }}</span><span
                            class="text-zinc-500">{{ $event->created_at?->toDayDateTimeString() }}</span></div>
                @endforeach
            </div>
        </section>
    </div>
</x-app-layout>

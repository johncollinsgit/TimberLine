<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <p class="text-[11px] font-bold tracking-[0.22em] text-sky-700">EVERBRANCH · AGREEMENT STUDIO</p>
                <h1 class="mt-1 text-2xl font-semibold tracking-tight text-zinc-950">{{ $agreement->title }}</h1>
                <p class="mt-1 text-sm text-zinc-600">{{ $agreement->tenant->name }} · {{ str_replace('_', ' ', $agreement->status) }} · version {{ $agreement->currentVersion?->version_number }}</p>
            </div>
            <a href="{{ route('landlord.agreements.index') }}" class="agreement-back-link">All agreements</a>
        </div>
    </x-slot>
    <div class="agreement-launch space-y-8">
        @if (session('status'))
            <div class="agreement-notice agreement-notice-success">
                {{ session('status') }}</div>
        @endif
        @if (session('status_error'))
            <div class="agreement-notice agreement-notice-error">{{ session('status_error') }}</div>
        @endif
        @if (session('proposal_access'))
            <div class="agreement-access-card">
                <h2 class="font-semibold text-zinc-950">Copy access details now</h2>
                <p class="mt-2 break-all text-sm"><strong>URL:</strong> {{ session('proposal_access.url') }}</p>
                <p class="mt-1 break-all text-sm"><strong>Password:</strong> {{ session('proposal_access.password') }}
                </p>
                <p class="mt-2 text-xs text-zinc-600">The plaintext password is not stored and will not be shown again.</p>
            </div>
        @endif
        <section class="agreement-hero">
            <div class="relative z-10 grid gap-8 lg:grid-cols-[minmax(0,1.35fr)_minmax(17rem,.65fr)]">
                <div>
                    <p class="agreement-eyebrow">A simple, secure handoff</p>
                    <h2 class="mt-2 text-3xl font-semibold tracking-tight text-zinc-950 sm:text-4xl">Send this agreement beautifully.</h2>
                    <p class="mt-3 max-w-2xl text-base leading-7 text-zinc-600">Choose email or text. Every recipient gets the same private link and one-time access code.</p>
                </div>
                <div class="agreement-status-orb">
                    <span class="agreement-status-dot"></span>
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-[0.16em] text-zinc-500">Delivery status</p>
                        <p class="mt-1 text-lg font-semibold text-zinc-900">{{ $agreement->access_revoked_at ? 'Link revoked' : ($agreement->access_expires_at ? 'Link is live' : 'Ready to send') }}</p>
                        @if ($agreement->access_expires_at)
                            <p class="mt-1 text-xs text-zinc-500">Expires {{ $agreement->access_expires_at->toDayDateTimeString() }}</p>
                        @endif
                    </div>
                </div>
            </div>

            @if ($agreement->email_sent_at)
                <p class="agreement-last-sent">Last emailed to {{ $agreement->recipient_email }} · {{ $agreement->email_sent_at->toDayDateTimeString() }}</p>
            @endif
            @if ($proposalUrl)
                <a href="{{ $proposalUrl }}" target="_blank" rel="noopener noreferrer" class="agreement-link-preview">Open the current secure agreement link ↗</a>
            @endif

            @if (!in_array($agreement->status, ['active', 'accepted', 'termination_pending', 'terminated']))
                <div class="mt-7 grid gap-5 xl:grid-cols-2">
                    <form method="post" action="{{ route('landlord.agreements.send', $agreement) }}" class="agreement-delivery-card">
                        @csrf
                        <div class="flex items-start gap-3">
                            <span class="agreement-channel-icon">@</span>
                            <div><h3 class="font-semibold text-zinc-950">Send by email</h3><p class="mt-1 text-sm text-zinc-500">A private invitation with the link and access code.</p></div>
                        </div>
                        <div class="mt-5 space-y-3">
                            <label class="agreement-field-label" for="agreement-recipient-email">Recipient email</label>
                            <input id="agreement-recipient-email" name="recipient_email" type="email" value="{{ old('recipient_email', $agreement->recipient_email ?: $ownerEmail) }}" required placeholder="owner@example.com" class="agreement-input">
                            <div class="grid gap-3 sm:grid-cols-[minmax(0,1fr)_9rem]">
                                <div><label class="agreement-field-label" for="agreement-password">Access code <span>optional</span></label><input id="agreement-password" name="password" placeholder="Create a 10+ character code" class="agreement-input"></div>
                                <div><label class="agreement-field-label" for="agreement-email-expires">Expires</label><input id="agreement-email-expires" name="expires_in_days" type="number" min="1" max="90" value="14" class="agreement-input"></div>
                            </div>
                            <button class="agreement-primary-button">Send agreement email</button>
                        </div>
                    </form>

                    <form method="post" action="{{ route('landlord.agreements.send-text', $agreement) }}" class="agreement-delivery-card agreement-delivery-card-text">
                        @csrf
                        <div class="flex items-start gap-3">
                            <span class="agreement-channel-icon agreement-channel-icon-text">✦</span>
                            <div><h3 class="font-semibold text-zinc-950">Send by text</h3><p class="mt-1 text-sm text-zinc-500">One private link and code, sent to the people you choose.</p></div>
                        </div>
                        <div class="mt-5 space-y-3">
                            <label class="agreement-field-label" for="agreement-recipient-phone">Mobile numbers <span>separate with commas</span></label>
                            <input id="agreement-recipient-phone" name="recipient_phone" type="tel" inputmode="tel" autocomplete="tel" maxlength="255" value="{{ old('recipient_phone', $agreement->recipient_phone) }}" required placeholder="(864) 640-6642, (864) 555-0123" class="agreement-input">
                            <div class="flex flex-wrap items-center justify-between gap-2 text-xs text-zinc-500"><span id="agreement-recipient-count">No recipients selected</span><span>Up to 10 people</span></div>
                            <div class="grid gap-3 sm:grid-cols-[9rem_minmax(0,1fr)]">
                                <div><label class="agreement-field-label" for="agreement-text-expires">Expires</label><input id="agreement-text-expires" name="expires_in_days" type="number" min="1" max="90" value="14" class="agreement-input"></div>
                                <p class="self-end pb-1 text-xs leading-5 text-zinc-500">The secure link stops working after the selected number of days.</p>
                            </div>
                            <div class="agreement-message-composer">
                                <div class="flex items-center justify-between gap-3"><label class="agreement-field-label" for="agreement-message-intro">Text message <span>edit before sending</span></label><span id="agreement-message-count" class="text-xs text-zinc-500"></span></div>
                                <textarea id="agreement-message-intro" name="message_intro" rows="3" maxlength="240" class="agreement-input agreement-message-input" data-default-message="Hi! {{ $agreement->tenant->name }}: your Everbranch workspace is ready." placeholder="Hi! {{ $agreement->tenant->name }}: your Everbranch workspace is ready.">{{ old('message_intro', $agreement->agreement_sms_message) }}</textarea>
                                <p class="mt-2 text-xs leading-5 text-zinc-500">Use <code>{{ '{{tenant_name}}' }}</code> to insert the workspace name. The secure link and one-time code are always added separately.</p>
                                <label class="agreement-field-label mt-4" for="agreement-message-image">Optional image <span>sends as MMS</span></label>
                                <input id="agreement-message-image" name="image_url" type="url" inputmode="url" value="{{ old('image_url', $agreement->agreement_mms_image_url) }}" placeholder="https://…/service-card.jpg" class="agreement-input">
                                <p class="mt-2 text-xs leading-5 text-zinc-500">Paste a public image URL. You can see it before it is sent; remove the URL to send a normal text only.</p>
                                <div class="agreement-phone-preview" aria-live="polite">
                                    <div class="agreement-phone-preview-bar"><span></span><strong>Message preview</strong><span>•••</span></div>
                                    <img id="agreement-message-image-preview" class="hidden" alt="Selected message image preview">
                                    <div id="agreement-message-preview" class="agreement-message-bubble"></div>
                                    <p class="agreement-preview-note">The live link and code are inserted only when you send.</p>
                                </div>
                            </div>
                            <button class="agreement-text-button">Text agreement link + code</button>
                        </div>
                    </form>
                </div>
                @if (!$agreement->access_revoked_at && $agreement->sent_at)
                    <form method="post" action="{{ route('landlord.agreements.revoke', $agreement) }}" class="mt-5">@csrf<button class="agreement-revoke-link">Revoke the current secure link</button></form>
                @endif
            @endif
        </section>

        <div class="grid gap-3 md:grid-cols-2">
            <details class="agreement-details">
                <summary>View version evidence</summary>
                <div class="agreement-details-body">
                    <p class="break-all font-mono text-xs text-zinc-600">{{ $agreement->currentVersion?->content_hash }}</p>
                    <p class="mt-2 text-sm text-zinc-600">{{ $agreement->versions->count() }} immutable version(s)</p>
                    @if (!in_array($agreement->status, ['active', 'accepted', 'termination_pending', 'terminated']))
                        <a href="{{ route('landlord.agreements.edit', $agreement) }}" class="agreement-subtle-button">Edit pricing / version</a>
                    @endif
                </div>
            </details>
            <details class="agreement-details">
                <summary>View acceptance details</summary>
                <div class="agreement-details-body">
                    @if ($agreement->acceptance)
                        <p class="text-sm text-zinc-600">{{ $agreement->acceptance->signer_legal_name }} · {{ $agreement->acceptance->accepted_at->toDayDateTimeString() }}</p>
                        <a href="{{ route('landlord.agreements.download', $agreement) }}" class="agreement-subtle-button">Download signed snapshot</a>
                    @else
                        <p class="text-sm text-zinc-600">Not accepted. Billing remains disabled.</p>
                    @endif
                </div>
            </details>
        </div>
        <section class="agreement-document">{!! $agreement->currentVersion?->rendered_content !!}</section>
        @if ($agreement->agreement_type === \App\Models\Agreement::TYPE_SANDBOX_VALIDATION)
            <div
                class="mb-5 rounded-xl border-2 border-amber-500 bg-amber-100 p-4 text-sm font-semibold text-amber-950">
                TEST MODE ONLY — validation evidence is visible to operators but hidden from the tenant workspace.</div>
        @endif
        <div class="agreement-secondary-grid grid gap-5 lg:grid-cols-2">
            <section class="agreement-panel">
                <h2 class="font-semibold">Internal notes</h2>
                <form method="post" action="{{ route('landlord.agreements.notes', $agreement) }}" class="mt-3">@csrf
                    <textarea name="internal_notes" rows="5" class="w-full rounded-lg border-zinc-300 text-sm">{{ $agreement->internal_notes }}</textarea><button
                        class="mt-2 rounded-lg border px-3 py-2 text-sm font-semibold">Save private notes</button>
                </form>
                <p class="mt-2 text-xs text-zinc-500">Never visible in tenant User Agreements.</p>
            </section>
            <section class="agreement-panel">
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
            <section class="agreement-panel">
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
        <section class="agreement-panel">
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
        <section class="agreement-panel">
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
    <script>
        (() => {
            const text = document.getElementById('agreement-message-intro');
            const image = document.getElementById('agreement-message-image');
            const preview = document.getElementById('agreement-message-preview');
            const imagePreview = document.getElementById('agreement-message-image-preview');
            const count = document.getElementById('agreement-message-count');
            if (!text || !image || !preview || !imagePreview || !count) return;
            const tenant = @json($agreement->tenant->name);
            const placeholderLink = 'https://evergrovesoftware.com/a/{{ $agreement->id }}/••••••••••••••••';
            const refresh = () => {
                const intro = (text.value.trim() || text.dataset.defaultMessage).replaceAll('{{tenant_name}}', tenant);
                preview.textContent = intro + ' Open, approve & pay: ' + placeholderLink + ' Code: ••••••••••';
                count.textContent = `${intro.length}/240`;
                const url = image.value.trim();
                imagePreview.classList.toggle('hidden', !url);
                imagePreview.src = url || '';
            };
            text.addEventListener('input', refresh);
            image.addEventListener('input', refresh);
            imagePreview.addEventListener('error', () => imagePreview.classList.add('hidden'));
            refresh();
        })();
    </script>
    <style>
        .agreement-launch {
            position: relative;
            isolation: isolate;
        }
        .agreement-launch::before {
            background: radial-gradient(circle at 14% 2%, rgba(125, 211, 252, .30), transparent 25rem), radial-gradient(circle at 88% 18%, rgba(186, 230, 253, .35), transparent 27rem);
            content: '';
            inset: -3rem -2rem auto;
            height: 38rem;
            pointer-events: none;
            position: absolute;
            z-index: -1;
        }
        .agreement-back-link, .agreement-subtle-button, .agreement-revoke-link {
            border: 1px solid rgba(148, 163, 184, .48);
            border-radius: 999px;
            box-shadow: inset 0 1px 0 rgba(255,255,255,.95), 0 1px 2px rgba(15,23,42,.08);
            color: #334155;
            display: inline-flex;
            font-size: .875rem;
            font-weight: 650;
            padding: .55rem 1rem;
            text-decoration: none;
            transition: transform .18s ease, box-shadow .18s ease;
        }
        .agreement-back-link:hover, .agreement-subtle-button:hover, .agreement-revoke-link:hover { box-shadow: inset 0 1px 0 #fff, 0 7px 18px rgba(15,23,42,.12); transform: translateY(-1px); }
        .agreement-notice { border-radius: 1rem; box-shadow: 0 10px 30px rgba(15,23,42,.07); font-size: .9rem; padding: 1rem 1.15rem; }
        .agreement-notice-success { background: linear-gradient(180deg, #f0fdf4, #dcfce7); border: 1px solid #86efac; color: #14532d; }
        .agreement-notice-error { background: linear-gradient(180deg, #fff7ed, #ffedd5); border: 1px solid #fdba74; color: #9a3412; }
        .agreement-access-card { background: linear-gradient(180deg, rgba(255,255,255,.95), rgba(239,246,255,.88)); border: 1px solid rgba(125,211,252,.7); border-radius: 1.4rem; box-shadow: inset 0 1px 0 white, 0 15px 35px rgba(14,116,144,.10); padding: 1.25rem; }
        .agreement-hero { background: linear-gradient(145deg, rgba(255,255,255,.98) 0%, rgba(240,249,255,.97) 47%, rgba(224,242,254,.9) 100%); border: 1px solid rgba(186,230,253,.95); border-radius: 2rem; box-shadow: inset 0 1px 1px rgba(255,255,255,.95), 0 24px 55px rgba(14,116,144,.14); overflow: hidden; padding: clamp(1.5rem, 4vw, 3.25rem); position: relative; }
        .agreement-hero::after { background: linear-gradient(115deg, transparent 24%, rgba(255,255,255,.78) 42%, transparent 57%); content: ''; height: 165%; left: -35%; pointer-events: none; position: absolute; top: -57%; transform: rotate(8deg); width: 56%; }
        .agreement-eyebrow { color: #0369a1; font-size: .7rem; font-weight: 800; letter-spacing: .2em; text-transform: uppercase; }
        .agreement-status-orb { align-items: center; background: rgba(255,255,255,.72); border: 1px solid rgba(255,255,255,.96); border-radius: 1.35rem; box-shadow: inset 0 1px 0 #fff, 0 12px 30px rgba(14,116,144,.1); display: flex; gap: .75rem; padding: 1rem; }
        .agreement-status-dot { background: linear-gradient(180deg, #34d399, #059669); border: 3px solid #d1fae5; border-radius: 999px; box-shadow: 0 0 0 3px rgba(16,185,129,.12); height: .9rem; width: .9rem; }
        .agreement-last-sent { color: #64748b; font-size: .78rem; margin-top: 1.2rem; position: relative; z-index: 1; }
        .agreement-link-preview { color: #0369a1; display: inline-flex; font-size: .82rem; font-weight: 700; margin-top: .55rem; position: relative; text-decoration: none; z-index: 1; }
        .agreement-link-preview:hover { text-decoration: underline; }
        .agreement-delivery-card { backdrop-filter: blur(14px); background: rgba(255,255,255,.72); border: 1px solid rgba(186,230,253,.85); border-radius: 1.5rem; box-shadow: inset 0 1px 0 #fff, 0 14px 32px rgba(15,23,42,.07); padding: 1.25rem; position: relative; z-index: 1; }
        .agreement-delivery-card-text { background: linear-gradient(145deg, rgba(255,255,255,.9), rgba(236,253,245,.86)); border-color: rgba(110,231,183,.9); }
        .agreement-channel-icon { align-items: center; background: linear-gradient(180deg, #0ea5e9, #0369a1); border: 2px solid rgba(255,255,255,.75); border-radius: 999px; box-shadow: 0 4px 12px rgba(3,105,161,.28), inset 0 1px 1px rgba(255,255,255,.55); color: #fff; display: inline-flex; font-size: 1.1rem; font-weight: 800; height: 2.45rem; justify-content: center; width: 2.45rem; }
        .agreement-channel-icon-text { background: linear-gradient(180deg, #34d399, #047857); font-size: 1rem; }
        .agreement-field-label { color: #334155; display: block; font-size: .74rem; font-weight: 750; letter-spacing: .025em; margin-bottom: .35rem; }
        .agreement-field-label span { color: #64748b; font-weight: 500; }
        .agreement-input { background: rgba(255,255,255,.88); border: 1px solid #cbd5e1; border-radius: .8rem; box-shadow: inset 0 1px 2px rgba(15,23,42,.05), 0 1px 0 rgba(255,255,255,.86); color: #0f172a; font-size: .92rem; min-height: 2.7rem; padding: .6rem .75rem; transition: border-color .18s ease, box-shadow .18s ease; width: 100%; }
        .agreement-input:focus { border-color: #38bdf8; box-shadow: 0 0 0 4px rgba(56,189,248,.18), inset 0 1px 2px rgba(15,23,42,.04); outline: none; }
        .agreement-primary-button, .agreement-text-button { border-radius: .85rem; box-shadow: inset 0 1px 0 rgba(255,255,255,.35), 0 6px 13px rgba(3,105,161,.22); color: #fff; font-size: .9rem; font-weight: 750; min-height: 2.75rem; transition: filter .18s ease, transform .18s ease; width: 100%; }
        .agreement-primary-button { background: linear-gradient(180deg, #0ea5e9, #0369a1); }
        .agreement-text-button { background: linear-gradient(180deg, #10b981, #047857); box-shadow: inset 0 1px 0 rgba(255,255,255,.38), 0 6px 13px rgba(4,120,87,.2); }
        .agreement-primary-button:hover, .agreement-text-button:hover { filter: brightness(1.05); transform: translateY(-1px); }
        .agreement-message-composer { border-top: 1px solid rgba(16,185,129,.18); margin-top: 1.25rem; padding-top: 1.1rem; }
        .agreement-message-input { min-height: 5.25rem; resize: vertical; }
        .agreement-phone-preview { background: linear-gradient(180deg,#f2f3f7,#fff); border: 1px solid #d7dce4; border-radius: 1.2rem; margin-top: 1rem; overflow: hidden; padding: .75rem; }
        .agreement-phone-preview-bar { align-items:center; color:#7b8493; display:flex; font-size:.7rem; justify-content:space-between; letter-spacing:.02em; margin:0 0 .65rem; }
        .agreement-phone-preview-bar strong { color:#485261; font-size:.72rem; }
        .agreement-phone-preview img { border-radius:.8rem; display:block; max-height:13rem; object-fit:cover; width:100%; }
        .agreement-message-bubble { background:#0b84ff; border-radius:1.05rem 1.05rem .3rem 1.05rem; color:white; font-size:.82rem; line-height:1.45; margin-left:auto; max-width:94%; padding:.7rem .8rem; white-space:pre-wrap; word-break:break-word; }
        .agreement-preview-note { color:#7b8493; font-size:.68rem; line-height:1.35; margin:.55rem .1rem 0; }
        .agreement-revoke-link { color: #b91c1c; font-size: .8rem; }
        .agreement-details { background: rgba(255,255,255,.66); border: 1px solid rgba(203,213,225,.85); border-radius: 1rem; box-shadow: inset 0 1px 0 rgba(255,255,255,.85); padding: .1rem 1rem; }
        .agreement-details summary { color: #0369a1; cursor: pointer; font-size: .84rem; font-weight: 700; list-style: none; padding: .85rem 0; }
        .agreement-details summary::-webkit-details-marker { display: none; }
        .agreement-details summary::after { content: '⌄'; float: right; font-size: 1rem; transition: transform .18s ease; }
        .agreement-details[open] summary::after { transform: rotate(180deg); }
        .agreement-details-body { border-top: 1px solid #e2e8f0; padding: .9rem 0 1rem; }
        .agreement-details-body .agreement-subtle-button { margin-top: .85rem; }
        .agreement-document, .agreement-panel { background: linear-gradient(180deg, rgba(255,255,255,.96), rgba(248,250,252,.94)); border: 1px solid rgba(203,213,225,.82); box-shadow: inset 0 1px 0 #fff, 0 16px 36px rgba(15,23,42,.06); }
        .agreement-document { border-radius: 2rem; padding: clamp(1.35rem, 3vw, 2.4rem); }
        .agreement-panel { border-radius: 1.3rem; padding: 1.25rem; }
        .agreement-panel h2 { color: #0f172a; font-size: 1rem; font-weight: 750; }
        .agreement-panel button:not(.agreement-primary-button):not(.agreement-text-button) { border-radius: .75rem; }
    </style>
    <script>
        (() => {
            const field = document.getElementById('agreement-recipient-phone');
            if (!field) return;
            const formatNumber = (value) => {
                let digits = value.replace(/\D/g, '');
                if (digits.length === 11 && digits.startsWith('1')) digits = digits.slice(1);
                digits = digits.slice(0, 10);
                if (digits.length < 4) return digits;
                if (digits.length < 7) return `(${digits.slice(0, 3)}) ${digits.slice(3)}`;
                return `(${digits.slice(0, 3)}) ${digits.slice(3, 6)}-${digits.slice(6)}`;
            };
            const recipientCount = document.getElementById('agreement-recipient-count');
            const format = (value) => value.split(',').map((phone) => formatNumber(phone.trim())).join(', ');
            const update = () => {
                field.value = format(field.value);
                const count = field.value.split(',').map((phone) => phone.trim()).filter(Boolean).length;
                if (recipientCount) recipientCount.textContent = count ? `${count} recipient${count === 1 ? '' : 's'} selected` : 'No recipients selected';
            };
            update();
            field.addEventListener('input', update);
        })();
    </script>
</x-app-layout>

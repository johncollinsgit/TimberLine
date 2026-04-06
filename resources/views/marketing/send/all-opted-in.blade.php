<x-layouts::app :title="'Send to All Opted-In'">
    @php
        $selection = old('channel', $channelSelection ?? 'both');
        $smsCount = (int) ($audience['sms'] ?? 0);
        $emailCount = (int) ($audience['email'] ?? 0);
        $overlapCount = (int) ($audience['overlap'] ?? 0);
        $uniqueCount = (int) ($audience['unique'] ?? 0);
        $deliveryTotal = (int) ($audience['delivery_total'] ?? 0);
        $selectedUnique = (int) ($audience['selected_unique'] ?? 0);
        $selectedSenderKey = old('sender_key', $defaultSmsSenderKey ?? '');
    @endphp

    <div class="mx-auto w-full max-w-[1200px] px-3 py-4 sm:px-4 sm:py-6 md:px-6 space-y-6 min-w-0">
        <x-marketing.partials.section-shell
            :section="$section"
            :sections="$sections"
            title="Send to All Opted-In"
            description="Quick send to all SMS and email subscribers using the existing marketing send system."
            hint-title="What this does"
            hint-text="This page skips the segment/group wizard. It uses canonical marketing profiles, existing consent flags, and the normal Twilio/SendGrid delivery services."
        />

        @if($sendResult)
            <section class="rounded-3xl border border-emerald-300/30 bg-emerald-100 p-5 sm:p-6 space-y-4">
                <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h2 class="text-lg font-semibold text-emerald-900">Send complete</h2>
                        <p class="mt-1 text-sm text-emerald-800">
                            {{ strtoupper((string) ($sendResult['selection'] ?? 'both')) }} send finished across {{ number_format((int) data_get($sendResult, 'counts.selected_unique', 0)) }} unique profiles.
                        </p>
                    </div>
                </div>
                <div class="grid gap-3 md:grid-cols-3">
                    @foreach((array) ($sendResult['campaigns'] ?? []) as $campaign)
                        <article class="rounded-2xl border border-zinc-200 bg-zinc-50 p-4">
                            <div class="flex items-start justify-between gap-3">
                                <div>
                                    <div class="text-sm font-semibold text-zinc-950">{{ $campaign['name'] }}</div>
                                    <div class="mt-1 text-xs uppercase tracking-[0.18em] text-emerald-800">{{ strtoupper((string) ($campaign['channel'] ?? '')) }}</div>
                                </div>
                                <a href="{{ route('marketing.campaigns.show', $campaign['id']) }}" wire:navigate class="inline-flex rounded-full border border-emerald-300/25 bg-emerald-100 px-3 py-1 text-xs font-semibold text-emerald-900">Open</a>
                            </div>
                            <div class="mt-3 grid grid-cols-2 gap-2 text-xs text-zinc-700">
                                <div class="rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2">Recipients {{ number_format((int) ($campaign['recipient_count'] ?? 0)) }}</div>
                                <div class="rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2">Sent {{ number_format((int) data_get($campaign, 'summary.sent', 0)) }}</div>
                                <div class="rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2">Failed {{ number_format((int) data_get($campaign, 'summary.failed', 0)) }}</div>
                                <div class="rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2">Skipped {{ number_format((int) data_get($campaign, 'summary.skipped', 0)) }}</div>
                            </div>
                        </article>
                    @endforeach
                </div>
            </section>
        @endif

        @if($testResult)
            <section class="rounded-3xl border border-sky-300/30 bg-sky-100 p-5 sm:p-6 space-y-4">
                <div>
                    <h2 class="text-lg font-semibold text-sky-900">Test send result</h2>
                    <p class="mt-1 text-sm text-sky-800">These sends used the same provider validation as a live send, but only to your supplied test destinations.</p>
                </div>
                <div class="grid gap-3 md:grid-cols-2">
                    @foreach((array) ($testResult['results'] ?? []) as $channel => $result)
                        <article class="rounded-2xl border border-sky-200/15 bg-zinc-50 p-4">
                            <div class="flex items-start justify-between gap-3">
                                <div>
                                    <div class="text-sm font-semibold text-zinc-950">{{ strtoupper((string) $channel) }}</div>
                                    <div class="mt-1 text-xs text-zinc-500">Status {{ strtoupper((string) ($result['status'] ?? 'unknown')) }}</div>
                                </div>
                                <div class="text-xs font-semibold {{ ($result['success'] ?? false) ? 'text-emerald-900' : 'text-rose-900' }}">
                                    {{ ($result['success'] ?? false) ? 'Success' : 'Failed' }}
                                </div>
                            </div>
                            @if(!empty($result['error_message']))
                                <p class="mt-3 text-sm text-rose-800">{{ $result['error_message'] }}</p>
                            @endif
                        </article>
                    @endforeach
                </div>
            </section>
        @endif

        <section class="rounded-3xl border border-zinc-200 bg-zinc-50 p-5 sm:p-6 space-y-5">
            <div class="flex flex-col gap-4 xl:flex-row xl:items-end xl:justify-between">
                <div>
                    <h2 class="text-lg font-semibold text-zinc-950">Audience</h2>
                    <p class="mt-1 text-sm text-zinc-600">Counts are built from canonical <code>marketing_profiles</code> using existing consent flags and sendable contact checks.</p>
                </div>
                <div class="rounded-2xl border border-zinc-200 bg-zinc-50 px-4 py-3 text-sm text-zinc-700">
                    Current selection will send {{ number_format($deliveryTotal) }} deliveries to {{ number_format($selectedUnique) }} unique profiles.
                </div>
            </div>

            <div class="grid gap-3 md:grid-cols-4">
                <article class="rounded-2xl border border-zinc-200 bg-zinc-50 p-4">
                    <div class="text-xs uppercase tracking-[0.2em] text-zinc-500">SMS</div>
                    <div class="mt-2 text-2xl font-semibold text-zinc-950">{{ number_format($smsCount) }}</div>
                    <div class="mt-1 text-xs text-zinc-500">Opted-in + sendable phone</div>
                </article>
                <article class="rounded-2xl border border-zinc-200 bg-zinc-50 p-4">
                    <div class="text-xs uppercase tracking-[0.2em] text-zinc-500">Email</div>
                    <div class="mt-2 text-2xl font-semibold text-zinc-950">{{ number_format($emailCount) }}</div>
                    <div class="mt-1 text-xs text-zinc-500">Opted-in + valid email</div>
                </article>
                <article class="rounded-2xl border border-zinc-200 bg-zinc-50 p-4">
                    <div class="text-xs uppercase tracking-[0.2em] text-zinc-500">Overlap</div>
                    <div class="mt-2 text-2xl font-semibold text-zinc-950">{{ number_format($overlapCount) }}</div>
                    <div class="mt-1 text-xs text-zinc-500">Can receive both</div>
                </article>
                <article class="rounded-2xl border border-zinc-200 bg-zinc-50 p-4">
                    <div class="text-xs uppercase tracking-[0.2em] text-zinc-500">Unique</div>
                    <div class="mt-2 text-2xl font-semibold text-zinc-950">{{ number_format($uniqueCount) }}</div>
                    <div class="mt-1 text-xs text-zinc-500">Across SMS or email</div>
                </article>
            </div>
        </section>

        <section class="rounded-3xl border border-zinc-200 bg-zinc-50 p-5 sm:p-6">
            <form method="POST" action="{{ route('marketing.send.all-opted-in.submit') }}" class="space-y-5" id="all-opted-in-form">
                @csrf
                <input type="hidden" name="confirmation_token" value="{{ $confirmationToken }}">

                <div class="space-y-3">
                    <div>
                        <h2 class="text-lg font-semibold text-zinc-950">Message</h2>
                        <p class="mt-1 text-sm text-zinc-600">Choose the channel, write the message, optionally add one link, test it, then send.</p>
                    </div>
                    <div class="grid gap-3 md:grid-cols-3">
                        @foreach(['sms' => 'SMS only', 'email' => 'Email only', 'both' => 'Both SMS + Email'] as $value => $label)
                            <label class="rounded-2xl border border-zinc-200 bg-zinc-50 p-4 text-sm text-zinc-800">
                                <input type="radio" name="channel" value="{{ $value }}" class="sr-only quick-send-channel" @checked($selection === $value)>
                                <span class="font-semibold">{{ $label }}</span>
                            </label>
                        @endforeach
                    </div>
                </div>

                <div class="grid gap-5 xl:grid-cols-[minmax(0,1fr),minmax(0,1fr)]">
                    <div class="space-y-4">
                        <div>
                            <label class="text-xs uppercase tracking-[0.2em] text-zinc-500">SMS Body</label>
                            <textarea name="sms_body" rows="6" class="mt-1 w-full rounded-2xl border border-zinc-200 bg-zinc-50 px-4 py-3 text-sm text-zinc-950" placeholder="Write the text message here...">{{ old('sms_body') }}</textarea>
                            @error('sms_body')<div class="mt-2 text-sm text-rose-200">{{ $message }}</div>@enderror
                        </div>

                        <div>
                            <label class="text-xs uppercase tracking-[0.2em] text-zinc-500">SMS Sender</label>
                            <select name="sender_key" class="mt-1 w-full rounded-2xl border border-zinc-200 bg-zinc-50 px-4 py-3 text-sm text-zinc-950">
                                @foreach($smsSenders as $sender)
                                    <option value="{{ $sender['key'] }}" @selected($selectedSenderKey === $sender['key']) @disabled(empty($sender['sendable']))>
                                        {{ $sender['label'] }} · {{ $sender['type'] }} · {{ $sender['status'] }}{{ empty($sender['sendable']) ? ' (not sendable yet)' : '' }}
                                    </option>
                                @endforeach
                            </select>
                            <div class="mt-2 grid gap-2">
                                @foreach($smsSenders as $sender)
                                    <div class="rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2 text-xs text-zinc-600">
                                        <span class="font-semibold text-zinc-950">{{ $sender['label'] }}</span>
                                        · {{ $sender['identity_label'] ?? 'Not configured' }}
                                        · {{ strtoupper((string) ($sender['status'] ?? 'active')) }}
                                        @if(!empty($sender['is_default']))
                                            · DEFAULT
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                        </div>

                        <div id="email-fields" class="space-y-4">
                            <div>
                                <label class="text-xs uppercase tracking-[0.2em] text-zinc-500">Email Subject</label>
                                <input type="text" name="email_subject" value="{{ old('email_subject') }}" class="mt-1 w-full rounded-2xl border border-zinc-200 bg-zinc-50 px-4 py-3 text-sm text-zinc-950" placeholder="Write the email subject here...">
                                @error('email_subject')<div class="mt-2 text-sm text-rose-200">{{ $message }}</div>@enderror
                            </div>
                            <div>
                                <label class="text-xs uppercase tracking-[0.2em] text-zinc-500">Email Body</label>
                                <textarea name="email_body" rows="8" class="mt-1 w-full rounded-2xl border border-zinc-200 bg-zinc-50 px-4 py-3 text-sm text-zinc-950" placeholder="Write the email body here...">{{ old('email_body') }}</textarea>
                                @error('email_body')<div class="mt-2 text-sm text-rose-200">{{ $message }}</div>@enderror
                            </div>
                        </div>

                        <div>
                            <label class="text-xs uppercase tracking-[0.2em] text-zinc-500">Optional Link</label>
                            <input type="url" name="cta_link" value="{{ old('cta_link') }}" class="mt-1 w-full rounded-2xl border border-zinc-200 bg-zinc-50 px-4 py-3 text-sm text-zinc-950" placeholder="https://...">
                            <div class="mt-2 text-xs text-zinc-500">If you add a link, it will be appended to the message body and routed through the normal tracking flow for supported sends.</div>
                            @error('cta_link')<div class="mt-2 text-sm text-rose-200">{{ $message }}</div>@enderror
                        </div>
                    </div>

                    <div class="space-y-4">
                        <section class="rounded-2xl border border-zinc-200 bg-zinc-50 p-4 space-y-4">
                            <div>
                                <h3 class="text-base font-semibold text-zinc-950">Test Send</h3>
                                <p class="mt-1 text-sm text-zinc-600">Use your own destinations before the full send.</p>
                            </div>
                            <div id="test-phone-wrap">
                                <label class="text-xs uppercase tracking-[0.2em] text-zinc-500">Test SMS Phone</label>
                                <input type="text" name="test_phone" value="{{ old('test_phone') }}" class="mt-1 w-full rounded-2xl border border-zinc-200 bg-zinc-50 px-4 py-3 text-sm text-zinc-950" placeholder="(555) 555-5555">
                                @error('test_phone')<div class="mt-2 text-sm text-rose-200">{{ $message }}</div>@enderror
                            </div>
                            <div id="test-email-wrap">
                                <label class="text-xs uppercase tracking-[0.2em] text-zinc-500">Test Email</label>
                                <input type="email" name="test_email" value="{{ $defaultTestEmail }}" class="mt-1 w-full rounded-2xl border border-zinc-200 bg-zinc-50 px-4 py-3 text-sm text-zinc-950" placeholder="you@example.com">
                                @error('test_email')<div class="mt-2 text-sm text-rose-200">{{ $message }}</div>@enderror
                            </div>
                            <button type="submit" name="intent" value="test" class="inline-flex w-full items-center justify-center rounded-full border border-sky-300/30 bg-sky-100 px-4 py-2.5 text-sm font-semibold text-sky-900">
                                Send Test
                            </button>
                        </section>

                        <section class="rounded-2xl border border-amber-300/20 bg-amber-100 p-4 space-y-4">
                            <div>
                                <h3 class="text-base font-semibold text-zinc-950">Final Send</h3>
                                <p class="mt-1 text-sm text-zinc-600">This creates tracked campaign records behind the scenes and sends through the normal Twilio/SendGrid flow.</p>
                            </div>
                            <label class="inline-flex items-start gap-3 text-sm text-zinc-700">
                                <input type="checkbox" name="confirm_send" value="1" class="mt-1 rounded border-zinc-300 bg-zinc-50">
                                <span>I want to send this to all opted-in recipients shown above.</span>
                            </label>
                            @error('confirm_send')<div class="text-sm text-rose-200">{{ $message }}</div>@enderror
                            <button type="submit" name="intent" value="send" class="inline-flex w-full items-center justify-center rounded-full border border-amber-300/30 bg-amber-100 px-4 py-2.5 text-sm font-semibold text-amber-900">
                                Send to All Opted-In Customers
                            </button>
                        </section>
                    </div>
                </div>
            </form>
        </section>

        <script>
            (() => {
                const channelInputs = Array.from(document.querySelectorAll('.quick-send-channel'));
                const emailFields = document.getElementById('email-fields');
                const testPhoneWrap = document.getElementById('test-phone-wrap');
                const testEmailWrap = document.getElementById('test-email-wrap');

                if (!channelInputs.length || !emailFields || !testPhoneWrap || !testEmailWrap) return;

                const sync = () => {
                    const selected = channelInputs.find((input) => input.checked)?.value || 'both';
                    const showEmail = selected === 'email' || selected === 'both';
                    const showSms = selected === 'sms' || selected === 'both';

                    emailFields.style.display = showEmail ? '' : 'none';
                    testEmailWrap.style.display = showEmail ? '' : 'none';
                    testPhoneWrap.style.display = showSms ? '' : 'none';

                    channelInputs.forEach((input) => {
                        const card = input.closest('label');
                        if (!card) return;
                        card.classList.toggle('border-zinc-300', input.checked);
                        card.classList.toggle('bg-emerald-100', input.checked);
                    });
                };

                channelInputs.forEach((input) => input.addEventListener('change', sync));
                sync();
            })();
        </script>
    </div>
</x-layouts::app>

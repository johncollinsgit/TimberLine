<x-layouts::app :title="'Send Internal Group Message'">
    @php
        $selectedSenderKey = old('sender_key', $defaultSmsSenderKey ?? '');
    @endphp
    <div class="mx-auto w-full max-w-[1200px] px-3 py-4 sm:px-4 sm:py-6 md:px-6 space-y-6 min-w-0">
        <x-marketing.partials.section-shell
            :section="$section"
            :sections="$sections"
            title="Send Internal Group Message"
            description="Direct-send workflow for internal groups without campaign approval queue."
            hint-title="Bypass guardrails"
            hint-text="This bypass is internal-group only. Consent and contact eligibility checks still run per profile before provider execution."
        />

        <section class="rounded-3xl border border-amber-300/30 bg-amber-500/10 p-5 sm:p-6">
            <h2 class="text-lg font-semibold text-amber-100">{{ $group->name }}</h2>
            <p class="mt-1 text-sm text-amber-50/90">
                Members: {{ $memberCount }} · Internal send bypass enabled.
            </p>
        </section>

        <section class="rounded-3xl border border-white/10 bg-black/15 p-5 sm:p-6">
            <form method="POST" action="{{ route('marketing.groups.send.execute', $group) }}" class="space-y-4">
                @csrf
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label class="text-xs uppercase tracking-[0.2em] text-white/55">Channel</label>
                        <select name="channel" id="group_send_channel" class="mt-1 w-full rounded-xl border border-white/10 bg-white/5 px-3 py-2 text-sm text-white">
                            <option value="sms" @selected(old('channel') === 'sms')>SMS</option>
                            <option value="email" @selected(old('channel') === 'email')>Email</option>
                        </select>
                    </div>
                    <div id="email_subject_wrap">
                        <label class="text-xs uppercase tracking-[0.2em] text-white/55">Email Subject</label>
                        <input type="text" name="subject" value="{{ old('subject') }}" class="mt-1 w-full rounded-xl border border-white/10 bg-white/5 px-3 py-2 text-sm text-white">
                    </div>
                </div>

                <div id="sms_sender_wrap">
                    <label class="text-xs uppercase tracking-[0.2em] text-white/55">SMS Sender</label>
                    <select name="sender_key" class="mt-1 w-full rounded-xl border border-white/10 bg-white/5 px-3 py-2 text-sm text-white">
                        @foreach($smsSenders as $sender)
                            <option value="{{ $sender['key'] }}" @selected($selectedSenderKey === $sender['key']) @disabled(empty($sender['sendable']))>
                                {{ $sender['label'] }} · {{ $sender['type'] }} · {{ $sender['status'] }}{{ empty($sender['sendable']) ? ' (not sendable yet)' : '' }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label class="text-xs uppercase tracking-[0.2em] text-white/55">Message</label>
                    <textarea name="message" rows="5" required class="mt-1 w-full rounded-xl border border-white/10 bg-white/5 px-3 py-2 text-sm text-white">{{ old('message') }}</textarea>
                </div>

                <label class="inline-flex items-center gap-2 text-sm text-white/75">
                    <input type="checkbox" name="dry_run" value="1" class="rounded border-white/20 bg-white/5">
                    Dry run (simulate provider success)
                </label>

                <div class="flex flex-wrap gap-3">
                    <button type="submit" class="inline-flex rounded-full border border-amber-300/35 bg-amber-500/20 px-4 py-2 text-sm font-semibold text-amber-100">
                        Send To Internal Group
                    </button>
                    <a href="{{ route('marketing.groups.show', $group) }}" wire:navigate class="inline-flex rounded-full border border-white/15 bg-white/5 px-4 py-2 text-sm font-semibold text-white/80">
                        Back To Group
                    </a>
                </div>
            </form>
        </section>

        <script>
            (() => {
                const channel = document.getElementById('group_send_channel');
                const subjectWrap = document.getElementById('email_subject_wrap');
                const senderWrap = document.getElementById('sms_sender_wrap');
                if (!channel || !subjectWrap || !senderWrap) return;

                const toggle = () => {
                    const isEmail = channel.value === 'email';
                    subjectWrap.style.display = isEmail ? '' : 'none';
                    senderWrap.style.display = isEmail ? 'none' : '';
                };

                channel.addEventListener('change', toggle);
                toggle();
            })();
        </script>
    </div>
</x-layouts::app>

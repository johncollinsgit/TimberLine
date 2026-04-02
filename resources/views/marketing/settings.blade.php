<x-layouts::app :title="$currentSection['label']">
    @php
        $settingsSenders = data_get($settingsDashboard, 'senders', []);
        $settingsDefaultSender = data_get($settingsDashboard, 'default_sender', []);
    @endphp

    <div class="mx-auto w-full max-w-[1800px] px-3 py-4 sm:px-4 sm:py-6 md:px-6 space-y-6 min-w-0">
        <x-marketing.partials.section-shell
            :section="$currentSection"
            :sections="$sections"
        />

        <section class="rounded-3xl border border-zinc-200 bg-zinc-50 p-5 sm:p-6 space-y-4">
            <x-admin.help-hint tone="neutral" title="SMS sender configuration">
                Sender numbers are environment-driven so production can stage future numbers safely. This page shows every configured sender and lets Backstage choose the default enabled sender.
            </x-admin.help-hint>
            <div class="grid gap-4 md:grid-cols-4">
                <article class="rounded-2xl border border-zinc-200 bg-zinc-50 p-4">
                    <div class="text-xs uppercase tracking-[0.2em] text-zinc-500">SMS Enabled</div>
                    <div class="mt-2 text-2xl font-semibold text-zinc-950">{{ data_get($settingsDashboard, 'sms_enabled') ? 'Yes' : 'No' }}</div>
                </article>
                <article class="rounded-2xl border border-zinc-200 bg-zinc-50 p-4">
                    <div class="text-xs uppercase tracking-[0.2em] text-zinc-500">Twilio Enabled</div>
                    <div class="mt-2 text-2xl font-semibold text-zinc-950">{{ data_get($settingsDashboard, 'twilio_enabled') ? 'Yes' : 'No' }}</div>
                </article>
                <article class="rounded-2xl border border-zinc-200 bg-zinc-50 p-4">
                    <div class="text-xs uppercase tracking-[0.2em] text-zinc-500">Default Sender</div>
                    <div class="mt-2 text-lg font-semibold text-zinc-950">{{ data_get($settingsDefaultSender, 'label', 'None') }}</div>
                    <div class="mt-1 text-xs text-zinc-500">{{ data_get($settingsDashboard, 'default_source', 'config') === 'marketing_settings' ? 'Saved in Backstage settings' : 'Using config default' }}</div>
                </article>
                <article class="rounded-2xl border border-zinc-200 bg-zinc-50 p-4">
                    <div class="text-xs uppercase tracking-[0.2em] text-zinc-500">Status Callback</div>
                    <div class="mt-2 text-sm font-semibold text-zinc-950 break-all">{{ data_get($settingsDashboard, 'status_callback_url', 'Not configured') }}</div>
                </article>
            </div>
        </section>

        <section class="rounded-3xl border border-zinc-200 bg-zinc-50 p-5 sm:p-6">
            <form method="POST" action="{{ route('marketing.settings.save') }}" class="space-y-5">
                @csrf
                <input type="hidden" name="scope" value="sms_senders">

                <div class="flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
                    <div>
                        <h2 class="text-lg font-semibold text-zinc-950">Configured sender numbers</h2>
                        <p class="mt-1 text-sm text-zinc-600">Active numbers are sendable today. Pending numbers stay visible so the future local rollout is already represented in the app.</p>
                    </div>
                    @if(data_get($settingsDashboard, 'test_number'))
                        <div class="rounded-2xl border border-zinc-200 bg-zinc-50 px-4 py-3 text-sm text-zinc-700">
                            Test number: {{ data_get($settingsDashboard, 'test_number') }}
                        </div>
                    @endif
                </div>

                <div class="grid gap-4 lg:grid-cols-2">
                    @forelse($settingsSenders as $sender)
                        <label class="rounded-2xl border border-zinc-200 bg-zinc-50 p-4 text-sm text-zinc-950/82">
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div>
                                    <div class="flex flex-wrap items-center gap-2">
                                        <span class="text-base font-semibold text-zinc-950">{{ $sender['label'] }}</span>
                                        <span class="rounded-full border border-zinc-200 bg-zinc-50 px-2 py-1 text-[11px] uppercase tracking-[0.16em] text-zinc-500">{{ $sender['type'] }}</span>
                                        <span class="rounded-full border px-2 py-1 text-[11px] uppercase tracking-[0.16em] {{ ($sender['status'] ?? '') === 'active' ? 'border-emerald-300/30 bg-emerald-100 text-emerald-900' : 'border-amber-300/30 bg-amber-100 text-amber-900' }}">{{ $sender['status'] }}</span>
                                        @if(!empty($sender['is_default']))
                                            <span class="rounded-full border border-sky-300/30 bg-sky-100 px-2 py-1 text-[11px] uppercase tracking-[0.16em] text-sky-900">Default</span>
                                        @endif
                                    </div>
                                    <div class="mt-3 text-sm text-zinc-600">{{ $sender['identity_label'] ?? 'Not configured yet' }}</div>
                                    @if(!empty($sender['phone_number_sid']))
                                        <div class="mt-2 text-xs text-zinc-500">Phone SID: {{ $sender['phone_number_sid'] }}</div>
                                    @endif
                                </div>
                                <div class="rounded-full border border-zinc-200 bg-zinc-50 px-3 py-2 text-xs text-zinc-600">
                                    <input type="radio" name="default_sender_key" value="{{ $sender['key'] }}" class="mr-2" @checked(!empty($sender['is_default'])) @disabled(empty($sender['sendable']))>
                                    Default send
                                </div>
                            </div>
                            @if(empty($sender['sendable']))
                                <div class="mt-3 text-xs text-amber-800">This sender is visible in the system but not sendable yet.</div>
                            @endif
                        </label>
                    @empty
                        <div class="rounded-2xl border border-zinc-200 bg-zinc-50 p-4 text-sm text-zinc-500">
                            No Twilio sender identities are configured yet.
                        </div>
                    @endforelse
                </div>

                <div class="flex items-center justify-end">
                    <button type="submit" class="inline-flex rounded-full border border-zinc-300 bg-emerald-100 px-4 py-2 text-sm font-semibold text-zinc-950">
                        Save Default Sender
                    </button>
                </div>
            </form>
        </section>
    </div>
</x-layouts::app>

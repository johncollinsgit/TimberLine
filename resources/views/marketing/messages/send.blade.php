<x-layouts::app :title="'Marketing Messages'">
    <div class="mx-auto w-full max-w-[1800px] px-3 py-4 sm:px-4 sm:py-6 md:px-6 space-y-6 min-w-0">
        <x-marketing.partials.section-shell
            :section="$section"
            :sections="$sections"
            title="Messaging Wizard"
            description="Internal SMS wizard for direct sends with search-built audiences, reusable groups, and delivery tracking."
            hint-title="How to use this wizard"
            hint-text="Complete audience selection first, then compose the message, review recipient counts, run an optional test SMS, and confirm send."
        />

        <section class="rounded-3xl border border-white/10 bg-black/15 p-4 sm:p-5">
            @php
                $currentStep = (int) ($state['step'] ?? 1);
                $steps = [
                    ['n' => 1, 'label' => 'Audience'],
                    ['n' => 2, 'label' => 'Message'],
                    ['n' => 3, 'label' => 'Review'],
                    ['n' => 4, 'label' => 'Send'],
                ];
            @endphp
            <div class="flex flex-wrap items-center gap-2">
                @foreach($steps as $step)
                    <div class="inline-flex items-center gap-2 rounded-full border px-3 py-1.5 text-xs font-semibold {{ $currentStep >= $step['n'] ? 'border-emerald-300/40 bg-emerald-500/20 text-emerald-50' : 'border-white/15 bg-white/5 text-white/70' }}">
                        <span class="inline-flex size-5 items-center justify-center rounded-full border {{ $currentStep >= $step['n'] ? 'border-emerald-200/60 bg-emerald-500/30 text-white' : 'border-white/20 text-white/60' }}">{{ $step['n'] }}</span>
                        <span>{{ $step['label'] }}</span>
                    </div>
                @endforeach
            </div>
        </section>

        <section class="rounded-3xl border border-white/10 bg-black/15 p-4 sm:p-5 space-y-4" x-data="{ audienceType: '{{ $state['audience_type'] ?? 'single_customer' }}' }">
            <div class="flex items-center justify-between">
                <h2 class="text-lg font-semibold text-white">Step 1: Audience</h2>
                <form method="POST" action="{{ route('marketing.messages.reset') }}">
                    @csrf
                    <button type="submit" class="rounded-xl border border-white/15 bg-white/5 px-3 py-1.5 text-xs font-semibold text-white/80 hover:bg-white/10">
                        Reset Wizard
                    </button>
                </form>
            </div>

            <form method="GET" action="{{ route('marketing.messages.send') }}" class="grid gap-3 md:grid-cols-12">
                <div class="md:col-span-10">
                    <label for="customer_search" class="text-xs uppercase tracking-[0.2em] text-white/55">Customer Search</label>
                    <input
                        id="customer_search"
                        name="customer_search"
                        value="{{ $search }}"
                        placeholder="Search by customer name, email, or phone"
                        class="mt-1 w-full rounded-xl border border-white/10 bg-white/5 px-3 py-2 text-sm text-white placeholder:text-white/35"
                    />
                </div>
                <div class="md:col-span-2 flex items-end">
                    <button type="submit" class="w-full rounded-xl border border-sky-300/35 bg-sky-500/15 px-3 py-2 text-sm font-semibold text-white">
                        Search
                    </button>
                </div>
            </form>

            <form method="POST" action="{{ route('marketing.messages.save-audience') }}" class="space-y-4">
                @csrf
                <input type="hidden" name="customer_search" value="{{ $search }}">

                <div class="grid gap-2 md:grid-cols-2 xl:grid-cols-5">
                    @php
                        $options = [
                            'single_customer' => 'Single Customer',
                            'segment' => 'Marketing Segment',
                            'existing_group' => 'Saved Group',
                            'custom_group' => 'Custom Group',
                            'manual' => 'Manual Numbers',
                        ];
                    @endphp
                    @foreach($options as $key => $label)
                        <label class="flex cursor-pointer items-start gap-2 rounded-xl border border-white/15 bg-white/5 p-3 text-sm text-white/85">
                            <input
                                type="radio"
                                name="audience_type"
                                value="{{ $key }}"
                                x-model="audienceType"
                                @checked(($state['audience_type'] ?? 'single_customer') === $key)
                                class="mt-1"
                            />
                            <span>{{ $label }}</span>
                        </label>
                    @endforeach
                </div>

                <div x-show="audienceType === 'single_customer'" class="rounded-2xl border border-white/10 bg-white/5 p-3">
                    <div class="text-xs uppercase tracking-[0.2em] text-white/55">Single Customer Selection</div>
                    @if($searchResults->isEmpty())
                        <p class="mt-2 text-sm text-white/60">Search for a customer, then choose one result.</p>
                    @else
                        <div class="mt-2 max-h-72 overflow-auto rounded-xl border border-white/10">
                            <table class="min-w-full text-sm">
                                <thead class="bg-white/5 text-white/60">
                                    <tr>
                                        <th class="px-3 py-2 text-left">Pick</th>
                                        <th class="px-3 py-2 text-left">Customer</th>
                                        <th class="px-3 py-2 text-left">Email</th>
                                        <th class="px-3 py-2 text-left">Phone</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-white/10">
                                    @foreach($searchResults as $profile)
                                        @php $name = trim((string) ($profile->first_name . ' ' . $profile->last_name)); @endphp
                                        <tr>
                                            <td class="px-3 py-2">
                                                <input
                                                    type="radio"
                                                    name="selected_profile_id"
                                                    value="{{ $profile->id }}"
                                                    @checked((int) ($state['selected_profile_id'] ?? 0) === (int) $profile->id)
                                                />
                                            </td>
                                            <td class="px-3 py-2 text-white/85">{{ $name !== '' ? $name : 'Unnamed profile' }}</td>
                                            <td class="px-3 py-2 text-white/70">{{ $profile->email ?: '—' }}</td>
                                            <td class="px-3 py-2 text-white/70">{{ $profile->phone ?: '—' }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>

                <div x-show="audienceType === 'segment'" class="rounded-2xl border border-white/10 bg-white/5 p-3">
                    <label for="segment_id" class="text-xs uppercase tracking-[0.2em] text-white/55">Marketing Segment</label>
                    <select id="segment_id" name="segment_id" class="mt-2 w-full rounded-xl border border-white/10 bg-black/20 px-3 py-2 text-sm text-white">
                        <option value="">Select segment</option>
                        @foreach($segments as $segment)
                            <option value="{{ $segment->id }}" @selected((int) ($state['segment_id'] ?? 0) === (int) $segment->id)>
                                {{ $segment->name }} @if($segment->channel_scope) ({{ $segment->channel_scope }}) @endif
                            </option>
                        @endforeach
                    </select>
                </div>

                <div x-show="audienceType === 'existing_group'" class="rounded-2xl border border-white/10 bg-white/5 p-3">
                    <label for="group_id" class="text-xs uppercase tracking-[0.2em] text-white/55">Saved Group</label>
                    <select id="group_id" name="group_id" class="mt-2 w-full rounded-xl border border-white/10 bg-black/20 px-3 py-2 text-sm text-white">
                        <option value="">Select group</option>
                        @foreach($groups as $group)
                            <option value="{{ $group->id }}" @selected((int) ($state['group_id'] ?? 0) === (int) $group->id)>
                                {{ $group->name }} ({{ $group->members_count }} members){{ !$group->is_reusable ? ' · ad-hoc' : '' }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <div x-show="audienceType === 'custom_group'" class="rounded-2xl border border-white/10 bg-white/5 p-3 space-y-3">
                    <div class="text-xs uppercase tracking-[0.2em] text-white/55">Custom Group Builder</div>
                    @foreach($selectedProfiles as $profile)
                        <input type="hidden" name="selected_profile_ids[]" value="{{ $profile->id }}">
                    @endforeach
                    @if($searchResults->isNotEmpty())
                        <div class="max-h-72 overflow-auto rounded-xl border border-white/10">
                            <table class="min-w-full text-sm">
                                <thead class="bg-white/5 text-white/60">
                                    <tr>
                                        <th class="px-3 py-2 text-left">Add</th>
                                        <th class="px-3 py-2 text-left">Customer</th>
                                        <th class="px-3 py-2 text-left">Email</th>
                                        <th class="px-3 py-2 text-left">Phone</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-white/10">
                                    @foreach($searchResults as $profile)
                                        @php
                                            $name = trim((string) ($profile->first_name . ' ' . $profile->last_name));
                                            $checked = in_array((int) $profile->id, (array) ($state['selected_profile_ids'] ?? []), true);
                                        @endphp
                                        <tr>
                                            <td class="px-3 py-2">
                                                <input type="checkbox" name="selected_profile_ids[]" value="{{ $profile->id }}" @checked($checked) />
                                            </td>
                                            <td class="px-3 py-2 text-white/85">{{ $name !== '' ? $name : 'Unnamed profile' }}</td>
                                            <td class="px-3 py-2 text-white/70">{{ $profile->email ?: '—' }}</td>
                                            <td class="px-3 py-2 text-white/70">{{ $profile->phone ?: '—' }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif

                    <div class="grid gap-3 md:grid-cols-2">
                        <div>
                            <label for="group_name" class="text-xs uppercase tracking-[0.2em] text-white/55">Group Name</label>
                            <input
                                id="group_name"
                                name="group_name"
                                value="{{ old('group_name', (string) ($state['group_name'] ?? '')) }}"
                                placeholder="VIP spring outreach"
                                class="mt-1 w-full rounded-xl border border-white/10 bg-black/20 px-3 py-2 text-sm text-white"
                            />
                        </div>
                        <div class="flex items-end">
                            <label class="inline-flex items-center gap-2 rounded-xl border border-white/10 bg-black/20 px-3 py-2 text-sm text-white/80">
                                <input type="checkbox" name="save_reusable_group" value="1" @checked((bool) ($state['save_reusable_group'] ?? false))>
                                Save as reusable group
                            </label>
                        </div>
                    </div>
                    <div>
                        <label for="group_description" class="text-xs uppercase tracking-[0.2em] text-white/55">Description (optional)</label>
                        <input
                            id="group_description"
                            name="group_description"
                            value="{{ old('group_description', (string) ($state['group_description'] ?? '')) }}"
                            placeholder="Created from spring launch customer search"
                            class="mt-1 w-full rounded-xl border border-white/10 bg-black/20 px-3 py-2 text-sm text-white"
                        />
                    </div>
                </div>

                <div x-show="audienceType === 'manual' || audienceType === 'custom_group'" class="rounded-2xl border border-white/10 bg-white/5 p-3">
                    <label for="manual_phones" class="text-xs uppercase tracking-[0.2em] text-white/55">
                        Manual Phone Numbers (comma/newline separated)
                    </label>
                    <textarea
                        id="manual_phones"
                        name="manual_phones"
                        rows="4"
                        class="mt-2 w-full rounded-xl border border-white/10 bg-black/20 px-3 py-2 text-sm text-white"
                        placeholder="+15551234567&#10;+15550000000"
                    >{{ old('manual_phones', (string) ($state['manual_phones'] ?? '')) }}</textarea>
                </div>

                <div>
                    <button type="submit" class="rounded-xl border border-emerald-300/35 bg-emerald-500/15 px-4 py-2 text-sm font-semibold text-white">
                        Save Audience and Continue
                    </button>
                </div>
            </form>
        </section>

        @if((int) ($state['step'] ?? 1) >= 2)
            @php
                $messageText = (string) ($state['message_text'] ?? '');
                $selectedTemplateId = (int) ($state['template_id'] ?? 0);
            @endphp
            <section class="rounded-3xl border border-white/10 bg-black/15 p-4 sm:p-5">
                <h2 class="text-lg font-semibold text-white">Step 2: Message</h2>
                <form method="POST" action="{{ route('marketing.messages.save-message') }}" class="mt-4 space-y-4" x-data="messageComposer(@js($messageText))">
                    @csrf
                    <div class="grid gap-3 md:grid-cols-3">
                        <div class="md:col-span-2">
                            <label for="template_id" class="text-xs uppercase tracking-[0.2em] text-white/55">Template (optional)</label>
                            <select id="template_id" name="template_id" class="mt-1 w-full rounded-xl border border-white/10 bg-white/5 px-3 py-2 text-sm text-white" @change="applyTemplate($event)">
                                <option value="">No template</option>
                                @foreach($templates as $template)
                                    <option value="{{ $template->id }}" data-template-text="{{ $template->template_text }}" @selected($selectedTemplateId === (int) $template->id)>
                                        {{ $template->name }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label for="send_at" class="text-xs uppercase tracking-[0.2em] text-white/55">Send Time</label>
                            <input
                                id="send_at"
                                type="datetime-local"
                                name="send_at"
                                value="{{ old('send_at', isset($state['send_at']) && $state['send_at'] ? \Illuminate\Support\Carbon::parse((string) $state['send_at'])->format('Y-m-d\TH:i') : '') }}"
                                class="mt-1 w-full rounded-xl border border-white/10 bg-white/5 px-3 py-2 text-sm text-white"
                            />
                        </div>
                    </div>

                    <div>
                        <label for="message_text" class="text-xs uppercase tracking-[0.2em] text-white/55">Message Body</label>
                        <textarea
                            id="message_text"
                            name="message_text"
                            rows="6"
                            x-model="text"
                            class="mt-1 w-full rounded-xl border border-white/10 bg-white/5 px-3 py-2 text-sm text-white"
                            placeholder="Write your SMS message here..."
                        >{{ old('message_text', $messageText) }}</textarea>
                        <div class="mt-2 flex flex-wrap items-center gap-3 text-xs text-white/60">
                            <span>Length: <span x-text="text.length"></span></span>
                            <span>Estimated Segments: <span x-text="segments"></span></span>
                        </div>
                    </div>

                    <div class="rounded-xl border border-white/10 bg-black/20 p-3">
                        <div class="text-xs uppercase tracking-[0.2em] text-white/55">Preview</div>
                        <p class="mt-2 whitespace-pre-wrap text-sm text-white/80" x-text="text || 'Message preview will appear here.'"></p>
                    </div>

                    <button type="submit" class="rounded-xl border border-emerald-300/35 bg-emerald-500/15 px-4 py-2 text-sm font-semibold text-white">
                        Save Message and Continue
                    </button>
                </form>
            </section>
        @endif

        @if((int) ($state['step'] ?? 1) >= 3 && $recipientCount > 0 && (string) ($state['message_text'] ?? '') !== '')
            <section class="rounded-3xl border border-white/10 bg-black/15 p-4 sm:p-5 space-y-4">
                <h2 class="text-lg font-semibold text-white">Step 3: Review</h2>
                <div class="grid gap-3 md:grid-cols-4">
                    <article class="rounded-xl border border-white/10 bg-white/5 p-3">
                        <div class="text-xs uppercase tracking-[0.2em] text-white/55">Recipients</div>
                        <div class="mt-1 text-lg font-semibold text-white">{{ number_format($recipientCount) }}</div>
                    </article>
                    <article class="rounded-xl border border-white/10 bg-white/5 p-3">
                        <div class="text-xs uppercase tracking-[0.2em] text-white/55">Segments / SMS</div>
                        <div class="mt-1 text-lg font-semibold text-white">{{ number_format($segmentsPerMessage) }}</div>
                    </article>
                    <article class="rounded-xl border border-white/10 bg-white/5 p-3">
                        <div class="text-xs uppercase tracking-[0.2em] text-white/55">Estimated Segments</div>
                        <div class="mt-1 text-lg font-semibold text-white">{{ number_format($estimatedSegments) }}</div>
                    </article>
                    <article class="rounded-xl border border-white/10 bg-white/5 p-3">
                        <div class="text-xs uppercase tracking-[0.2em] text-white/55">Send Time</div>
                        <div class="mt-1 text-sm font-semibold text-white">
                            {{ !empty($state['send_at']) ? \Illuminate\Support\Carbon::parse((string) $state['send_at'])->format('Y-m-d H:i') : 'Immediately' }}
                        </div>
                    </article>
                </div>

                <div class="rounded-xl border border-white/10 bg-black/20 p-3">
                    <div class="text-xs uppercase tracking-[0.2em] text-white/55">Message Preview</div>
                    <p class="mt-2 whitespace-pre-wrap text-sm text-white/85">{{ (string) ($state['message_text'] ?? '') }}</p>
                </div>

                <div class="rounded-xl border border-white/10 bg-black/20 p-3">
                    <div class="text-xs uppercase tracking-[0.2em] text-white/55">Recipient Sample</div>
                    <div class="mt-2 overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <thead class="text-white/60">
                                <tr>
                                    <th class="px-2 py-1 text-left">Name</th>
                                    <th class="px-2 py-1 text-left">Phone</th>
                                    <th class="px-2 py-1 text-left">Source</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-white/10">
                                @foreach(array_slice((array) ($state['recipients'] ?? []), 0, 10) as $recipient)
                                    <tr>
                                        <td class="px-2 py-1 text-white/80">{{ $recipient['name'] ?? '—' }}</td>
                                        <td class="px-2 py-1 text-white/80">{{ $recipient['phone'] ?? $recipient['normalized_phone'] ?? '—' }}</td>
                                        <td class="px-2 py-1 text-white/65">{{ $recipient['source_type'] ?? 'profile' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>

            <section class="rounded-3xl border border-white/10 bg-black/15 p-4 sm:p-5 space-y-4">
                <h2 class="text-lg font-semibold text-white">Step 4: Send</h2>

                <form method="POST" action="{{ route('marketing.messages.send-test') }}" class="grid gap-3 md:grid-cols-12">
                    @csrf
                    <div class="md:col-span-8">
                        <label for="test_phone" class="text-xs uppercase tracking-[0.2em] text-white/55">Send Test SMS To Me (phone)</label>
                        <input
                            id="test_phone"
                            name="test_phone"
                            value="{{ old('test_phone', (string) env('MARKETING_SMS_TEST_NUMBER', '')) }}"
                            placeholder="+15551234567"
                            class="mt-1 w-full rounded-xl border border-white/10 bg-white/5 px-3 py-2 text-sm text-white"
                        />
                    </div>
                    <div class="md:col-span-2 flex items-end">
                        <label class="inline-flex items-center gap-2 rounded-xl border border-white/10 bg-white/5 px-3 py-2 text-xs text-white/80">
                            <input type="checkbox" name="dry_run" value="1">
                            Dry Run
                        </label>
                    </div>
                    <div class="md:col-span-2 flex items-end">
                        <button type="submit" class="w-full rounded-xl border border-sky-300/35 bg-sky-500/15 px-3 py-2 text-sm font-semibold text-white">
                            Send Test
                        </button>
                    </div>
                </form>

                <form method="POST" action="{{ route('marketing.messages.execute') }}" onsubmit="return window.confirm('Send this message to the selected audience now?');" class="space-y-3">
                    @csrf
                    <div class="flex flex-wrap items-center gap-3">
                        <label class="inline-flex items-center gap-2 rounded-xl border border-white/10 bg-white/5 px-3 py-2 text-sm text-white/80">
                            <input type="checkbox" name="confirm_send" value="1" required>
                            I confirm this send
                        </label>
                        <label class="inline-flex items-center gap-2 rounded-xl border border-white/10 bg-white/5 px-3 py-2 text-sm text-white/80">
                            <input type="checkbox" name="dry_run" value="1">
                            Dry run only
                        </label>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <button type="submit" class="rounded-xl border border-emerald-300/35 bg-emerald-500/20 px-4 py-2 text-sm font-semibold text-white">
                            Send Message
                        </button>
                        <a href="{{ route('marketing.messages.deliveries') }}" wire:navigate class="rounded-xl border border-white/15 bg-white/5 px-4 py-2 text-sm font-semibold text-white/80 hover:bg-white/10">
                            Open Delivery Log
                        </a>
                    </div>
                </form>
            </section>
        @endif
    </div>

    <script>
        function messageComposer(initialText) {
            return {
                text: initialText || '',
                segments: 0,
                init() {
                    this.recalculate();
                    this.$watch('text', () => this.recalculate());
                },
                applyTemplate(event) {
                    const option = event.target.selectedOptions[0];
                    if (!option) return;
                    const templateText = option.dataset.templateText || '';
                    if (templateText && this.text.trim() === '') {
                        this.text = templateText;
                    }
                },
                recalculate() {
                    const text = (this.text || '').trim();
                    if (text === '') {
                        this.segments = 0;
                        return;
                    }
                    const gsmRegex = /^[\r\n !\"#$%&'()*+,\-.\/0-9:;<=>?@A-Z\[\]\\_a-z{|}~\^€£¥èéùìòÇØøÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉÄÖÑÜ§¿äöñüà]+$/u;
                    const length = [...text].length;
                    if (gsmRegex.test(text)) {
                        this.segments = length <= 160 ? 1 : Math.ceil(length / 153);
                        return;
                    }
                    this.segments = length <= 70 ? 1 : Math.ceil(length / 67);
                }
            };
        }
    </script>
</x-layouts::app>

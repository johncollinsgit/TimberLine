<x-layouts::app :title="'Send a Text'">
    <div class="mx-auto w-full max-w-5xl px-3 py-4 sm:px-5 sm:py-6 space-y-5">
        <header class="rounded-2xl border border-white/10 bg-white/[0.03] px-4 py-4 sm:px-5 sm:py-5">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="space-y-1">
                    <p class="text-[11px] uppercase tracking-[0.24em] text-white/45">Marketing Messages</p>
                    <h1 class="text-2xl font-semibold text-white">Send a text</h1>
                    <p class="text-sm text-white/70">Pick an audience, write the message, send a quick test, then launch.</p>
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    <a href="{{ $deliveryLogUrl }}" class="rounded-lg border border-white/15 bg-white/5 px-3 py-2 text-xs font-semibold text-white/80 hover:bg-white/10">
                        Delivery Log
                    </a>
                    <form method="POST" action="{{ route('marketing.messages.reset') }}">
                        @csrf
                        <button type="submit" class="rounded-lg border border-white/15 bg-white/5 px-3 py-2 text-xs font-semibold text-white/75 hover:bg-white/10">
                            Reset
                        </button>
                    </form>
                </div>
            </div>
        </header>

        <section class="rounded-2xl border border-white/10 bg-white/[0.02] px-3 py-3 sm:px-4">
            @php
                $steps = [
                    1 => 'Who',
                    2 => 'Message',
                    3 => 'Review',
                    4 => 'Send',
                ];
            @endphp
            <ol class="grid grid-cols-2 gap-2 sm:grid-cols-4">
                @foreach($steps as $number => $label)
                    <li class="rounded-xl border px-3 py-2 text-xs {{ $step === $number ? 'border-emerald-300/45 bg-emerald-400/12 text-white' : ($step > $number ? 'border-white/20 bg-white/[0.06] text-white/85' : 'border-white/10 bg-transparent text-white/45') }}">
                        <span class="font-semibold">{{ $number }}.</span> {{ $label }}
                    </li>
                @endforeach
            </ol>
        </section>

        @if($profileCount === 0)
            <section class="rounded-2xl border border-amber-300/30 bg-amber-500/10 px-4 py-3 text-sm text-amber-100">
                No customer profiles yet, so search will be empty. Run <code class="font-mono">php artisan marketing:sync-profiles</code> then refresh.
            </section>
        @endif

        @if($step === 1)
            <section class="rounded-2xl border border-white/10 bg-white/[0.03] px-4 py-4 sm:px-5 sm:py-5" x-data="{ audienceKind: '{{ old('audience_kind', (string) ($state['audience_kind'] ?? 'person')) }}', groupMode: '{{ old('group_mode', (string) ($state['group_mode'] ?? 'saved')) }}' }">
                <div class="space-y-1">
                    <h2 class="text-xl font-semibold text-white">Who are we texting?</h2>
                    <p class="text-sm text-white/68">Pick a person, a group, a segment, or paste numbers if you're feeling chaotic.</p>
                </div>

                <form method="POST" action="{{ route('marketing.messages.save-audience') }}" class="mt-5 space-y-4">
                    @csrf

                    <div class="space-y-2">
                        <label for="audience_kind" class="text-xs uppercase tracking-[0.2em] text-white/50">Send To</label>
                        <select id="audience_kind" name="audience_kind" x-model="audienceKind" class="w-full rounded-xl border border-white/10 bg-black/20 px-3 py-2 text-sm text-white sm:w-64">
                            <option value="person">Person</option>
                            <option value="group">Group</option>
                            <option value="segment">Segment</option>
                            <option value="manual">Manual</option>
                        </select>
                    </div>

                    <div x-show="audienceKind === 'person'" x-cloak class="rounded-xl border border-white/10 bg-black/20 p-3 sm:p-4">
                        <p class="text-sm font-medium text-white">Find one customer</p>
                        <p class="mt-1 text-xs text-white/60">Start typing a name, email, or phone. Enter picks the highlighted result.</p>

                        <div class="mt-3" x-data="singleProfilePicker({
                            endpoint: @js($searchEndpoint),
                            initialSelected: @js($selectedPerson),
                            profileCount: @js($profileCount)
                        })" x-init="init()">
                            <input type="hidden" name="selected_profile_id" :value="selected ? selected.id : ''">

                            <div class="relative" @click.outside="open = false">
                                <input
                                    type="text"
                                    x-model="query"
                                    @keydown="handleKeydown($event)"
                                    @focus="handleFocus()"
                                    placeholder="Type a name, email, or phone"
                                    class="w-full rounded-xl border border-white/10 bg-white/5 px-3 py-2.5 text-sm text-white placeholder:text-white/35"
                                />

                                <div x-show="loading" x-cloak class="pointer-events-none absolute inset-y-0 right-3 flex items-center text-xs text-white/50">Searching...</div>

                                <div x-show="open" x-cloak class="absolute z-30 mt-2 w-full overflow-hidden rounded-xl border border-white/10 bg-[#0f1917] shadow-2xl">
                                    <template x-if="items.length > 0">
                                        <ul class="max-h-64 overflow-auto py-1">
                                            <template x-for="(item, index) in items" :key="item.id">
                                                <li>
                                                    <button
                                                        type="button"
                                                        @mouseenter="activeIndex = index"
                                                        @click="select(item)"
                                                        class="w-full px-3 py-2 text-left text-sm"
                                                        :class="activeIndex === index ? 'bg-emerald-500/20 text-white' : 'text-white/85 hover:bg-white/5'"
                                                    >
                                                        <div class="font-medium" x-text="item.name"></div>
                                                        <div class="text-xs text-white/60" x-text="item.email || item.phone || 'No email or phone yet'"></div>
                                                    </button>
                                                </li>
                                            </template>
                                        </ul>
                                    </template>

                                    <template x-if="!loading && items.length === 0">
                                        <div class="px-3 py-3 text-xs text-white/60">
                                            <template x-if="meta.empty_reason === 'no_profiles'">
                                                <p>No profiles yet. Sync customers first.</p>
                                            </template>
                                            <template x-if="meta.empty_reason === 'no_searchable_profiles'">
                                                <p>Profiles exist, but most are missing names/emails. You can still use Manual for a quick test.</p>
                                            </template>
                                            <template x-if="meta.empty_reason !== 'no_profiles' && meta.empty_reason !== 'no_searchable_profiles'">
                                                <p>No matches yet. Try another name, email, or phone.</p>
                                            </template>
                                        </div>
                                    </template>
                                </div>
                            </div>

                            <template x-if="selected">
                                <div class="mt-3 inline-flex items-center gap-2 rounded-full border border-emerald-300/30 bg-emerald-500/15 px-3 py-1.5 text-sm text-emerald-50">
                                    <span x-text="selected.name"></span>
                                    <span class="text-emerald-100/70" x-text="selected.phone || selected.email || ''"></span>
                                    <button type="button" class="text-emerald-100/80 hover:text-white" @click="clearSelected()">Remove</button>
                                </div>
                            </template>
                        </div>
                    </div>

                    <div x-show="audienceKind === 'group'" x-cloak class="rounded-xl border border-white/10 bg-black/20 p-3 sm:p-4 space-y-3">
                        <div class="space-y-1">
                            <p class="text-sm font-medium text-white">Groups</p>
                            <p class="text-xs text-white/60">Saved group = reusable list. Custom group = build one now.</p>
                        </div>

                        <div class="space-y-2">
                            <label for="group_mode" class="text-xs uppercase tracking-[0.2em] text-white/50">Group Type</label>
                            <select id="group_mode" name="group_mode" x-model="groupMode" class="w-full rounded-xl border border-white/10 bg-white/5 px-3 py-2 text-sm text-white sm:w-72">
                                <option value="saved">Saved Group</option>
                                <option value="custom">Build Custom Group</option>
                            </select>
                        </div>

                        <div x-show="groupMode === 'saved'" x-cloak class="space-y-2">
                            <label for="group_id" class="text-xs uppercase tracking-[0.2em] text-white/50">Saved Group</label>
                            <select id="group_id" name="group_id" class="w-full rounded-xl border border-white/10 bg-white/5 px-3 py-2 text-sm text-white">
                                <option value="">Choose a group</option>
                                @foreach($groups as $group)
                                    <option value="{{ $group->id }}" @selected((int) ($state['group_id'] ?? 0) === (int) $group->id)>
                                        {{ $group->name }} ({{ $group->members_count }} members)
                                    </option>
                                @endforeach
                            </select>
                            @if($groups->isEmpty())
                                <p class="text-xs text-white/55">No saved groups yet. Build one below in about 10 seconds.</p>
                            @endif
                        </div>

                        <div x-show="groupMode === 'custom'" x-cloak class="space-y-3">
                            <div x-data="multiProfilePicker({
                                endpoint: @js($searchEndpoint),
                                initialSelected: @js($selectedProfiles),
                                profileCount: @js($profileCount)
                            })" x-init="init()" class="space-y-2">
                                <template x-for="item in selected" :key="item.id">
                                    <input type="hidden" name="selected_profile_ids[]" :value="item.id">
                                </template>

                                <label class="text-xs uppercase tracking-[0.2em] text-white/50">Add Customers</label>
                                <div class="relative" @click.outside="open = false">
                                    <input
                                        type="text"
                                        x-model="query"
                                        @keydown="handleKeydown($event)"
                                        @focus="handleFocus()"
                                        placeholder="Search and press enter to add"
                                        class="w-full rounded-xl border border-white/10 bg-white/5 px-3 py-2.5 text-sm text-white placeholder:text-white/35"
                                    />

                                    <div x-show="loading" x-cloak class="pointer-events-none absolute inset-y-0 right-3 flex items-center text-xs text-white/50">Searching...</div>

                                    <div x-show="open" x-cloak class="absolute z-30 mt-2 w-full overflow-hidden rounded-xl border border-white/10 bg-[#0f1917] shadow-2xl">
                                        <template x-if="items.length > 0">
                                            <ul class="max-h-64 overflow-auto py-1">
                                                <template x-for="(item, index) in items" :key="item.id">
                                                    <li>
                                                        <button
                                                            type="button"
                                                            @mouseenter="activeIndex = index"
                                                            @click="select(item)"
                                                            class="w-full px-3 py-2 text-left text-sm"
                                                            :class="activeIndex === index ? 'bg-emerald-500/20 text-white' : 'text-white/85 hover:bg-white/5'"
                                                        >
                                                            <div class="font-medium" x-text="item.name"></div>
                                                            <div class="text-xs text-white/60" x-text="item.email || item.phone || 'No email or phone yet'"></div>
                                                        </button>
                                                    </li>
                                                </template>
                                            </ul>
                                        </template>

                                        <template x-if="!loading && items.length === 0">
                                            <div class="px-3 py-3 text-xs text-white/60">No matches yet. Try a different search term.</div>
                                        </template>
                                    </div>
                                </div>

                                <div class="flex flex-wrap gap-2" x-show="selected.length > 0" x-cloak>
                                    <template x-for="item in selected" :key="'pill-' + item.id">
                                        <div class="inline-flex items-center gap-2 rounded-full border border-white/15 bg-white/5 px-3 py-1.5 text-xs text-white/85">
                                            <span x-text="item.name"></span>
                                            <button type="button" @click="remove(item.id)" class="text-white/60 hover:text-white">Remove</button>
                                        </div>
                                    </template>
                                </div>
                            </div>

                            <div>
                                <label for="manual_phones_custom" class="text-xs uppercase tracking-[0.2em] text-white/50">Optional Extra Numbers</label>
                                <textarea
                                    id="manual_phones_custom"
                                    name="manual_phones"
                                    rows="3"
                                    class="mt-1 w-full rounded-xl border border-white/10 bg-white/5 px-3 py-2 text-sm text-white"
                                    placeholder="Paste numbers separated by commas or new lines"
                                >{{ old('manual_phones', (string) ($state['manual_phones'] ?? '')) }}</textarea>
                            </div>

                            <div class="grid gap-3 sm:grid-cols-2">
                                <div>
                                    <label for="group_name" class="text-xs uppercase tracking-[0.2em] text-white/50">Group Name (optional)</label>
                                    <input
                                        id="group_name"
                                        name="group_name"
                                        value="{{ old('group_name', (string) ($state['group_name'] ?? '')) }}"
                                        placeholder="Spring launch crowd"
                                        class="mt-1 w-full rounded-xl border border-white/10 bg-white/5 px-3 py-2 text-sm text-white"
                                    />
                                </div>
                                <label class="inline-flex items-center gap-2 rounded-xl border border-white/10 bg-white/5 px-3 py-2 text-sm text-white/80">
                                    <input type="checkbox" name="save_reusable_group" value="1" @checked((bool) ($state['save_reusable_group'] ?? false))>
                                    Save this as a reusable group
                                </label>
                            </div>

                            <div>
                                <label for="group_description" class="text-xs uppercase tracking-[0.2em] text-white/50">Description (optional)</label>
                                <input
                                    id="group_description"
                                    name="group_description"
                                    value="{{ old('group_description', (string) ($state['group_description'] ?? '')) }}"
                                    placeholder="Great for launch reminders"
                                    class="mt-1 w-full rounded-xl border border-white/10 bg-white/5 px-3 py-2 text-sm text-white"
                                />
                            </div>
                        </div>
                    </div>

                    <div x-show="audienceKind === 'segment'" x-cloak class="rounded-xl border border-white/10 bg-black/20 p-3 sm:p-4 space-y-2">
                        <p class="text-sm font-medium text-white">Segment</p>
                        <select id="segment_id" name="segment_id" class="w-full rounded-xl border border-white/10 bg-white/5 px-3 py-2 text-sm text-white">
                            <option value="">Choose a segment</option>
                            @foreach($segments as $segment)
                                <option value="{{ $segment->id }}" @selected((int) ($state['segment_id'] ?? 0) === (int) $segment->id)>
                                    {{ $segment->name }}
                                </option>
                            @endforeach
                        </select>
                        <p class="text-xs text-white/58">Rule-based audience. We'll lock current matches when you continue.</p>
                    </div>

                    <div x-show="audienceKind === 'manual'" x-cloak class="rounded-xl border border-white/10 bg-black/20 p-3 sm:p-4 space-y-2">
                        <p class="text-sm font-medium text-white">Manual numbers</p>
                        <textarea
                            id="manual_phones"
                            name="manual_phones"
                            rows="5"
                            class="w-full rounded-xl border border-white/10 bg-white/5 px-3 py-2 text-sm text-white"
                            placeholder="+15551234567&#10;+15557654321"
                        >{{ old('manual_phones', (string) ($state['manual_phones'] ?? '')) }}</textarea>
                        <p class="text-xs text-white/58">Paste one per line, or separate with commas.</p>
                    </div>

                    <div class="flex justify-end">
                        <button type="submit" class="rounded-xl border border-emerald-300/40 bg-emerald-500/18 px-4 py-2 text-sm font-semibold text-white">
                            Continue
                        </button>
                    </div>
                </form>
            </section>
        @endif

        @if($step === 2)
            <section class="rounded-2xl border border-white/10 bg-white/[0.03] px-4 py-4 sm:px-5 sm:py-5" x-data="messageComposer(@js((string) (($state['raw_message_text'] ?? '') !== '' ? $state['raw_message_text'] : ($state['message_text'] ?? ''))))" x-init="init()">
                <div class="space-y-1">
                    <h2 class="text-xl font-semibold text-white">What should it say?</h2>
                    <p class="text-sm text-white/68">Keep it clear, friendly, and worth opening.</p>
                </div>

                <form method="POST" action="{{ route('marketing.messages.save-message') }}" id="message-form" class="mt-5 space-y-4">
                    @csrf
                    <div class="grid gap-3 sm:grid-cols-2">
                        <div>
                            <label for="template_id" class="text-xs uppercase tracking-[0.2em] text-white/50">Template (optional)</label>
                            <select id="template_id" name="template_id" @change="applyTemplate($event)" class="mt-1 w-full rounded-xl border border-white/10 bg-white/5 px-3 py-2 text-sm text-white">
                                <option value="">No template</option>
                                @foreach($templates as $template)
                                    <option value="{{ $template->id }}" data-template-text="{{ $template->template_text }}" @selected((int) ($state['template_id'] ?? 0) === (int) $template->id)>
                                        {{ $template->name }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label for="send_at" class="text-xs uppercase tracking-[0.2em] text-white/50">Send Time</label>
                            <input
                                id="send_at"
                                type="datetime-local"
                                name="send_at"
                                value="{{ old('send_at', isset($state['send_at']) && $state['send_at'] ? \Illuminate\Support\Carbon::parse((string) $state['send_at'])->format('Y-m-d\TH:i') : '') }}"
                                class="mt-1 w-full rounded-xl border border-white/10 bg-white/5 px-3 py-2 text-sm text-white"
                            />
                        </div>
                    </div>

                    <div class="space-y-2">
                        <label for="message_text" class="text-xs uppercase tracking-[0.2em] text-white/50">Message</label>
                        <textarea
                            id="message_text"
                            name="message_text"
                            rows="9"
                            x-model="text"
                            class="w-full rounded-2xl border border-white/10 bg-black/25 px-4 py-3 text-base text-white"
                            placeholder="Example: Fresh drop just landed. Grab yours here: https://..."
                        >{{ old('message_text', (string) (($state['raw_message_text'] ?? '') !== '' ? $state['raw_message_text'] : ($state['message_text'] ?? ''))) }}</textarea>

                        <div class="flex flex-wrap items-center gap-3 text-xs text-white/65">
                            <span>Characters: <span x-text="length"></span></span>
                            <span>Estimated SMS segments: <span x-text="segments"></span></span>
                        </div>

                        <div class="rounded-xl border border-white/10 bg-white/[0.04] px-3 py-2 text-xs text-white/68">
                            Paste a link and we'll shorten it automatically before send.
                        </div>

                        <div class="grid gap-2 sm:grid-cols-[1fr_auto_auto]">
                            <input
                                type="url"
                                x-model="linkDraft"
                                placeholder="Paste a product link"
                                class="rounded-xl border border-white/10 bg-white/5 px-3 py-2 text-sm text-white"
                            />
                            <button type="button" @click="appendLink()" class="rounded-xl border border-sky-300/30 bg-sky-500/15 px-3 py-2 text-sm font-medium text-white">
                                Add Link
                            </button>
                            <button type="button" @click="appendFirstName()" class="rounded-xl border border-white/15 bg-white/5 px-3 py-2 text-sm font-medium text-white/85">
                                Add First Name
                            </button>
                        </div>
                    </div>

                    <div class="rounded-2xl border border-white/10 bg-black/20 p-3">
                        <p class="text-xs uppercase tracking-[0.2em] text-white/50">Preview</p>
                        <p class="mt-2 whitespace-pre-wrap text-sm text-white/88" x-text="text || 'Your preview will show up here.'"></p>
                    </div>
                </form>

                <div class="mt-5 flex flex-wrap items-center justify-between gap-2">
                    <form method="POST" action="{{ route('marketing.messages.set-step') }}">
                        @csrf
                        <input type="hidden" name="step" value="1">
                        <button type="submit" class="rounded-xl border border-white/15 bg-white/5 px-4 py-2 text-sm text-white/80">Back</button>
                    </form>

                    <button type="submit" form="message-form" class="rounded-xl border border-emerald-300/40 bg-emerald-500/18 px-4 py-2 text-sm font-semibold text-white">
                        Continue
                    </button>
                </div>
            </section>
        @endif

        @if($step === 3)
            <section class="rounded-2xl border border-white/10 bg-white/[0.03] px-4 py-4 sm:px-5 sm:py-5 space-y-4">
                <div class="space-y-1">
                    <h2 class="text-xl font-semibold text-white">Give it one last look.</h2>
                    <p class="text-sm text-white/68">Want to test it first? Good instinct.</p>
                </div>

                <div class="grid gap-3 sm:grid-cols-3">
                    <article class="rounded-xl border border-white/10 bg-black/20 p-3">
                        <p class="text-xs uppercase tracking-[0.2em] text-white/50">Audience</p>
                        <p class="mt-1 text-sm font-semibold text-white">{{ data_get($state, 'audience_summary.label', 'Audience') }}</p>
                        <p class="mt-1 text-xs text-white/65">{{ data_get($state, 'audience_summary.detail', 'No audience selected yet.') }}</p>
                    </article>
                    <article class="rounded-xl border border-white/10 bg-black/20 p-3">
                        <p class="text-xs uppercase tracking-[0.2em] text-white/50">Recipients</p>
                        <p class="mt-1 text-sm font-semibold text-white">{{ number_format($recipientCount) }}</p>
                        <p class="mt-1 text-xs text-white/65">Estimated segments: {{ number_format($estimatedSegments) }}</p>
                    </article>
                    <article class="rounded-xl border border-white/10 bg-black/20 p-3">
                        <p class="text-xs uppercase tracking-[0.2em] text-white/50">Send Time</p>
                        <p class="mt-1 text-sm font-semibold text-white">
                            {{ !empty($state['send_at']) ? \Illuminate\Support\Carbon::parse((string) $state['send_at'])->format('Y-m-d H:i') : 'Immediately' }}
                        </p>
                        <p class="mt-1 text-xs text-white/65">Timezone follows app default.</p>
                    </article>
                </div>

                <div class="rounded-xl border border-white/10 bg-black/20 p-3">
                    <p class="text-xs uppercase tracking-[0.2em] text-white/50">Message Preview</p>
                    <p class="mt-2 whitespace-pre-wrap text-sm text-white/88">{{ (string) ($state['message_text'] ?? '') }}</p>
                </div>

                <div class="rounded-xl border border-white/10 bg-black/20 p-3 space-y-2">
                    <p class="text-xs uppercase tracking-[0.2em] text-white/50">Compliance / Deliverability</p>
                    @if($recipientWarnings !== [])
                        <ul class="space-y-1 text-sm text-amber-100/90">
                            @foreach($recipientWarnings as $warning)
                                <li>{{ $warning }}</li>
                            @endforeach
                        </ul>
                    @else
                        <p class="text-sm text-emerald-100/90">No obvious consent/suppression blockers found in this audience snapshot.</p>
                    @endif
                </div>

                <div class="rounded-xl border border-white/10 bg-black/20 p-3 space-y-2">
                    <p class="text-xs uppercase tracking-[0.2em] text-white/50">Shortened Links</p>
                    @if($shortenedLinks !== [])
                        <div class="space-y-2 text-sm">
                            @foreach($shortenedLinks as $link)
                                <div class="rounded-lg border border-white/10 bg-white/[0.03] px-3 py-2">
                                    <p class="text-white/60 break-all">{{ $link['original'] ?? '—' }}</p>
                                    <p class="text-emerald-100 break-all">{{ $link['shortened'] ?? '—' }}</p>
                                </div>
                            @endforeach
                        </div>
                    @else
                        <p class="text-sm text-white/65">No links detected in your message.</p>
                    @endif
                </div>

                <form method="POST" action="{{ route('marketing.messages.send-test') }}" class="rounded-xl border border-white/10 bg-black/20 p-3">
                    @csrf
                    <div class="grid gap-3 sm:grid-cols-[1fr_auto_auto] sm:items-end">
                        <div>
                            <label for="test_phone" class="text-xs uppercase tracking-[0.2em] text-white/50">Want to test it first?</label>
                            <input
                                id="test_phone"
                                name="test_phone"
                                value="{{ old('test_phone', (string) ($state['last_test_phone'] ?? env('MARKETING_SMS_TEST_NUMBER', ''))) }}"
                                placeholder="+15551234567"
                                class="mt-1 w-full rounded-xl border border-white/10 bg-white/5 px-3 py-2 text-sm text-white"
                            />
                        </div>
                        <label class="inline-flex items-center gap-2 rounded-xl border border-white/10 bg-white/5 px-3 py-2 text-xs text-white/75">
                            <input type="checkbox" name="dry_run" value="1">
                            Dry run
                        </label>
                        <button type="submit" class="rounded-xl border border-sky-300/30 bg-sky-500/15 px-4 py-2 text-sm font-semibold text-white">
                            Send Test Text
                        </button>
                    </div>
                </form>

                <div class="flex flex-wrap items-center justify-between gap-2">
                    <form method="POST" action="{{ route('marketing.messages.set-step') }}">
                        @csrf
                        <input type="hidden" name="step" value="2">
                        <button type="submit" class="rounded-xl border border-white/15 bg-white/5 px-4 py-2 text-sm text-white/80">Back</button>
                    </form>

                    <form method="POST" action="{{ route('marketing.messages.set-step') }}">
                        @csrf
                        <input type="hidden" name="step" value="4">
                        <button type="submit" class="rounded-xl border border-emerald-300/40 bg-emerald-500/18 px-4 py-2 text-sm font-semibold text-white">
                            Continue to Send
                        </button>
                    </form>
                </div>
            </section>
        @endif

        @if($step === 4)
            <section class="rounded-2xl border border-white/10 bg-white/[0.03] px-4 py-4 sm:px-5 sm:py-5 space-y-4">
                <div class="space-y-1">
                    <h2 class="text-xl font-semibold text-white">Ready to send this thing?</h2>
                    <p class="text-sm text-white/68">Quick final check, then send.</p>
                </div>

                <div class="rounded-xl border border-white/10 bg-black/20 p-3 text-sm text-white/80 space-y-2">
                    <p><span class="text-white/55">Audience:</span> {{ data_get($state, 'audience_summary.label', 'Audience') }}</p>
                    <p><span class="text-white/55">Recipients:</span> {{ number_format($recipientCount) }}</p>
                    <p><span class="text-white/55">Message:</span> {{ \Illuminate\Support\Str::limit((string) ($state['message_text'] ?? ''), 180) }}</p>
                </div>

                <form id="final-send-form" method="POST" action="{{ route('marketing.messages.execute') }}" onsubmit="return window.confirm('Send this message now?');" class="space-y-3">
                    @csrf
                    <label class="inline-flex items-center gap-2 rounded-xl border border-white/10 bg-white/5 px-3 py-2 text-sm text-white/80">
                        <input type="checkbox" name="confirm_send" value="1" required>
                        Yes, send it
                    </label>
                    <label class="inline-flex items-center gap-2 rounded-xl border border-white/10 bg-white/5 px-3 py-2 text-sm text-white/80">
                        <input type="checkbox" name="dry_run" value="1">
                        Dry run only
                    </label>

                </form>

                <div class="flex flex-wrap items-center justify-between gap-2">
                    <form method="POST" action="{{ route('marketing.messages.set-step') }}">
                        @csrf
                        <input type="hidden" name="step" value="3">
                        <button type="submit" class="rounded-xl border border-white/15 bg-white/5 px-4 py-2 text-sm text-white/80">Back</button>
                    </form>

                    <button type="submit" form="final-send-form" class="rounded-xl border border-emerald-300/45 bg-emerald-500/22 px-4 py-2 text-sm font-semibold text-white">
                        Send Message
                    </button>
                </div>
            </section>
        @endif
    </div>

    <script>
        function singleProfilePicker(config) {
            return {
                endpoint: config.endpoint,
                profileCount: Number(config.profileCount || 0),
                selected: config.initialSelected || null,
                query: '',
                loading: false,
                open: false,
                items: [],
                activeIndex: -1,
                debounceTimer: null,
                meta: {
                    empty_reason: (Number(config.profileCount || 0) > 0) ? 'empty_query' : 'no_profiles'
                },

                init() {
                    this.$watch('query', (value) => this.debounceSearch(value));
                },

                handleFocus() {
                    const term = (this.query || '').trim();
                    if (term === '') {
                        this.search('');
                        return;
                    }

                    if (this.items.length > 0) {
                        this.open = true;
                    }
                },

                debounceSearch(value) {
                    window.clearTimeout(this.debounceTimer);
                    const term = (value || '').trim();
                    if (term === '') {
                        this.debounceTimer = window.setTimeout(() => {
                            this.search('');
                        }, 180);
                        return;
                    }

                    this.debounceTimer = window.setTimeout(() => {
                        this.search(term);
                    }, 250);
                },

                async search(term) {
                    this.loading = true;

                    try {
                        const response = await fetch(`${this.endpoint}?q=${encodeURIComponent(term)}`, {
                            headers: {
                                'Accept': 'application/json',
                                'X-Requested-With': 'XMLHttpRequest',
                            },
                        });

                        const payload = await response.json();
                        this.items = Array.isArray(payload.data) ? payload.data : [];
                        this.meta = payload.meta || {};
                        this.open = true;
                        this.activeIndex = this.items.length > 0 ? 0 : -1;
                    } catch (error) {
                        this.items = [];
                        this.open = true;
                        this.activeIndex = -1;
                        this.meta = { empty_reason: 'error' };
                    } finally {
                        this.loading = false;
                    }
                },

                handleKeydown(event) {
                    if (event.key === 'ArrowDown') {
                        if (!this.open) this.open = true;
                        if (this.items.length > 0) {
                            this.activeIndex = (this.activeIndex + 1) % this.items.length;
                        }
                        return;
                    }

                    if (event.key === 'ArrowUp') {
                        if (!this.open) this.open = true;
                        if (this.items.length > 0) {
                            this.activeIndex = this.activeIndex <= 0 ? this.items.length - 1 : this.activeIndex - 1;
                        }
                        return;
                    }

                    if (event.key === 'Enter') {
                        if (this.items.length > 0) {
                            const target = this.items[this.activeIndex >= 0 ? this.activeIndex : 0];
                            if (target) {
                                event.preventDefault();
                                this.select(target);
                            }
                        }
                        return;
                    }

                    if (event.key === 'Escape') {
                        this.open = false;
                    }
                },

                select(item) {
                    this.selected = item;
                    this.query = '';
                    this.items = [];
                    this.open = false;
                    this.activeIndex = -1;
                },

                clearSelected() {
                    this.selected = null;
                },
            };
        }

        function multiProfilePicker(config) {
            return {
                endpoint: config.endpoint,
                profileCount: Number(config.profileCount || 0),
                selected: Array.isArray(config.initialSelected) ? config.initialSelected : [],
                query: '',
                loading: false,
                open: false,
                items: [],
                activeIndex: -1,
                debounceTimer: null,

                init() {
                    this.$watch('query', (value) => this.debounceSearch(value));
                },

                handleFocus() {
                    const term = (this.query || '').trim();
                    if (term === '') {
                        this.search('');
                        return;
                    }

                    if (this.items.length > 0) {
                        this.open = true;
                    }
                },

                debounceSearch(value) {
                    window.clearTimeout(this.debounceTimer);
                    const term = (value || '').trim();
                    if (term === '') {
                        this.debounceTimer = window.setTimeout(() => {
                            this.search('');
                        }, 180);
                        return;
                    }

                    this.debounceTimer = window.setTimeout(() => {
                        this.search(term);
                    }, 250);
                },

                async search(term) {
                    this.loading = true;
                    try {
                        const response = await fetch(`${this.endpoint}?q=${encodeURIComponent(term)}`, {
                            headers: {
                                'Accept': 'application/json',
                                'X-Requested-With': 'XMLHttpRequest',
                            },
                        });
                        const payload = await response.json();
                        const rows = Array.isArray(payload.data) ? payload.data : [];
                        this.items = rows.filter((row) => !this.selected.some((selected) => Number(selected.id) === Number(row.id)));
                        this.open = true;
                        this.activeIndex = this.items.length > 0 ? 0 : -1;
                    } catch (error) {
                        this.items = [];
                        this.open = true;
                        this.activeIndex = -1;
                    } finally {
                        this.loading = false;
                    }
                },

                handleKeydown(event) {
                    if (event.key === 'ArrowDown') {
                        if (!this.open) this.open = true;
                        if (this.items.length > 0) {
                            this.activeIndex = (this.activeIndex + 1) % this.items.length;
                        }
                        return;
                    }

                    if (event.key === 'ArrowUp') {
                        if (!this.open) this.open = true;
                        if (this.items.length > 0) {
                            this.activeIndex = this.activeIndex <= 0 ? this.items.length - 1 : this.activeIndex - 1;
                        }
                        return;
                    }

                    if (event.key === 'Enter') {
                        if (this.items.length > 0) {
                            const target = this.items[this.activeIndex >= 0 ? this.activeIndex : 0];
                            if (target) {
                                event.preventDefault();
                                this.select(target);
                            }
                        }
                        return;
                    }

                    if (event.key === 'Escape') {
                        this.open = false;
                    }
                },

                select(item) {
                    if (!this.selected.some((row) => Number(row.id) === Number(item.id))) {
                        this.selected.push(item);
                    }
                    this.query = '';
                    this.items = [];
                    this.open = false;
                    this.activeIndex = -1;
                },

                remove(id) {
                    this.selected = this.selected.filter((item) => Number(item.id) !== Number(id));
                },
            };
        }

        function messageComposer(initialText) {
            return {
                text: initialText || '',
                length: 0,
                segments: 0,
                linkDraft: '',

                init() {
                    this.recalculate();
                    this.$watch('text', () => this.recalculate());
                },

                recalculate() {
                    const text = (this.text || '').trim();
                    this.length = [...(this.text || '')].length;
                    if (text === '') {
                        this.segments = 0;
                        return;
                    }

                    const gsmRegex = /^[\r\n !\"#$%&'()*+,\-.\/0-9:;<=>?@A-Z\[\]\\_a-z{|}~\^€£¥èéùìòÇØøÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉÄÖÑÜ§¿äöñüà]+$/u;
                    if (gsmRegex.test(text)) {
                        this.segments = this.length <= 160 ? 1 : Math.ceil(this.length / 153);
                        return;
                    }

                    this.segments = this.length <= 70 ? 1 : Math.ceil(this.length / 67);
                },

                applyTemplate(event) {
                    const option = event.target.selectedOptions[0];
                    if (!option) {
                        return;
                    }

                    const templateText = option.dataset.templateText || '';
                    if (templateText && (this.text || '').trim() === '') {
                        this.text = templateText;
                    }
                },

                appendFirstName() {
                    const token = '@{{first_name}}';
                    if ((this.text || '').includes(token)) {
                        return;
                    }
                    this.text = (this.text || '').trim() === '' ? token : `${this.text} ${token}`;
                },

                appendLink() {
                    const url = (this.linkDraft || '').trim();
                    if (url === '') {
                        return;
                    }

                    this.text = (this.text || '').trim() === '' ? url : `${this.text} ${url}`;
                    this.linkDraft = '';
                },
            };
        }
    </script>
</x-layouts::app>

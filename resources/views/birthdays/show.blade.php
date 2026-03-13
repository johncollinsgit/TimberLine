@php
    $section = $section ?? \App\Support\Birthdays\BirthdaySectionRegistry::section($sectionKey ?? 'customers');
    $sections = $sections ?? [];
@endphp

<x-layouts::app :title="'Birthdays - ' . ($section['label'] ?? 'Birthdays')">
    <div class="space-y-6">
        <x-birthdays.partials.section-shell
            :section="$section"
            :sections="$sections"
        />

        @if($sectionKey === 'customers')
            <section class="grid gap-4 xl:grid-cols-4">
                <article class="rounded-[1.7rem] border border-white/10 bg-black/15 p-5">
                    <div class="text-[11px] uppercase tracking-[0.24em] text-white/45">Club Size</div>
                    <div class="mt-3 text-4xl font-semibold text-white">{{ number_format((int) data_get($summary, 'with_birthday', 0)) }}</div>
                    <p class="mt-2 text-sm text-white/65">Profiles with birthdays stored in Backstage.</p>
                </article>
                <article class="rounded-[1.7rem] border border-white/10 bg-black/15 p-5">
                    <div class="text-[11px] uppercase tracking-[0.24em] text-white/45">Today</div>
                    <div class="mt-3 text-4xl font-semibold text-white">{{ number_format((int) data_get($summary, 'birthdays_today', 0)) }}</div>
                    <p class="mt-2 text-sm text-white/65">Birthdays happening today.</p>
                </article>
                <article class="rounded-[1.7rem] border border-white/10 bg-black/15 p-5">
                    <div class="text-[11px] uppercase tracking-[0.24em] text-white/45">Email Ready</div>
                    <div class="mt-3 text-4xl font-semibold text-white">{{ number_format((int) data_get($summary, 'email_subscribed', 0)) }}</div>
                    <p class="mt-2 text-sm text-white/65">Birthday club customers who can receive email.</p>
                </article>
                <article class="rounded-[1.7rem] border border-white/10 bg-black/15 p-5">
                    <div class="text-[11px] uppercase tracking-[0.24em] text-white/45">Shopify Match</div>
                    <div class="mt-3 text-4xl font-semibold text-white">{{ number_format((int) data_get($summary, 'shopify_matched', 0)) }}</div>
                    <p class="mt-2 text-sm text-white/65">Birthday club records already matched to Shopify-linked customers.</p>
                </article>
            </section>

            <section class="grid gap-4 xl:grid-cols-[minmax(0,1.2fr),minmax(360px,0.8fr)]">
                <article class="rounded-[1.8rem] border border-white/10 bg-black/15 p-5 space-y-4">
                    <div class="flex items-end justify-between gap-3">
                        <div>
                            <div class="text-[11px] uppercase tracking-[0.24em] text-white/45">Import</div>
                            <h2 class="mt-2 text-lg font-semibold text-white">Birthday CSV import</h2>
                            <p class="mt-2 text-sm text-white/65">Preview the file first, map the columns, then import into canonical profiles and birthday records.</p>
                        </div>
                    </div>

                    <form method="POST" action="{{ route('birthdays.customers.import.preview') }}" enctype="multipart/form-data" class="flex flex-col gap-3 md:flex-row md:items-end">
                        @csrf
                        <div class="flex-1">
                            <label class="text-xs uppercase tracking-[0.2em] text-white/45">CSV file</label>
                            <input type="file" name="import_file" accept=".csv,text/csv" class="mt-2 block w-full rounded-2xl border border-white/10 bg-white/5 px-4 py-3 text-sm text-white file:mr-4 file:rounded-full file:border-0 file:bg-white/10 file:px-4 file:py-2 file:text-xs file:font-semibold file:text-white" />
                            @error('import_file')
                                <div class="mt-2 text-sm text-rose-200">{{ $message }}</div>
                            @enderror
                        </div>
                        <button type="submit" class="inline-flex rounded-full border border-rose-300/30 bg-rose-500/15 px-5 py-3 text-sm font-semibold text-white">Preview Import</button>
                    </form>
                </article>

                <article class="rounded-[1.8rem] border border-white/10 bg-black/15 p-5">
                    <div class="text-[11px] uppercase tracking-[0.24em] text-white/45">Filters</div>
                    <h2 class="mt-2 text-lg font-semibold text-white">Find birthday customers fast</h2>
                    <form method="GET" action="{{ route('birthdays.customers') }}" class="mt-4 grid gap-3 md:grid-cols-2">
                        <input type="text" name="search" value="{{ data_get($filters, 'search') }}" placeholder="Search name, email, phone, source" class="rounded-2xl border border-white/10 bg-white/5 px-4 py-3 text-sm text-white placeholder:text-white/35 md:col-span-2" />
                        <select name="month" class="rounded-2xl border border-white/10 bg-white/5 px-4 py-3 text-sm text-white">
                            <option value="0">All months</option>
                            @for($monthIndex = 1; $monthIndex <= 12; $monthIndex++)
                                <option value="{{ $monthIndex }}" @selected((int) data_get($filters, 'month', 0) === $monthIndex)>{{ \Carbon\CarbonImmutable::create(null, $monthIndex, 1)->format('F') }}</option>
                            @endfor
                        </select>
                        <select name="timing" class="rounded-2xl border border-white/10 bg-white/5 px-4 py-3 text-sm text-white">
                            <option value="all" @selected(data_get($filters, 'timing') === 'all')>All timing</option>
                            <option value="today" @selected(data_get($filters, 'timing') === 'today')>Today</option>
                            <option value="this_week" @selected(data_get($filters, 'timing') === 'this_week')>This week</option>
                            <option value="this_month" @selected(data_get($filters, 'timing') === 'this_month')>This month</option>
                        </select>
                        <select name="subscription" class="rounded-2xl border border-white/10 bg-white/5 px-4 py-3 text-sm text-white">
                            <option value="all" @selected(data_get($filters, 'subscription') === 'all')>All subscription states</option>
                            <option value="email" @selected(data_get($filters, 'subscription') === 'email')>Email subscribed</option>
                            <option value="sms" @selected(data_get($filters, 'subscription') === 'sms')>SMS subscribed</option>
                            <option value="unsubscribed" @selected(data_get($filters, 'subscription') === 'unsubscribed')>Unsubscribed</option>
                        </select>
                        <select name="reward_status" class="rounded-2xl border border-white/10 bg-white/5 px-4 py-3 text-sm text-white">
                            <option value="all" @selected(data_get($filters, 'reward_status') === 'all')>All reward states</option>
                            @foreach(['issued' => 'Available', 'claimed' => 'Activated', 'redeemed' => 'Redeemed', 'expired' => 'Expired', 'cancelled' => 'Cancelled'] as $value => $label)
                                <option value="{{ $value }}" @selected(data_get($filters, 'reward_status') === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                        <select name="source" class="rounded-2xl border border-white/10 bg-white/5 px-4 py-3 text-sm text-white">
                            <option value="all" @selected(data_get($filters, 'source') === 'all')>All sources</option>
                            @foreach(($sourceOptions ?? collect()) as $sourceOption)
                                <option value="{{ $sourceOption }}" @selected(data_get($filters, 'source') === $sourceOption)>{{ $sourceOption }}</option>
                            @endforeach
                        </select>
                        <div class="md:col-span-2 flex gap-3">
                            <button type="submit" class="inline-flex rounded-full border border-white/15 bg-white/5 px-4 py-2 text-sm font-semibold text-white">Apply</button>
                            <a href="{{ route('birthdays.customers') }}" wire:navigate class="inline-flex rounded-full border border-white/10 px-4 py-2 text-sm font-semibold text-white/70">Reset</a>
                        </div>
                    </form>
                </article>
            </section>

            @if(!empty($importPreview))
                <section class="rounded-[1.8rem] border border-white/10 bg-black/15 p-5 space-y-4">
                    <div class="flex items-end justify-between gap-3">
                        <div>
                            <div class="text-[11px] uppercase tracking-[0.24em] text-white/45">Preview</div>
                            <h2 class="mt-2 text-lg font-semibold text-white">{{ $importPreview['file_name'] }}</h2>
                            <p class="mt-2 text-sm text-white/65">Adjust the column mapping if needed, then import.</p>
                        </div>
                    </div>

                    <form method="POST" action="{{ route('birthdays.customers.import.run') }}" class="space-y-4">
                        @csrf
                        <input type="hidden" name="temp_path" value="{{ $importPreview['temp_path'] }}" />
                        <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                            @foreach($importPreview['headers'] as $header)
                                <label class="block rounded-2xl border border-white/10 bg-white/5 p-3">
                                    <div class="text-xs uppercase tracking-[0.2em] text-white/45">{{ $header }}</div>
                                    <select name="mapping[{{ $header }}]" class="mt-3 w-full rounded-xl border border-white/10 bg-black/25 px-3 py-2 text-sm text-white">
                                        @foreach($importPreview['fieldOptions'] as $value => $label)
                                            <option value="{{ $value }}" @selected(($importPreview['mapping'][$header] ?? 'ignore') === $value)>{{ $label }}</option>
                                        @endforeach
                                    </select>
                                </label>
                            @endforeach
                        </div>
                        <div class="overflow-x-auto rounded-[1.4rem] border border-white/10">
                            <table class="min-w-full text-left text-sm text-white/80">
                                <thead class="bg-white/5 text-xs uppercase tracking-[0.18em] text-white/45">
                                    <tr>
                                        @foreach($importPreview['headers'] as $header)
                                            <th class="px-4 py-3">{{ $header }}</th>
                                        @endforeach
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($importPreview['preview_rows'] as $previewRow)
                                        <tr class="border-t border-white/10">
                                            @foreach($importPreview['headers'] as $header)
                                                <td class="px-4 py-3 align-top">{{ data_get($previewRow, $header) ?: '—' }}</td>
                                            @endforeach
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                        <button type="submit" class="inline-flex rounded-full border border-rose-300/30 bg-rose-500/15 px-5 py-3 text-sm font-semibold text-white">Import Birthdays</button>
                    </form>
                </section>
            @endif

            <section class="rounded-[1.8rem] border border-white/10 bg-black/15 p-5">
                <div class="flex items-end justify-between gap-3">
                    <div>
                        <div class="text-[11px] uppercase tracking-[0.24em] text-white/45">Customers</div>
                        <h2 class="mt-2 text-lg font-semibold text-white">Birthday club customers</h2>
                    </div>
                    <div class="text-sm text-white/60">{{ number_format($profiles->total()) }} total</div>
                </div>

                <div class="mt-4 overflow-x-auto rounded-[1.4rem] border border-white/10">
                    <table class="min-w-full text-left text-sm text-white/80">
                        <thead class="bg-white/5 text-xs uppercase tracking-[0.18em] text-white/45">
                            <tr>
                                <th class="px-4 py-3">Customer</th>
                                <th class="px-4 py-3">Birthday</th>
                                <th class="px-4 py-3">Source</th>
                                <th class="px-4 py-3">Subscribed</th>
                                <th class="px-4 py-3">Reward</th>
                                <th class="px-4 py-3">Last Send</th>
                                <th class="px-4 py-3">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($profiles as $birthdayProfile)
                                @php($profile = $birthdayProfile->marketingProfile)
                                @php($issuance = $birthdayProfile->rewardIssuances->first())
                                @php($shopifyLink = $profile?->links?->firstWhere('source_type', 'shopify_customer'))
                                <tr class="border-t border-white/10">
                                    <td class="px-4 py-3 align-top">
                                        <div class="font-semibold text-white">{{ trim(($profile->first_name ?? '').' '.($profile->last_name ?? '')) ?: 'Unknown' }}</div>
                                        <div class="mt-1 text-xs text-white/55">{{ $profile->email ?: ($profile->phone ?: 'No email or phone') }}</div>
                                        @if($shopifyLink)
                                            <div class="mt-1 text-[11px] text-white/40">Shopify {{ $shopifyLink->source_id }}</div>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3 align-top">
                                        <div class="font-medium text-white">{{ sprintf('%02d/%02d', (int) $birthdayProfile->birth_month, (int) $birthdayProfile->birth_day) }}</div>
                                        <div class="mt-1 text-xs text-white/55">{{ $birthdayProfile->birth_year ?: 'Year optional' }}</div>
                                    </td>
                                    <td class="px-4 py-3 align-top">
                                        <div>{{ $birthdayProfile->signup_source ?: '—' }}</div>
                                        <div class="mt-1 text-xs text-white/55">Imported {{ optional($birthdayProfile->capture_date)->format('Y-m-d') ?: '—' }}</div>
                                    </td>
                                    <td class="px-4 py-3 align-top">
                                        <div>Email: {{ $birthdayProfile->email_subscribed ? 'Yes' : ($birthdayProfile->email_subscribed === false ? 'No' : '—') }}</div>
                                        <div class="mt-1 text-xs text-white/55">SMS: {{ $birthdayProfile->sms_subscribed ? 'Yes' : ($birthdayProfile->sms_subscribed === false ? 'No' : '—') }}</div>
                                    </td>
                                    <td class="px-4 py-3 align-top">
                                        <div class="font-medium text-white">{{ $issuance?->reward_name ?: 'No reward yet' }}</div>
                                        <div class="mt-1 text-xs text-white/55">{{ $issuance?->status ? ucfirst($issuance->status) : '—' }}</div>
                                        @if($issuance?->reward_code)
                                            <div class="mt-1 text-[11px] text-white/40">{{ $issuance->reward_code }}</div>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3 align-top">
                                        <div>{{ optional($birthdayProfile->messageEvents()->latest('sent_at')->first()?->sent_at)->format('Y-m-d H:i') ?: '—' }}</div>
                                    </td>
                                    <td class="px-4 py-3 align-top">
                                        <div class="flex flex-wrap gap-2">
                                            <a href="{{ route('marketing.customers.show', $profile) }}" wire:navigate class="inline-flex rounded-full border border-white/10 px-3 py-1 text-xs font-semibold text-white/80">Open</a>
                                            <form method="POST" action="{{ route('birthdays.customers.issue-reward', $profile) }}">
                                                @csrf
                                                <button type="submit" class="inline-flex rounded-full border border-white/10 px-3 py-1 text-xs font-semibold text-white/80">Issue</button>
                                            </form>
                                            @if($issuance && $issuance->status === 'issued')
                                                <form method="POST" action="{{ route('birthdays.rewards.activate', $issuance) }}">
                                                    @csrf
                                                    <button type="submit" class="inline-flex rounded-full border border-rose-300/30 bg-rose-500/10 px-3 py-1 text-xs font-semibold text-white">Activate</button>
                                                </form>
                                            @endif
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="7" class="px-4 py-6 text-sm text-white/60">No birthday customers match the current filters.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <div class="mt-4">{{ $profiles->links() }}</div>
            </section>
        @elseif($sectionKey === 'analytics')
            <section class="grid gap-4 xl:grid-cols-4">
                @foreach([
                    ['label' => 'Birthdays Today', 'value' => data_get($summary, 'birthdays_today', 0), 'detail' => 'Birthdays happening today.'],
                    ['label' => 'Birthdays This Week', 'value' => data_get($summary, 'birthdays_this_week', 0), 'detail' => 'Birthdays in the current week.'],
                    ['label' => 'Rewards Redeemed', 'value' => data_get($summary, 'rewards_redeemed_this_year', 0), 'detail' => 'Birthday rewards redeemed this year.'],
                    ['label' => 'Email Open Rate', 'value' => number_format((float) data_get($summary, 'email_open_rate', 0), 1) . '%', 'detail' => 'Open rate across stored birthday email events.'],
                ] as $card)
                    <article class="rounded-[1.7rem] border border-white/10 bg-black/15 p-5">
                        <div class="text-[11px] uppercase tracking-[0.24em] text-white/45">{{ $card['label'] }}</div>
                        <div class="mt-3 text-4xl font-semibold text-white">{{ $card['value'] }}</div>
                        <p class="mt-2 text-sm text-white/65">{{ $card['detail'] }}</p>
                    </article>
                @endforeach
            </section>

            <section class="grid gap-4 xl:grid-cols-[minmax(0,1.15fr),minmax(360px,0.85fr)]">
                <article class="rounded-[1.8rem] border border-white/10 bg-black/15 p-5">
                    <div class="text-[11px] uppercase tracking-[0.24em] text-white/45">Recent Trend</div>
                    <h2 class="mt-2 text-lg font-semibold text-white">Last 30 days</h2>
                    <div class="mt-4 space-y-3">
                        @foreach(collect(data_get($summary, 'recent_trend', []))->take(-10) as $day)
                            @php($max = max(1, collect(data_get($summary, 'recent_trend', []))->max('signups'), collect(data_get($summary, 'recent_trend', []))->max('issued'), collect(data_get($summary, 'recent_trend', []))->max('redeemed')))
                            <div>
                                <div class="flex items-center justify-between text-xs text-white/55">
                                    <span>{{ \Carbon\CarbonImmutable::parse($day['date'])->format('M j') }}</span>
                                    <span>Signups {{ $day['signups'] }} · Issued {{ $day['issued'] }} · Redeemed {{ $day['redeemed'] }}</span>
                                </div>
                                <div class="mt-2 h-2 overflow-hidden rounded-full bg-white/10">
                                    <div class="h-full rounded-full bg-rose-300/70" style="width: {{ min(100, (($day['signups'] + $day['issued'] + $day['redeemed']) / ($max * 3)) * 100) }}%"></div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </article>
                <article class="rounded-[1.8rem] border border-white/10 bg-black/15 p-5">
                    <div class="text-[11px] uppercase tracking-[0.24em] text-white/45">Campaign Overview</div>
                    <h2 class="mt-2 text-lg font-semibold text-white">Stored birthday sends</h2>
                    <div class="mt-4 space-y-3">
                        @foreach(($campaignSummary ?? []) as $campaignType => $stats)
                            <div class="rounded-2xl border border-white/10 bg-white/5 p-4">
                                <div class="text-sm font-semibold text-white">{{ str($campaignType)->replace('_', ' ')->title() }}</div>
                                <div class="mt-2 flex flex-wrap gap-2 text-xs text-white/60">
                                    <span class="rounded-full border border-white/10 bg-black/20 px-2 py-1">Sent {{ $stats['sent'] }}</span>
                                    <span class="rounded-full border border-white/10 bg-black/20 px-2 py-1">Opened {{ $stats['opened'] }}</span>
                                    <span class="rounded-full border border-white/10 bg-black/20 px-2 py-1">Clicked {{ $stats['clicked'] }}</span>
                                    <span class="rounded-full border border-white/10 bg-black/20 px-2 py-1">Converted {{ $stats['converted'] }}</span>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </article>
            </section>

            <section class="grid gap-4 xl:grid-cols-2">
                <article class="rounded-[1.8rem] border border-white/10 bg-black/15 p-5">
                    <div class="text-[11px] uppercase tracking-[0.24em] text-white/45">Birthdays by Month</div>
                    <div class="mt-4 space-y-3">
                        @php($monthMax = max(1, collect(data_get($summary, 'segments_by_month', []))->max('total')))
                        @foreach(data_get($summary, 'segments_by_month', []) as $monthRow)
                            <div>
                                <div class="flex items-center justify-between text-sm text-white/70">
                                    <span>{{ \Carbon\CarbonImmutable::create(null, $monthRow['month'], 1)->format('F') }}</span>
                                    <span>{{ number_format((int) $monthRow['total']) }}</span>
                                </div>
                                <div class="mt-2 h-2 overflow-hidden rounded-full bg-white/10">
                                    <div class="h-full rounded-full bg-amber-300/70" style="width: {{ (($monthRow['total'] ?? 0) / $monthMax) * 100 }}%"></div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </article>
                <article class="rounded-[1.8rem] border border-white/10 bg-black/15 p-5">
                    <div class="text-[11px] uppercase tracking-[0.24em] text-white/45">Signup Sources</div>
                    <div class="mt-4 space-y-3">
                        @php($sourceMax = max(1, collect(data_get($summary, 'signup_sources', []))->max('total')))
                        @foreach(data_get($summary, 'signup_sources', []) as $sourceRow)
                            <div>
                                <div class="flex items-center justify-between text-sm text-white/70">
                                    <span>{{ $sourceRow['label'] }}</span>
                                    <span>{{ number_format((int) $sourceRow['total']) }}</span>
                                </div>
                                <div class="mt-2 h-2 overflow-hidden rounded-full bg-white/10">
                                    <div class="h-full rounded-full bg-sky-300/70" style="width: {{ (($sourceRow['total'] ?? 0) / $sourceMax) * 100 }}%"></div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </article>
            </section>
        @elseif($sectionKey === 'campaigns')
            <section class="grid gap-4 xl:grid-cols-2">
                @foreach([
                    'birthday_email' => ['title' => 'Birthday Email', 'enabled' => data_get($campaignConfig, 'email_enabled', true), 'preview' => data_get($campaignConfig, 'birthday_email_subject'), 'body' => data_get($campaignConfig, 'birthday_email_body'), 'offset' => data_get($campaignConfig, 'birthday_send_offset', 0)],
                    'birthday_sms' => ['title' => 'Birthday SMS', 'enabled' => data_get($campaignConfig, 'sms_enabled', false), 'preview' => 'SMS', 'body' => data_get($campaignConfig, 'birthday_sms_body'), 'offset' => data_get($campaignConfig, 'birthday_send_offset', 0)],
                    'followup_email' => ['title' => 'Follow-up Email', 'enabled' => data_get($campaignConfig, 'email_enabled', true), 'preview' => data_get($campaignConfig, 'followup_email_subject'), 'body' => data_get($campaignConfig, 'followup_email_body'), 'offset' => data_get($campaignConfig, 'followup_send_offset', 3)],
                    'followup_sms' => ['title' => 'Follow-up SMS', 'enabled' => data_get($campaignConfig, 'sms_enabled', false), 'preview' => 'SMS', 'body' => data_get($campaignConfig, 'followup_sms_body'), 'offset' => data_get($campaignConfig, 'followup_send_offset', 3)],
                ] as $key => $campaign)
                    @php($stats = data_get($campaignSummary, $key, ['sent' => 0, 'opened' => 0, 'clicked' => 0, 'converted' => 0]))
                    <article class="rounded-[1.8rem] border border-white/10 bg-black/15 p-5">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <div class="text-[11px] uppercase tracking-[0.24em] text-white/45">{{ $campaign['title'] }}</div>
                                <h2 class="mt-2 text-lg font-semibold text-white">{{ $campaign['enabled'] ? 'Enabled' : 'Disabled' }}</h2>
                            </div>
                            <span class="inline-flex rounded-full border border-white/10 bg-white/5 px-3 py-1 text-xs font-semibold text-white/75">Offset {{ $campaign['offset'] }}d</span>
                        </div>
                        <div class="mt-4 rounded-2xl border border-white/10 bg-white/5 p-4">
                            <div class="text-xs uppercase tracking-[0.18em] text-white/45">Preview</div>
                            <div class="mt-2 text-sm font-semibold text-white">{{ $campaign['preview'] }}</div>
                            <p class="mt-2 text-sm leading-6 text-white/65">{{ $campaign['body'] }}</p>
                        </div>
                        <div class="mt-4 flex flex-wrap gap-2 text-xs text-white/60">
                            <span class="rounded-full border border-white/10 bg-black/20 px-2 py-1">Sent {{ $stats['sent'] }}</span>
                            <span class="rounded-full border border-white/10 bg-black/20 px-2 py-1">Opened {{ $stats['opened'] }}</span>
                            <span class="rounded-full border border-white/10 bg-black/20 px-2 py-1">Clicked {{ $stats['clicked'] }}</span>
                            <span class="rounded-full border border-white/10 bg-black/20 px-2 py-1">Converted {{ $stats['converted'] }}</span>
                        </div>
                    </article>
                @endforeach
            </section>
        @elseif($sectionKey === 'rewards')
            <section class="grid gap-4 xl:grid-cols-4">
                @foreach([
                    ['label' => 'Available', 'value' => data_get($rewardSummary, 'available', 0)],
                    ['label' => 'Activated', 'value' => data_get($rewardSummary, 'activated', 0)],
                    ['label' => 'Redeemed', 'value' => data_get($rewardSummary, 'redeemed', 0)],
                    ['label' => 'Expired', 'value' => data_get($rewardSummary, 'expired', 0)],
                ] as $card)
                    <article class="rounded-[1.7rem] border border-white/10 bg-black/15 p-5">
                        <div class="text-[11px] uppercase tracking-[0.24em] text-white/45">{{ $card['label'] }}</div>
                        <div class="mt-3 text-4xl font-semibold text-white">{{ number_format((int) $card['value']) }}</div>
                    </article>
                @endforeach
            </section>

            <section class="grid gap-4 xl:grid-cols-[minmax(0,0.85fr),minmax(0,1.15fr)]">
                <article class="rounded-[1.8rem] border border-white/10 bg-black/15 p-5">
                    <div class="text-[11px] uppercase tracking-[0.24em] text-white/45">Reward Rules</div>
                    <h2 class="mt-2 text-lg font-semibold text-white">Birthday reward defaults</h2>
                    <form method="POST" action="{{ route('birthdays.settings.save') }}" class="mt-4 grid gap-3">
                        @csrf
                        <input type="hidden" name="scope" value="reward" />
                        <label class="block">
                            <span class="text-xs uppercase tracking-[0.18em] text-white/45">Reward Name</span>
                            <input type="text" name="reward_name" value="{{ data_get($rewardConfig, 'reward_name') }}" class="mt-2 w-full rounded-2xl border border-white/10 bg-white/5 px-4 py-3 text-sm text-white" />
                        </label>
                        <div class="grid gap-3 md:grid-cols-2">
                            <label class="block">
                                <span class="text-xs uppercase tracking-[0.18em] text-white/45">Reward Type</span>
                                <select name="reward_type" class="mt-2 w-full rounded-2xl border border-white/10 bg-white/5 px-4 py-3 text-sm text-white">
                                    @foreach(['discount_code' => 'Fixed amount code', 'points' => 'Candle Cash points', 'free_shipping' => 'Free shipping'] as $value => $label)
                                        <option value="{{ $value }}" @selected(data_get($rewardConfig, 'reward_type') === $value)>{{ $label }}</option>
                                    @endforeach
                                </select>
                            </label>
                            <label class="block">
                                <span class="text-xs uppercase tracking-[0.18em] text-white/45">Reward Value</span>
                                <input type="number" step="0.01" min="0" name="reward_value" value="{{ data_get($rewardConfig, 'reward_value') }}" class="mt-2 w-full rounded-2xl border border-white/10 bg-white/5 px-4 py-3 text-sm text-white" />
                            </label>
                        </div>
                        <div class="grid gap-3 md:grid-cols-2">
                            <label class="block">
                                <span class="text-xs uppercase tracking-[0.18em] text-white/45">Coupon Prefix</span>
                                <input type="text" name="discount_code_prefix" value="{{ data_get($rewardConfig, 'discount_code_prefix') }}" class="mt-2 w-full rounded-2xl border border-white/10 bg-white/5 px-4 py-3 text-sm text-white" />
                            </label>
                            <label class="block">
                                <span class="text-xs uppercase tracking-[0.18em] text-white/45">Expiration Days</span>
                                <input type="number" min="1" name="claim_window_days_after" value="{{ data_get($rewardConfig, 'claim_window_days_after') }}" class="mt-2 w-full rounded-2xl border border-white/10 bg-white/5 px-4 py-3 text-sm text-white" />
                            </label>
                        </div>
                        <button type="submit" class="inline-flex rounded-full border border-rose-300/30 bg-rose-500/15 px-5 py-3 text-sm font-semibold text-white">Save Reward Rules</button>
                    </form>
                </article>

                <article class="rounded-[1.8rem] border border-white/10 bg-black/15 p-5">
                    <div class="flex items-end justify-between gap-3">
                        <div>
                            <div class="text-[11px] uppercase tracking-[0.24em] text-white/45">Issuances</div>
                            <h2 class="mt-2 text-lg font-semibold text-white">Birthday rewards</h2>
                        </div>
                    </div>
                    <div class="mt-4 overflow-x-auto rounded-[1.4rem] border border-white/10">
                        <table class="min-w-full text-left text-sm text-white/80">
                            <thead class="bg-white/5 text-xs uppercase tracking-[0.18em] text-white/45">
                                <tr>
                                    <th class="px-4 py-3">Customer</th>
                                    <th class="px-4 py-3">Reward</th>
                                    <th class="px-4 py-3">Status</th>
                                    <th class="px-4 py-3">Code</th>
                                    <th class="px-4 py-3">Dates</th>
                                    <th class="px-4 py-3">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($rewardIssuances as $issuance)
                                    @php($profile = $issuance->marketingProfile)
                                    <tr class="border-t border-white/10">
                                        <td class="px-4 py-3">{{ trim(($profile->first_name ?? '').' '.($profile->last_name ?? '')) ?: ($profile->email ?: 'Unknown') }}</td>
                                        <td class="px-4 py-3">{{ $issuance->reward_name ?: $issuance->reward_type }} @if($issuance->reward_value) · ${{ number_format((float) $issuance->reward_value, 2) }} @endif</td>
                                        <td class="px-4 py-3">{{ ucfirst((string) $issuance->status) }}</td>
                                        <td class="px-4 py-3">{{ $issuance->reward_code ?: '—' }}</td>
                                        <td class="px-4 py-3 text-xs text-white/60">
                                            Issued {{ optional($issuance->issued_at)->format('Y-m-d') ?: '—' }}<br>
                                            Expires {{ optional($issuance->expires_at)->format('Y-m-d') ?: '—' }}
                                        </td>
                                        <td class="px-4 py-3">
                                            <div class="flex flex-wrap gap-2">
                                                @if($issuance->status === 'issued')
                                                    <form method="POST" action="{{ route('birthdays.rewards.activate', $issuance) }}">
                                                        @csrf
                                                        <button type="submit" class="inline-flex rounded-full border border-white/10 px-3 py-1 text-xs font-semibold text-white/80">Activate</button>
                                                    </form>
                                                @endif
                                                @foreach(['redeemed' => 'Mark Used', 'expired' => 'Expire', 'cancelled' => 'Cancel'] as $value => $label)
                                                    <form method="POST" action="{{ route('birthdays.rewards.status', $issuance) }}">
                                                        @csrf
                                                        <input type="hidden" name="status" value="{{ $value }}" />
                                                        <button type="submit" class="inline-flex rounded-full border border-white/10 px-3 py-1 text-xs font-semibold text-white/80">{{ $label }}</button>
                                                    </form>
                                                @endforeach
                                            </div>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    <div class="mt-4">{{ $rewardIssuances->links() }}</div>
                </article>
            </section>
        @elseif($sectionKey === 'settings')
            <section class="grid gap-4 xl:grid-cols-3">
                <article class="rounded-[1.8rem] border border-white/10 bg-black/15 p-5">
                    <div class="text-[11px] uppercase tracking-[0.24em] text-white/45">General</div>
                    <form method="POST" action="{{ route('birthdays.settings.save') }}" class="mt-4 grid gap-3">
                        @csrf
                        <input type="hidden" name="scope" value="capture" />
                        <label class="inline-flex items-center gap-3 rounded-2xl border border-white/10 bg-white/5 px-4 py-3 text-sm text-white">
                            <input type="checkbox" name="year_optional" value="1" @checked(data_get($captureConfig, 'year_optional')) class="rounded border-white/20 bg-white/5 text-rose-400" />
                            Allow month/day without year
                        </label>
                        <label class="block">
                            <span class="text-xs uppercase tracking-[0.18em] text-white/45">Match Priority</span>
                            <input type="text" name="match_priority" value="{{ data_get($captureConfig, 'match_priority') }}" class="mt-2 w-full rounded-2xl border border-white/10 bg-white/5 px-4 py-3 text-sm text-white" />
                        </label>
                        <label class="block">
                            <span class="text-xs uppercase tracking-[0.18em] text-white/45">Required Fields</span>
                            <input type="text" name="required_fields" value="{{ data_get($captureConfig, 'required_fields') }}" class="mt-2 w-full rounded-2xl border border-white/10 bg-white/5 px-4 py-3 text-sm text-white" />
                        </label>
                        <button type="submit" class="inline-flex rounded-full border border-white/15 bg-white/5 px-5 py-3 text-sm font-semibold text-white">Save Capture Rules</button>
                    </form>
                </article>
                <article class="rounded-[1.8rem] border border-white/10 bg-black/15 p-5">
                    <div class="text-[11px] uppercase tracking-[0.24em] text-white/45">Email + SMS</div>
                    <form method="POST" action="{{ route('birthdays.settings.save') }}" class="mt-4 grid gap-3">
                        @csrf
                        <input type="hidden" name="scope" value="campaign" />
                        <label class="inline-flex items-center gap-3 rounded-2xl border border-white/10 bg-white/5 px-4 py-3 text-sm text-white">
                            <input type="checkbox" name="email_enabled" value="1" @checked(data_get($campaignConfig, 'email_enabled')) class="rounded border-white/20 bg-white/5 text-rose-400" />
                            Birthday email enabled
                        </label>
                        <label class="inline-flex items-center gap-3 rounded-2xl border border-white/10 bg-white/5 px-4 py-3 text-sm text-white">
                            <input type="checkbox" name="sms_enabled" value="1" @checked(data_get($campaignConfig, 'sms_enabled')) class="rounded border-white/20 bg-white/5 text-rose-400" />
                            Birthday SMS enabled
                        </label>
                        <label class="block">
                            <span class="text-xs uppercase tracking-[0.18em] text-white/45">Birthday Email Subject</span>
                            <input type="text" name="birthday_email_subject" value="{{ data_get($campaignConfig, 'birthday_email_subject') }}" class="mt-2 w-full rounded-2xl border border-white/10 bg-white/5 px-4 py-3 text-sm text-white" />
                        </label>
                        <label class="block">
                            <span class="text-xs uppercase tracking-[0.18em] text-white/45">Birthday Email Body</span>
                            <textarea name="birthday_email_body" rows="4" class="mt-2 w-full rounded-2xl border border-white/10 bg-white/5 px-4 py-3 text-sm text-white">{{ data_get($campaignConfig, 'birthday_email_body') }}</textarea>
                        </label>
                        <button type="submit" class="inline-flex rounded-full border border-white/15 bg-white/5 px-5 py-3 text-sm font-semibold text-white">Save Messages</button>
                    </form>
                </article>
                <article class="rounded-[1.8rem] border border-white/10 bg-black/15 p-5">
                    <div class="text-[11px] uppercase tracking-[0.24em] text-white/45">Timing</div>
                    <form method="POST" action="{{ route('birthdays.settings.save') }}" class="mt-4 grid gap-3">
                        @csrf
                        <input type="hidden" name="scope" value="campaign" />
                        <label class="block">
                            <span class="text-xs uppercase tracking-[0.18em] text-white/45">Birthday Send Offset</span>
                            <input type="number" name="birthday_send_offset" value="{{ data_get($campaignConfig, 'birthday_send_offset') }}" class="mt-2 w-full rounded-2xl border border-white/10 bg-white/5 px-4 py-3 text-sm text-white" />
                        </label>
                        <label class="block">
                            <span class="text-xs uppercase tracking-[0.18em] text-white/45">Follow-up Offset</span>
                            <input type="number" name="followup_send_offset" value="{{ data_get($campaignConfig, 'followup_send_offset') }}" class="mt-2 w-full rounded-2xl border border-white/10 bg-white/5 px-4 py-3 text-sm text-white" />
                        </label>
                        <label class="block">
                            <span class="text-xs uppercase tracking-[0.18em] text-white/45">Follow-up Subject</span>
                            <input type="text" name="followup_email_subject" value="{{ data_get($campaignConfig, 'followup_email_subject') }}" class="mt-2 w-full rounded-2xl border border-white/10 bg-white/5 px-4 py-3 text-sm text-white" />
                        </label>
                        <label class="block">
                            <span class="text-xs uppercase tracking-[0.18em] text-white/45">Follow-up Body</span>
                            <textarea name="followup_email_body" rows="4" class="mt-2 w-full rounded-2xl border border-white/10 bg-white/5 px-4 py-3 text-sm text-white">{{ data_get($campaignConfig, 'followup_email_body') }}</textarea>
                        </label>
                        <button type="submit" class="inline-flex rounded-full border border-white/15 bg-white/5 px-5 py-3 text-sm font-semibold text-white">Save Timing</button>
                    </form>
                </article>
            </section>
        @elseif($sectionKey === 'activity')
            <section class="grid gap-4 xl:grid-cols-3">
                <article class="rounded-[1.8rem] border border-white/10 bg-black/15 p-5 xl:col-span-1">
                    <div class="text-[11px] uppercase tracking-[0.24em] text-white/45">Imports</div>
                    <div class="mt-4 space-y-3">
                        @forelse($recentImports as $run)
                            <div class="rounded-2xl border border-white/10 bg-white/5 p-4">
                                <div class="text-sm font-semibold text-white">{{ $run->file_name ?: 'Birthday import' }}</div>
                                <div class="mt-1 text-xs text-white/55">{{ strtoupper((string) $run->status) }} · Processed {{ number_format((int) data_get($run->summary, 'processed', 0)) }}</div>
                                <div class="mt-1 text-xs text-white/45">{{ optional($run->finished_at ?: $run->started_at)->format('Y-m-d H:i') ?: '—' }}</div>
                            </div>
                        @empty
                            <div class="rounded-2xl border border-white/10 bg-white/5 p-4 text-sm text-white/60">No birthday imports recorded yet.</div>
                        @endforelse
                    </div>
                </article>
                <article class="rounded-[1.8rem] border border-white/10 bg-black/15 p-5 xl:col-span-1">
                    <div class="text-[11px] uppercase tracking-[0.24em] text-white/45">Audit</div>
                    <div class="mt-4 space-y-3">
                        @foreach($recentAudits as $audit)
                            <div class="rounded-2xl border border-white/10 bg-white/5 p-4">
                                <div class="text-sm font-semibold text-white">{{ str($audit->action)->replace('_', ' ')->title() }}</div>
                                <div class="mt-1 text-xs text-white/55">{{ trim(($audit->marketingProfile?->first_name ?? '').' '.($audit->marketingProfile?->last_name ?? '')) ?: ($audit->marketingProfile?->email ?: 'Unknown') }}</div>
                                <div class="mt-1 text-xs text-white/45">{{ optional($audit->created_at)->format('Y-m-d H:i') ?: '—' }}</div>
                            </div>
                        @endforeach
                    </div>
                    <div class="mt-4">{{ $recentAudits->links() }}</div>
                </article>
                <article class="rounded-[1.8rem] border border-white/10 bg-black/15 p-5 xl:col-span-1">
                    <div class="text-[11px] uppercase tracking-[0.24em] text-white/45">Messages</div>
                    <div class="mt-4 space-y-3">
                        @foreach($recentEvents as $event)
                            <div class="rounded-2xl border border-white/10 bg-white/5 p-4">
                                <div class="text-sm font-semibold text-white">{{ str($event->campaign_type)->replace('_', ' ')->title() }}</div>
                                <div class="mt-1 text-xs text-white/55">{{ $event->status ?: 'sent' }} · {{ trim(($event->marketingProfile?->first_name ?? '').' '.($event->marketingProfile?->last_name ?? '')) ?: ($event->marketingProfile?->email ?: 'Unknown') }}</div>
                                <div class="mt-1 text-xs text-white/45">{{ optional($event->sent_at ?: $event->created_at)->format('Y-m-d H:i') ?: '—' }}</div>
                            </div>
                        @endforeach
                    </div>
                    <div class="mt-4">{{ $recentEvents->links() }}</div>
                </article>
            </section>
        @endif
    </div>
</x-layouts::app>

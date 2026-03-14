@php
    $section = $section ?? \App\Support\Marketing\CandleCashSectionRegistry::section($sectionKey ?? 'dashboard');
    $sections = $sections ?? [];
@endphp

<x-layouts::app :title="'Candle Cash - ' . ($section['label'] ?? 'Candle Cash')">
    <div class="space-y-6">
        <x-marketing.partials.candle-cash-shell :section="$section" :sections="$sections" />

        @if($sectionKey === 'dashboard')
            <section class="grid gap-4 md:grid-cols-2 xl:grid-cols-6">
                @foreach([
                    ['label' => 'Issued', 'value' => '$' . number_format((float) data_get($dashboard, 'total_issued_amount', 0), 2), 'detail' => 'Total Candle Cash awarded across the program.'],
                    ['label' => 'Pending events', 'value' => number_format((int) data_get($dashboard, 'pending_events', 0)), 'detail' => 'Events waiting on a final verification step or fallback handling.'],
                    ['label' => 'Referrals', 'value' => number_format((int) data_get($dashboard, 'total_referrals', 0)), 'detail' => 'Captured friend referrals in the system.'],
                    ['label' => 'Active tasks', 'value' => number_format((int) data_get($dashboard, 'active_tasks', 0)), 'detail' => 'Tasks currently visible to customers.'],
                    ['label' => 'Referral conversions', 'value' => number_format((int) data_get($dashboard, 'referral_conversions', 0)), 'detail' => 'Verified referral rewards triggered by qualifying orders.'],
                    ['label' => 'Avg cost', 'value' => '$' . number_format((float) data_get($dashboard, 'avg_reward_cost', 0), 2), 'detail' => 'Average reward cost per awarded task.'],
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
                    <div class="flex items-end justify-between gap-3">
                        <div>
                            <div class="text-[11px] uppercase tracking-[0.24em] text-white/45">Top tasks</div>
                            <h2 class="mt-2 text-lg font-semibold text-white">What is driving Candle Cash</h2>
                        </div>
                        <a href="{{ route('marketing.candle-cash.tasks') }}" wire:navigate class="inline-flex rounded-full border border-white/10 bg-white/5 px-3 py-1.5 text-xs font-semibold text-white/80">Open tasks</a>
                    </div>
                    <div class="mt-4 space-y-3">
                        @foreach(data_get($dashboard, 'top_tasks', collect()) as $task)
                            <div class="rounded-2xl border border-white/10 bg-white/5 p-4">
                                <div class="flex items-start justify-between gap-3">
                                    <div>
                                        <div class="font-semibold text-white">{{ $task->title }}</div>
                                        <div class="mt-1 text-xs text-white/55">{{ $task->handle }} · {{ $task->task_type }}</div>
                                    </div>
                                    <div class="text-right text-sm text-white/70">
                                        <div>{{ number_format((int) $task->awarded_count) }} awarded</div>
                                        <div class="mt-1 text-xs text-white/45">{{ number_format((int) $task->pending_count) }} pending</div>
                                    </div>
                                </div>
                                <div class="mt-3 text-sm text-white/65">{{ $task->description }}</div>
                            </div>
                        @endforeach
                    </div>
                </article>

                <article class="rounded-[1.8rem] border border-white/10 bg-black/15 p-5">
                    <div class="text-[11px] uppercase tracking-[0.24em] text-white/45">Fresh activity</div>
                    <h2 class="mt-2 text-lg font-semibold text-white">Latest task outcomes</h2>
                    <div class="mt-4 space-y-3">
                        @forelse(data_get($dashboard, 'recent_completions', collect()) as $completion)
                            <div class="rounded-2xl border border-white/10 bg-white/5 p-4">
                                <div class="flex items-start justify-between gap-3">
                                    <div>
                                        <div class="font-semibold text-white">{{ $completion->task?->title ?: 'Task' }}</div>
                                        <div class="mt-1 text-xs text-white/55">{{ trim(($completion->profile->first_name ?? '') . ' ' . ($completion->profile->last_name ?? '')) ?: ($completion->profile->email ?? 'Unknown customer') }}</div>
                                    </div>
                                    <div class="rounded-full border border-white/10 px-2.5 py-1 text-[11px] uppercase tracking-[0.18em] text-white/65">{{ $completion->status }}</div>
                                </div>
                                <div class="mt-3 text-xs text-white/45">{{ optional($completion->created_at)->format('Y-m-d H:i') }}</div>
                            </div>
                        @empty
                            <div class="rounded-2xl border border-white/10 bg-white/5 p-4 text-sm text-white/60">No task activity yet.</div>
                        @endforelse
                    </div>
                </article>
            </section>
        @elseif($sectionKey === 'tasks')
            <section class="rounded-[1.8rem] border border-white/10 bg-black/15 p-5">
                <div class="flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
                    <div>
                        <div class="text-[11px] uppercase tracking-[0.24em] text-white/45">Task manager</div>
                        <h2 class="mt-2 text-lg font-semibold text-white">Create and tune verified growth tasks</h2>
                    </div>
                    <form method="GET" action="{{ route('marketing.candle-cash.tasks') }}" class="grid gap-3 sm:grid-cols-3">
                        <select name="filter" class="rounded-2xl border border-white/10 bg-white/5 px-4 py-3 text-sm text-white">
                            @foreach(['active' => 'Active', 'inactive' => 'Inactive', 'archived' => 'Archived', 'manual' => 'Fallback / manual', 'auto' => 'Automatic'] as $value => $label)
                                <option value="{{ $value }}" @selected(data_get($taskFilters, 'filter') === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                        <select name="type" class="rounded-2xl border border-white/10 bg-white/5 px-4 py-3 text-sm text-white">
                            <option value="all">All task types</option>
                            @foreach(($taskTypes ?? collect()) as $taskType)
                                <option value="{{ $taskType }}" @selected(data_get($taskFilters, 'type') === $taskType)>{{ $taskType }}</option>
                            @endforeach
                        </select>
                        <select name="verification" class="rounded-2xl border border-white/10 bg-white/5 px-4 py-3 text-sm text-white">
                            <option value="all">All verification modes</option>
                            @foreach(($taskVerificationModes ?? collect()) as $mode)
                                <option value="{{ $mode }}" @selected(data_get($taskFilters, 'verification') === $mode)>{{ $mode }}</option>
                            @endforeach
                        </select>
                        <button type="submit" class="sm:col-span-3 inline-flex rounded-full border border-white/10 bg-white/5 px-4 py-2 text-sm font-semibold text-white/80">Apply filters</button>
                    </form>
                </div>
            </section>

            <section class="grid gap-4 xl:grid-cols-[minmax(0,0.95fr),minmax(0,1.05fr)]">
                <article class="rounded-[1.8rem] border border-white/10 bg-black/15 p-5">
                    <div class="text-[11px] uppercase tracking-[0.24em] text-white/45">New task</div>
                    <h2 class="mt-2 text-lg font-semibold text-white">Add another verified reward task</h2>
                    <form method="POST" action="{{ route('marketing.candle-cash.tasks.store') }}" class="mt-4 grid gap-3 md:grid-cols-2">
                        @csrf
                        @include('marketing.candle-cash.task-form-fields', ['taskForm' => $newTask, 'taskPrefix' => 'new'])
                        <div class="md:col-span-2">
                            <button type="submit" class="inline-flex rounded-full border border-amber-300/30 bg-amber-500/10 px-5 py-3 text-sm font-semibold text-white">Create task</button>
                        </div>
                    </form>
                </article>

                <article class="space-y-4">
                    @foreach($tasks as $task)
                        <div class="rounded-[1.8rem] border border-white/10 bg-black/15 p-5">
                            <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                                <div>
                                    <div class="text-[11px] uppercase tracking-[0.24em] text-white/45">{{ $task->handle }}</div>
                                    <h3 class="mt-2 text-lg font-semibold text-white">{{ $task->title }}</h3>
                                    <p class="mt-2 text-sm text-white/65">{{ $task->description }}</p>
                                    <div class="mt-3 flex flex-wrap gap-2 text-[11px] uppercase tracking-[0.18em] text-white/45">
                                        <span>{{ $task->task_type }}</span>
                                        <span>{{ $task->verification_mode }}</span>
                                        <span>{{ number_format((int) $task->awarded_count) }} awarded</span>
                                        <span>{{ number_format((int) $task->pending_count) }} pending</span>
                                        <span>{{ number_format((int) $task->blocked_count) }} blocked</span>
                                        <span>{{ number_format((int) $task->event_count) }} events</span>
                                    </div>
                                </div>
                                <div class="flex flex-wrap gap-2">
                                    <form method="POST" action="{{ route('marketing.candle-cash.tasks.toggle', $task) }}">
                                        @csrf
                                        <button type="submit" class="inline-flex rounded-full border border-white/10 px-3 py-1.5 text-xs font-semibold text-white/80">{{ $task->enabled ? 'Disable' : 'Enable' }}</button>
                                    </form>
                                    <form method="POST" action="{{ route('marketing.candle-cash.tasks.archive', $task) }}">
                                        @csrf
                                        <button type="submit" class="inline-flex rounded-full border border-white/10 px-3 py-1.5 text-xs font-semibold text-white/80">Archive</button>
                                    </form>
                                </div>
                            </div>
                            <form method="POST" action="{{ route('marketing.candle-cash.tasks.update', $task) }}" class="mt-4 grid gap-3 md:grid-cols-2">
                                @csrf
                                @method('PATCH')
                                @include('marketing.candle-cash.task-form-fields', ['taskForm' => $task->toArray(), 'taskPrefix' => 'task-' . $task->id])
                                <div class="md:col-span-2">
                                    <button type="submit" class="inline-flex rounded-full border border-white/10 bg-white/5 px-4 py-2 text-sm font-semibold text-white/80">Save task</button>
                                </div>
                            </form>
                        </div>
                    @endforeach
                </article>
            </section>
        @elseif($sectionKey === 'queue')
            <section class="grid gap-4 md:grid-cols-5">
                @foreach([
                    'all' => 'All events',
                    'awarded' => 'Awarded',
                    'pending' => 'Pending',
                    'blocked' => 'Blocked',
                    'duplicates' => 'Duplicates',
                ] as $key => $label)
                    <a href="{{ route('marketing.candle-cash.queue', ['status' => $key]) }}" wire:navigate class="rounded-[1.7rem] border p-5 {{ $queueStatus === $key ? 'border-emerald-300/35 bg-emerald-500/10' : 'border-white/10 bg-black/15' }}">
                        <div class="text-[11px] uppercase tracking-[0.24em] text-white/45">{{ $label }}</div>
                        <div class="mt-3 text-4xl font-semibold text-white">{{ number_format((int) data_get($queueSummary, $key, 0)) }}</div>
                    </a>
                @endforeach
            </section>

            <section class="rounded-[1.8rem] border border-white/10 bg-black/15 p-5">
                <div class="overflow-x-auto rounded-[1.4rem] border border-white/10">
                    <table class="min-w-full text-left text-sm text-white/80">
                        <thead class="bg-white/5 text-xs uppercase tracking-[0.18em] text-white/45">
                            <tr>
                                <th class="px-4 py-3">Customer</th>
                                <th class="px-4 py-3">Task</th>
                                <th class="px-4 py-3">Mode</th>
                                <th class="px-4 py-3">Status</th>
                                <th class="px-4 py-3">Source</th>
                                <th class="px-4 py-3">Event</th>
                                <th class="px-4 py-3">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($eventLog as $event)
                                <tr class="border-t border-white/10">
                                    <td class="px-4 py-3 align-top">
                                        <div class="font-semibold text-white">{{ trim(($event->profile->first_name ?? '') . ' ' . ($event->profile->last_name ?? '')) ?: ($event->profile->email ?? 'Unknown customer') }}</div>
                                        <div class="mt-1 text-xs text-white/50">{{ $event->profile->email ?: ($event->profile->phone ?: 'No email or phone') }}</div>
                                    </td>
                                    <td class="px-4 py-3 align-top">
                                        <div class="font-medium text-white">{{ $event->task?->title ?: 'Task' }}</div>
                                        <div class="mt-1 text-xs text-white/50">{{ $event->task?->handle }}</div>
                                    </td>
                                    <td class="px-4 py-3 align-top text-white/60">{{ $event->verification_mode }}</td>
                                    <td class="px-4 py-3 align-top text-white/70">{{ strtoupper($event->status) }}@if($event->reward_awarded) · AWARDED @endif</td>
                                    <td class="px-4 py-3 align-top text-white/70">
                                        <div>{{ $event->source_type ?: '—' }}</div>
                                        <div class="mt-1 text-xs text-white/45">{{ $event->source_id ?: 'No source id' }}</div>
                                        @if($event->duplicate_hits > 0)
                                            <div class="mt-1 text-xs text-amber-200">{{ number_format((int) $event->duplicate_hits) }} duplicate hit{{ (int) $event->duplicate_hits === 1 ? '' : 's' }}</div>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3 align-top text-white/60">
                                        <div>{{ $event->source_event_key }}</div>
                                        <div class="mt-1 text-xs text-white/45">{{ optional($event->processed_at ?: $event->occurred_at ?: $event->created_at)->format('Y-m-d H:i') }}</div>
                                        @if($event->blocked_reason)
                                            <div class="mt-1 text-xs text-rose-200">{{ str_replace('_', ' ', $event->blocked_reason) }}</div>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3 align-top">
                                        @if($event->completion && $event->completion->status === 'pending')
                                            <div class="flex flex-wrap gap-2">
                                                <form method="POST" action="{{ route('marketing.candle-cash.queue.approve', $event->completion) }}" class="space-y-2">
                                                    @csrf
                                                    <textarea name="review_notes" rows="2" placeholder="Optional note" class="block w-56 rounded-2xl border border-white/10 bg-white/5 px-3 py-2 text-xs text-white placeholder:text-white/35"></textarea>
                                                    <button type="submit" class="inline-flex rounded-full border border-emerald-300/35 bg-emerald-500/10 px-3 py-1.5 text-xs font-semibold text-white">Approve</button>
                                                </form>
                                                <form method="POST" action="{{ route('marketing.candle-cash.queue.reject', $event->completion) }}" class="space-y-2">
                                                    @csrf
                                                    <textarea name="review_notes" rows="2" required placeholder="Reason for rejection" class="block w-56 rounded-2xl border border-white/10 bg-white/5 px-3 py-2 text-xs text-white placeholder:text-white/35"></textarea>
                                                    <button type="submit" class="inline-flex rounded-full border border-rose-300/35 bg-rose-500/10 px-3 py-1.5 text-xs font-semibold text-white">Reject</button>
                                                </form>
                                            </div>
                                        @else
                                            <div class="text-xs text-white/50">{{ $event->completion?->review_notes ?: 'No action needed.' }}</div>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="7" class="px-4 py-6 text-center text-white/55">No event log rows match this status.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <div class="mt-4">{{ $eventLog->links() }}</div>
            </section>
        @elseif($sectionKey === 'customers')
            <section class="grid gap-4 xl:grid-cols-[minmax(320px,0.9fr),minmax(0,1.1fr)]">
                <article class="rounded-[1.8rem] border border-white/10 bg-black/15 p-5">
                    <form method="GET" action="{{ route('marketing.candle-cash.customers') }}" class="flex gap-3">
                        <input type="text" name="search" value="{{ $customerSearch }}" placeholder="Search name, email, phone" class="flex-1 rounded-2xl border border-white/10 bg-white/5 px-4 py-3 text-sm text-white placeholder:text-white/35" />
                        <button type="submit" class="inline-flex rounded-full border border-white/10 bg-white/5 px-4 py-2 text-sm font-semibold text-white/80">Search</button>
                    </form>
                    <div class="mt-4 space-y-3">
                        @foreach($customerProfiles as $profile)
                            <a href="{{ route('marketing.candle-cash.customers', ['search' => $customerSearch, 'profile' => $profile->id]) }}" wire:navigate class="block rounded-2xl border p-4 {{ (int) request()->query('profile', 0) === (int) $profile->id ? 'border-amber-300/35 bg-amber-500/10' : 'border-white/10 bg-white/5' }}">
                                <div class="flex items-start justify-between gap-3">
                                    <div>
                                        <div class="font-semibold text-white">{{ trim(($profile->first_name ?? '') . ' ' . ($profile->last_name ?? '')) ?: ($profile->email ?: 'Profile #' . $profile->id) }}</div>
                                        <div class="mt-1 text-xs text-white/50">{{ $profile->email ?: ($profile->phone ?: 'No email or phone') }}</div>
                                    </div>
                                    <div class="text-right text-sm text-white/70">
                                        <div>{{ number_format((int) ($profile->candleCashBalance->balance ?? 0)) }} pts</div>
                                        <div class="mt-1 text-xs text-white/45">{{ number_format((int) $profile->pending_task_count) }} pending · {{ number_format((int) $profile->referral_count) }} refs</div>
                                    </div>
                                </div>
                            </a>
                        @endforeach
                    </div>
                    <div class="mt-4">{{ $customerProfiles->links() }}</div>
                </article>

                <article class="rounded-[1.8rem] border border-white/10 bg-black/15 p-5">
                    @if($selectedProfile)
                        <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                            <div>
                                <div class="text-[11px] uppercase tracking-[0.24em] text-white/45">Customer rewards view</div>
                                <h2 class="mt-2 text-lg font-semibold text-white">{{ trim(($selectedProfile->first_name ?? '') . ' ' . ($selectedProfile->last_name ?? '')) ?: ($selectedProfile->email ?: 'Customer') }}</h2>
                                <div class="mt-2 text-sm text-white/60">{{ $selectedProfile->email ?: ($selectedProfile->phone ?: 'No email or phone') }}</div>
                            </div>
                            <a href="{{ route('marketing.customers.show', $selectedProfile) }}" wire:navigate class="inline-flex rounded-full border border-white/10 bg-white/5 px-3 py-1.5 text-xs font-semibold text-white/80">Open full customer</a>
                        </div>

                        <div class="mt-5 grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                            <div class="rounded-2xl border border-white/10 bg-white/5 p-4"><div class="text-xs uppercase tracking-[0.18em] text-white/45">Balance</div><div class="mt-2 text-2xl font-semibold text-white">{{ number_format((int) data_get($selectedProfileSummary, 'balance_points', 0)) }} pts</div></div>
                            <div class="rounded-2xl border border-white/10 bg-white/5 p-4"><div class="text-xs uppercase tracking-[0.18em] text-white/45">Lifetime earned</div><div class="mt-2 text-2xl font-semibold text-white">{{ number_format((int) data_get($selectedProfileSummary, 'lifetime_earned_points', 0)) }} pts</div></div>
                            <div class="rounded-2xl border border-white/10 bg-white/5 p-4"><div class="text-xs uppercase tracking-[0.18em] text-white/45">Lifetime redeemed</div><div class="mt-2 text-2xl font-semibold text-white">{{ number_format((int) data_get($selectedProfileSummary, 'lifetime_redeemed_points', 0)) }} pts</div></div>
                            <div class="rounded-2xl border border-white/10 bg-white/5 p-4"><div class="text-xs uppercase tracking-[0.18em] text-white/45">Candle Club</div><div class="mt-2 text-lg font-semibold text-white">{{ data_get($selectedProfileSummary, 'membership_status') === 'active_candle_club_member' ? 'Active member' : 'Not active' }}</div></div>
                            <div class="rounded-2xl border border-white/10 bg-white/5 p-4"><div class="text-xs uppercase tracking-[0.18em] text-white/45">Blocked duplicates</div><div class="mt-2 text-2xl font-semibold text-white">{{ number_format((int) data_get($selectedProfileSummary, 'blocked_duplicate_attempts', 0)) }}</div></div>
                        </div>

                        <form method="POST" action="{{ route('marketing.candle-cash.customers.adjust', $selectedProfile) }}" class="mt-5 grid gap-3 md:grid-cols-4">
                            @csrf
                            <select name="adjustment_type" class="rounded-2xl border border-white/10 bg-white/5 px-4 py-3 text-sm text-white">
                                <option value="add">Add Candle Cash</option>
                                <option value="deduct">Deduct Candle Cash</option>
                            </select>
                            <input type="number" step="0.01" min="0.01" max="500" name="amount" placeholder="Amount" class="rounded-2xl border border-white/10 bg-white/5 px-4 py-3 text-sm text-white placeholder:text-white/35" />
                            <input type="text" name="note" placeholder="Reason" class="rounded-2xl border border-white/10 bg-white/5 px-4 py-3 text-sm text-white placeholder:text-white/35 md:col-span-2" />
                            <button type="submit" class="md:col-span-4 inline-flex rounded-full border border-white/10 bg-white/5 px-4 py-2 text-sm font-semibold text-white/80">Save adjustment</button>
                        </form>

                        <div class="mt-5 grid gap-4 xl:grid-cols-2">
                            <div class="rounded-2xl border border-white/10 bg-white/5 p-4">
                                <div class="text-xs uppercase tracking-[0.18em] text-white/45">Task history</div>
                                <div class="mt-3 space-y-3">
                                    @forelse($selectedProfile->candleCashTaskCompletions->take(12) as $completion)
                                        <div class="rounded-2xl border border-white/10 bg-black/10 p-3">
                                            <div class="font-medium text-white">{{ $completion->task?->title ?: 'Task' }}</div>
                                            <div class="mt-1 text-xs text-white/50">{{ strtoupper($completion->status) }} · {{ optional($completion->created_at)->format('Y-m-d H:i') }}</div>
                                            @if($completion->blocked_reason)
                                                <div class="mt-1 text-xs text-rose-200">{{ str_replace('_', ' ', $completion->blocked_reason) }}</div>
                                            @endif
                                        </div>
                                    @empty
                                        <div class="text-sm text-white/55">No task history yet.</div>
                                    @endforelse
                                </div>
                            </div>
                            <div class="rounded-2xl border border-white/10 bg-white/5 p-4">
                                <div class="text-xs uppercase tracking-[0.18em] text-white/45">Ledger + referrals</div>
                                <div class="mt-3 space-y-3">
                                    @forelse($selectedProfile->candleCashTransactions as $transaction)
                                        <div class="rounded-2xl border border-white/10 bg-black/10 p-3">
                                            <div class="font-medium text-white">{{ $transaction->description ?: $transaction->source }}</div>
                                            <div class="mt-1 text-xs text-white/50">{{ $transaction->points > 0 ? '+' : '' }}{{ $transaction->points }} pts · {{ optional($transaction->created_at)->format('Y-m-d H:i') }}</div>
                                        </div>
                                    @empty
                                        <div class="text-sm text-white/55">No Candle Cash transactions yet.</div>
                                    @endforelse
                                </div>
                                @if($selectedProfile->candleCashReferralsMade->isNotEmpty())
                                    <div class="mt-4 border-t border-white/10 pt-4">
                                        <div class="text-xs uppercase tracking-[0.18em] text-white/45">Referrals made</div>
                                        <div class="mt-3 space-y-3">
                                            @foreach($selectedProfile->candleCashReferralsMade->take(8) as $referral)
                                                <div class="rounded-2xl border border-white/10 bg-black/10 p-3 text-sm text-white/75">
                                                    {{ $referral->referredProfile?->email ?: ($referral->referred_email ?: 'Friend') }} · {{ strtoupper($referral->status) }}
                                                </div>
                                            @endforeach
                                        </div>
                                    </div>
                                @endif
                            </div>
                        </div>
                    @else
                        <div class="text-sm text-white/60">Pick a customer from the left to view balance, referrals, and task history.</div>
                    @endif
                </article>
            </section>
        @elseif($sectionKey === 'referrals')
            <section class="grid gap-4 md:grid-cols-3">
                @foreach(['all' => 'All referrals', 'captured' => 'Captured', 'qualified' => 'Qualified'] as $key => $label)
                    <a href="{{ route('marketing.candle-cash.referrals', ['status' => $key]) }}" wire:navigate class="rounded-[1.7rem] border p-5 {{ $referralStatus === $key ? 'border-sky-300/35 bg-sky-500/10' : 'border-white/10 bg-black/15' }}">
                        <div class="text-[11px] uppercase tracking-[0.24em] text-white/45">{{ $label }}</div>
                        <div class="mt-3 text-4xl font-semibold text-white">{{ number_format((int) data_get($referralSummary, $key === 'all' ? 'captured' : $key, 0)) }}</div>
                    </a>
                @endforeach
            </section>

            <section class="rounded-[1.8rem] border border-white/10 bg-black/15 p-5">
                <div class="overflow-x-auto rounded-[1.4rem] border border-white/10">
                    <table class="min-w-full text-left text-sm text-white/80">
                        <thead class="bg-white/5 text-xs uppercase tracking-[0.18em] text-white/45">
                            <tr>
                                <th class="px-4 py-3">Referrer</th>
                                <th class="px-4 py-3">Friend</th>
                                <th class="px-4 py-3">Status</th>
                                <th class="px-4 py-3">Order</th>
                                <th class="px-4 py-3">Rewards</th>
                                <th class="px-4 py-3">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($referrals as $referral)
                                <tr class="border-t border-white/10">
                                    <td class="px-4 py-3 align-top">
                                        <div class="font-semibold text-white">{{ trim(($referral->referrer->first_name ?? '') . ' ' . ($referral->referrer->last_name ?? '')) ?: ($referral->referrer->email ?? 'Unknown') }}</div>
                                        <div class="mt-1 text-xs text-white/45">{{ $referral->referral_code }}</div>
                                    </td>
                                    <td class="px-4 py-3 align-top text-white/70">{{ trim(($referral->referredProfile->first_name ?? '') . ' ' . ($referral->referredProfile->last_name ?? '')) ?: ($referral->referred_email ?: ($referral->referred_phone ?: 'Unknown friend')) }}</td>
                                    <td class="px-4 py-3 align-top text-white/70">{{ strtoupper($referral->status) }}</td>
                                    <td class="px-4 py-3 align-top text-white/60">{{ $referral->qualifying_order_number ?: '—' }}{{ $referral->qualifying_order_total ? (' · $' . number_format((float) $referral->qualifying_order_total, 2)) : '' }}</td>
                                    <td class="px-4 py-3 align-top text-white/60">Referrer {{ $referral->referrer_reward_status }} · Friend {{ $referral->referred_reward_status }}</td>
                                    <td class="px-4 py-3 align-top">
                                        <form method="POST" action="{{ route('marketing.candle-cash.referrals.reprocess', $referral) }}">
                                            @csrf
                                            <button type="submit" class="inline-flex rounded-full border border-white/10 px-3 py-1 text-xs font-semibold text-white/80">Reprocess</button>
                                        </form>
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="6" class="px-4 py-6 text-center text-white/55">No referrals recorded yet.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <div class="mt-4">{{ $referrals->links() }}</div>
            </section>
        @elseif($sectionKey === 'settings')
            <section class="grid gap-4 xl:grid-cols-3">
                <article class="rounded-[1.8rem] border border-white/10 bg-black/15 p-5">
                    <div class="text-[11px] uppercase tracking-[0.24em] text-white/45">Program</div>
                    <h2 class="mt-2 text-lg font-semibold text-white">Reward basics</h2>
                    <form method="POST" action="{{ route('marketing.candle-cash.settings.save') }}" class="mt-4 space-y-3">
                        @csrf
                        <input type="hidden" name="scope" value="program" />
                        <label class="block text-sm text-white/75">Label<input type="text" name="label" value="{{ data_get($programConfig, 'label', 'Candle Cash') }}" class="mt-2 block w-full rounded-2xl border border-white/10 bg-white/5 px-4 py-3 text-white" /></label>
                        <label class="block text-sm text-white/75">Points per $1<input type="number" min="1" max="100" name="points_per_dollar" value="{{ data_get($programConfig, 'points_per_dollar', 10) }}" class="mt-2 block w-full rounded-2xl border border-white/10 bg-white/5 px-4 py-3 text-white" /></label>
                        <label class="block text-sm text-white/75">Email signup reward<input type="number" step="0.01" min="0" max="50" name="email_signup_reward_amount" value="{{ data_get($programConfig, 'email_signup_reward_amount', 5) }}" class="mt-2 block w-full rounded-2xl border border-white/10 bg-white/5 px-4 py-3 text-white" /></label>
                        <label class="block text-sm text-white/75">SMS signup reward<input type="number" step="0.01" min="0" max="50" name="sms_signup_reward_amount" value="{{ data_get($programConfig, 'sms_signup_reward_amount', 2) }}" class="mt-2 block w-full rounded-2xl border border-white/10 bg-white/5 px-4 py-3 text-white" /></label>
                        <label class="block text-sm text-white/75">Google review reward<input type="number" step="0.01" min="0" max="50" name="google_review_reward_amount" value="{{ data_get($programConfig, 'google_review_reward_amount', 3) }}" class="mt-2 block w-full rounded-2xl border border-white/10 bg-white/5 px-4 py-3 text-white" /></label>
                        <label class="block text-sm text-white/75">Birthday reward<input type="number" step="0.01" min="0" max="50" name="birthday_signup_reward_amount" value="{{ data_get($programConfig, 'birthday_signup_reward_amount', 2) }}" class="mt-2 block w-full rounded-2xl border border-white/10 bg-white/5 px-4 py-3 text-white" /></label>
                        <label class="block text-sm text-white/75">Candle Club join reward<input type="number" step="0.01" min="0" max="50" name="candle_club_join_reward_amount" value="{{ data_get($programConfig, 'candle_club_join_reward_amount', 2) }}" class="mt-2 block w-full rounded-2xl border border-white/10 bg-white/5 px-4 py-3 text-white" /></label>
                        <label class="block text-sm text-white/75">Candle Club vote reward<input type="number" step="0.01" min="0" max="50" name="candle_club_vote_reward_amount" value="{{ data_get($programConfig, 'candle_club_vote_reward_amount', 1) }}" class="mt-2 block w-full rounded-2xl border border-white/10 bg-white/5 px-4 py-3 text-white" /></label>
                        <label class="block text-sm text-white/75">Second order reward<input type="number" step="0.01" min="0" max="50" name="second_order_reward_amount" value="{{ data_get($programConfig, 'second_order_reward_amount', 5) }}" class="mt-2 block w-full rounded-2xl border border-white/10 bg-white/5 px-4 py-3 text-white" /></label>
                        <label class="block text-sm text-white/75">Birthday frequency<select name="birthday_reward_frequency" class="mt-2 block w-full rounded-2xl border border-white/10 bg-white/5 px-4 py-3 text-white"><option value="once_per_year" @selected(data_get($programConfig, 'birthday_reward_frequency') === 'once_per_year')>Once per year</option><option value="once_per_lifetime" @selected(data_get($programConfig, 'birthday_reward_frequency') === 'once_per_lifetime')>Once per lifetime</option></select></label>
                        <label class="block text-sm text-white/75">Homepage signup copy<input type="text" name="homepage_signup_copy" value="{{ data_get($programConfig, 'homepage_signup_copy') }}" class="mt-2 block w-full rounded-2xl border border-white/10 bg-white/5 px-4 py-3 text-white" /></label>
                        <label class="block text-sm text-white/75">Homepage hero title<input type="text" name="homepage_central_title" value="{{ data_get($programConfig, 'homepage_central_title') }}" class="mt-2 block w-full rounded-2xl border border-white/10 bg-white/5 px-4 py-3 text-white" /></label>
                        <label class="block text-sm text-white/75">Homepage hero copy<textarea name="homepage_central_copy" rows="3" class="mt-2 block w-full rounded-2xl border border-white/10 bg-white/5 px-4 py-3 text-white">{{ data_get($programConfig, 'homepage_central_copy') }}</textarea></label>
                        <button type="submit" class="inline-flex rounded-full border border-white/10 bg-white/5 px-4 py-2 text-sm font-semibold text-white/80">Save program settings</button>
                    </form>
                </article>

                <article class="rounded-[1.8rem] border border-white/10 bg-black/15 p-5">
                    <div class="text-[11px] uppercase tracking-[0.24em] text-white/45">Referrals</div>
                    <h2 class="mt-2 text-lg font-semibold text-white">Friend bonus rules</h2>
                    <form method="POST" action="{{ route('marketing.candle-cash.settings.save') }}" class="mt-4 space-y-3">
                        @csrf
                        <input type="hidden" name="scope" value="referral" />
                        <label class="flex items-center gap-2 text-sm text-white/75"><input type="checkbox" name="enabled" value="1" @checked(data_get($referralConfig, 'enabled', true)) /> Enable referral program</label>
                        <label class="block text-sm text-white/75">Referrer reward<input type="number" step="0.01" min="0" max="50" name="referrer_reward_amount" value="{{ data_get($referralConfig, 'referrer_reward_amount', 10) }}" class="mt-2 block w-full rounded-2xl border border-white/10 bg-white/5 px-4 py-3 text-white" /></label>
                        <label class="block text-sm text-white/75">New friend reward<input type="number" step="0.01" min="0" max="50" name="referred_reward_amount" value="{{ data_get($referralConfig, 'referred_reward_amount', 5) }}" class="mt-2 block w-full rounded-2xl border border-white/10 bg-white/5 px-4 py-3 text-white" /></label>
                        <label class="block text-sm text-white/75">Qualifying event<select name="qualifying_event" class="mt-2 block w-full rounded-2xl border border-white/10 bg-white/5 px-4 py-3 text-white"><option value="first_order" @selected(data_get($referralConfig, 'qualifying_event') === 'first_order')>First order</option><option value="account_or_first_order" @selected(data_get($referralConfig, 'qualifying_event') === 'account_or_first_order')>Account or first order</option></select></label>
                        <label class="block text-sm text-white/75">Minimum order total<input type="number" step="0.01" min="0" name="qualifying_min_order_total" value="{{ data_get($referralConfig, 'qualifying_min_order_total') }}" class="mt-2 block w-full rounded-2xl border border-white/10 bg-white/5 px-4 py-3 text-white" /></label>
                        <label class="block text-sm text-white/75">Headline<input type="text" name="program_headline" value="{{ data_get($referralConfig, 'program_headline') }}" class="mt-2 block w-full rounded-2xl border border-white/10 bg-white/5 px-4 py-3 text-white" /></label>
                        <label class="block text-sm text-white/75">Copy<textarea name="program_copy" rows="3" class="mt-2 block w-full rounded-2xl border border-white/10 bg-white/5 px-4 py-3 text-white">{{ data_get($referralConfig, 'program_copy') }}</textarea></label>
                        <button type="submit" class="inline-flex rounded-full border border-white/10 bg-white/5 px-4 py-2 text-sm font-semibold text-white/80">Save referral settings</button>
                    </form>
                </article>

                <article class="rounded-[1.8rem] border border-white/10 bg-black/15 p-5">
                    <div class="text-[11px] uppercase tracking-[0.24em] text-white/45">Customer copy</div>
                    <h2 class="mt-2 text-lg font-semibold text-white">Candle Cash Central text</h2>
                    <form method="POST" action="{{ route('marketing.candle-cash.settings.save') }}" class="mt-4 space-y-3">
                        @csrf
                        <input type="hidden" name="scope" value="frontend" />
                        <label class="block text-sm text-white/75">Title<input type="text" name="central_title" value="{{ data_get($frontendConfig, 'central_title') }}" class="mt-2 block w-full rounded-2xl border border-white/10 bg-white/5 px-4 py-3 text-white" /></label>
                        <label class="block text-sm text-white/75">Subtitle<textarea name="central_subtitle" rows="3" class="mt-2 block w-full rounded-2xl border border-white/10 bg-white/5 px-4 py-3 text-white">{{ data_get($frontendConfig, 'central_subtitle') }}</textarea></label>
                        <label class="block text-sm text-white/75">Approval help<input type="text" name="faq_approval_copy" value="{{ data_get($frontendConfig, 'faq_approval_copy') }}" class="mt-2 block w-full rounded-2xl border border-white/10 bg-white/5 px-4 py-3 text-white" /></label>
                        <label class="block text-sm text-white/75">Stacking help<input type="text" name="faq_stack_copy" value="{{ data_get($frontendConfig, 'faq_stack_copy') }}" class="mt-2 block w-full rounded-2xl border border-white/10 bg-white/5 px-4 py-3 text-white" /></label>
                        <label class="block text-sm text-white/75">Pending help<input type="text" name="faq_pending_copy" value="{{ data_get($frontendConfig, 'faq_pending_copy') }}" class="mt-2 block w-full rounded-2xl border border-white/10 bg-white/5 px-4 py-3 text-white" /></label>
                        <label class="block text-sm text-white/75">Verification help<input type="text" name="faq_verification_copy" value="{{ data_get($frontendConfig, 'faq_verification_copy') }}" class="mt-2 block w-full rounded-2xl border border-white/10 bg-white/5 px-4 py-3 text-white" /></label>
                        <button type="submit" class="inline-flex rounded-full border border-white/10 bg-white/5 px-4 py-2 text-sm font-semibold text-white/80">Save frontend copy</button>
                    </form>
                </article>

                <article class="rounded-[1.8rem] border border-white/10 bg-black/15 p-5">
                    <div class="text-[11px] uppercase tracking-[0.24em] text-white/45">Integrations</div>
                    <h2 class="mt-2 text-lg font-semibold text-white">Verification hooks</h2>
                    <form method="POST" action="{{ route('marketing.candle-cash.settings.save') }}" class="mt-4 space-y-3">
                        @csrf
                        <input type="hidden" name="scope" value="integrations" />
                        <label class="flex items-center gap-2 text-sm text-white/75"><input type="checkbox" name="google_review_enabled" value="1" @checked(data_get($integrationConfig, 'google_review_enabled', false)) /> Google review matching enabled</label>
                        <label class="block text-sm text-white/75">Google review URL<input type="text" name="google_review_url" value="{{ data_get($integrationConfig, 'google_review_url') }}" class="mt-2 block w-full rounded-2xl border border-white/10 bg-white/5 px-4 py-3 text-white" /></label>
                        <label class="block text-sm text-white/75">Google Business location id<input type="text" name="google_business_location_id" value="{{ data_get($integrationConfig, 'google_business_location_id') }}" class="mt-2 block w-full rounded-2xl border border-white/10 bg-white/5 px-4 py-3 text-white" /></label>
                        <label class="block text-sm text-white/75">Google matching strategy<input type="text" name="google_review_matching_strategy" value="{{ data_get($integrationConfig, 'google_review_matching_strategy', 'email_phone_or_profile') }}" class="mt-2 block w-full rounded-2xl border border-white/10 bg-white/5 px-4 py-3 text-white" /></label>
                        <label class="flex items-center gap-2 text-sm text-white/75"><input type="checkbox" name="product_review_enabled" value="1" @checked(data_get($integrationConfig, 'product_review_enabled', false)) /> Product review integration enabled</label>
                        <label class="block text-sm text-white/75">Product review platform<input type="text" name="product_review_platform" value="{{ data_get($integrationConfig, 'product_review_platform') }}" class="mt-2 block w-full rounded-2xl border border-white/10 bg-white/5 px-4 py-3 text-white" /></label>
                        <label class="block text-sm text-white/75">Product review matching strategy<input type="text" name="product_review_matching_strategy" value="{{ data_get($integrationConfig, 'product_review_matching_strategy', 'profile_or_external_customer') }}" class="mt-2 block w-full rounded-2xl border border-white/10 bg-white/5 px-4 py-3 text-white" /></label>
                        <label class="flex items-center gap-2 text-sm text-white/75"><input type="checkbox" name="email_signup_enabled" value="1" @checked(data_get($integrationConfig, 'email_signup_enabled', true)) /> Email signup task enabled</label>
                        <label class="flex items-center gap-2 text-sm text-white/75"><input type="checkbox" name="sms_signup_enabled" value="1" @checked(data_get($integrationConfig, 'sms_signup_enabled', true)) /> SMS signup task enabled</label>
                        <label class="block text-sm text-white/75">Candle Club locked CTA URL<input type="text" name="vote_locked_join_url" value="{{ data_get($integrationConfig, 'vote_locked_join_url') }}" class="mt-2 block w-full rounded-2xl border border-white/10 bg-white/5 px-4 py-3 text-white" /></label>
                        <button type="submit" class="inline-flex rounded-full border border-white/10 bg-white/5 px-4 py-2 text-sm font-semibold text-white/80">Save integration settings</button>
                    </form>
                </article>
            </section>
        @endif
    </div>
</x-layouts::app>

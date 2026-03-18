@php
    use Illuminate\Support\Str;

    $section = $section ?? \App\Support\Marketing\CandleCashSectionRegistry::section($sectionKey ?? 'dashboard');
    $sections = $sections ?? [];
@endphp

<x-layouts::app :title="'Candle Cash - ' . ($section['label'] ?? 'Candle Cash')">
    <div class="space-y-6">
        <x-marketing.partials.candle-cash-shell :section="$section" :sections="$sections" />

        @if($sectionKey === 'dashboard')
            @include('shared.candle-cash.rewards-overview', [
                'overview' => $dashboard ?? [],
                'earnUrl' => route('marketing.candle-cash.tasks'),
                'redeemUrl' => route('marketing.candle-cash.redeem'),
                'wireNavigate' => true,
                'theme' => 'backstage',
            ])
        @elseif($sectionKey === 'tasks')
            <section class="rounded-[1.8rem] border border-white/10 bg-black/15 p-5">
                <div class="flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
                    <div>
                        <div class="text-[11px] uppercase tracking-[0.24em] text-white/45">Ways to Earn</div>
                        <h2 class="mt-2 text-lg font-semibold text-white">Review and manage live earn rules</h2>
                        <p class="mt-2 max-w-2xl text-sm text-white/65">These are the live Candle Cash tasks currently powering how customers earn Candle Cash.</p>
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
        @elseif($sectionKey === 'redeem')
            <section class="rounded-[1.8rem] border border-white/10 bg-black/15 p-5">
                <div class="flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
                    <div class="max-w-2xl">
                        <div class="text-[11px] uppercase tracking-[0.24em] text-white/45">Ways to Redeem</div>
                        <h2 class="mt-2 text-lg font-semibold text-white">Review and manage live reward rows</h2>
                        <p class="mt-2 text-sm leading-7 text-white/65">
                            These are the live Candle Cash reward rows customers can currently redeem against.
                        </p>
                    </div>
                    <div class="rounded-2xl border border-white/10 bg-white/5 px-4 py-3 text-sm text-white/70">
                        {{ number_format((int) data_get($redeemSummary, 'enabled', 0)) }} active · {{ number_format((int) data_get($redeemSummary, 'total', 0)) }} total
                    </div>
                </div>
            </section>

            <section class="grid gap-4 md:grid-cols-3">
                @foreach([
                    ['label' => 'Active rewards', 'value' => number_format((int) data_get($redeemSummary, 'enabled', 0)), 'detail' => 'Reward rows currently available to customers.'],
                    ['label' => 'Inactive rewards', 'value' => number_format((int) data_get($redeemSummary, 'disabled', 0)), 'detail' => 'Rows kept in Backstage but not currently active.'],
                    ['label' => 'Reward rows', 'value' => number_format((int) data_get($redeemSummary, 'total', 0)), 'detail' => 'Total redeem options currently stored in Candle Cash.'],
                ] as $card)
                    <article class="rounded-[1.7rem] border border-white/10 bg-black/15 p-5">
                        <div class="text-[11px] uppercase tracking-[0.24em] text-white/45">{{ $card['label'] }}</div>
                        <div class="mt-3 text-3xl font-semibold text-white">{{ $card['value'] }}</div>
                        <p class="mt-2 text-sm text-white/62">{{ $card['detail'] }}</p>
                    </article>
                @endforeach
            </section>

            <section class="space-y-4">
                @forelse(($redeemRules ?? []) as $reward)
                    <article class="rounded-[1.8rem] border border-white/10 bg-black/15 p-5">
                        <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                            <div class="max-w-2xl">
                                <div class="text-[11px] uppercase tracking-[0.24em] text-white/45">{{ $reward['reward_type_label'] ?? 'Reward' }}</div>
                                <h3 class="mt-2 text-lg font-semibold text-white">{{ $reward['title'] }}</h3>
                                <p class="mt-2 text-sm leading-7 text-white/65">{{ $reward['description'] ?: 'No description yet.' }}</p>
                            </div>
                            <div class="rounded-2xl border border-white/10 bg-white/5 px-4 py-3 text-sm text-white/70">
                                {{ $reward['enabled'] ? 'Active' : 'Inactive' }}
                            </div>
                        </div>

                        <form method="POST" action="{{ route('marketing.candle-cash.redeem.update', $reward['id']) }}" class="mt-5 grid gap-3 md:grid-cols-2">
                            @csrf
                            @method('PATCH')
                            <input type="hidden" name="enabled" value="0" />

                            <label class="block text-sm text-white/75">
                                Title
                                <input type="text" name="title" value="{{ old('title', $reward['title']) }}" class="mt-2 block w-full rounded-2xl border border-white/10 bg-white/5 px-4 py-3 text-white" />
                            </label>

                            <label class="block text-sm text-white/75">
                                Candle Cash cost
                                <input type="number" step="0.01" min="0" max="50000" name="candle_cash_cost" value="{{ old('candle_cash_cost', $reward['candle_cash_cost'] ?? 0) }}" class="mt-2 block w-full rounded-2xl border border-white/10 bg-white/5 px-4 py-3 text-white" />
                            </label>

                            <label class="block text-sm text-white/75 md:col-span-2">
                                Description
                                <textarea name="description" rows="3" class="mt-2 block w-full rounded-2xl border border-white/10 bg-white/5 px-4 py-3 text-white">{{ old('description', $reward['description']) }}</textarea>
                            </label>

                            <label class="block text-sm text-white/75">
                                Reward value
                                <input type="text" name="reward_value" value="{{ old('reward_value', $reward['reward_value']) }}" class="mt-2 block w-full rounded-2xl border border-white/10 bg-white/5 px-4 py-3 text-white" />
                                <span class="mt-2 block text-xs text-white/45">Current display: {{ $reward['value_display'] ?: 'No value set' }}</span>
                            </label>

                            <label class="flex items-center gap-2 self-end rounded-2xl border border-white/10 bg-white/5 px-4 py-3 text-sm text-white/75">
                                <input type="checkbox" name="enabled" value="1" @checked(old('enabled', $reward['enabled'])) />
                                Active reward row
                            </label>

                            <div class="md:col-span-2">
                                <button type="submit" class="inline-flex rounded-full border border-white/10 bg-white/5 px-4 py-2 text-sm font-semibold text-white/80">Save reward</button>
                            </div>
                        </form>
                    </article>
                @empty
                    <article class="rounded-[1.8rem] border border-white/10 bg-black/15 p-5 text-sm text-white/60">
                        No redeem rules are currently configured.
                    </article>
                @endforelse
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
        @elseif($sectionKey === 'reviews')
            <section class="grid gap-4 md:grid-cols-5">
                @foreach([
                    'all' => 'All reviews',
                    'approved' => 'Approved',
                    'pending' => 'Pending',
                    'rejected' => 'Rejected',
                    'imported' => 'Imported',
                ] as $key => $label)
                    <a href="{{ route('marketing.candle-cash.reviews', ['status' => $key === 'imported' ? 'all' : $key, 'source' => $key === 'imported' ? 'imported' : 'all', 'search' => data_get($reviewFilters, 'search'), 'rating' => data_get($reviewFilters, 'rating')]) }}" wire:navigate class="rounded-[1.7rem] border p-5 {{ (($key === 'imported' ? 'all' : $key) === data_get($reviewFilters, 'status') && ($key !== 'imported' || data_get($reviewFilters, 'source') === 'imported')) ? 'border-amber-300/35 bg-amber-500/10' : 'border-white/10 bg-black/15' }}">
                        <div class="text-[11px] uppercase tracking-[0.24em] text-white/45">{{ $label }}</div>
                        <div class="mt-3 text-4xl font-semibold text-white">{{ number_format((int) data_get($reviewSummary, $key, 0)) }}</div>
                    </a>
                @endforeach
            </section>

            <section class="grid gap-4 xl:grid-cols-[minmax(0,1.05fr),minmax(340px,0.95fr)]">
                <article class="rounded-[1.8rem] border border-white/10 bg-black/15 p-5">
                    <form method="GET" action="{{ route('marketing.candle-cash.reviews') }}" class="grid gap-3 md:grid-cols-[minmax(0,1fr),180px,180px,180px,auto]">
                        <input type="text" name="search" value="{{ data_get($reviewFilters, 'search') }}" placeholder="Search product, reviewer, title, body" class="rounded-2xl border border-white/10 bg-white/5 px-4 py-3 text-sm text-white placeholder:text-white/35" />
                        <select name="status" class="rounded-2xl border border-white/10 bg-white/5 px-4 py-3 text-sm text-white">
                            @foreach(['all' => 'All statuses', 'approved' => 'Approved', 'pending' => 'Pending', 'rejected' => 'Rejected'] as $value => $label)
                                <option value="{{ $value }}" @selected(data_get($reviewFilters, 'status') === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                        <select name="rating" class="rounded-2xl border border-white/10 bg-white/5 px-4 py-3 text-sm text-white">
                            <option value="all">All ratings</option>
                            @for($stars = 5; $stars >= 1; $stars--)
                                <option value="{{ $stars }}" @selected((string) data_get($reviewFilters, 'rating') === (string) $stars)>{{ $stars }} stars</option>
                            @endfor
                        </select>
                        <select name="source" class="rounded-2xl border border-white/10 bg-white/5 px-4 py-3 text-sm text-white">
                            <option value="all" @selected(data_get($reviewFilters, 'source') === 'all')>All sources</option>
                            <option value="native" @selected(data_get($reviewFilters, 'source') === 'native')>Native</option>
                            <option value="imported" @selected(data_get($reviewFilters, 'source') === 'imported')>Imported</option>
                        </select>
                        <button type="submit" class="inline-flex rounded-full border border-white/10 bg-white/5 px-4 py-2 text-sm font-semibold text-white/80">Apply</button>
                    </form>

                    <div class="mt-5 overflow-x-auto rounded-[1.4rem] border border-white/10">
                        <table class="min-w-full text-left text-sm text-white/80">
                            <thead class="bg-white/5 text-xs uppercase tracking-[0.18em] text-white/45">
                                <tr>
                                    <th class="px-4 py-3">Product</th>
                                    <th class="px-4 py-3">Reviewer</th>
                                    <th class="px-4 py-3">Rating</th>
                                    <th class="px-4 py-3">Status</th>
                                    <th class="px-4 py-3">Source</th>
                                    <th class="px-4 py-3">Submitted</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($reviews as $review)
                                    <tr class="border-t border-white/10">
                                        <td class="px-4 py-3 align-top">
                                            <a href="{{ route('marketing.candle-cash.reviews', array_filter(['search' => data_get($reviewFilters, 'search'), 'status' => data_get($reviewFilters, 'status'), 'rating' => data_get($reviewFilters, 'rating'), 'source' => data_get($reviewFilters, 'source'), 'review' => $review->id])) }}" wire:navigate class="font-semibold text-white">{{ $review->product_title ?: ($review->product_handle ?: 'Product #' . $review->product_id) }}</a>
                                            <div class="mt-1 text-xs text-white/45">{{ $review->product_handle ?: $review->product_id ?: 'No product handle' }}</div>
                                        </td>
                                        <td class="px-4 py-3 align-top">
                                            <div class="font-medium text-white">{{ $review->displayReviewerName() }}</div>
                                            <div class="mt-1 text-xs text-white/45">{{ $review->reviewer_email ?: ($review->profile->email ?? 'No email') }}</div>
                                        </td>
                                        <td class="px-4 py-3 align-top text-white/70">{{ str_repeat('★', max(0, (int) $review->rating)) }}{{ str_repeat('☆', max(0, 5 - (int) $review->rating)) }}</td>
                                        <td class="px-4 py-3 align-top text-white/70">{{ strtoupper($review->status ?: 'approved') }}</td>
                                        <td class="px-4 py-3 align-top text-white/60">{{ $review->submission_source ?: 'native' }}</td>
                                        <td class="px-4 py-3 align-top text-white/50">{{ optional($review->submitted_at ?: $review->created_at)->format('Y-m-d H:i') }}</td>
                                    </tr>
                                @empty
                                    <tr><td colspan="6" class="px-4 py-6 text-center text-white/55">No product reviews match the current filters.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                    <div class="mt-4">{{ $reviews->links() }}</div>
                </article>

                <article class="rounded-[1.8rem] border border-white/10 bg-black/15 p-5">
                    <div class="text-[11px] uppercase tracking-[0.24em] text-white/45">Review detail</div>
                    @if($selectedReview)
                        <h2 class="mt-2 text-lg font-semibold text-white">{{ $selectedReview->product_title ?: ($selectedReview->product_handle ?: 'Product review') }}</h2>
                        <div class="mt-2 text-sm text-white/60">{{ $selectedReview->displayReviewerName() }} · {{ optional($selectedReview->submitted_at ?: $selectedReview->created_at)->format('Y-m-d H:i') }}</div>

                        <div class="mt-5 grid gap-3 md:grid-cols-2">
                            <div class="rounded-2xl border border-white/10 bg-white/5 p-4">
                                <div class="text-xs uppercase tracking-[0.18em] text-white/45">Rating</div>
                                <div class="mt-2 text-2xl text-white">{{ str_repeat('★', max(0, (int) $selectedReview->rating)) }}{{ str_repeat('☆', max(0, 5 - (int) $selectedReview->rating)) }}</div>
                            </div>
                            <div class="rounded-2xl border border-white/10 bg-white/5 p-4">
                                <div class="text-xs uppercase tracking-[0.18em] text-white/45">Source</div>
                                <div class="mt-2 text-lg font-semibold text-white">{{ $selectedReview->submission_source ?: 'native' }}</div>
                                <div class="mt-1 text-xs text-white/45">{{ $selectedReview->status ?: 'approved' }}</div>
                            </div>
                        </div>

                        @if($selectedReview->title)
                            <div class="mt-5 rounded-2xl border border-white/10 bg-white/5 p-4">
                                <div class="text-xs uppercase tracking-[0.18em] text-white/45">Headline</div>
                                <div class="mt-2 text-lg font-semibold text-white">{{ $selectedReview->title }}</div>
                            </div>
                        @endif

                        <div class="mt-5 rounded-2xl border border-white/10 bg-white/5 p-4">
                            <div class="text-xs uppercase tracking-[0.18em] text-white/45">Review body</div>
                            <div class="mt-3 text-sm leading-7 text-white/80">{{ $selectedReview->body }}</div>
                        </div>

                        <div class="mt-5 grid gap-3">
                            <form method="POST" action="{{ route('marketing.candle-cash.reviews.approve', $selectedReview) }}" class="space-y-3 rounded-2xl border border-emerald-300/20 bg-emerald-500/10 p-4">
                                @csrf
                                <div class="text-sm font-semibold text-white">Approve review</div>
                                <textarea name="moderation_notes" rows="2" placeholder="Optional note" class="block w-full rounded-2xl border border-white/10 bg-black/10 px-3 py-2 text-sm text-white placeholder:text-white/35"></textarea>
                                <button type="submit" class="inline-flex rounded-full border border-emerald-300/35 px-4 py-2 text-sm font-semibold text-white">Approve</button>
                            </form>

                            <form method="POST" action="{{ route('marketing.candle-cash.reviews.reject', $selectedReview) }}" class="space-y-3 rounded-2xl border border-rose-300/20 bg-rose-500/10 p-4">
                                @csrf
                                <div class="text-sm font-semibold text-white">Reject review</div>
                                <textarea name="moderation_notes" rows="2" required placeholder="Why should this stay hidden?" class="block w-full rounded-2xl border border-white/10 bg-black/10 px-3 py-2 text-sm text-white placeholder:text-white/35"></textarea>
                                <button type="submit" class="inline-flex rounded-full border border-rose-300/35 px-4 py-2 text-sm font-semibold text-white">Reject</button>
                            </form>

                            <form method="POST" action="{{ route('marketing.candle-cash.reviews.delete', $selectedReview) }}" class="rounded-2xl border border-white/10 bg-white/5 p-4">
                                @csrf
                                <div class="flex items-center justify-between gap-3">
                                    <div>
                                        <div class="text-sm font-semibold text-white">Delete review</div>
                                        <div class="mt-1 text-xs text-white/50">Use this only when the review should be removed entirely.</div>
                                    </div>
                                    <button type="submit" class="inline-flex rounded-full border border-white/10 px-4 py-2 text-sm font-semibold text-white/80">Delete</button>
                                </div>
                            </form>
                        </div>
                    @else
                        <div class="mt-3 text-sm text-white/60">Pick a review from the left to see details and moderation actions.</div>
                    @endif
                </article>
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
                                        <div>{{ app(\App\Services\Marketing\CandleCashService::class)->formatRewardCurrency(app(\App\Services\Marketing\CandleCashService::class)->amountFromPoints((int) ($profile->candleCashBalance->balance ?? 0))) }}</div>
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
                            <div class="rounded-2xl border border-white/10 bg-white/5 p-4"><div class="text-xs uppercase tracking-[0.18em] text-white/45">Balance</div><div class="mt-2 text-2xl font-semibold text-white">${{ number_format((float) data_get($selectedProfileSummary, 'balance_amount', 0), 2) }}</div></div>
                            <div class="rounded-2xl border border-white/10 bg-white/5 p-4"><div class="text-xs uppercase tracking-[0.18em] text-white/45">Lifetime earned</div><div class="mt-2 text-2xl font-semibold text-white">${{ number_format((float) data_get($selectedProfileSummary, 'lifetime_earned_amount', 0), 2) }}</div></div>
                            <div class="rounded-2xl border border-white/10 bg-white/5 p-4"><div class="text-xs uppercase tracking-[0.18em] text-white/45">Lifetime redeemed</div><div class="mt-2 text-2xl font-semibold text-white">${{ number_format((float) data_get($selectedProfileSummary, 'lifetime_redeemed_amount', 0), 2) }}</div></div>
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
                                            <div class="mt-1 text-xs text-white/50">{{ app(\App\Services\Marketing\CandleCashService::class)->candleCashAmountLabelFromPoints((int) $transaction->points, true) }} · {{ optional($transaction->created_at)->format('Y-m-d H:i') }}</div>
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
        @elseif($sectionKey === 'gifts-report')
            @php
                $giftReport = $giftReport ?? ['totals' => [], 'breakdowns' => [], 'transactions' => [], 'conversion' => [], 'range' => ['from' => null, 'to' => null]];
                $reportFilters = $reportFilters ?? ['from' => '', 'to' => ''];
            @endphp
            <section class="space-y-6 rounded-[1.8rem] border border-white/10 bg-black/15 p-5">
                <div class="flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
                    <div>
                        <div class="text-[11px] uppercase tracking-[0.24em] text-white/45">Gifts</div>
                        <h2 class="mt-2 text-lg font-semibold text-white">Gift Attribution & Post-Gift Revenue</h2>
                        <p class="mt-2 text-sm text-white/65">Track why Candle Cash gifts were sent, how notifications performed, and whether those recipients later converted.</p>
                    </div>
                    <form method="GET" action="{{ route('marketing.candle-cash.gifts-report') }}" class="flex flex-wrap items-center gap-2">
                        <input type="date" name="from" value="{{ $reportFilters['from'] ?? '' }}" class="rounded-2xl border border-white/10 bg-white/5 px-4 py-2 text-sm text-white" />
                        <input type="date" name="to" value="{{ $reportFilters['to'] ?? '' }}" class="rounded-2xl border border-white/10 bg-white/5 px-4 py-2 text-sm text-white" />
                        <button type="submit" class="inline-flex rounded-full border border-white/10 bg-white/5 px-4 py-2 text-xs font-semibold text-white/80">Refresh</button>
                    </form>
                </div>
                <div class="grid gap-4 md:grid-cols-4">
                    <article class="rounded-[1.6rem] border border-white/10 bg-black/15 p-4">
                        <div class="text-[11px] uppercase tracking-[0.24em] text-white/45">Gift transactions</div>
                        <div class="mt-2 text-3xl font-semibold text-white">{{ number_format((int) data_get($giftReport, 'totals.gift_transactions', 0)) }}</div>
                        <p class="mt-2 text-xs text-white/60">Total gifts in the selected range.</p>
                    </article>
                    <article class="rounded-[1.6rem] border border-white/10 bg-black/15 p-4">
                        <div class="text-[11px] uppercase tracking-[0.24em] text-white/45">Gift Candle Cash</div>
                        <div class="mt-2 text-3xl font-semibold text-white">${{ number_format((float) data_get($giftReport, 'totals.gift_amount', 0), 2) }}</div>
                        <p class="mt-2 text-xs text-white/60">Candle Cash granted via gift send activity.</p>
                    </article>
                    <article class="rounded-[1.6rem] border border-white/10 bg-black/15 p-4">
                        <div class="text-[11px] uppercase tracking-[0.24em] text-white/45">Gift amount</div>
                        <div class="mt-2 text-3xl font-semibold text-white">${{ number_format((float) data_get($giftReport, 'totals.gift_amount', 0), 2) }}</div>
                        <p class="mt-2 text-xs text-white/60">Approximate Candle Cash liability from gifts.</p>
                    </article>
                    <article class="rounded-[1.6rem] border border-white/10 bg-black/15 p-4">
                        <div class="text-[11px] uppercase tracking-[0.24em] text-white/45">Gifted customers with orders</div>
                        <div class="mt-2 text-3xl font-semibold text-white">{{ number_format((int) data_get($giftReport, 'conversion.gifted_customers_with_orders', 0)) }}</div>
                        <p class="mt-2 text-xs text-white/60">Customers who placed an order after receiving a gift.</p>
                    </article>
                </div>
                <div class="grid gap-4 lg:grid-cols-3">
                    <article class="rounded-[1.6rem] border border-white/10 bg-black/15 p-4">
                        <div class="text-[11px] uppercase tracking-[0.24em] text-white/45">Breakdown by intent</div>
                        <div class="-mx-2 mt-3 overflow-hidden rounded-[1.3rem] border border-white/5">
                            <table class="min-w-full text-left text-xs uppercase tracking-[0.18em] text-white/45">
                                <thead class="bg-white/5">
                                    <tr>
                                        <th class="px-3 py-2">Intent</th>
                                        <th class="px-3 py-2">Gifts</th>
                                        <th class="px-3 py-2">Candle Cash</th>
                                    </tr>
                                </thead>
                                <tbody class="text-sm text-white/70">
                                    @forelse(data_get($giftReport, 'breakdowns.intent', []) as $intent)
                                        <tr class="border-t border-white/5">
                                            <td class="px-3 py-2 font-medium text-white">{{ $intent['label'] ?? 'Unspecified' }}</td>
                                            <td class="px-3 py-2">{{ number_format((int) ($intent['count'] ?? 0)) }}</td>
                                            <td class="px-3 py-2">${{ number_format((float) ($intent['candle_cash_amount'] ?? 0), 2) }}</td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="3" class="px-3 py-3 text-xs text-white/55">No intent data yet.</td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </article>
                    <article class="rounded-[1.6rem] border border-white/10 bg-black/15 p-4">
                        <div class="text-[11px] uppercase tracking-[0.24em] text-white/45">Breakdown by origin</div>
                        <div class="-mx-2 mt-3 overflow-hidden rounded-[1.3rem] border border-white/5">
                            <table class="min-w-full text-left text-xs uppercase tracking-[0.18em] text-white/45">
                                <thead class="bg-white/5">
                                    <tr>
                                        <th class="px-3 py-2">Origin</th>
                                        <th class="px-3 py-2">Gifts</th>
                                        <th class="px-3 py-2">Candle Cash</th>
                                    </tr>
                                </thead>
                                <tbody class="text-sm text-white/70">
                                    @forelse(data_get($giftReport, 'breakdowns.origin', []) as $origin)
                                        <tr class="border-t border-white/5">
                                            <td class="px-3 py-2 font-medium text-white">{{ $origin['label'] ?? 'Unspecified' }}</td>
                                            <td class="px-3 py-2">{{ number_format((int) ($origin['count'] ?? 0)) }}</td>
                                            <td class="px-3 py-2">${{ number_format((float) ($origin['candle_cash_amount'] ?? 0), 2) }}</td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="3" class="px-3 py-3 text-xs text-white/55">No origin data yet.</td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </article>
                    <article class="rounded-[1.6rem] border border-white/10 bg-black/15 p-4">
                        <div class="text-[11px] uppercase tracking-[0.24em] text-white/45">Breakdown by notification</div>
                        <div class="-mx-2 mt-3 overflow-hidden rounded-[1.3rem] border border-white/5">
                            <table class="min-w-full text-left text-xs uppercase tracking-[0.18em] text-white/45">
                                <thead class="bg-white/5">
                                    <tr>
                                        <th class="px-3 py-2">Status</th>
                                        <th class="px-3 py-2">Gifts</th>
                                        <th class="px-3 py-2">Candle Cash</th>
                                    </tr>
                                </thead>
                                <tbody class="text-sm text-white/70">
                                    @forelse(data_get($giftReport, 'breakdowns.notification', []) as $notification)
                                        <tr class="border-t border-white/5">
                                            <td class="px-3 py-2 font-medium text-white">{{ $notification['label'] ?? 'Unspecified' }}</td>
                                            <td class="px-3 py-2">{{ number_format((int) ($notification['count'] ?? 0)) }}</td>
                                            <td class="px-3 py-2">${{ number_format((float) ($notification['candle_cash_amount'] ?? 0), 2) }}</td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="3" class="px-3 py-3 text-xs text-white/55">No notification data yet.</td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </article>
                </div>
                <div class="grid gap-4 lg:grid-cols-[minmax(0,1.1fr),minmax(0,0.9fr)]">
                    <article class="rounded-[1.6rem] border border-white/10 bg-black/15 p-4">
                        <div class="flex items-center justify-between">
                            <div class="text-[11px] uppercase tracking-[0.24em] text-white/45">Actor breakdown</div>
                        </div>
                        <div class="mt-3 overflow-x-auto rounded-[1.3rem] border border-white/5">
                            <table class="min-w-full text-left text-sm text-white/70">
                                <thead class="bg-white/5 text-xs uppercase tracking-[0.18em] text-white/45">
                                    <tr>
                                        <th class="px-3 py-2">Actor</th>
                                        <th class="px-3 py-2">Gifts</th>
                                        <th class="px-3 py-2">Candle Cash</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse(data_get($giftReport, 'breakdowns.actor', []) as $actor)
                                        <tr class="border-t border-white/5 text-white/70">
                                            <td class="px-3 py-2 font-medium text-white">{{ $actor['label'] ?? 'Admin' }}</td>
                                            <td class="px-3 py-2">{{ number_format((int) ($actor['count'] ?? 0)) }}</td>
                                            <td class="px-3 py-2">${{ number_format((float) ($actor['candle_cash_amount'] ?? 0), 2) }}</td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="3" class="px-3 py-3 text-xs text-white/55">No actor data yet.</td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </article>
                    <article class="rounded-[1.6rem] border border-white/10 bg-black/15 p-4">
                        <div class="text-[11px] uppercase tracking-[0.24em] text-white/45">Conversions</div>
                        <div class="mt-2 text-3xl font-semibold text-white">{{ number_format((int) data_get($giftReport, 'conversion.converted_orders', 0)) }}</div>
                        <p class="text-xs text-white/60">Orders found after gifts.</p>
                        <div class="mt-4 text-sm text-white/70">
                            <div>Gifted customers with orders: {{ number_format((int) data_get($giftReport, 'conversion.gifted_customers_with_orders', 0)) }}</div>
                            <div class="mt-2">Revenue after gifts: ${{ number_format((float) data_get($giftReport, 'conversion.revenue_after_gifts', 0), 2) }}</div>
                        </div>
                    </article>
                </div>
                <article class="rounded-[1.6rem] border border-white/10 bg-black/15 p-4">
                    <div class="flex flex-wrap items-baseline justify-between gap-2">
                        <div>
                            <div class="text-[11px] uppercase tracking-[0.24em] text-white/45">Gift transactions</div>
                            <div class="text-xs text-white/60">{{ data_get($giftReport, 'range.from') ?? 'Beginning' }} – {{ data_get($giftReport, 'range.to') ?? 'Now' }}</div>
                        </div>
                        <span class="text-xs text-white/50">Showing {{ number_format(count(data_get($giftReport, 'transactions', []))) }} rows</span>
                    </div>
                    <div class="mt-3 overflow-x-auto rounded-[1.3rem] border border-white/5">
                        <table class="min-w-full text-left text-sm text-white/70">
                            <thead class="bg-white/5 text-xs uppercase tracking-[0.18em] text-white/45">
                                <tr>
                                    <th class="px-3 py-2">Date</th>
                                    <th class="px-3 py-2">Candle Cash</th>
                                    <th class="px-3 py-2">Intent</th>
                                    <th class="px-3 py-2">Origin</th>
                                    <th class="px-3 py-2">Notification</th>
                                    <th class="px-3 py-2">Campaign</th>
                                    <th class="px-3 py-2">Description</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse(data_get($giftReport, 'transactions', []) as $transaction)
                                    <tr class="border-t border-white/5">
                                        <td class="px-3 py-2 text-xs text-white/60">{{ $transaction['created_at'] ?? '—' }}</td>
                                        <td class="px-3 py-2 font-semibold text-white">${{ number_format((float) ($transaction['candle_cash_amount'] ?? 0), 2) }}</td>
                                        <td class="px-3 py-2">{{ Str::headline(str_replace('_', ' ', (string) ($transaction['gift_intent'] ?? '')) ?: '—') }}</td>
                                        <td class="px-3 py-2">{{ Str::headline(str_replace('_', ' ', (string) ($transaction['gift_origin'] ?? '')) ?: '—') }}</td>
                                        <td class="px-3 py-2">{{ Str::headline(str_replace('_', ' ', (string) ($transaction['notification_status'] ?? '')) ?: '—') }}</td>
                                        <td class="px-3 py-2">{{ $transaction['campaign_key'] ?? '—' }}</td>
                                        <td class="px-3 py-2">{{ $transaction['description'] ?: '—' }}</td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="7" class="px-3 py-6 text-center text-xs text-white/55">No gift transactions to display.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </article>
            </section>
        @elseif($sectionKey === 'settings')
            @php
                $googleBusinessStatus = $googleBusinessStatus ?? [];
                $googleConnection = data_get($googleBusinessStatus, 'connection');
                $googleLocations = collect(data_get($googleBusinessStatus, 'locations', []));
                $googleLastRun = data_get($googleBusinessStatus, 'last_sync_run');
            @endphp
            <section class="grid gap-4 xl:grid-cols-3">
                <article class="rounded-[1.8rem] border border-white/10 bg-black/15 p-5">
                    <div class="text-[11px] uppercase tracking-[0.24em] text-white/45">Program</div>
                    <h2 class="mt-2 text-lg font-semibold text-white">Reward basics</h2>
                    <form method="POST" action="{{ route('marketing.candle-cash.settings.save') }}" class="mt-4 space-y-3">
                        @csrf
                        <input type="hidden" name="scope" value="program" />
                        <label class="block text-sm text-white/75">Label<input type="text" name="label" value="{{ data_get($programConfig, 'label', 'Candle Cash') }}" class="mt-2 block w-full rounded-2xl border border-white/10 bg-white/5 px-4 py-3 text-white" /></label>
                        <div class="rounded-2xl border border-white/10 bg-white/5 px-4 py-3 text-sm text-white/70">Candle Cash is displayed 1:1 across the app. Legacy storage conversion remains internal for existing records.</div>
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
                    <div class="text-[11px] uppercase tracking-[0.24em] text-white/45">Google Business Profile</div>
                    <h2 class="mt-2 text-lg font-semibold text-white">Verified Google review sync</h2>
                    <div class="mt-4 rounded-[1.5rem] border border-white/10 bg-white/5 p-4">
                        <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                            <div>
                                <div class="text-xs uppercase tracking-[0.18em] text-white/45">Connection status</div>
                                <div class="mt-2 text-lg font-semibold text-white">{{ str_replace('_', ' ', (string) data_get($googleBusinessStatus, 'connection_status', 'not_configured')) }}</div>
                                <div class="mt-2 text-sm text-white/60">
                                    @if(! data_get($googleBusinessStatus, 'oauth_ready', false))
                                        Google Business OAuth is not configured yet. Add the GBP env values first.
                                    @elseif(data_get($googleBusinessStatus, 'linked_location_title'))
                                        Linked to {{ data_get($googleBusinessStatus, 'linked_location_title') }}.
                                    @elseif($googleConnection)
                                        Connected. Pick a location below to start syncing reviews.
                                    @else
                                        Connect the business owner or manager account, then pick the location to sync.
                                    @endif
                                </div>
                            </div>
                            <div class="flex flex-wrap gap-2">
                                @if(data_get($googleBusinessStatus, 'oauth_ready', false))
                                    <a href="{{ route('marketing.candle-cash.google-business.connect') }}" class="inline-flex rounded-full border border-white/10 bg-white/5 px-4 py-2 text-sm font-semibold text-white/80">
                                        {{ $googleConnection ? 'Reconnect' : 'Connect Google Business Profile' }}
                                    </a>
                                @endif
                                @if($googleConnection)
                                    <form method="POST" action="{{ route('marketing.candle-cash.google-business.sync') }}">
                                        @csrf
                                        <button type="submit" class="inline-flex rounded-full border border-emerald-300/35 bg-emerald-500/10 px-4 py-2 text-sm font-semibold text-white">Sync now</button>
                                    </form>
                                    <form method="POST" action="{{ route('marketing.candle-cash.google-business.disconnect') }}">
                                        @csrf
                                        <button type="submit" class="inline-flex rounded-full border border-white/10 bg-white/5 px-4 py-2 text-sm font-semibold text-white/80">Disconnect</button>
                                    </form>
                                @endif
                            </div>
                        </div>

                        <div class="mt-4 grid gap-3 md:grid-cols-2">
                            <div class="rounded-2xl border border-white/10 bg-black/10 p-4 text-sm text-white/70">
                                <div class="text-xs uppercase tracking-[0.18em] text-white/45">Project approval</div>
                                <div class="mt-2 font-medium text-white">{{ str_replace('_', ' ', (string) data_get($googleBusinessStatus, 'project_approval_status', 'unknown')) }}</div>
                                <div class="mt-2 text-xs text-white/50">If the Google My Business review API is still hidden or quota is 0 QPM, treat the project as not approved yet.</div>
                            </div>
                            <div class="rounded-2xl border border-white/10 bg-black/10 p-4 text-sm text-white/70">
                                <div class="text-xs uppercase tracking-[0.18em] text-white/45">Linked source</div>
                                <div class="mt-2 font-medium text-white">{{ data_get($googleBusinessStatus, 'linked_account_display_name') ?: 'No account linked yet' }}</div>
                                <div class="mt-2 text-xs text-white/50">{{ data_get($googleBusinessStatus, 'linked_location_title') ?: 'No location selected' }}</div>
                                @if(data_get($googleBusinessStatus, 'review_url'))
                                    <a href="{{ data_get($googleBusinessStatus, 'review_url') }}" target="_blank" rel="noopener" class="mt-3 inline-flex text-xs font-semibold text-amber-100/80 underline decoration-white/20 underline-offset-4">Open current review link</a>
                                @endif
                            </div>
                            <div class="rounded-2xl border border-white/10 bg-black/10 p-4 text-sm text-white/70">
                                <div class="text-xs uppercase tracking-[0.18em] text-white/45">Scopes granted</div>
                                <div class="mt-2 text-xs text-white/60">{{ collect(data_get($googleBusinessStatus, 'granted_scopes', []))->join(', ') ?: 'No scopes stored yet' }}</div>
                                <div class="mt-3 text-xs text-white/50">Required scope: https://www.googleapis.com/auth/business.manage</div>
                            </div>
                            <div class="rounded-2xl border border-white/10 bg-black/10 p-4 text-sm text-white/70">
                                <div class="text-xs uppercase tracking-[0.18em] text-white/45">Latest sync</div>
                                <div class="mt-2 font-medium text-white">{{ optional(data_get($googleBusinessStatus, 'last_sync_at'))->format('Y-m-d H:i') ?: 'Not synced yet' }}</div>
                                @if($googleLastRun)
                                    <div class="mt-2 text-xs text-white/50">{{ strtoupper((string) $googleLastRun->status) }} · {{ number_format((int) $googleLastRun->fetched_reviews_count) }} fetched · {{ number_format((int) $googleLastRun->awarded_reviews_count) }} awarded</div>
                                @endif
                            </div>
                        </div>

                        @if(data_get($googleBusinessStatus, 'last_error_message'))
                            <div class="mt-4 rounded-2xl border border-rose-300/25 bg-rose-500/10 p-4 text-sm text-rose-100">
                                <div class="font-semibold">Last API issue</div>
                                <div class="mt-2">{{ data_get($googleBusinessStatus, 'last_error_message') }}</div>
                                @if(data_get($googleBusinessStatus, 'last_error_code'))
                                    <div class="mt-1 text-xs text-rose-100/75">Code: {{ data_get($googleBusinessStatus, 'last_error_code') }}</div>
                                @endif
                            </div>
                        @endif

                        @if($googleLocations->isNotEmpty())
                            <form method="POST" action="{{ route('marketing.candle-cash.google-business.select-location') }}" class="mt-4 space-y-3">
                                @csrf
                                <label class="block text-sm text-white/75">Linked location
                                    <select name="location_id" class="mt-2 block w-full rounded-2xl border border-white/10 bg-white/5 px-4 py-3 text-white">
                                        @foreach($googleLocations as $location)
                                            <option value="{{ $location->id }}" @selected($location->is_selected)>{{ $location->account_display_name ?: 'Account' }} · {{ $location->title ?: $location->location_id }}</option>
                                        @endforeach
                                    </select>
                                </label>
                                <button type="submit" class="inline-flex rounded-full border border-white/10 bg-white/5 px-4 py-2 text-sm font-semibold text-white/80">Save linked location</button>
                            </form>
                        @endif
                    </div>
                </article>

                <article class="rounded-[1.8rem] border border-white/10 bg-black/15 p-5">
                    <div class="text-[11px] uppercase tracking-[0.24em] text-white/45">Integrations</div>
                    <h2 class="mt-2 text-lg font-semibold text-white">Verification hooks</h2>
                    <form method="POST" action="{{ route('marketing.candle-cash.settings.save') }}" class="mt-4 space-y-3">
                        @csrf
                        <input type="hidden" name="scope" value="integrations" />
                        <label class="flex items-center gap-2 text-sm text-white/75"><input type="checkbox" name="google_review_enabled" value="1" @checked(data_get($integrationConfig, 'google_review_enabled', false)) /> Google review matching enabled</label>
                        <label class="block text-sm text-white/75">Google review URL<input type="text" name="google_review_url" value="{{ data_get($integrationConfig, 'google_review_url') }}" class="mt-2 block w-full rounded-2xl border border-white/10 bg-white/5 px-4 py-3 text-white" /></label>
                        <input type="hidden" name="google_business_location_id" value="{{ data_get($integrationConfig, 'google_business_location_id') }}" />
                        <label class="block text-sm text-white/75">Google matching strategy<input type="text" name="google_review_matching_strategy" value="{{ data_get($integrationConfig, 'google_review_matching_strategy', 'recent_click_name_match') }}" class="mt-2 block w-full rounded-2xl border border-white/10 bg-white/5 px-4 py-3 text-white" /></label>
                        <label class="flex items-center gap-2 text-sm text-white/75"><input type="checkbox" name="product_review_enabled" value="1" @checked(data_get($integrationConfig, 'product_review_enabled', false)) /> Product review integration enabled</label>
                        <label class="block text-sm text-white/75">Product review platform<input type="text" name="product_review_platform" value="{{ data_get($integrationConfig, 'product_review_platform') }}" class="mt-2 block w-full rounded-2xl border border-white/10 bg-white/5 px-4 py-3 text-white" /></label>
                        <label class="block text-sm text-white/75">Product review matching strategy<input type="text" name="product_review_matching_strategy" value="{{ data_get($integrationConfig, 'product_review_matching_strategy', 'profile_or_external_customer') }}" class="mt-2 block w-full rounded-2xl border border-white/10 bg-white/5 px-4 py-3 text-white" /></label>
                        <label class="flex items-center gap-2 text-sm text-white/75"><input type="checkbox" name="product_review_moderation_enabled" value="1" @checked(data_get($integrationConfig, 'product_review_moderation_enabled', false)) /> Hold new product reviews for moderation</label>
                        <label class="flex items-center gap-2 text-sm text-white/75"><input type="checkbox" name="product_review_allow_guest" value="1" @checked(data_get($integrationConfig, 'product_review_allow_guest', true)) /> Allow guest product reviews</label>
                        <label class="block text-sm text-white/75">Product review minimum length<input type="number" min="12" max="500" name="product_review_min_length" value="{{ data_get($integrationConfig, 'product_review_min_length', 24) }}" class="mt-2 block w-full rounded-2xl border border-white/10 bg-white/5 px-4 py-3 text-white" /></label>
                        <label class="block text-sm text-white/75">Product review notification email<input type="email" name="product_review_notification_email" value="{{ data_get($integrationConfig, 'product_review_notification_email', 'info@theforestrystudio.com') }}" class="mt-2 block w-full rounded-2xl border border-white/10 bg-white/5 px-4 py-3 text-white" /></label>
                        <label class="flex items-center gap-2 text-sm text-white/75"><input type="checkbox" name="email_signup_enabled" value="1" @checked(data_get($integrationConfig, 'email_signup_enabled', true)) /> Email signup task enabled</label>
                        <label class="flex items-center gap-2 text-sm text-white/75"><input type="checkbox" name="sms_signup_enabled" value="1" @checked(data_get($integrationConfig, 'sms_signup_enabled', true)) /> SMS signup task enabled</label>
                        <label class="block text-sm text-white/75">Candle Club locked CTA URL<input type="text" name="vote_locked_join_url" value="{{ data_get($integrationConfig, 'vote_locked_join_url') }}" class="mt-2 block w-full rounded-2xl border border-white/10 bg-white/5 px-4 py-3 text-white" /></label>
                        <div class="rounded-2xl border border-amber-300/25 bg-amber-500/10 p-4 text-sm text-amber-100">Google Business Q&amp;A is intentionally excluded here. The Q&amp;A API is discontinued, so it is not part of the active Candle Cash rewards program.</div>
                        <button type="submit" class="inline-flex rounded-full border border-white/10 bg-white/5 px-4 py-2 text-sm font-semibold text-white/80">Save integration settings</button>
                    </form>
                </article>
            </section>
        @endif
    </div>
</x-layouts::app>

<x-layouts::app.sidebar title="Payroll hours"><flux:main><div class="fb-workflow-shell space-y-6">
    <header class="flex flex-wrap justify-between gap-4 border-b border-zinc-200 pb-5"><div><p class="text-xs font-semibold uppercase tracking-[.18em] text-emerald-700">{{ $tenant->name }} · Field service</p><h1 class="mt-1 text-3xl font-semibold text-zinc-950">Payroll hours</h1><p class="mt-2 text-sm text-zinc-600">Job-linked time collection and manager approval. Payroll calculation, tax, withholding, filing, and remittance remain outside Everbranch.</p></div><div class="flex gap-2"><a href="{{ route('field-service.calendar') }}" class="fb-btn fb-btn-secondary">Work calendar</a>@if($canManage)<a href="{{ route('field-service.payroll-hours.export') }}" class="fb-btn fb-btn-primary">Export approved CSV</a>@endif</div></header>
    @if(session('status'))<div class="fb-state fb-state-success">{{ session('status') }}</div>@endif @if($errors->any())<div class="rounded-lg bg-red-50 p-3 text-sm text-red-800">{{ $errors->first() }}</div>@endif
    <section class="fb-panel"><div class="fb-panel-head"><div class="fb-panel-title">Submit hours</div></div><div class="fb-panel-body"><form method="POST" action="{{ route('field-service.payroll-hours.store') }}" class="grid gap-3 md:grid-cols-4">@csrf
        @if($canManage)<label class="text-sm font-semibold">Employee<select name="user_id" class="mt-1 w-full rounded-lg border-zinc-300">@foreach($team as $member)<option value="{{ $member->id }}">{{ $member->name ?: $member->email }}</option>@endforeach</select></label>@endif
        <label class="text-sm font-semibold">Job<select name="field_service_job_id" class="mt-1 w-full rounded-lg border-zinc-300"><option value="">General / no job</option>@foreach($jobs as $job)<option value="{{ $job->id }}">{{ $job->title }}</option>@endforeach</select></label><label class="text-sm font-semibold">Work date<input type="date" name="work_date" value="{{ now()->toDateString() }}" required class="mt-1 w-full rounded-lg border-zinc-300"></label><label class="text-sm font-semibold">Start<input type="time" name="started_at" required class="mt-1 w-full rounded-lg border-zinc-300"></label><label class="text-sm font-semibold">End<input type="time" name="ended_at" required class="mt-1 w-full rounded-lg border-zinc-300"></label><label class="text-sm font-semibold">Unpaid break (minutes)<input type="number" name="break_minutes" value="0" min="0" max="720" class="mt-1 w-full rounded-lg border-zinc-300"></label><label class="text-sm font-semibold md:col-span-2">Notes<input name="notes" class="mt-1 w-full rounded-lg border-zinc-300"></label><div class="flex items-end"><button class="fb-btn fb-btn-primary w-full justify-center">Submit hours</button></div>
    </form></div></section>
    <section class="fb-panel">
        <div class="fb-panel-head"><div class="fb-panel-title">Clock In/Out sessions</div></div>
        <div class="fb-panel-body overflow-x-auto">
            <table class="min-w-full divide-y divide-zinc-200 text-sm">
                <thead><tr class="text-left text-xs uppercase text-zinc-500"><th class="py-2 pr-3">Employee</th><th class="py-2 pr-3">Job</th><th class="py-2 pr-3">Time</th><th class="py-2 pr-3">Hours</th><th class="py-2 pr-3">Status</th><th class="py-2">Correction / approval</th></tr></thead>
                <tbody class="divide-y divide-zinc-100">
                    @forelse($timerSessions as $session)
                        @php
                            $liveSeconds = $session->clocked_out_at
                                ? (int) ($session->duration_seconds ?? 0)
                                : max(0, (int) $session->clocked_in_at?->diffInSeconds(now()) - (int) $session->break_seconds);
                            $stale = !$session->clocked_out_at && $session->clocked_in_at?->lt(now()->subHours(12));
                        @endphp
                        <tr>
                            <td class="py-3 pr-3 font-semibold">{{ $session->user?->name }}</td>
                            <td class="py-3 pr-3">{{ $session->job?->title }}</td>
                            <td class="py-3 pr-3">{{ $session->clocked_in_at?->format('M j, g:i A') }}–{{ $session->clocked_out_at?->format('g:i A') ?? 'running' }}<div class="text-xs {{ $stale ? 'font-semibold text-amber-700' : 'text-zinc-500' }}">{{ $stale ? 'Possible missed clock-out' : number_format($session->break_seconds / 60) . 'm break' }}</div></td>
                            <td class="py-3 pr-3 font-semibold">{{ number_format($liveSeconds / 3600, 2) }}</td>
                            <td class="py-3 pr-3">{{ ucfirst($session->status) }}</td>
                            <td class="py-3">
                                @if($canManage && in_array($session->status, ['running','paused','submitted']))
                                    <form method="POST" action="{{ route('field-service.payroll-timers.review', $session) }}" class="grid min-w-[36rem] grid-cols-6 gap-2">@csrf
                                        <input type="datetime-local" name="clocked_in_at" required value="{{ $session->clocked_in_at?->format('Y-m-d\TH:i') }}" class="col-span-2 rounded border-zinc-300 text-xs">
                                        <input type="datetime-local" name="clocked_out_at" required value="{{ ($session->clocked_out_at ?? now())->format('Y-m-d\TH:i') }}" class="col-span-2 rounded border-zinc-300 text-xs">
                                        <input type="number" name="break_minutes" min="0" max="720" value="{{ (int) round($session->break_seconds / 60) }}" aria-label="Break minutes" class="rounded border-zinc-300 text-xs">
                                        <select name="status" class="rounded border-zinc-300 text-xs"><option value="submitted">Correct</option><option value="approved">Approve</option><option value="rejected">Reject</option></select>
                                        <input name="clock_out_notes" value="{{ $session->clock_out_notes }}" placeholder="Clock-out note" class="col-span-5 rounded border-zinc-300 text-xs">
                                        <button class="rounded border px-2 py-1 text-xs font-semibold">Save</button>
                                    </form>
                                @else
                                    <span class="text-xs text-zinc-500">{{ $session->reviewed_at?->format('M j, g:i A') ?? '—' }}</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="py-8 text-center text-zinc-500">No Clock In/Out sessions yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
    <section class="fb-panel"><div class="fb-panel-head"><div class="fb-panel-title">Hours ledger</div></div><div class="fb-panel-body overflow-x-auto"><table class="min-w-full divide-y divide-zinc-200 text-sm"><thead><tr class="text-left text-xs uppercase text-zinc-500"><th class="py-2 pr-3">Date</th><th class="py-2 pr-3">Employee</th><th class="py-2 pr-3">Job</th><th class="py-2 pr-3">Time</th><th class="py-2 pr-3">Hours</th><th class="py-2 pr-3">Status</th><th class="py-2">Review</th></tr></thead><tbody class="divide-y divide-zinc-100">@forelse($entries as $entry)<tr><td class="py-3 pr-3">{{ $entry->work_date?->format('M j, Y') }}</td><td class="py-3 pr-3 font-semibold">{{ $entry->user?->name }}</td><td class="py-3 pr-3">{{ $entry->job?->title ?? 'General' }}</td><td class="py-3 pr-3">{{ $entry->started_at }}–{{ $entry->ended_at }}<div class="text-xs text-zinc-500">{{ $entry->break_minutes }}m break</div></td><td class="py-3 pr-3 font-semibold">{{ number_format($entry->duration_minutes/60, 2) }}</td><td class="py-3 pr-3">{{ ucfirst($entry->status) }}</td><td class="py-3">@if($canManage && $entry->status==='submitted')<div class="flex gap-1">@foreach(['approved','rejected'] as $status)<form method="POST" action="{{ route('field-service.payroll-hours.review', $entry) }}">@csrf<input type="hidden" name="status" value="{{ $status }}"><button class="rounded border px-2 py-1 text-xs font-semibold">{{ ucfirst($status) }}</button></form>@endforeach</div>@else<span class="text-xs text-zinc-500">{{ $entry->reviewedBy?->name ?? '—' }}</span>@endif</td></tr>@empty<tr><td colspan="7" class="py-8 text-center text-zinc-500">No hours submitted yet.</td></tr>@endforelse</tbody></table></div></section>
</div></flux:main></x-layouts::app.sidebar>

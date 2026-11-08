@extends('layouts.app')

@section('content')
@php
    $revision = $editor['draft'] ?? $editor['published'];
    $rules = $revision['rules'];
@endphp
<div class="mx-auto max-w-3xl space-y-6 px-4 py-8">
    <div>
        <h1 class="text-2xl font-semibold">Customer outreach limits</h1>
        <p class="mt-2 text-sm text-gray-600">{{ $editor['scope'] }}</p>
        <p class="mt-2 text-sm text-gray-600">{{ $editor['notice'] }}</p>
        <p class="mt-2 text-sm">Published policy: {{ $editor['published']['status'] }} · version {{ $editor['published']['version'] }}. Latest revision: {{ $editor['version'] }}.</p>
    </div>
    @if(session('status'))<p role="status" class="rounded border p-3">{{ session('status') }}</p>@endif
    @if($errors->any())<div role="alert" class="rounded border border-red-300 p-3"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
    <form method="post" class="space-y-5 rounded border bg-white p-5">
        @csrf
        <input type="hidden" name="request_id" value="{{ old('request_id', (string) \Illuminate\Support\Str::uuid()) }}">
        <input type="hidden" name="expected_version" value="{{ $editor['version'] }}">
        <label class="block">Policy time zone
            <input class="mt-1 block w-full rounded border p-2" name="rules[timezone]" value="{{ old('rules.timezone', $rules['timezone']) }}" required placeholder="America/New_York">
        </label>
        <p class="text-sm text-gray-600">Daily limits reset at midnight in this time zone. Seven-day limits and cooldowns use elapsed time across both channels. Zero prevents promotional outreach for that limit.</p>
        <div class="grid gap-4 sm:grid-cols-2">
        @foreach(['daily_cap' => 'All-channel daily cap', 'rolling_7_day_cap' => 'All-channel rolling seven-day cap', 'email_daily_cap' => 'Email daily cap', 'sms_daily_cap' => 'Text daily cap', 'cross_channel_cooldown_minutes' => 'Across-channel cooldown (minutes)', 'email_cooldown_minutes' => 'Email cooldown (minutes)', 'sms_cooldown_minutes' => 'Text cooldown (minutes)'] as $key => $label)
            <label class="block">{{ $label }}<input type="number" min="0" max="{{ str_contains($key, 'cooldown') ? 10080 : ($key === 'rolling_7_day_cap' ? 500 : 100) }}" class="mt-1 block w-full rounded border p-2" name="rules[{{ $key }}]" value="{{ old('rules.'.$key, $rules[$key]) }}" required></label>
        @endforeach
        </div>
        <fieldset class="space-y-3 border-t pt-4">
            <legend class="font-semibold">Review delivery-history coverage</legend>
            <p class="text-sm text-gray-600">Before publishing, verify that retained Everbranch email and text marketing records cover at least the full previous seven days, all relevant aliases, and every promotional send in that period. If an external sender or missing records make this unknown, keep this policy in draft. Receipt delivery uses its own controls and does not consume these promotional limits.</p>
            @foreach(['email' => 'Email', 'sms' => 'Text'] as $channel => $label)
            <label class="block">{{ $label }} history verified from (include UTC offset)<input class="mt-1 block w-full rounded border p-2" name="rules[delivery_history_verified_from][{{ $channel }}]" value="{{ old('rules.delivery_history_verified_from.'.$channel, $rules['delivery_history_verified_from'][$channel]) }}" placeholder="2026-01-01T00:00:00-05:00"></label>
            @endforeach
            <label class="block">Coverage review notes<textarea class="mt-1 block w-full rounded border p-2" name="rules[coverage_review_note]" maxlength="1000">{{ old('rules.coverage_review_note', $rules['coverage_review_note']) }}</textarea></label>
        </fieldset>
        <label class="block">Reason for revision<textarea class="mt-1 block w-full rounded border p-2" name="reason" minlength="8" maxlength="1000" required>{{ old('reason') }}</textarea></label>
        <p class="text-sm text-gray-600">Review notes and revision reasons are retained in policy history. Use operational reasons and evidence references; omit customer contact details.</p>
        <div class="flex flex-wrap gap-3">
            <button class="rounded border px-4 py-2" name="publish" value="0">Save draft limits</button>
            <button class="rounded bg-gray-900 px-4 py-2 text-white" name="publish" value="1">Publish reviewed limits</button>
        </div>
    </form>
    @if($editor['revisions'])
    <section class="space-y-3">
        <h2 class="text-lg font-semibold">Recent policy revisions</h2>
        @foreach($editor['revisions'] as $revision)
        <div class="rounded border p-3"><p>Version {{ $revision['version'] }} · {{ $revision['status'] }} · {{ $revision['created_at'] }}</p><p class="text-sm text-gray-600">{{ $revision['reason'] }}</p></div>
        @endforeach
    </section>
    @endif
</div>
@endsection

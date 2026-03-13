@php
    $taskForm = $taskForm ?? [];
@endphp

<label class="block text-sm text-white/75">Handle
    <input type="text" name="handle" value="{{ data_get($taskForm, 'handle') }}" class="mt-2 block w-full rounded-2xl border border-white/10 bg-white/5 px-4 py-3 text-white" />
</label>
<label class="block text-sm text-white/75">Title
    <input type="text" name="title" value="{{ data_get($taskForm, 'title') }}" class="mt-2 block w-full rounded-2xl border border-white/10 bg-white/5 px-4 py-3 text-white" />
</label>
<label class="block text-sm text-white/75 md:col-span-2">Description
    <textarea name="description" rows="2" class="mt-2 block w-full rounded-2xl border border-white/10 bg-white/5 px-4 py-3 text-white">{{ data_get($taskForm, 'description') }}</textarea>
</label>
<label class="block text-sm text-white/75">Reward amount
    <input type="number" step="0.01" min="0" max="100" name="reward_amount" value="{{ data_get($taskForm, 'reward_amount') }}" class="mt-2 block w-full rounded-2xl border border-white/10 bg-white/5 px-4 py-3 text-white" />
</label>
<label class="block text-sm text-white/75">Display order
    <input type="number" min="0" max="999" name="display_order" value="{{ data_get($taskForm, 'display_order', 999) }}" class="mt-2 block w-full rounded-2xl border border-white/10 bg-white/5 px-4 py-3 text-white" />
</label>
<label class="block text-sm text-white/75">Task type
    <input type="text" name="task_type" value="{{ data_get($taskForm, 'task_type') }}" class="mt-2 block w-full rounded-2xl border border-white/10 bg-white/5 px-4 py-3 text-white" />
</label>
<label class="block text-sm text-white/75">Button text
    <input type="text" name="button_text" value="{{ data_get($taskForm, 'button_text') }}" class="mt-2 block w-full rounded-2xl border border-white/10 bg-white/5 px-4 py-3 text-white" />
</label>
<label class="block text-sm text-white/75 md:col-span-2">Action URL
    <input type="url" name="action_url" value="{{ data_get($taskForm, 'action_url') }}" class="mt-2 block w-full rounded-2xl border border-white/10 bg-white/5 px-4 py-3 text-white" />
</label>
<label class="block text-sm text-white/75">Max completions
    <input type="number" min="1" max="999" name="max_completions_per_customer" value="{{ data_get($taskForm, 'max_completions_per_customer', 1) }}" class="mt-2 block w-full rounded-2xl border border-white/10 bg-white/5 px-4 py-3 text-white" />
</label>
<label class="block text-sm text-white/75">Icon
    <input type="text" name="icon" value="{{ data_get($taskForm, 'icon') }}" class="mt-2 block w-full rounded-2xl border border-white/10 bg-white/5 px-4 py-3 text-white" />
</label>
<label class="block text-sm text-white/75">Eligibility type
    <select name="eligibility_type" class="mt-2 block w-full rounded-2xl border border-white/10 bg-white/5 px-4 py-3 text-white">
        @foreach(['everyone' => 'Everyone', 'candle_club_only' => 'Candle Club only'] as $value => $label)
            <option value="{{ $value }}" @selected(data_get($taskForm, 'eligibility_type', 'everyone') === $value)>{{ $label }}</option>
        @endforeach
    </select>
</label>
<label class="block text-sm text-white/75">Membership status
    <input type="text" name="required_membership_status" value="{{ data_get($taskForm, 'required_membership_status') }}" class="mt-2 block w-full rounded-2xl border border-white/10 bg-white/5 px-4 py-3 text-white" />
</label>
<label class="block text-sm text-white/75">Start date
    <input type="date" name="start_date" value="{{ optional(data_get($taskForm, 'start_date'))->format ? data_get($taskForm, 'start_date')->format('Y-m-d') : data_get($taskForm, 'start_date') }}" class="mt-2 block w-full rounded-2xl border border-white/10 bg-white/5 px-4 py-3 text-white" />
</label>
<label class="block text-sm text-white/75">End date
    <input type="date" name="end_date" value="{{ optional(data_get($taskForm, 'end_date'))->format ? data_get($taskForm, 'end_date')->format('Y-m-d') : data_get($taskForm, 'end_date') }}" class="mt-2 block w-full rounded-2xl border border-white/10 bg-white/5 px-4 py-3 text-white" />
</label>
<label class="flex items-center gap-2 text-sm text-white/75"><input type="checkbox" name="enabled" value="1" @checked((bool) data_get($taskForm, 'enabled', true)) /> Enabled</label>
<label class="flex items-center gap-2 text-sm text-white/75"><input type="checkbox" name="requires_manual_approval" value="1" @checked((bool) data_get($taskForm, 'requires_manual_approval', false)) /> Manual approval</label>
<label class="flex items-center gap-2 text-sm text-white/75"><input type="checkbox" name="requires_customer_submission" value="1" @checked((bool) data_get($taskForm, 'requires_customer_submission', false)) /> Needs customer proof</label>
<label class="flex items-center gap-2 text-sm text-white/75"><input type="checkbox" name="visible_to_noneligible_customers" value="1" @checked((bool) data_get($taskForm, 'visible_to_noneligible_customers', false)) /> Show locked card to non-eligible customers</label>
<label class="block text-sm text-white/75 md:col-span-2">Locked message
    <input type="text" name="locked_message" value="{{ data_get($taskForm, 'locked_message') }}" class="mt-2 block w-full rounded-2xl border border-white/10 bg-white/5 px-4 py-3 text-white" />
</label>
<label class="block text-sm text-white/75">Locked CTA text
    <input type="text" name="locked_cta_text" value="{{ data_get($taskForm, 'locked_cta_text') }}" class="mt-2 block w-full rounded-2xl border border-white/10 bg-white/5 px-4 py-3 text-white" />
</label>
<label class="block text-sm text-white/75">Locked CTA URL
    <input type="url" name="locked_cta_url" value="{{ data_get($taskForm, 'locked_cta_url') }}" class="mt-2 block w-full rounded-2xl border border-white/10 bg-white/5 px-4 py-3 text-white" />
</label>
<label class="block text-sm text-white/75 md:col-span-2">Admin notes
    <textarea name="admin_notes" rows="3" class="mt-2 block w-full rounded-2xl border border-white/10 bg-white/5 px-4 py-3 text-white">{{ data_get($taskForm, 'admin_notes') }}</textarea>
</label>

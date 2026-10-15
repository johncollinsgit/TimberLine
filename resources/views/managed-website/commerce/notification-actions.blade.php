@if($canManageShipping ?? false)
@php($orderNotifications = \App\Models\WebsiteOrderNotification::query()->forTenant($tenant)->where('website_order_id', $order->id)->latest('id')->limit(40)->get(['id', 'kind', 'status', 'attempts', 'failure_code', 'created_at']))
<section class="border border-zinc-200 bg-white p-4">
    <h2 class="font-semibold">Transactional order updates</h2>
    <p class="mt-2 text-sm text-zinc-600">Accepted means the mail provider accepted the message. An uncertain send requires provider evidence before any retry.</p>
    @forelse($orderNotifications as $notification)
        <article class="mt-4 space-y-3 border-t pt-4 text-sm">
            <p><strong>{{ str($notification->kind)->headline() }}</strong> · {{ str($notification->status)->headline() }} · {{ $notification->attempts }} attempts</p>
            @if($notification->failure_code)<p class="text-amber-900">{{ str($notification->failure_code)->headline() }}</p>@endif
            @if($notification->status === 'unknown')
                <form method="POST" action="{{ route('managed-website.notifications.resolve', [$order, $notification->id]) }}" class="space-y-3">@csrf
                    <label class="block font-semibold">Provider finding<select required name="outcome" class="mt-1 block w-full rounded-lg border-zinc-300"><option value="">Select evidence</option><option value="accepted">Provider confirms acceptance</option><option value="confirmed_not_accepted">Provider confirms no acceptance</option></select></label>
                    <label class="block font-semibold">Provider reference and review note<textarea required name="reason" minlength="10" maxlength="1000" class="mt-1 block w-full rounded-lg border-zinc-300"></textarea></label>
                    <label class="flex items-start gap-2"><input required type="checkbox" name="confirm_evidence" value="1"><span>I verified this outcome in the provider records.</span></label>
                    <button class="fb-btn fb-btn-secondary" type="submit">Record evidence without sending</button>
                </form>
            @elseif(in_array($notification->status, ['blocked', 'failed'], true))
                <details><summary class="cursor-pointer font-semibold text-emerald-800">Review a send retry</summary><form method="POST" action="{{ route('managed-website.notifications.retry', [$order, $notification->id]) }}" class="mt-3 space-y-3">@csrf
                    <label class="block font-semibold">Reason for retry<textarea required name="reason" minlength="10" maxlength="1000" class="mt-1 block w-full rounded-lg border-zinc-300"></textarea></label>
                    <label class="flex items-start gap-2"><input required type="checkbox" name="confirm_send" value="1"><span>Send this saved update to the original checkout email.</span></label>
                    <button class="fb-btn fb-btn-secondary" type="submit">Queue reviewed update</button>
                </form></details>
            @endif
        </article>
    @empty<p class="mt-4 text-sm text-zinc-600">No transactional updates recorded.</p>@endforelse
</section>
@endif

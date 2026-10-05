<?php

namespace App\Jobs;

use App\Models\TeamMessage;
use App\Models\Tenant;
use App\Models\TenantMemberPreference;
use App\Services\FieldService\FieldServiceAccessService;
use App\Services\Mobile\EverbranchApnsService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

class SendTeamMessagePushNotification implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $messageId) {}

    public function handle(EverbranchApnsService $apns, FieldServiceAccessService $access): void
    {
        $message = TeamMessage::query()->whereKey($this->messageId)->whereNull('deleted_at')->with('channel.job')->first();
        $channel = $message?->channel;
        if (! $channel || $channel->archived_at || (int) $channel->tenant_id !== (int) $message->tenant_id) {
            return;
        }
        $tenant = Tenant::query()->find($channel->tenant_id);
        if (! $tenant) {
            return;
        }
        $members = $tenant->users()->wherePivot('membership_active', true)->where('users.is_active', true)
            ->where('users.id', '!=', (int) $message->created_by_user_id)->get();
        $channelMemberIds = $channel->members()->pluck('users.id')->map(fn ($id): int => (int) $id)->all();
        $mutedIds = DB::table('team_channel_members')->where('team_channel_id', $channel->id)
            ->whereNotNull('muted_at')->pluck('user_id')->map(fn ($id): int => (int) $id)->all();
        $disabledIds = TenantMemberPreference::query()->forTenantId((int) $tenant->id)
            ->where(function ($query): void {
                $query->where('push_enabled', false)->orWhere('team_message_notifications', false);
            })->pluck('user_id')->map(fn ($id): int => (int) $id)->all();
        $recipients = $members->filter(function ($user) use ($channel, $tenant, $access, $channelMemberIds, $mutedIds, $disabledIds): bool {
            $id = (int) $user->id;
            if (in_array($id, $mutedIds, true) || in_array($id, $disabledIds, true)) {
                return false;
            }
            if ($channel->kind === 'company') {
                return true;
            }
            if (in_array($id, $channelMemberIds, true)) {
                return true;
            }

            return $channel->kind === 'job' && $channel->job && $access->canAccessJob($user, $tenant, $channel->job);
        })->pluck('id')->map(fn ($id): int => (int) $id)->values();
        $apns->sendTeamMessage($message, $channel, $recipients);
    }
}

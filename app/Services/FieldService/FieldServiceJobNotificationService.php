<?php

namespace App\Services\FieldService;

use App\Models\EverbranchMobilePushDevice;
use App\Models\FieldServiceJob;
use App\Models\FieldServiceJobNote;
use App\Models\FieldServiceJobNotification;
use App\Models\FieldServiceReminderSetting;
use App\Models\TenantMemberPreference;
use App\Models\User;
use App\Services\Marketing\TwilioSmsService;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class FieldServiceJobNotificationService
{
    public function __construct(protected TwilioSmsService $sms) {}

    /** @param array<int,int> $mentionedUserIds @return array<string,int> */
    public function notifyComment(FieldServiceJob $job, FieldServiceJobNote $note, User $actor, array $mentionedUserIds): array
    {
        $recipients = $this->recipients($job, $mentionedUserIds)->reject(fn (User $user): bool => (int) $user->id === (int) $actor->id);
        $summary = ['recipients' => $recipients->count(), 'in_app' => 0, 'push' => 0, 'sms_sent' => 0, 'sms_blocked' => 0];
        $reminders = FieldServiceReminderSetting::query()->forTenantId((int) $job->tenant_id)->first();
        $smsReady = $reminders?->enabled === true && $reminders?->provider_status === 'verified';

        foreach ($recipients as $user) {
            FieldServiceJobNotification::query()->firstOrCreate([
                'field_service_job_note_id' => (int) $note->id,
                'user_id' => (int) $user->id,
                'channel' => 'in_app',
            ], [
                'tenant_id' => (int) $job->tenant_id,
                'field_service_job_id' => (int) $job->id,
                'status' => 'delivered',
                'sent_at' => now(),
                'metadata' => ['actor_id' => (int) $actor->id],
            ]);
            $summary['in_app']++;

            if (EverbranchMobilePushDevice::query()->where('user_id', $user->id)->where('notifications_enabled', true)->exists()) {
                FieldServiceJobNotification::query()->firstOrCreate([
                    'field_service_job_note_id' => (int) $note->id,
                    'user_id' => (int) $user->id,
                    'channel' => 'push',
                ], [
                    'tenant_id' => (int) $job->tenant_id,
                    'field_service_job_id' => (int) $job->id,
                    'status' => 'queued',
                    'metadata' => ['route' => 'field_service_job', 'job_id' => (int) $job->id],
                ]);
                $summary['push']++;
            }

            $preference = TenantMemberPreference::query()->forTenantId((int) $job->tenant_id)->where('user_id', $user->id)->first();
            if (! $smsReady || ! $preference?->operational_sms_enabled || ! $preference?->operational_sms_opted_in_at || ! $preference?->phone_verified_at || blank($preference?->phone)) {
                $summary['sms_blocked']++;

                continue;
            }

            $notification = FieldServiceJobNotification::query()->firstOrCreate([
                'field_service_job_note_id' => (int) $note->id,
                'user_id' => (int) $user->id,
                'channel' => 'sms',
            ], [
                'tenant_id' => (int) $job->tenant_id,
                'field_service_job_id' => (int) $job->id,
                'status' => 'pending',
            ]);
            if (! $notification->wasRecentlyCreated) {
                continue;
            }
            $result = $this->sms->sendSms((string) $preference->phone, Str::limit($actor->name.' on '.$job->title.': '.$note->body, 300), [
                'tenant_id' => (int) $job->tenant_id,
            ]);
            $sent = (bool) ($result['success'] ?? false);
            $notification->forceFill([
                'status' => $sent ? 'sent' : 'failed',
                'provider_message_id' => $result['provider_message_id'] ?? null,
                'failure_code' => $sent ? null : ($result['error_code'] ?? 'send_failed'),
                'sent_at' => $sent ? now() : null,
            ])->save();
            $summary[$sent ? 'sms_sent' : 'sms_blocked']++;
        }

        return $summary;
    }

    /** @param array<int,int> $mentionedUserIds @return Collection<int,User> */
    protected function recipients(FieldServiceJob $job, array $mentionedUserIds): Collection
    {
        $ids = collect($mentionedUserIds)
            ->merge($job->participants()->wherePivot('following', true)->pluck('users.id'))
            ->when($job->assigned_user_id, fn (Collection $users) => $users->push((int) $job->assigned_user_id))
            ->unique()->values();

        return User::query()->whereIn('id', $ids)->whereHas('tenants', fn ($query) => $query->whereKey((int) $job->tenant_id))->get();
    }
}

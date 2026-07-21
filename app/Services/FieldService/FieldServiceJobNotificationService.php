<?php

namespace App\Services\FieldService;

use App\Jobs\SendFieldServicePushNotification;
use App\Models\CustomerEquipment;
use App\Models\EverbranchMobilePushDevice;
use App\Models\FieldServiceJob;
use App\Models\FieldServiceJobNote;
use App\Models\FieldServiceJobNotification;
use App\Models\FieldServiceReminderSetting;
use App\Models\Tenant;
use App\Models\TenantMemberPreference;
use App\Models\User;
use App\Services\Marketing\MarketingDirectMessagingService;
use App\Services\Marketing\TwilioSmsService;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class FieldServiceJobNotificationService
{
    public function __construct(protected TwilioSmsService $sms, protected MarketingDirectMessagingService $directMessaging) {}

    /** @param array<int,int> $mentionedUserIds @return array<string,int> */
    public function notifyComment(FieldServiceJob $job, FieldServiceJobNote $note, User $actor, array $mentionedUserIds): array
    {
        $recipients = $this->recipients($job, $mentionedUserIds)->reject(fn (User $user): bool => (int) $user->id === (int) $actor->id);
        $summary = $this->notifyRecipients($job, $recipients, 'comment', 'comment:'.$note->id, $actor->name.' posted an update', $note->body, $note);
        $reminders = FieldServiceReminderSetting::query()->forTenantId((int) $job->tenant_id)->first();
        $smsReady = $reminders?->enabled === true && $reminders?->provider_status === 'verified';

        foreach ($recipients as $user) {
            $preference = TenantMemberPreference::query()->forTenantId((int) $job->tenant_id)->where('user_id', $user->id)->first();
            if (! $smsReady || ! $preference?->operational_sms_enabled || ! $preference?->operational_sms_opted_in_at || ! $preference?->phone_verified_at || blank($preference?->phone)) {
                $summary['sms_blocked']++;

                continue;
            }
            $notification = FieldServiceJobNotification::query()->firstOrCreate([
                'tenant_id' => (int) $job->tenant_id, 'user_id' => (int) $user->id, 'channel' => 'sms', 'event_key' => 'comment:'.$note->id,
            ], [
                'field_service_job_id' => (int) $job->id, 'field_service_job_note_id' => (int) $note->id,
                'event_type' => 'comment', 'status' => 'pending', 'metadata' => $this->metadata($job, $actor->name.' posted an update', $note->body),
            ]);
            if (! $notification->wasRecentlyCreated) {
                continue;
            }
            $result = $this->sms->sendSms((string) $preference->phone, Str::limit($actor->name.' on '.$job->title.': '.$note->body, 300), ['tenant_id' => (int) $job->tenant_id]);
            $sent = (bool) ($result['success'] ?? false);
            $notification->forceFill([
                'status' => $sent ? 'sent' : 'failed', 'provider_message_id' => $result['provider_message_id'] ?? null,
                'failure_code' => $sent ? null : ($result['error_code'] ?? 'send_failed'), 'sent_at' => $sent ? now() : null,
            ])->save();
            $summary[$sent ? 'sms_sent' : 'sms_blocked']++;
        }

        return $summary;
    }

    /** @param array<int,int> $additionalUserIds @return array<string,int> */
    public function notifyJobEvent(FieldServiceJob $job, User $actor, string $eventType, string $body, string $eventKey, array $additionalUserIds = []): array
    {
        $recipients = $this->recipients($job, $additionalUserIds)->reject(fn (User $user): bool => (int) $user->id === (int) $actor->id);

        return $this->notifyRecipients($job, $recipients, $eventType, $eventKey, $job->title, $body);
    }

    /** @return array<string,int> */
    public function notifyUpcomingJob(FieldServiceJob $job, int $minutesBefore): array
    {
        $recipients = $this->recipients($job, [])->filter(function (User $user) use ($job): bool {
            $preference = TenantMemberPreference::query()->forTenantId((int) $job->tenant_id)->where('user_id', $user->id)->first();

            return $preference?->upcoming_job_notifications !== false;
        })->values();
        $label = $minutesBefore >= 1440 ? 'tomorrow' : 'in about two hours';

        return $this->notifyRecipients(
            $job,
            $recipients,
            'upcoming_job',
            'upcoming:'.$job->id.':'.$job->scheduled_for?->timestamp.':'.$minutesBefore,
            'Upcoming job '.$label,
            $job->title.' is scheduled '.$label.'.'
        );
    }

    /** @return array<string,int> */
    public function notifyMaintenanceDue(FieldServiceJob $job, CustomerEquipment $equipment): array
    {
        $recipients = $this->recipients($job, []);
        $eventKey = (string) $job->external_id;
        $body = $equipment->name.' for '.$job->customer_name.' is due '.$equipment->next_service_due_at?->format('M j, Y').'.';
        $summary = $this->notifyRecipients($job, $recipients, 'equipment_maintenance_due', $eventKey, 'Equipment maintenance due', $body);
        $reminders = FieldServiceReminderSetting::query()->forTenantId((int) $job->tenant_id)->first();
        $smsReady = $reminders?->enabled === true && $reminders?->provider_status === 'verified';

        foreach ($recipients as $user) {
            $preference = TenantMemberPreference::query()->forTenantId((int) $job->tenant_id)->where('user_id', $user->id)->first();
            if (! $smsReady || ! $preference?->operational_sms_enabled || ! $preference?->operational_sms_opted_in_at || ! $preference?->phone_verified_at || blank($preference?->phone)) {
                $summary['sms_blocked']++;

                continue;
            }
            $notification = FieldServiceJobNotification::query()->firstOrCreate([
                'tenant_id' => (int) $job->tenant_id, 'user_id' => (int) $user->id, 'channel' => 'sms', 'event_key' => $eventKey,
            ], [
                'field_service_job_id' => (int) $job->id, 'event_type' => 'equipment_maintenance_due', 'status' => 'pending',
                'metadata' => $this->metadata($job, 'Equipment maintenance due', $body),
            ]);
            if (! $notification->wasRecentlyCreated) {
                continue;
            }
            $brand = (string) (Tenant::query()->whereKey((int) $job->tenant_id)->value('name') ?: 'Everbranch');
            $result = $this->sms->sendSms((string) $preference->phone, $brand.': '.$body.' Reply STOP to opt out.', ['tenant_id' => (int) $job->tenant_id]);
            $sent = (bool) ($result['success'] ?? false);
            $notification->forceFill([
                'status' => $sent ? 'sent' : 'failed', 'provider_message_id' => $result['provider_message_id'] ?? null,
                'failure_code' => $sent ? null : ($result['error_code'] ?? 'send_failed'), 'sent_at' => $sent ? now() : null,
            ])->save();
            $summary[$sent ? 'sms_sent' : 'sms_blocked']++;
        }

        $summary['customer_sms_sent'] = 0;
        $summary['customer_sms_blocked'] = 0;
        $customer = $equipment->customer;
        if (! $smsReady || ! $customer || ! $customer->accepts_sms_marketing || $customer->sms_opted_out_at || (blank($customer->phone) && blank($customer->normalized_phone))) {
            $summary['customer_sms_blocked']++;

            return $summary;
        }
        $brand = (string) (Tenant::query()->whereKey((int) $job->tenant_id)->value('name') ?: 'Everbranch');
        $customerResult = $this->directMessaging->send('sms', [[
            'profile_id' => (int) $customer->id,
            'name' => trim(($customer->first_name ?? '').' '.($customer->last_name ?? '')),
            'email' => $customer->email,
            'phone' => $customer->phone,
            'normalized_phone' => $customer->normalized_phone,
            'source_type' => 'equipment_maintenance',
        ]], $brand.': '.$equipment->name.' maintenance is due '.$equipment->next_service_due_at?->format('M j, Y').'. Reply STOP to opt out.', [
            'tenant_id' => (int) $job->tenant_id,
            'batch_id' => 'equipment-maintenance-'.$eventKey,
            'source_label' => 'equipment_maintenance_due',
        ]);
        $summary['customer_sms_sent'] += (int) ($customerResult['sent'] ?? 0);
        $summary['customer_sms_blocked'] += (int) ($customerResult['skipped'] ?? 0) + (int) ($customerResult['failed'] ?? 0);

        return $summary;
    }

    /** @param Collection<int,User> $recipients @return array<string,int> */
    private function notifyRecipients(FieldServiceJob $job, Collection $recipients, string $eventType, string $eventKey, string $title, string $body, ?FieldServiceJobNote $note = null): array
    {
        $summary = ['recipients' => $recipients->count(), 'in_app' => 0, 'push' => 0, 'sms_sent' => 0, 'sms_blocked' => 0];
        foreach ($recipients as $user) {
            $inApp = FieldServiceJobNotification::query()->firstOrCreate([
                'tenant_id' => (int) $job->tenant_id, 'user_id' => (int) $user->id, 'channel' => 'in_app', 'event_key' => $eventKey,
            ], [
                'field_service_job_id' => (int) $job->id, 'field_service_job_note_id' => $note?->id,
                'event_type' => $eventType, 'status' => 'delivered', 'sent_at' => now(), 'metadata' => $this->metadata($job, $title, $body),
            ]);
            if ($inApp->wasRecentlyCreated) {
                $summary['in_app']++;
            }
            $preference = TenantMemberPreference::query()->forTenantId((int) $job->tenant_id)->where('user_id', $user->id)->first();
            if ($preference && ! $preference->push_enabled) {
                continue;
            }
            if (! EverbranchMobilePushDevice::query()->where('user_id', $user->id)->where('notifications_enabled', true)->exists()) {
                continue;
            }
            $push = FieldServiceJobNotification::query()->firstOrCreate([
                'tenant_id' => (int) $job->tenant_id, 'user_id' => (int) $user->id, 'channel' => 'push', 'event_key' => $eventKey,
            ], [
                'field_service_job_id' => (int) $job->id, 'field_service_job_note_id' => $note?->id,
                'event_type' => $eventType, 'status' => 'queued', 'metadata' => $this->metadata($job, $title, $body),
            ]);
            if ($push->wasRecentlyCreated) {
                SendFieldServicePushNotification::dispatch((int) $push->id)->afterCommit();
                $summary['push']++;
            }
        }

        return $summary;
    }

    /** @param array<int,int> $additionalUserIds @return Collection<int,User> */
    private function recipients(FieldServiceJob $job, array $additionalUserIds): Collection
    {
        $ids = collect($additionalUserIds)
            ->merge($job->participants()->wherePivot('following', true)->pluck('users.id'))
            ->when($job->assigned_user_id, fn (Collection $users) => $users->push((int) $job->assigned_user_id))
            ->unique()->values();

        return User::query()->whereIn('id', $ids)->whereHas('tenants', fn ($query) => $query->whereKey((int) $job->tenant_id))->get();
    }

    /** @return array<string,mixed> */
    private function metadata(FieldServiceJob $job, string $title, string $body): array
    {
        return [
            'title' => Str::limit($title, 120), 'body' => Str::limit($body, 240),
            'workspace_slug' => Tenant::query()->whereKey((int) $job->tenant_id)->value('slug'),
            'route' => 'field_service_job', 'job_id' => (int) $job->id,
        ];
    }
}

<?php

namespace App\Services\Work;

use App\Models\Tenant;
use App\Models\User;
use App\Models\WorkNotification;
use App\Models\WorkNotificationDelivery;
use App\Models\WorkNotificationPreference;
use App\Models\WorkPushDevice;
use App\Notifications\EverbranchWorkItemNotification;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\Http;
use Throwable;

class EverbranchWorkNotificationService
{
    public const CATEGORIES = [
        'direct_notify',
        'assignment',
        'mention',
        'comment',
        'status_change',
        'due_date',
        'customer_message',
    ];

    public function preference(Tenant $tenant, User $user, string $category): WorkNotificationPreference
    {
        $category = $this->normalizeCategory($category);

        return WorkNotificationPreference::query()->firstOrCreate(
            [
                'tenant_id' => (int) $tenant->id,
                'user_id' => (int) $user->id,
                'category' => $category,
            ],
            [
                'email_enabled' => true,
                'in_app_enabled' => true,
                'push_enabled' => true,
            ]
        );
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function preferencesPayload(Tenant $tenant, User $user): array
    {
        return collect(self::CATEGORIES)
            ->map(function (string $category) use ($tenant, $user): array {
                $preference = $this->preference($tenant, $user, $category);

                return [
                    'category' => $category,
                    'email_enabled' => (bool) $preference->email_enabled,
                    'in_app_enabled' => (bool) $preference->in_app_enabled,
                    'push_enabled' => (bool) $preference->push_enabled,
                    'muted_until' => $preference->muted_until?->toIso8601String(),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  array<string,mixed>  $data
     */
    public function notifyUser(
        Tenant $tenant,
        User $user,
        string $category,
        string $title,
        ?string $body = null,
        ?string $itemType = null,
        ?int $itemId = null,
        ?User $actor = null,
        array $data = [],
        ?string $deepLink = null
    ): WorkNotification {
        $category = $this->normalizeCategory($category);
        $preference = $this->preference($tenant, $user, $category);

        $notification = WorkNotification::query()->create([
            'tenant_id' => (int) $tenant->id,
            'user_id' => (int) $user->id,
            'actor_user_id' => $actor?->id,
            'category' => $category,
            'title' => $title,
            'body' => $body,
            'item_type' => $itemType,
            'item_id' => $itemId,
            'deep_link' => $deepLink ?? $this->deepLink($itemType, $itemId),
            'data' => $data,
        ]);

        if ((bool) $preference->email_enabled && ! $this->muted($preference)) {
            $this->sendEmail($notification, $user);
        }

        if ((bool) $preference->push_enabled && ! $this->muted($preference)) {
            $this->auditPush($notification, $tenant, $user);
        }

        return $notification;
    }

    /**
     * @param  iterable<int,User>  $users
     * @param  array<string,mixed>  $data
     */
    public function notifyUsers(
        Tenant $tenant,
        iterable $users,
        string $category,
        string $title,
        ?string $body = null,
        ?string $itemType = null,
        ?int $itemId = null,
        ?User $actor = null,
        array $data = []
    ): void {
        $seen = [];

        foreach ($users as $user) {
            if (! $user instanceof User || isset($seen[(int) $user->id])) {
                continue;
            }

            if ($actor instanceof User && (int) $actor->id === (int) $user->id) {
                continue;
            }

            $seen[(int) $user->id] = true;

            $this->notifyUser(
                tenant: $tenant,
                user: $user,
                category: $category,
                title: $title,
                body: $body,
                itemType: $itemType,
                itemId: $itemId,
                actor: $actor,
                data: $data
            );
        }
    }

    protected function sendEmail(WorkNotification $notification, User $user): void
    {
        try {
            $user->notify(new EverbranchWorkItemNotification($notification));

            WorkNotificationDelivery::query()->create([
                'work_notification_id' => (int) $notification->id,
                'tenant_id' => (int) $notification->tenant_id,
                'user_id' => (int) $user->id,
                'channel' => 'email',
                'status' => 'sent',
                'delivered_at' => now(),
            ]);
        } catch (Throwable $exception) {
            WorkNotificationDelivery::query()->create([
                'work_notification_id' => (int) $notification->id,
                'tenant_id' => (int) $notification->tenant_id,
                'user_id' => (int) $user->id,
                'channel' => 'email',
                'status' => 'failed',
                'error' => $exception->getMessage(),
            ]);
        }
    }

    protected function auditPush(WorkNotification $notification, Tenant $tenant, User $user): void
    {
        /** @var EloquentCollection<int,WorkPushDevice> $devices */
        $devices = WorkPushDevice::query()
            ->forTenantId((int) $tenant->id)
            ->where('user_id', (int) $user->id)
            ->where('push_enabled', true)
            ->whereNull('revoked_at')
            ->get();

        if ($devices->isEmpty()) {
            WorkNotificationDelivery::query()->create([
                'work_notification_id' => (int) $notification->id,
                'tenant_id' => (int) $tenant->id,
                'user_id' => (int) $user->id,
                'channel' => 'push',
                'status' => 'skipped_no_device',
            ]);

            return;
        }

        foreach ($devices as $device) {
            try {
                $response = Http::timeout(5)
                    ->acceptJson()
                    ->post((string) config('services.everbranch_work_mobile.expo_push_url', 'https://exp.host/--/api/v2/push/send'), [
                        'to' => (string) $device->device_token,
                        'title' => (string) $notification->title,
                        'body' => (string) ($notification->body ?? ''),
                        'sound' => 'default',
                        'data' => [
                            'notification_id' => (int) $notification->id,
                            'tenant_id' => (int) $tenant->id,
                            'category' => (string) $notification->category,
                            'item_type' => $notification->item_type,
                            'item_id' => $notification->item_id,
                            'deep_link' => $notification->deep_link,
                        ],
                    ]);

                WorkNotificationDelivery::query()->create([
                    'work_notification_id' => (int) $notification->id,
                    'tenant_id' => (int) $tenant->id,
                    'user_id' => (int) $user->id,
                    'channel' => 'push',
                    'status' => $response->successful() ? 'sent' : 'failed',
                    'error' => $response->successful() ? null : $response->body(),
                    'metadata' => [
                        'work_push_device_id' => (int) $device->id,
                        'platform' => (string) $device->platform,
                        'provider' => 'expo',
                        'status' => $response->status(),
                        'response' => $response->json(),
                    ],
                    'delivered_at' => $response->successful() ? now() : null,
                ]);
            } catch (Throwable $exception) {
                WorkNotificationDelivery::query()->create([
                    'work_notification_id' => (int) $notification->id,
                    'tenant_id' => (int) $tenant->id,
                    'user_id' => (int) $user->id,
                    'channel' => 'push',
                    'status' => 'failed',
                    'error' => $exception->getMessage(),
                    'metadata' => [
                        'work_push_device_id' => (int) $device->id,
                        'platform' => (string) $device->platform,
                        'provider' => 'expo',
                    ],
                ]);
            }
        }
    }

    protected function muted(WorkNotificationPreference $preference): bool
    {
        return $preference->muted_until !== null && $preference->muted_until->isFuture();
    }

    protected function normalizeCategory(string $category): string
    {
        $normalized = strtolower(trim($category));

        return in_array($normalized, self::CATEGORIES, true) ? $normalized : 'direct_notify';
    }

    protected function deepLink(?string $itemType, ?int $itemId): ?string
    {
        if ($itemType === null || $itemId === null || $itemId <= 0) {
            return null;
        }

        return 'everbranch://work/'.$itemType.'/'.$itemId;
    }
}

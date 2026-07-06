<?php

namespace App\Services\Work;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

class EverbranchWorkMentionService
{
    public function __construct(
        protected EverbranchWorkNotificationService $notificationService
    ) {}

    /**
     * @param  array<int,mixed>  $userIds
     * @return EloquentCollection<int,User>
     */
    public function validMentionedUsers(Tenant $tenant, array $userIds): EloquentCollection
    {
        $ids = collect($userIds)
            ->filter(fn (mixed $id): bool => is_numeric($id) && (int) $id > 0)
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return new EloquentCollection;
        }

        return User::query()
            ->whereIn('id', $ids)
            ->whereHas('tenants', fn ($query) => $query->whereKey((int) $tenant->id))
            ->get();
    }

    /**
     * @param  array<int,mixed>  $userIds
     * @return EloquentCollection<int,User>
     */
    public function notifyMentions(
        Tenant $tenant,
        array $userIds,
        string $title,
        ?string $body,
        string $itemType,
        int $itemId,
        User $actor
    ): EloquentCollection {
        $mentioned = $this->validMentionedUsers($tenant, $userIds);

        $this->notificationService->notifyUsers(
            tenant: $tenant,
            users: $mentioned,
            category: 'mention',
            title: $title,
            body: $body,
            itemType: $itemType,
            itemId: $itemId,
            actor: $actor
        );

        return $mentioned;
    }
}

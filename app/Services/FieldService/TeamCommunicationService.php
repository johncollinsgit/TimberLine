<?php

namespace App\Services\FieldService;

use App\Models\FieldServiceJob;
use App\Models\TeamChannel;
use App\Models\TeamMessage;
use App\Models\Tenant;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class TeamCommunicationService
{
    public function __construct(protected FieldServiceAccessService $access) {}

    /** @return Collection<int,TeamChannel> */
    public function channels(Tenant $tenant, User $user): Collection
    {
        $this->companyChannel($tenant, $user);
        $query = TeamChannel::query()->forTenantId((int) $tenant->id)
            ->whereNull('archived_at')
            ->where(function (Builder $visible) use ($tenant, $user): void {
                $visible->where('kind', 'company')
                    ->orWhereHas('members', fn (Builder $members) => $members->whereKey((int) $user->id))
                    ->orWhereHas('job', function (Builder $jobs) use ($tenant, $user): void {
                        $this->access->scopeVisibleJobs($jobs, $user, $tenant);
                    });
            })
            ->with(['job:id,tenant_id,title', 'members:id,name', 'lastMessage.author:id,name'])
            ->withCount('messages')
            ->orderByDesc('updated_at');

        return $query->get();
    }

    public function companyChannel(Tenant $tenant, User $actor): TeamChannel
    {
        return TeamChannel::query()->firstOrCreate(
            ['tenant_id' => (int) $tenant->id, 'kind' => 'company', 'name' => 'Company'],
            ['created_by_user_id' => (int) $actor->id]
        );
    }

    public function jobChannel(Tenant $tenant, User $actor, FieldServiceJob $job): TeamChannel
    {
        abort_unless($this->access->canAccessJob($actor, $tenant, $job), 404);

        return TeamChannel::query()->firstOrCreate(
            ['tenant_id' => (int) $tenant->id, 'field_service_job_id' => (int) $job->id],
            ['kind' => 'job', 'name' => $job->title, 'created_by_user_id' => (int) $actor->id]
        );
    }

    public function directChannel(Tenant $tenant, User $actor, User $other): TeamChannel
    {
        abort_unless($tenant->users()->whereKey((int) $other->id)->wherePivot('membership_active', true)->where('users.is_active', true)->exists(), 404);
        $ids = collect([(int) $actor->id, (int) $other->id])->sort()->values();
        $key = $ids->implode(':');
        $channel = TeamChannel::query()->firstOrCreate(
            ['tenant_id' => (int) $tenant->id, 'direct_key' => $key],
            ['kind' => 'direct', 'name' => null, 'created_by_user_id' => (int) $actor->id]
        );
        $channel->members()->syncWithoutDetaching($ids->mapWithKeys(fn (int $id): array => [$id => ['tenant_id' => (int) $tenant->id]])->all());

        return $channel;
    }

    /** @param array<int,int> $memberIds */
    public function groupChannel(Tenant $tenant, User $actor, string $name, array $memberIds): TeamChannel
    {
        abort_unless(mb_strlen(trim($name)) >= 2, 422, 'Name the group.');
        $ids = collect($memberIds)->map(fn ($id): int => (int) $id)->unique()
            ->reject(fn (int $id): bool => $id === (int) $actor->id)->values();
        abort_unless($ids->isNotEmpty() && $ids->count() <= 49, 422, 'Choose at least one teammate.');
        $activeIds = $tenant->users()->wherePivot('membership_active', true)->where('users.is_active', true)->whereIn('users.id', $ids->all())
            ->pluck('users.id')->map(fn ($id): int => (int) $id);
        abort_unless($activeIds->count() === $ids->count(), 422, 'A teammate is no longer active in this workspace.');

        return DB::transaction(function () use ($tenant, $actor, $name, $activeIds): TeamChannel {
            $channel = TeamChannel::query()->create([
                'tenant_id' => (int) $tenant->id,
                'kind' => 'group',
                'name' => trim($name),
                'created_by_user_id' => (int) $actor->id,
            ]);
            $members = $activeIds->push((int) $actor->id)
                ->mapWithKeys(fn (int $id): array => [$id => ['tenant_id' => (int) $tenant->id]])->all();
            $channel->members()->sync($members);

            return $channel->load('members:id,name');
        });
    }

    public function assertAccess(Tenant $tenant, User $user, TeamChannel $channel): void
    {
        abort_unless((int) $channel->tenant_id === (int) $tenant->id, 404);
        if ($channel->kind === 'company') {
            return;
        }
        if ($channel->kind === 'job' && $channel->job && $this->access->canAccessJob($user, $tenant, $channel->job)) {
            return;
        }
        abort_unless($channel->members()->whereKey((int) $user->id)->exists(), 404);
    }

    /** @param array<int,int> $mentionUserIds */
    public function post(Tenant $tenant, User $user, TeamChannel $channel, string $body, string $clientUuid, array $mentionUserIds = [], ?int $parentId = null): TeamMessage
    {
        $this->assertAccess($tenant, $user, $channel);
        $mentions = $tenant->users()->whereIn('users.id', $mentionUserIds)->pluck('users.id')->map(fn ($id): int => (int) $id)->all();
        if ($parentId !== null) {
            abort_unless($channel->messages()->whereKey($parentId)->exists(), 422, 'The reply target is not in this channel.');
        }

        return DB::transaction(function () use ($tenant, $user, $channel, $body, $clientUuid, $mentions, $parentId): TeamMessage {
            $message = TeamMessage::query()->firstOrCreate(
                ['tenant_id' => (int) $tenant->id, 'created_by_user_id' => (int) $user->id, 'client_uuid' => $clientUuid],
                ['team_channel_id' => (int) $channel->id, 'parent_message_id' => $parentId, 'body' => trim($body), 'mention_user_ids' => $mentions]
            );
            $channel->forceFill(['updated_at' => now()])->save();
            $this->markRead($tenant, $user, $channel);

            return $message->load('author:id,name');
        });
    }

    public function markRead(Tenant $tenant, User $user, TeamChannel $channel, ?CarbonInterface $readThrough = null): void
    {
        $this->assertAccess($tenant, $user, $channel);
        $at = $readThrough ?: now();
        $channel->members()->syncWithoutDetaching([(int) $user->id => ['tenant_id' => (int) $tenant->id, 'last_read_at' => $at]]);
        $channel->members()->updateExistingPivot((int) $user->id, ['last_read_at' => $at]);
    }

    public function markUnread(Tenant $tenant, User $user, TeamChannel $channel): void
    {
        $this->assertAccess($tenant, $user, $channel);
        $latest = $channel->messages()->whereNull('deleted_at')->where('created_by_user_id', '!=', (int) $user->id)->latest('id')->first();
        if (! $latest) {
            return;
        }
        $channel->members()->syncWithoutDetaching([(int) $user->id => ['tenant_id' => (int) $tenant->id]]);
        $channel->members()->updateExistingPivot((int) $user->id, ['last_read_at' => $latest->created_at->copy()->subSecond()]);
    }
}

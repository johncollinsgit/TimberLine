<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Models\FieldServiceJob;
use App\Models\TeamChannel;
use App\Models\TeamMessage;
use App\Models\Tenant;
use App\Models\User;
use App\Services\FieldService\TeamCommunicationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EverbranchMobileTeamController extends Controller
{
    public function index(Request $request, TeamCommunicationService $team): JsonResponse
    {
        $tenant = $this->tenant($request);
        $user = $this->user($request);

        return response()->json(['contract_version' => 5, 'channels' => $team->channels($tenant, $user)->map(fn (TeamChannel $channel): array => $this->channelPayload($channel, $user))->values()]);
    }

    public function show(Request $request, string $tenant, TeamChannel $channel, TeamCommunicationService $team): JsonResponse
    {
        $tenantModel = $this->tenant($request);
        $user = $this->user($request);
        $team->assertAccess($tenantModel, $user, $channel);
        $messages = $channel->messages()->whereNull('deleted_at')->with('author:id,name')->latest('id')->limit(100)->get()->reverse()->values();
        $team->markRead($tenantModel, $user, $channel);

        return response()->json(['channel' => $this->channelPayload($channel->loadMissing(['job:id,tenant_id,title', 'members:id,name']), $user), 'messages' => $messages->map(fn (TeamMessage $message): array => $this->messagePayload($message))->values(), 'poll_after_ms' => 5000]);
    }

    public function store(Request $request, string $tenant, TeamChannel $channel, TeamCommunicationService $team): JsonResponse
    {
        $validated = $request->validate([
            'body' => ['required', 'string', 'max:10000'], 'client_uuid' => ['required', 'uuid'],
            'mention_user_ids' => ['nullable', 'array', 'max:50'], 'mention_user_ids.*' => ['integer'],
            'parent_message_id' => ['nullable', 'integer'],
        ]);
        $message = $team->post($this->tenant($request), $this->user($request), $channel, $validated['body'], $validated['client_uuid'], (array) ($validated['mention_user_ids'] ?? []), $validated['parent_message_id'] ?? null);

        return response()->json(['ok' => true, 'message' => $this->messagePayload($message)], 201);
    }

    public function createJobChannel(Request $request, TeamCommunicationService $team): JsonResponse
    {
        $validated = $request->validate(['job_id' => ['required', 'integer']]);
        $tenant = $this->tenant($request);
        $job = FieldServiceJob::query()->forTenantId((int) $tenant->id)->findOrFail((int) $validated['job_id']);
        $channel = $team->jobChannel($tenant, $this->user($request), $job)->load(['job:id,tenant_id,title', 'members:id,name']);

        return response()->json(['channel' => $this->channelPayload($channel, $this->user($request))], 201);
    }

    public function createDirectChannel(Request $request, TeamCommunicationService $team): JsonResponse
    {
        $validated = $request->validate(['user_id' => ['required', 'integer']]);
        $tenant = $this->tenant($request);
        $other = $tenant->users()->whereKey((int) $validated['user_id'])->firstOrFail();
        $channel = $team->directChannel($tenant, $this->user($request), $other)->load(['members:id,name']);

        return response()->json(['channel' => $this->channelPayload($channel, $this->user($request))], 201);
    }

    /** @return array<string,mixed> */
    protected function channelPayload(TeamChannel $channel, User $viewer): array
    {
        $other = $channel->kind === 'direct' ? $channel->members->firstWhere('id', '!=', (int) $viewer->id) : null;

        return [
            'id' => (int) $channel->id, 'kind' => $channel->kind,
            'name' => $channel->kind === 'job' ? $channel->job?->title : ($other?->name ?: ($channel->name ?: 'Conversation')),
            'job_id' => $channel->field_service_job_id ? (int) $channel->field_service_job_id : null,
            'message_count' => (int) ($channel->messages_count ?? 0),
            'updated_at' => $channel->updated_at?->toIso8601String(),
        ];
    }

    /** @return array<string,mixed> */
    protected function messagePayload(TeamMessage $message): array
    {
        return ['id' => (int) $message->id, 'client_uuid' => $message->client_uuid, 'body' => $message->body, 'author' => $message->author ? ['id' => (int) $message->author->id, 'name' => $message->author->name] : null, 'parent_message_id' => $message->parent_message_id, 'mention_user_ids' => $message->mention_user_ids ?: [], 'reactions' => $message->reactions ?: [], 'created_at' => $message->created_at?->toIso8601String()];
    }

    protected function tenant(Request $request): Tenant
    {
        $tenant = $request->attributes->get('current_tenant');
        abort_unless($tenant instanceof Tenant, 403);

        return $tenant;
    }

    protected function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->is_active !== false, 401);

        return $user;
    }
}

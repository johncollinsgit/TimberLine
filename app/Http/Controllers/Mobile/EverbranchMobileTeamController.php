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
use Illuminate\Support\Str;

class EverbranchMobileTeamController extends Controller
{
    public function index(Request $request, TeamCommunicationService $team): JsonResponse
    {
        $tenant = $this->tenant($request);
        $user = $this->user($request);

        $teammates = $tenant->users()->wherePivot('membership_active', true)->where('users.is_active', true)->where('users.id', '!=', (int) $user->id)
            ->orderBy('users.name')->get(['users.id', 'users.name']);

        return response()->json([
            'contract_version' => 6,
            'channels' => $team->channels($tenant, $user)->map(fn (TeamChannel $channel): array => $this->channelPayload($channel, $user))->values(),
            'teammates' => $teammates->map(fn (User $teammate): array => [
                'id' => (int) $teammate->id,
                'name' => $teammate->name,
                'role' => (string) $teammate->pivot->role,
            ])->values(),
        ]);
    }

    public function show(Request $request, string $tenant, TeamChannel $channel, TeamCommunicationService $team): JsonResponse
    {
        $tenantModel = $this->tenant($request);
        $user = $this->user($request);
        $team->assertAccess($tenantModel, $user, $channel);
        $messages = $channel->messages()->whereNull('deleted_at')->with(['author:id,name', 'attachments'])->latest('id')->limit(100)->get()->reverse()->values();
        if ($messages->isNotEmpty()) {
            $team->markRead($tenantModel, $user, $channel, $messages->last()->created_at);
        }

        return response()->json(['channel' => $this->channelPayload($channel->loadMissing(['job:id,tenant_id,title', 'members:id,name']), $user), 'messages' => $messages->map(fn (TeamMessage $message): array => $this->messagePayload($message))->values(), 'poll_after_ms' => 5000]);
    }

    public function store(Request $request, string $tenant, TeamChannel $channel, TeamCommunicationService $team): JsonResponse
    {
        $validated = $request->validate([
            'body' => ['nullable', 'required_without:attachment_ids', 'string', 'max:10000'], 'client_uuid' => ['required', 'uuid'],
            'mention_user_ids' => ['nullable', 'array', 'max:50'], 'mention_user_ids.*' => ['integer'],
            'parent_message_id' => ['nullable', 'integer'],
            'attachment_ids' => ['nullable', 'array', 'max:5'], 'attachment_ids.*' => ['required', 'integer', 'distinct'],
            'review_source_token' => ['sometimes', 'string', 'max:12000'],
        ]);
        $tenant = $this->tenant($request);
        $user = $this->user($request);
        // Keep access checks ahead of idempotent replay: a receipt never grants
        // access to a channel after the user's job membership has changed.
        $team->assertAccess($tenant, $user, $channel);
        if (isset($validated['review_source_token'])) {
            abort_unless($channel->kind === 'job' && $channel->field_service_job_id, 422);
            $receipt = TeamMessage::query()
                ->where('tenant_id', $tenant->id)
                ->where('created_by_user_id', $user->id)
                ->where('client_uuid', $validated['client_uuid'])
                ->with('attachments')
                ->first();
            if ($receipt) {
                $mentions = $tenant->users()->whereIn('users.id', (array) ($validated['mention_user_ids'] ?? []))
                    ->pluck('users.id')->map(fn ($id): int => (int) $id)->all();
                $attachmentIds = collect($validated['attachment_ids'] ?? [])->map(fn ($id): int => (int) $id)->sort()->values()->all();
                $existingAttachmentIds = $receipt->attachments->pluck('id')->map(fn ($id): int => (int) $id)->sort()->values()->all();
                abort_unless((int) $receipt->team_channel_id === (int) $channel->id
                    && trim((string) $receipt->body) === trim((string) ($validated['body'] ?? ''))
                    && (int) ($receipt->parent_message_id ?? 0) === (int) ($validated['parent_message_id'] ?? 0)
                    && collect($receipt->mention_user_ids ?: [])->map(fn ($id): int => (int) $id)->sort()->values()->all() === collect($mentions)->sort()->values()->all()
                    && $existingAttachmentIds === $attachmentIds, 409,
                    'This message key was already used for a different message.');

                return response()->json(['ok' => true, 'message' => $this->messagePayload($receipt->loadMissing('author:id,name'))], 200);
            }
        }
        if (isset($validated['review_source_token'])) {
            abort_unless($channel->kind === 'job' && $channel->field_service_job_id, 422);
            app(\App\Services\FieldService\FieldCrewAssistant::class)->assertReviewSourceToken(
                $validated['review_source_token'], $tenant, $user, (int) $channel->field_service_job_id, (int) $channel->id
            );
        }
        $message = $team->post($tenant, $user, $channel, (string) ($validated['body'] ?? ''), $validated['client_uuid'], (array) ($validated['mention_user_ids'] ?? []), $validated['parent_message_id'] ?? null, (array) ($validated['attachment_ids'] ?? []));

        return response()->json(['ok' => true, 'message' => $this->messagePayload($message)], 201);
    }

    public function markUnread(Request $request, string $tenant, TeamChannel $channel, TeamCommunicationService $team): JsonResponse
    {
        $team->markUnread($this->tenant($request), $this->user($request), $channel);

        return response()->json(['ok' => true]);
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

    public function createGroupChannel(Request $request, TeamCommunicationService $team): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:80'],
            'member_ids' => ['required', 'array', 'min:1', 'max:49'],
            'member_ids.*' => ['required', 'integer', 'distinct'],
        ]);
        $tenant = $this->tenant($request);
        $user = $this->user($request);
        $channel = $team->groupChannel($tenant, $user, $validated['name'], $validated['member_ids']);

        return response()->json(['channel' => $this->channelPayload($channel, $user)], 201);
    }

    /** @return array<string,mixed> */
    protected function channelPayload(TeamChannel $channel, User $viewer): array
    {
        $other = $channel->kind === 'direct' ? $channel->members->firstWhere('id', '!=', (int) $viewer->id) : null;
        $lastMessage = $channel->lastMessage;

        return [
            'id' => (int) $channel->id, 'kind' => $channel->kind,
            'name' => $channel->kind === 'job' ? $channel->job?->title : ($other?->name ?: ($channel->name ?: 'Conversation')),
            'job_id' => $channel->field_service_job_id ? (int) $channel->field_service_job_id : null,
            'message_count' => (int) ($channel->messages_count ?? 0),
            'unread_count' => $channel->messages()
                ->whereNull('deleted_at')
                ->where('created_by_user_id', '!=', (int) $viewer->id)
                ->when($channel->members->firstWhere('id', (int) $viewer->id)?->pivot?->last_read_at, fn ($messages, $lastRead) => $messages->where('created_at', '>', $lastRead))
                ->count(),
            'updated_at' => $channel->updated_at?->toIso8601String(),
            'last_message' => $lastMessage ? [
                'preview' => Str::limit(trim((string) preg_replace('/\s+/u', ' ', $lastMessage->body)) ?: 'Shared a file', 120),
                'author_name' => $lastMessage->author?->name,
                'created_at' => $lastMessage->created_at?->toIso8601String(),
            ] : null,
        ];
    }

    /** @return array<string,mixed> */
    protected function messagePayload(TeamMessage $message): array
    {
        return ['id' => (int) $message->id, 'client_uuid' => $message->client_uuid, 'body' => $message->body, 'author' => $message->author ? ['id' => (int) $message->author->id, 'name' => $message->author->name] : null, 'parent_message_id' => $message->parent_message_id, 'mention_user_ids' => $message->mention_user_ids ?: [], 'reactions' => $message->reactions ?: [], 'attachments' => $message->attachments->map(fn ($file): array => ['id' => (int) $file->id, 'name' => $file->file_name, 'mime_type' => $file->mime_type, 'size' => (int) $file->file_size])->values(), 'created_at' => $message->created_at?->toIso8601String()];
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

<?php

namespace App\Services\AiBranch;

use App\Models\AiBranchAccount;
use App\Models\AiBranchAction;
use App\Models\CustomerLoopAction;
use App\Models\Tenant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Shared triage for registered Customer Loop follow-ups; never sends a draft. */
class AiSharedAttention
{
    public function authorize(Tenant $tenant, User $user): void
    {
        app(AiAssistantAccess::class)->authorize($tenant, $user);
        app(AiWorkspaceContext::class)->authorize($tenant, $user);
        abort_unless(config('ai_branch.assistant_attention_enabled'), 404);
        abort_unless(app(AiWorkspaceContext::class)->permissions($tenant, $user)['followups'], 403);
    }

    private function query(Tenant $tenant, User $user)
    {
        return app(AiWorkspaceSources::class)->followupsQuery($tenant, $user, false)
            ->addSelect(['customer_loop_activity_id', 'assigned_to_user_id', 'created_by_user_id', 'action_type', 'reason', 'draft_body', 'prepared_at', 'completed_at', 'snoozed_until', 'safe_context', 'created_at', 'updated_at']);
    }

    public function version(CustomerLoopAction $action): string
    {
        $state = \Illuminate\Support\Arr::only($action->getRawOriginal(), ['id', 'tenant_id', 'customer_loop_activity_id', 'marketing_profile_id', 'assigned_to_user_id', 'created_by_user_id', 'action_type', 'status', 'title', 'reason', 'draft_body', 'due_at', 'prepared_at', 'completed_at', 'snoozed_until', 'safe_context', 'created_at', 'updated_at']);
        ksort($state);

        return hash('sha256', json_encode($state, JSON_THROW_ON_ERROR));
    }

    private function item(CustomerLoopAction $action, Tenant $tenant): array
    {
        $lease = (array) data_get($action->safe_context, 'ai_attention.lease', []);
        $claim = isset($lease['until']) && CarbonImmutable::parse($lease['until'])->isFuture() ? $lease : null;
        $users = $tenant->users()->whereIn('users.id', array_filter([$action->assigned_to_user_id, $claim['user_id'] ?? null]))
            ->wherePivot('membership_active', true)->where('is_active', true)->pluck('users.name', 'users.id');

        return ['ref' => 'followup:'.$action->id, 'id' => $action->id, 'title' => mb_substr($action->title, 0, 190),
            'status' => $action->status, 'version' => $this->version($action), 'due_at' => $action->due_at?->toIso8601String(),
            'snoozed_until' => $action->snoozed_until?->toIso8601String(), 'assigned_user_id' => $action->assigned_to_user_id,
            'assigned_name' => $action->assigned_to_user_id ? ($users[$action->assigned_to_user_id] ?? 'Assignment unavailable') : null,
            'claim' => $claim ? [...$claim, 'name' => $users[$claim['user_id']] ?? 'Unavailable member'] : null,
            'url' => route('customer-loop.index', ['tenant' => $tenant->slug])];
    }

    public function assignees(Tenant $tenant): array
    {
        // Wider operational grants are deliberately not inferred from membership.
        return $tenant->users()->wherePivot('membership_active', true)->where('is_active', true)
            ->wherePivotIn('role', ['owner', 'tenant_owner', 'admin'])->orderBy('users.name')->get()
            ->filter(fn ($member) => $member->canAccessMarketing())->map(fn ($member) => ['id' => $member->id, 'name' => $member->name])->values()->all();
    }

    public function listing(Tenant $tenant, User $user, string $view, int $after = 0): array
    {
        $this->authorize($tenant, $user);
        abort_unless(in_array($view, ['open', 'snoozed', 'resolved'], true), 422);
        $rows = $this->query($tenant, $user)->where('id', '>', $after);
        if ($view === 'resolved') {
            $rows->whereIn('status', ['completed', 'dismissed']);
        } else {
            $rows->whereNotIn('status', ['completed', 'dismissed']);
            if ($view === 'snoozed') {
                $rows->where('snoozed_until', '>', now());
            } else {
                $rows->where(fn ($q) => $q->whereNull('snoozed_until')->orWhere('snoozed_until', '<=', now()));
            }
        }
        $found = $rows->orderBy('id')->limit(51)->get();
        $items = $found->take(50)->map(fn ($row) => $this->item($row, $tenant))->values()->all();
        $this->authorize($tenant, $user);

        return ['version' => 1, 'viewer_id' => $user->id, 'items' => $items, 'next_cursor' => $found->count() > 50 ? end($items)['id'] : null,
            'assignees' => $this->assignees($tenant), 'generated_at' => now()->toIso8601String(),
            'coverage' => 'Registered Customer Loop follow-ups only. Other work, mail and calls remain in their current screens. Changes here do not send a draft.'];
    }

    public function transition(Tenant $tenant, User $user, int $id, string $operation, string $version, string $uuid, array $options = []): array
    {
        $this->authorize($tenant, $user);
        abort_unless(Str::isUuid($uuid) && preg_match('/^[a-f0-9]{64}$/', $version), 422);
        abort_unless(in_array($operation, ['claim', 'release', 'assign', 'snooze', 'resolve', 'reopen'], true), 422);
        ksort($options);
        $requestHash = hash('sha256', json_encode([$user->id, $id, $operation, $version, $options], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($tenant, $user, $id, $operation, $version, $uuid, $options, $requestHash): array {
            AiBranchAccount::query()->where('tenant_id', $tenant->id)->lockForUpdate()->firstOrFail();
            $action = $this->query($tenant, $user)->whereKey($id)->lockForUpdate()->firstOrFail();
            $this->authorize($tenant, $user);
            $receipt = AiBranchAction::query()->where('tenant_id', $tenant->id)->where('client_uuid', $uuid)->first();
            if ($receipt) {
                abort_unless($receipt->kind === 'attention_transition' && (int) $receipt->user_id === (int) $user->id
                    && hash_equals((string) ($receipt->payload['request_hash'] ?? ''), $requestHash), 409);

                return ['receipt_id' => $receipt->id, 'replayed' => true, 'item' => $this->item($action, $tenant)];
            }
            abort_unless(hash_equals($this->version($action), $version), 409, 'This item changed. Refresh before changing it.');
            $context = (array) $action->safe_context;
            $attention = (array) ($context['ai_attention'] ?? []);
            $lease = (array) ($attention['lease'] ?? []);
            $owned = ($lease['user_id'] ?? null) === $user->id && isset($lease['until']) && CarbonImmutable::parse($lease['until'])->isFuture();
            $held = isset($lease['until']) && CarbonImmutable::parse($lease['until'])->isFuture();
            if ($operation === 'claim') {
                abort_if($held && ! $owned, 409, 'Another person is working on this item.');
                $attention['lease'] = ['user_id' => $user->id, 'until' => now()->addMinutes(5)->toIso8601String()];
            } else {
                abort_unless($owned, 409, 'Claim this item before changing it. Your previous claim may have expired.');
                if ($operation === 'assign') {
                    $assignee = (int) ($options['assignee_id'] ?? 0);
                    abort_unless(in_array($assignee, array_column($this->assignees($tenant), 'id'), true), 422, 'Choose an authorized active workspace assignee.');
                    $action->assigned_to_user_id = $assignee;
                } elseif ($operation === 'snooze') {
                    abort_unless(preg_match('/(?:Z|[+-]\d{2}:\d{2})$/', (string) ($options['until'] ?? '')), 422, 'Snooze time must include its timezone.');
                    try {
                        $until = CarbonImmutable::parse((string) ($options['until'] ?? ''));
                    } catch (\Throwable) {
                        abort(422, 'Choose a valid snooze time.');
                    }
                    abort_unless(! empty($options['until']) && $until->isFuture() && $until->lte(now()->addDays(90)), 422);
                    abort_if(in_array($action->status, ['completed', 'dismissed'], true), 409);
                    $action->forceFill(['status' => 'snoozed', 'snoozed_until' => $until]);
                } elseif ($operation === 'resolve') {
                    $resolution = trim((string) ($options['resolution'] ?? ''));
                    abort_unless($resolution !== '' && mb_strlen($resolution) <= 500, 422);
                    abort_if(in_array($action->status, ['completed', 'dismissed'], true), 409);
                    $attention['resolution_hash'] = hash('sha256', $resolution);
                    $action->forceFill(['status' => 'completed', 'completed_at' => now(), 'snoozed_until' => null]);
                } elseif ($operation === 'reopen') {
                    abort_unless(in_array($action->status, ['completed', 'dismissed'], true), 409);
                    unset($attention['resolution_hash']);
                    $action->forceFill(['status' => 'suggested', 'completed_at' => null, 'snoozed_until' => null]);
                }
                $attention['lease'] = null;
            }
            $attention['revision'] = (int) ($attention['revision'] ?? 0) + 1;
            $action->safe_context = [...$context, 'ai_attention' => $attention];
            $action->save();
            $payload = ['request_hash' => $requestHash, 'source_id' => $id, 'operation' => $operation,
                'before_version' => $version, 'after_version' => $this->version($action->fresh()),
                ...($operation === 'resolve' ? ['resolution' => $resolution] : [])];
            $receipt = AiBranchAction::create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'client_uuid' => $uuid,
                'kind' => 'attention_transition', 'payload' => $payload, 'digest' => hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR)),
                'status' => 'executed', 'result_id' => $id, 'decided_at' => now(), 'expires_at' => now()->addDays(30)]);
            app(\App\Services\Tenancy\LandlordOperatorActionAuditService::class)->record($tenant->id, $user->id, 'ai_branch.attention.'.$operation,
                targetType: 'customer_loop_action', targetId: $id, confirmation: ['explicit' => true], result: ['receipt_id' => $receipt->id]);

            return ['receipt_id' => $receipt->id, 'replayed' => false, 'item' => $this->item($action->fresh(), $tenant)];
        }, 3);
    }
}

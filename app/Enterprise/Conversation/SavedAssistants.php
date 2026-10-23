<?php

namespace App\Enterprise\Conversation;

use App\Enterprise\Planning;
use App\Enterprise\RecordStore;
use App\Enterprise\WorkspaceAccess;
use App\Models\TeamOperationAttempt;
use App\Models\TeamOperationRecord;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Str;

/** Explicit private configuration, never a role, model/account override or provider tool grant. */
class SavedAssistants
{
    public const KIND = 'ent_saved_assistant';

    public function __construct(private WorkspaceAccess $access, private RecordStore $store) {}

    private function actor(Tenant $tenant, User $user, string $area, bool $write = false): array
    {
        abort_unless(config('enterprise.conversation.saved_assistants_enabled'), 404);
        $actor = $this->access->actor($tenant, $user, $write);
        $this->access->area($tenant, $actor, $area);
        abort_if($write && ! WorkspaceAccess::permits($actor, $area, true), 403);

        return $actor;
    }

    public static function checks(array $actor): array
    {
        return ['overview' => (bool) config('enterprise.read_only_assistant_enabled') && (bool) config('enterprise.briefing_enabled'),
            'building' => (bool) config('enterprise.read_only_assistant_enabled') && (bool) config('enterprise.resources_enabled'),
            'costs' => (bool) config('enterprise.read_only_assistant_enabled') && (bool) config('enterprise.planning_enabled') && Planning::financial($actor)];
    }

    private function row(Tenant $tenant, User $user, string $area, string $id): TeamOperationRecord
    {
        return TeamOperationRecord::query()->where(['tenant_id' => $tenant->id, 'area_id' => $area, 'kind' => self::KIND,
            'assigned_user_id' => $user->id])->findOrFail($id);
    }

    private function view(TeamOperationRecord $row, array $actor): array
    {
        abort_unless($row->status === 'active', 404);
        $available = self::checks($actor);

        return ['id' => $row->id, 'area_id' => $row->area_id, 'version' => $row->version, 'name' => $row->data['name'],
            'purpose' => $row->data['purpose'], 'instructions' => $row->data['instructions'],
            'checks' => $row->data['checks'], 'available_checks' => array_values(array_filter($row->data['checks'], fn ($key) => $available[$key] ?? false)),
            'provenance' => ['method' => 'explicit_user_configuration', 'created_by' => (string) $row->assigned_user_id,
                'created_at' => $row->data['created_at'], 'updated_at' => $row->updated_at?->toIso8601String(),
                'scope' => 'Private to this account, workspace and department.'],
            'retention' => 'Saved until you explicitly delete it. Profile content is removed; content-free audit and retry receipts remain.',
            'notice' => 'Purpose and instructions are preferences, not current facts or permissions. Only the currently available read-only checks can be used. No model, account, send, voice or write tool can be configured here.'];
    }

    public function listing(Tenant $tenant, User $user, string $area): array
    {
        $actor = $this->actor($tenant, $user, $area);
        $rows = TeamOperationRecord::query()->where(['tenant_id' => $tenant->id, 'area_id' => $area, 'kind' => self::KIND,
            'assigned_user_id' => $user->id, 'status' => 'active'])->orderBy('id')->limit(11)->get();
        $items = $rows->take(10)->map(fn ($row) => $this->view($row, $actor))->values()->all();
        $fresh = $this->actor($tenant, $user, $area);
        abort_unless(hash_equals(\App\Enterprise\AskEverbranch::digest($actor), \App\Enterprise\AskEverbranch::digest($fresh)), 409);

        return ['items' => $items, 'has_more' => $rows->count() > 10, 'checks' => self::checks($fresh),
            'can_manage' => config('enterprise.writes_enabled') && WorkspaceAccess::permits($fresh, $area, true),
            'scope' => ['tenant_id' => (string) $tenant->id, 'user_id' => (string) $user->id, 'area_id' => $area],
            'provider_dispatch' => false, 'memory' => 'Persistent preference/fact memory is not part of saved assistant configuration.'];
    }

    public function show(Tenant $tenant, User $user, string $area, string $id): array
    {
        $actor = $this->actor($tenant, $user, $area);

        $value = $this->view($this->row($tenant, $user, $area, $id), $actor);
        $fresh = $this->actor($tenant, $user, $area);
        abort_unless(hash_equals(\App\Enterprise\AskEverbranch::digest($actor), \App\Enterprise\AskEverbranch::digest($fresh)), 409);

        return $value;
    }

    /** Fresh own-profile/current-intent check before each snapshot, dispatch and replay. */
    public function context(Tenant $tenant, User $user, string $area, string $id, string $intent): array
    {
        $profile = $this->show($tenant, $user, $area, $id);
        abort_unless(in_array($intent, $profile['available_checks'], true), 404, 'This read check is not available to this assistant with your current access.');

        return array_intersect_key($profile, array_flip(['id', 'version', 'name', 'purpose', 'instructions', 'available_checks']));
    }

    public function save(Tenant $tenant, User $user, array $input): array
    {
        return $this->store->locked($tenant, function () use ($tenant, $user, $input): array {
            $actor = $this->actor($tenant, $user, $input['area_id'], true);
            sort($input['checks']);
            $allowed = self::checks($actor);
            foreach ($input['checks'] as $check) {
                abort_unless($allowed[$check] ?? false, 422, 'Only currently permitted read checks can be saved.');
            }
            // Do not automatically capture conversation content, and reject recognizable credential material.
            abort_if(preg_match('/-----BEGIN[ A-Z]*PRIVATE KEY-----|\bsk-(?:proj-)?[A-Za-z0-9_-]{12,}|\bAKIA[A-Z0-9]{16}\b|\b(?:password|api[ _-]?key|authorization|access[ _-]?token)\s*[:=]/iu',
                $input['name'].' '.$input['purpose'].' '.$input['instructions']), 422, 'Do not save passwords, keys or access tokens in an assistant.');
            $hash = hash('sha256', json_encode(['save', $input], JSON_THROW_ON_ERROR));
            $receipt = TeamOperationAttempt::query()->where(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'request_id' => $input['request_id']])->first();
            if ($receipt) {
                abort_unless($receipt->kind === 'ent.saved_assistant' && hash_equals($receipt->input_hash, $hash), 409);

                $current = $this->row($tenant, $user, $input['area_id'], $input['id']);
                abort_unless($current->status === 'active' && $current->version === ($receipt->result['version'] ?? null), 409,
                    'The saved configuration changed after that receipt. Inspect the current assistant before editing.');

                return $this->view($current, $actor) + ['replayed' => true];
            }
            if (TeamOperationRecord::query()->whereKey($input['id'])->exists()) {
                $row = $this->row($tenant, $user, $input['area_id'], $input['id']);
                abort_unless($row->status === 'active' && $row->version === $input['expected_version'], 409);
            } else {
                abort_unless($input['expected_version'] === 0, 409);
                abort_if(TeamOperationRecord::query()->where(['tenant_id' => $tenant->id, 'kind' => self::KIND,
                    'assigned_user_id' => $user->id, 'status' => 'active'])->count() >= 10, 422, 'Delete an existing saved assistant first.');
                $row = new TeamOperationRecord(['id' => $input['id'], 'tenant_id' => $tenant->id, 'area_id' => $input['area_id'],
                    'kind' => self::KIND, 'assigned_user_id' => $user->id, 'source_key' => hash('sha256', 'saved-assistant:'.$user->id.':'.$input['id']),
                    'visibility' => 'private', 'title' => 'Private saved assistant', 'version' => 0, 'status' => 'active']);
            }
            $created = $row->data['created_at'] ?? now()->toIso8601String();
            $row->data = ['name' => $input['name'], 'purpose' => $input['purpose'], 'instructions' => $input['instructions'], 'checks' => $input['checks'], 'created_at' => $created];
            $row->version++;
            $row->save();
            $this->store->audit($row, $user->id, 'saved_assistant.saved', ['configuration_hash' => $hash]);
            $this->receipt($tenant, $user, $row, $input, $hash);

            return $this->view($row, $actor) + ['replayed' => false];
        });
    }

    public function delete(Tenant $tenant, User $user, array $input): array
    {
        return $this->store->locked($tenant, function () use ($tenant, $user, $input): array {
            $this->actor($tenant, $user, $input['area_id'], true);
            $row = $this->row($tenant, $user, $input['area_id'], $input['id']);
            $hash = hash('sha256', json_encode(['delete', $input], JSON_THROW_ON_ERROR));
            $receipt = TeamOperationAttempt::query()->where(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'request_id' => $input['request_id']])->first();
            if ($receipt) {
                abort_unless($receipt->kind === 'ent.saved_assistant' && hash_equals($receipt->input_hash, $hash), 409);

                return ['id' => $row->id, 'version' => $row->version, 'status' => $row->status, 'replayed' => true];
            }
            abort_unless($row->status === 'active' && $row->version === $input['expected_version'], 409);
            $row->data = ['deleted_at' => now()->toIso8601String()];
            $row->status = 'deleted';
            $row->version++;
            $row->save();
            $this->store->audit($row, $user->id, 'saved_assistant.deleted_content_scrubbed');
            $this->receipt($tenant, $user, $row, $input, $hash);

            return ['id' => $row->id, 'version' => $row->version, 'status' => 'deleted', 'replayed' => false];
        });
    }

    private function receipt(Tenant $tenant, User $user, TeamOperationRecord $row, array $input, string $hash): void
    {
        TeamOperationAttempt::query()->create(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'area_id' => $row->area_id,
            'record_id' => $row->id, 'user_id' => $user->id, 'kind' => 'ent.saved_assistant', 'status' => 'accepted',
            'request_id' => $input['request_id'], 'input_hash' => $hash, 'result' => ['id' => $row->id, 'version' => $row->version, 'status' => $row->status]]);
    }
}

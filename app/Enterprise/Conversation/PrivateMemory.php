<?php

namespace App\Enterprise\Conversation;

use App\Enterprise\AskEverbranch;
use App\Enterprise\AuthorizedEvidence;
use App\Enterprise\RecordStore;
use App\Enterprise\WorkspaceAccess;
use App\Models\TeamOperationAttempt;
use App\Models\TeamOperationRecord;
use App\Models\Tenant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/** Explicit typed preferences and source observations. Never automatic chat capture or additional retrieval authority. */
class PrivateMemory
{
    public const KIND = 'ent_assistant_memory';

    public const PREFERENCES = ['reply_length' => ['brief', 'standard'], 'date_style' => ['iso', 'long']];

    public const FIELDS = ['status' => 'Status', 'priority' => 'Priority', 'due_on' => 'Due date',
        'amount_minor' => 'Amount in minor units', 'currency' => 'Currency', 'basis' => 'Basis'];

    public function __construct(private WorkspaceAccess $access, private RecordStore $store, private AuthorizedEvidence $evidence) {}

    public static function enabled(): bool
    {
        return config('enterprise.conversation.memory_enabled') === true && config('enterprise.conversation.memory_retention_ready') === true;
    }

    private function actor(Tenant $tenant, User $user, string $area, bool $write = false): array
    {
        abort_unless(self::enabled(), 404);
        app(\App\Enterprise\Provisioning\ClientSilo::class)->assertReady();
        $actor = $this->access->actor($tenant, $user, $write);
        $this->access->area($tenant, $actor, $area);
        abort_if($write && ! WorkspaceAccess::permits($actor, $area, true), 403);

        return $actor;
    }

    private function row(Tenant $tenant, User $user, string $area, string $id, bool $lock = false): TeamOperationRecord
    {
        $query = TeamOperationRecord::query()->where(['tenant_id' => $tenant->id, 'area_id' => $area, 'kind' => self::KIND, 'assigned_user_id' => $user->id]);
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->findOrFail($id);
    }

    private function source(Tenant $tenant, User $user, string $area, string $ref): array
    {
        $parts = explode(':', $ref);
        abort_unless(count($parts) === 2 && in_array($parts[0], ['request', 'project', 'task', 'building', 'cost'], true) && Str::isUuid($parts[1]), 422);

        return $this->evidence->capture($tenant, $user, $area, $parts[0], $parts[1]);
    }

    private function stamp(array $source): string
    {
        return AskEverbranch::digest(['ref' => $source['ref'], 'version' => $source['version'], 'facts' => $source['facts']]);
    }

    private function field(array $source, string $key): mixed
    {
        $label = self::FIELDS[$key] ?? null;
        abort_unless($label && array_key_exists($label, $source['facts']), 422, 'That source does not provide this bounded observation field.');
        $value = $source['facts'][$label];
        $valid = match ($key) {
            'amount_minor' => is_int($value) && $value >= 0 && $value <= 1000000000,
            'currency' => is_string($value) && preg_match('/^[A-Z]{3}$/D', $value),
            'basis' => in_array($value, ['estimate', 'recorded_cost'], true),
            'priority' => in_array($value, [null, 'urgent', 'high', 'normal', 'low'], true),
            'status' => is_string($value) && preg_match('/^[a-z_]{1,40}$/D', $value),
            'due_on' => $value === null || is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value)
                && checkdate((int) substr($value, 5, 2), (int) substr($value, 8, 2), (int) substr($value, 0, 4)),
            default => false,
        };
        abort_unless($valid, 422, 'The field is not a supported typed value. No free-text fact or secret is saved.');

        return $value;
    }

    public function preview(Tenant $tenant, User $user, string $area, string $ref, string $field): array
    {
        $this->actor($tenant, $user, $area);
        $source = $this->source($tenant, $user, $area, $ref);

        return ['scope' => ['tenant_id' => (string) $tenant->id, 'user_id' => (string) $user->id, 'area_id' => $area],
            'source_ref' => $ref, 'field' => $field, 'value' => $this->field($source, $field), 'source_title' => $source['title'],
            'source_url' => $source['url'], 'source_version' => $source['version'], 'source_sha256' => $this->stamp($source),
            'captured_at' => now()->toIso8601String(), 'provider_dispatch' => false,
            'notice' => 'This is a saved source observation, not an approved budget or an independent current fact. Current source and access are rechecked before use.'];
    }

    private function view(Tenant $tenant, User $user, TeamOperationRecord $row): array
    {
        $data = $row->data;
        $base = ['id' => $row->id, 'version' => $row->version, 'area_id' => $row->area_id, 'type' => $data['type'] ?? null,
            'expires_at' => $data['expires_at'] ?? null, 'captured_at' => $data['captured_at'] ?? null,
            'captured_by' => (string) $row->assigned_user_id, 'provenance' => 'Explicitly confirmed by this account; never automatically captured from chat.'];
        if ($row->status !== 'active' || now()->gte(CarbonImmutable::parse($data['expires_at']))) {
            return $base + ['state' => 'expired', 'notice' => 'This memory is unavailable after deletion or expiry.'];
        }
        if ($data['type'] === 'preference') {
            return $base + ['state' => 'available', 'key' => $data['key'], 'value' => $data['value'], 'notice' => 'A formatting preference, not a current source fact or permission.'];
        }
        try {
            $source = $this->source($tenant, $user, $row->area_id, $data['source_ref']);
            if (! hash_equals($data['source_sha256'], $this->stamp($source))) {
                return $base + ['state' => 'changed', 'source_ref' => $source['ref'], 'source_title' => $source['title'], 'source_url' => $source['url'],
                    'field' => $data['field'], 'notice' => 'The current source changed. Its saved observation is withheld; explicitly review a new observation.'];
            }

            return $base + ['state' => 'available', 'source_ref' => $source['ref'], 'source_title' => $source['title'], 'source_url' => $source['url'],
                'source_version' => $source['version'], 'field' => $data['field'], 'value' => $this->field($source, $data['field']),
                'notice' => 'Current source and access rechecked. A recorded estimate is not an approved budget.'];
        } catch (HttpExceptionInterface $error) {
            if (! in_array($error->getStatusCode(), [403, 404, 409], true)) {
                throw $error;
            }

            return $base + ['state' => 'unavailable', 'notice' => 'Source details are withheld under current access or source state. You can delete this private entry.'];
        }
    }

    public function listing(Tenant $tenant, User $user, string $area): array
    {
        $actor = $this->actor($tenant, $user, $area);
        $rows = TeamOperationRecord::query()->where(['tenant_id' => $tenant->id, 'area_id' => $area, 'kind' => self::KIND,
            'assigned_user_id' => $user->id, 'status' => 'active'])->orderBy('id')->limit(21)->get();
        $items = $rows->take(20)->map(fn ($row) => $this->view($tenant, $user, $row))->all();
        $fresh = $this->actor($tenant, $user, $area);
        abort_unless(hash_equals(AskEverbranch::digest($actor), AskEverbranch::digest($fresh)), 409);

        return ['items' => $items, 'has_more' => $rows->count() > 20, 'can_manage' => config('enterprise.writes_enabled') && WorkspaceAccess::permits($fresh, $area, true),
            'can_delete' => config('enterprise.writes_enabled') === true,
            'scope' => ['tenant_id' => (string) $tenant->id, 'user_id' => (string) $user->id, 'area_id' => $area],
            'preferences' => self::PREFERENCES, 'retention_days' => [1, 7, 30], 'provider_dispatch' => false,
            'notice' => 'Private account and department memory. Expiry denies access immediately; approved bounded retention cleanup scrubs content. Saved observations never grant additional access.'];
    }

    /** Only an explicit opt-in may use available memories, and observations must already occur in the selected authorized snapshot. */
    public function context(Tenant $tenant, User $user, string $area, array $sources): array
    {
        $actor = $this->actor($tenant, $user, $area);
        $refs = array_column($sources, 'ref');
        $rows = TeamOperationRecord::query()->where(['tenant_id' => $tenant->id, 'area_id' => $area, 'kind' => self::KIND,
            'assigned_user_id' => $user->id, 'status' => 'active'])->orderBy('id')->limit(20)->get();
        $items = [];
        foreach ($rows as $row) {
            // Do not perform a canonical read for an observation outside this selected check's sources.
            if ($row->data['type'] === 'source_observation' && ! in_array($row->data['source_ref'], $refs, true)) {
                continue;
            }
            $item = $this->view($tenant, $user, $row);
            if ($item['state'] !== 'available') {
                continue;
            }
            $items[] = array_intersect_key($item, array_flip(['id', 'version', 'type', 'key', 'value', 'source_ref', 'source_version', 'field', 'captured_at', 'expires_at']));
            if (count($items) === 8) {
                break;
            }
        }
        $fresh = $this->actor($tenant, $user, $area);
        abort_unless(hash_equals(AskEverbranch::digest($actor), AskEverbranch::digest($fresh)), 409);

        return $items;
    }

    public function save(Tenant $tenant, User $user, array $input): array
    {
        return $this->store->locked($tenant, function () use ($tenant, $user, $input): array {
            $this->actor($tenant, $user, $input['area_id'], true);
            $hash = hash('sha256', json_encode(['save', $input], JSON_THROW_ON_ERROR));
            $receipt = TeamOperationAttempt::query()->where(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'request_id' => $input['request_id']])->first();
            if ($receipt) {
                abort_unless($receipt->kind === 'ent.private_memory' && hash_equals($receipt->input_hash, $hash), 409);
                $row = $this->row($tenant, $user, $input['area_id'], $input['id'], true);
                abort_unless($row->status === 'active' && $row->version === ($receipt->result['version'] ?? null), 409);

                return $this->view($tenant, $user, $row) + ['replayed' => true];
            }
            $data = ['type' => $input['type'], 'captured_at' => now()->toIso8601String(), 'expires_at' => now()->addDays($input['retention_days'])->toIso8601String()];
            if ($input['type'] === 'preference') {
                abort_unless(in_array($input['value'], self::PREFERENCES[$input['key']] ?? [], true), 422);
                $data += ['key' => $input['key'], 'value' => $input['value']];
            } else {
                $source = $this->source($tenant, $user, $input['area_id'], $input['source_ref']);
                abort_unless(hash_equals($input['source_sha256'], $this->stamp($source)), 409, 'The source changed since preview. Review it again before saving.');
                $data += ['source_ref' => $input['source_ref'], 'field' => $input['field'], 'value' => $this->field($source, $input['field']),
                    'source_sha256' => $this->stamp($source), 'source_version' => $source['version']];
            }
            if (TeamOperationRecord::query()->whereKey($input['id'])->exists()) {
                $row = $this->row($tenant, $user, $input['area_id'], $input['id'], true);
                abort_unless($row->status === 'active' && $row->version === $input['expected_version'], 409);
            } else {
                abort_unless($input['expected_version'] === 0, 409);
                abort_if(TeamOperationRecord::query()->where(['tenant_id' => $tenant->id, 'kind' => self::KIND,
                    'assigned_user_id' => $user->id, 'status' => 'active'])->count() >= 20, 422, 'Delete an existing private memory first.');
                $row = new TeamOperationRecord(['id' => $input['id'], 'tenant_id' => $tenant->id, 'area_id' => $input['area_id'],
                    'kind' => self::KIND, 'assigned_user_id' => $user->id, 'visibility' => 'private', 'title' => 'Private assistant memory',
                    'status' => 'active', 'version' => 0, 'source_key' => hash('sha256', 'private-memory:'.$user->id.':'.$input['id'])]);
            }
            $row->data = $data;
            $row->ends_on = CarbonImmutable::parse($data['expires_at'])->toDateString();
            $row->version++;
            $row->save();
            $this->store->audit($row, $user->id, 'assistant_memory.saved', ['typed_input_hash' => $hash]);
            $this->receipt($tenant, $user, $row, $input, $hash);

            return $this->view($tenant, $user, $row) + ['replayed' => false];
        });
    }

    public function delete(Tenant $tenant, User $user, array $input): array
    {
        return $this->store->locked($tenant, function () use ($tenant, $user, $input): array {
            // Erasing an owned entry needs current department read access, not the old source's financial permission.
            app(\App\Enterprise\Provisioning\ClientSilo::class)->assertReady();
            $actor = $this->access->actor($tenant, $user, true);
            $this->access->area($tenant, $actor, $input['area_id']);
            $row = $this->row($tenant, $user, $input['area_id'], $input['id'], true);
            $hash = hash('sha256', json_encode(['delete', $input], JSON_THROW_ON_ERROR));
            $receipt = TeamOperationAttempt::query()->where(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'request_id' => $input['request_id']])->first();
            if ($receipt) {
                abort_unless($receipt->kind === 'ent.private_memory' && hash_equals($receipt->input_hash, $hash), 409);

                return ['id' => $row->id, 'version' => $row->version, 'status' => $row->status, 'replayed' => true];
            }
            abort_unless($row->version === $input['expected_version'] && in_array($row->status, ['active', 'expired'], true), 409);
            $row->data = ['deleted_at' => now()->toIso8601String()];
            $row->status = 'deleted';
            $row->version++;
            $row->save();
            $this->store->audit($row, $user->id, 'assistant_memory.deleted_content_scrubbed');
            $this->receipt($tenant, $user, $row, $input, $hash);

            return ['id' => $row->id, 'version' => $row->version, 'status' => 'deleted', 'replayed' => false];
        });
    }

    private function receipt(Tenant $tenant, User $user, TeamOperationRecord $row, array $input, string $hash): void
    {
        TeamOperationAttempt::query()->create(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'area_id' => $row->area_id,
            'record_id' => $row->id, 'user_id' => $user->id, 'kind' => 'ent.private_memory', 'status' => 'accepted',
            'request_id' => $input['request_id'], 'input_hash' => $hash, 'result' => ['id' => $row->id, 'version' => $row->version, 'status' => $row->status]]);
    }
}

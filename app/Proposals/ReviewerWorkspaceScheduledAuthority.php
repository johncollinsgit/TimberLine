<?php

namespace App\Proposals;

use App\Enterprise\Provisioning\ClientManifest;
use App\Enterprise\Provisioning\ClientSilo;
use App\Models\TeamArea;
use App\Models\TeamOperationRecord;
use App\Models\Tenant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PDO;
use Throwable;

/** A configured finite operation, never a native User/Request or a general service identity. */
final class ReviewerWorkspaceScheduledAuthority
{
    private array $versions = [];

    private function __construct(private readonly array $pins, private readonly string $digest, private readonly array $context,
        private readonly PDO $pdo, private readonly int $connection, private readonly float $deadline) {}

    private function __clone() {}

    public function __serialize(): array
    {
        throw new \LogicException('Scheduled authority is process local.');
    }

    public function __unserialize(array $data): void
    {
        throw new \LogicException('Scheduled authority cannot be restored.');
    }

    public static function parse(mixed $bytes): ?array
    {
        try {
            abort_unless(is_string($bytes) && strlen($bytes) <= 12288, 422);
            abort_unless(json_decode($bytes, false, 12, JSON_THROW_ON_ERROR) instanceof \stdClass, 422);
            $p = json_decode($bytes, true, 12, JSON_THROW_ON_ERROR);
            ReviewerWorkspaceSchema::exact($p, ['schema_version', 'purpose', 'runtime_root_sha256', 'process_uid', 'process_gid',
                'client_id', 'silo_id', 'storage_id', 'tenant_id', 'anchor_area_id', 'anchor_marker_sha256', 'context_hash', 'database', 'server_uuid',
                'build_id', 'source_hash', 'composer_sha256', 'authorizing_owner_id', 'valid_from', 'expires_at', 'limit', 'max_run_seconds',
                'kinds', 'editions', 'previous_policy_sha256']);
            abort_unless($p['schema_version'] === 1 && $p['purpose'] === 'proposal_workspace_cleanup', 422);
            foreach (['runtime_root_sha256', 'anchor_marker_sha256', 'context_hash', 'source_hash', 'composer_sha256'] as $key) {
                abort_unless(ReviewerWorkspaceSchema::hash($p[$key]), 422);
            }
            foreach (['client_id', 'silo_id', 'storage_id', 'anchor_area_id', 'server_uuid'] as $key) {
                abort_unless(ReviewerWorkspaceSchema::uuid($p[$key]), 422);
            }
            foreach (['tenant_id', 'authorizing_owner_id'] as $key) {
                abort_unless(is_int($p[$key]) && $p[$key] > 0, 422);
            }
            foreach (['process_uid', 'process_gid'] as $key) {
                abort_unless(is_int($p[$key]) && $p[$key] >= 0, 422);
            }
            abort_unless(is_string($p['database']) && preg_match('/^[A-Za-z0-9_]{1,64}$/D', $p['database'])
                && ReviewerWorkspaceSchema::text($p['build_id'], 120) && ReviewerWorkspaceSchema::utc($p['valid_from'])
                && ReviewerWorkspaceSchema::utc($p['expires_at']) && strtotime($p['valid_from']) < strtotime($p['expires_at'])
                && is_int($p['limit']) && $p['limit'] >= 1 && $p['limit'] <= 25
                && is_int($p['max_run_seconds']) && $p['max_run_seconds'] >= 1 && $p['max_run_seconds'] <= 60
                && is_array($p['previous_policy_sha256']), 422);
            ReviewerWorkspaceSchema::exact($p['previous_policy_sha256'], ['graph', 'comments']);
            foreach (['graph', 'comments'] as $kind) {
                abort_unless($p['previous_policy_sha256'][$kind] === null || ReviewerWorkspaceSchema::hash($p['previous_policy_sha256'][$kind]), 422);
            }
            abort_unless(is_array($p['kinds']) && array_is_list($p['kinds']) && count($p['kinds']) >= 1 && count($p['kinds']) <= 2
                && ReviewerWorkspaceSchema::uniqueStrings($p['kinds']) && array_diff($p['kinds'], ['graph', 'comments']) === [], 422);
            $sorted = $p['kinds'];
            sort($sorted, SORT_STRING);
            abort_unless($sorted === $p['kinds'] && is_array($p['editions']) && array_is_list($p['editions'])
                && count($p['editions']) >= 1 && count($p['editions']) <= 10, 422);
            $ids = $workspaces = [];
            foreach ($p['editions'] as $row) {
                abort_unless(is_array($row), 422);
                ReviewerWorkspaceSchema::exact($row, ['version_id', 'version_hash', 'workspace_id']);
                abort_unless(ReviewerWorkspaceSchema::uuid($row['version_id']) && ReviewerWorkspaceSchema::uuid($row['workspace_id'])
                    && ReviewerWorkspaceSchema::hash($row['version_hash']) && ! in_array($row['version_id'], $ids, true)
                    && ! in_array($row['workspace_id'], $workspaces, true), 422);
                $ids[] = $row['version_id'];
                $workspaces[] = $row['workspace_id'];
            }
            $sorted = $ids;
            sort($sorted, SORT_STRING);
            abort_unless($sorted === $ids, 422);

            return $p;
        } catch (Throwable) {
            return null;
        }
    }

    /** Missing approval refuses before this method's DB/content work. Artisan bootstrap has already occurred. */
    public static function admit(): self
    {
        abort_unless(config('service_proposals.reviewer_workspace.scheduled_cleanup.enabled') === true, 404);
        $digest = config('service_proposals.reviewer_workspace.scheduled_cleanup.pins_sha256');
        abort_unless(ReviewerWorkspaceSchema::hash($digest) && config('enterprise_runtime.isolated') === true
            && config('enterprise_runtime.base_path') === '/enterprise-owner' && function_exists('posix_geteuid'), 503);
        $root = realpath((string) getenv('ENTERPRISE_RUNTIME_ROOT'));
        abort_unless($root !== false && $root === app()->environmentPath() && ! is_link((string) getenv('ENTERPRISE_RUNTIME_ROOT'))
            && (fileperms($root) & 0777) === 0700 && fileowner($root) === posix_geteuid(), 503);
        $path = $root.'/reviewer-workspace-cleanup.json';
        $before = @lstat($path);
        abort_unless(is_array($before) && ($before['mode'] & 0170000) === 0100000 && ($before['mode'] & 0777) === 0600
            && $before['nlink'] === 1 && $before['uid'] === posix_geteuid() && $before['gid'] === posix_getegid()
            && $before['size'] <= 12288, 503);
        $handle = @fopen($path, 'rb');
        abort_unless(is_resource($handle), 503);
        try {
            $opened = fstat($handle);
            abort_unless(is_array($opened) && self::sameFile($opened, $before), 503);
            $bytes = stream_get_contents($handle, 12289);
            clearstatcache(true, $path);
            abort_unless(self::sameFile(@lstat($path), $before) && self::sameFile(fstat($handle), $opened) && is_string($bytes)
                && strlen($bytes) <= 12288 && hash_equals($digest, hash('sha256', $bytes)), 503);
        } finally {
            fclose($handle);
        }
        $p = self::parse($bytes);
        abort_unless($p !== null && hash_equals($p['runtime_root_sha256'], hash('sha256', $root))
            && $p['process_uid'] === posix_geteuid() && $p['process_gid'] === posix_getegid(), 503);
        self::source($p);
        self::valid($p);
        $db = DB::connection();
        abort_unless($db === DB::connection('enterprise') && $db->getDriverName() === 'mysql' && $db->transactionLevel() === 0, 503);
        $pdo = $db->getPdo();
        abort_unless(in_array(get_class($pdo), ['PDO', 'Pdo\\Mysql'], true) && (new \ReflectionClass($pdo))->isInternal()
            && $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql', 503);
        $actual = $db->selectOne('SELECT DATABASE() AS db, @@server_uuid AS uuid, VERSION() AS version, CONNECTION_ID() AS id, @@transaction_isolation AS isolation');
        abort_unless($actual->db === $p['database'] && strtolower($actual->uuid) === $p['server_uuid'] && str_starts_with($actual->version, '8.4.')
            && $actual->isolation === 'REPEATABLE-READ', 503);
        $context = app(ReviewerWorkspaceStore::class)->context(false);
        self::contextMatches($p, $context);

        return new self($p, $digest, $context, $pdo, (int) $actual->id, microtime(true) + $p['max_run_seconds']);
    }

    private static function sameFile(mixed $actual, array $expected): bool
    {
        if (! is_array($actual)) {
            return false;
        }
        foreach (['dev', 'ino', 'mode', 'nlink', 'uid', 'gid', 'size', 'mtime', 'ctime'] as $key) {
            if ($actual[$key] !== $expected[$key]) {
                return false;
            }
        }

        return true;
    }

    private static function source(array $p): void
    {
        abort_unless(config('service_proposals.runtime_purpose') === 'proposal_owner'
            && config('service_proposals.campus_review.build_id') === $p['build_id']
            && hash_equals($p['source_hash'], app(CampusReviewManifest::class)->sourceFingerprint())
            && hash_equals($p['composer_sha256'], hash_file('sha256', base_path('composer.lock'))), 503);
    }

    private static function contextMatches(array $p, array $context): void
    {
        abort_unless((int) $context['tenant']->id === $p['tenant_id'] && $context['area_id'] === $p['anchor_area_id']
            && hash_equals($context['context_hash'], $p['context_hash'])
            && config('enterprise_client.client_id') === $p['client_id'] && config('enterprise_client.silo_id') === $p['silo_id']
            && config('enterprise_client.storage_id') === $p['storage_id'] && config('enterprise_client.database') === $p['database']
            && config('service_proposals.reviewer_workspace.anchor_marker_sha256') === $p['anchor_marker_sha256'], 503);
    }

    private static function valid(array $p): void
    {
        abort_unless(now()->gte(CarbonImmutable::parse($p['valid_from'])) && now()->lt(CarbonImmutable::parse($p['expires_at'])), 410);
    }

    public function pins(): array
    {
        return $this->pins;
    }

    public function digest(): string
    {
        return $this->digest;
    }

    public function context(): array
    {
        return $this->context;
    }

    public function lockName(): string
    {
        return hash('sha256', 'proposal-workspace-cleanup|'.$this->pins['database'].'|'.$this->pins['context_hash']);
    }

    public function connectionId(): int
    {
        return $this->connection;
    }

    public function held(bool $transaction = false): void
    {
        self::valid($this->pins);
        abort_unless(microtime(true) < $this->deadline && DB::connection()->getPdo() === $this->pdo
            && (! $transaction || (DB::transactionLevel() > 0 && $this->pdo->inTransaction())), 503);
        $lock = DB::selectOne('SELECT CONNECTION_ID() AS id, IS_USED_LOCK(?) AS owner, @@transaction_isolation AS isolation', [$this->lockName()]);
        abort_unless((int) $lock->id === $this->connection && (int) $lock->owner === $this->connection && $lock->isolation === 'REPEATABLE-READ', 503);
    }

    /** Called under tenant serialization before selection and again immediately before commit. */
    public function assertPage(): void
    {
        $this->held(true);
        self::source($this->pins);
        $user = User::whereKey($this->pins['authorizing_owner_id'])->lockForUpdate()->first();
        $member = DB::table('tenant_user')->where('tenant_id', $this->pins['tenant_id'])->where('user_id', $this->pins['authorizing_owner_id'])->lockForUpdate()->first();
        abort_unless($user !== null && $user->is_active === true && $user->email_verified_at !== null && $member !== null
            && in_array($member->role, ['owner', 'admin'], true) && in_array($member->membership_active, [true, 1, '1'], true), 403);
        $this->currentContext();
        $rows = Version::where('tenant_id', $this->pins['tenant_id'])->whereIn('id', array_column($this->pins['editions'], 'version_id'))
            ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $versions = [];
        foreach ($this->pins['editions'] as $pair) {
            $v = $rows->get($pair['version_id']);
            abort_unless($v !== null && hash_equals($v->content_hash, $pair['version_hash'])
                && hash_equals($v->content_hash, app(ProposalService::class)->hash($v->payload))
                && ($v->payload['audience'] ?? null) === 'reviewer' && ($v->payload['client']['reference'] ?? null) === CampusReviewManifest::CLIENT_REFERENCE, 503);
            $descriptor = app(ReviewerWorkspaceSchema::class)->descriptor($v->payload['review_workspace'] ?? []);
            abort_unless($descriptor['workspace_id'] === $pair['workspace_id'] && is_array($v->payload['campus_review'] ?? null)
                && ReviewerWorkspaceSchema::hash($v->payload['campus_review']['source_hash'] ?? null)
                && ReviewerWorkspaceSchema::text($v->payload['campus_review']['build_id'] ?? null, 120), 503);
            $versions[$pair['workspace_id']] = $v;
        }
        $this->versions = $versions;
        $this->held(true);
    }

    /** Current locking reads; plain Store::context observations cannot stand in for this fence. */
    private function currentContext(): void
    {
        $silos = DB::table('enterprise_client_silos')->orderBy('id')->limit(2)->lockForUpdate()->get();
        $tenants = Tenant::query()->orderBy('id')->limit(2)->lockForUpdate()->get();
        abort_unless($silos->count() === 1 && $tenants->count() === 1, 503);
        $silo = $silos->first();
        $tenant = $tenants->first();
        $service = app(ClientSilo::class);
        $binding = hash('sha256', ClientManifest::canonical($service->binding()));
        $marker = $service->marker();
        abort_unless((int) $silo->id === 1 && (int) $tenant->id === $this->pins['tenant_id']
            && (int) $silo->tenant_id === (int) $tenant->id && is_array($marker)
            && ($marker['binding_hash'] ?? null) === $binding && $silo->binding_hash === $binding
            && ($marker['plan_hash'] ?? null) === $silo->plan_hash && ($marker['manifest_hash'] ?? null) === $silo->manifest_hash
            && $silo->client_id === config('enterprise_client.client_id') && $silo->silo_id === config('enterprise_client.silo_id')
            && $silo->environment === config('enterprise_client.environment')
            && $silo->client_id === config('service_proposals.owner_client_id') && $silo->silo_id === config('service_proposals.owner_silo_id')
            && config('service_proposals.owner_workspaces') === [(int) $tenant->id => 'enterprise'], 503);
        $area = TeamArea::query()->where(['tenant_id' => $tenant->id, 'id' => $this->pins['anchor_area_id']])->lockForUpdate()->first();
        // Supported RR current absence read; physical span/query plan remains MySQL acceptance work.
        $members = DB::table('team_area_members')->where('area_id', $this->pins['anchor_area_id'])->limit(1)->lockForUpdate()->get();
        abort_unless($area !== null && $area->profile === 'enterprise_shared' && $members->isEmpty()
            && ($area->settings['synthetic_fixture'] ?? null) === true && ($area->settings['proposal_review_workspace_anchor'] ?? null) === true
            && ReviewerWorkspaceSchema::digest($area->settings) === $this->pins['anchor_marker_sha256'], 503);
        self::contextMatches($this->pins, ['tenant' => $tenant, 'area_id' => $area->id,
            'context_hash' => ReviewerWorkspaceSchema::digest(['client_id' => $silo->client_id, 'silo_id' => $silo->silo_id,
                'storage_id' => config('enterprise_client.storage_id'), 'tenant_id' => (int) $tenant->id,
                'area_id' => $area->id, 'marker' => ReviewerWorkspaceSchema::digest($area->settings)])]);
    }

    public function version(TeamOperationRecord $row, string $kind): Version
    {
        $this->held(true);
        $data = $row->data;
        $id = $kind === 'graph' ? $row->id : ($data['workspace_id'] ?? null);
        abort_unless(is_string($id) && isset($this->versions[$id]), 503);
        $v = $this->versions[$id];
        $expected = ['context_hash' => $this->pins['context_hash'], 'version_id' => $v->id, 'version_hash' => $v->content_hash,
            'build_id' => $v->payload['campus_review']['build_id'], 'source_hash' => $v->payload['campus_review']['source_hash'], 'descriptor' => $v->payload['review_workspace']];
        if ($kind === 'comments') {
            abort_unless($row->title === 'Private workspace discussion:'.$id && is_array($v->payload['review_workspace_collaboration'] ?? null), 503);
            $expected['collaboration'] = $v->payload['review_workspace_collaboration'];
        }
        abort_unless(is_array($data['binding'] ?? null)
            && ReviewerWorkspaceSchema::digest($data['binding']) === ReviewerWorkspaceSchema::digest($expected), 503);

        return $v;
    }

    public function audit(TeamOperationRecord $row, string $action, string $run): void
    {
        $this->held(true);
        $kind = match ($action) {
            'workspace.content_erased' => 'graph',
            'workspace.comments_erased' => 'comments',
            default => null,
        };
        abort_unless($kind !== null && ReviewerWorkspaceSchema::uuid($run) && (int) $row->tenant_id === $this->pins['tenant_id']
            && $row->area_id === $this->pins['anchor_area_id'] && $row->status === 'expired'
            && $row->kind === ($kind === 'graph' ? ReviewerWorkspaceStore::KIND : ReviewerWorkspaceCollaborationStore::THREAD), 503);
        $this->version($row, $kind);
        DB::table('team_operation_revisions')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $row->tenant_id, 'record_id' => $row->id,
            'version' => $row->version, 'user_id' => $this->pins['authorizing_owner_id'], 'created_at' => now(),
            'payload' => Crypt::encryptString(json_encode(['actor_type' => 'approved_schedule', 'authorizing_owner_id' => $this->pins['authorizing_owner_id'],
                'policy_sha256' => $this->digest, 'run_id' => $run, 'action' => $action], JSON_THROW_ON_ERROR))]);
    }
}

<?php

namespace App\Proposals;

use App\Enterprise\MountUrls;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Real-owner initialization only. Never publishes, captures browser work, provisions an anchor or admits AI. */
final class ReviewerWorkspaceInitialization
{
    private const POLICY = ['schema_version', 'data_basis', 'version_id', 'version_hash', 'build_id', 'source_hash',
        'workspace_id', 'descriptor_sha256', 'seed_sha256', 'import_evidence_sha256',
        'predecessor_version_id', 'predecessor_version_hash', 'expires_at'];

    private const INPUT = ['version_id', 'request_id', 'approval_sha256', 'import_evidence_sha256', 'seed'];

    public function __construct(private Access $owners, private ReviewerWorkspaceStore $store,
        private ReviewerWorkspaceAccess $access, private ReviewerWorkspaceSchema $schema,
        private ReviewerWorkspaceMaintenanceAuthority $authority, private ReviewerWorkspace $workspace) {}

    /** Runtime configuration is restrictive reviewed metadata, never an account or identity grant. */
    public static function parseApprovals(mixed $text): ?array
    {
        if ($text === '') {
            return [];
        }
        try {
            abort_unless(is_string($text) && strlen($text) <= 24000, 422);
            $object = json_decode($text, false, 12, JSON_THROW_ON_ERROR);
            abort_unless($object instanceof \stdClass && count(get_object_vars($object)) <= 10, 422);
            $rows = json_decode($text, true, 12, JSON_THROW_ON_ERROR);
            foreach ($rows as $digest => $row) {
                abort_unless(ReviewerWorkspaceSchema::hash($digest) && is_array($row), 422);
                self::policy($row);
                abort_unless(hash_equals($digest, ReviewerWorkspaceSchema::digest($row)), 422);
            }

            return $rows;
        } catch (Throwable) {
            return null;
        }
    }

    private static function policy(array $row): void
    {
        ReviewerWorkspaceSchema::exact($row, self::POLICY);
        abort_unless($row['schema_version'] === 1 && $row['data_basis'] === 'reviewed_current_edits'
            && ReviewerWorkspaceSchema::text($row['build_id'], 120) && ReviewerWorkspaceSchema::utc($row['expires_at']), 422);
        foreach (['version_id', 'workspace_id', 'predecessor_version_id'] as $key) {
            abort_unless(ReviewerWorkspaceSchema::uuid($row[$key]), 422);
        }
        foreach (['version_hash', 'source_hash', 'descriptor_sha256', 'seed_sha256', 'import_evidence_sha256', 'predecessor_version_hash'] as $key) {
            abort_unless(ReviewerWorkspaceSchema::hash($row[$key]), 422);
        }
        abort_unless($row['version_id'] !== $row['predecessor_version_id'], 422);
    }

    private function approval(array $input, bool $status): array
    {
        abort_unless(config('service_proposals.reviewer_workspace.owner_initialization.enabled') === true, 404);
        ReviewerWorkspaceSchema::exact($input, $status ? ['version_id', 'request_id', 'approval_sha256'] : self::INPUT);
        abort_unless(ReviewerWorkspaceSchema::uuid($input['version_id']) && ReviewerWorkspaceSchema::uuid($input['request_id'])
            && ReviewerWorkspaceSchema::hash($input['approval_sha256']), 422);
        $rows = config('service_proposals.reviewer_workspace.owner_initialization.approvals');
        abort_unless(is_array($rows) && count($rows) <= 10, 503);
        $row = $rows[$input['approval_sha256']] ?? null;
        abort_unless(is_array($row), 403);
        self::policy($row);
        abort_unless(hash_equals($input['approval_sha256'], ReviewerWorkspaceSchema::digest($row))
            && $row['version_id'] === $input['version_id'], 409);
        // Read-only status may reconcile an original receipt after preview/data-approval expiry.
        // Current owner, exact publication and descriptor expiry remain mandatory below.
        abort_if(! $status && now()->timestamp >= strtotime($row['expires_at']), 410);
        if (! $status) {
            abort_unless(is_array($input['seed']) && ReviewerWorkspaceSchema::hash($input['import_evidence_sha256'])
                && hash_equals($row['import_evidence_sha256'], $input['import_evidence_sha256']), 409);
        }

        return $row;
    }

    /** Must be called within the same DB transaction that retains fresh owner/publication locks. */
    private function current(Request $request, array $policy, bool $write = false): array
    {
        abort_unless(MountUrls::matches($request) && $request->hasSession() && $request->session()->getId() !== '', 403);
        $context = $this->store->context();
        $this->authority->assertOwner($request, $context);
        $owner = $this->owners->owner($request, $write);
        abort_unless((int) $owner->id === (int) $context['tenant']->id, 404);
        // The old edition is observed only, never rewritten/revoked or rebound.
        $predecessor = Version::query()->where('tenant_id', $owner->id)->lockForUpdate()->findOrFail($policy['predecessor_version_id']);
        $version = Version::query()->where('tenant_id', $owner->id)->lockForUpdate()->findOrFail($policy['version_id']);
        foreach ([[$predecessor, $policy['predecessor_version_hash']], [$version, $policy['version_hash']]] as [$actual, $hash]) {
            abort_unless(hash_equals($actual->content_hash, $hash)
                && hash_equals($hash, app(ProposalService::class)->hash($actual->payload)), 409);
            abort_if($actual->revoked_at !== null || ! $actual->expires_at->isFuture(), 410);
        }
        $scope = $this->access->publication($version);
        abort_unless($scope['manifest']['build_id'] === $policy['build_id'] && hash_equals($scope['manifest']['source_hash'], $policy['source_hash'])
            && $scope['descriptor']['workspace_id'] === $policy['workspace_id']
            && hash_equals(ReviewerWorkspaceSchema::digest($scope['descriptor']), $policy['descriptor_sha256'])
            && hash_equals($scope['descriptor']['seed_sha256'], $policy['seed_sha256'])
            && strtotime($policy['expires_at']) <= strtotime($scope['descriptor']['expires_at']), 409);
        $actor = (int) $request->user()->getKey();

        return compact('context', 'scope', 'actor');
    }

    private function graph(array $input, array $policy): array
    {
        $graph = $this->schema->graph($input['seed'], true);
        abort_unless(hash_equals($policy['seed_sha256'], ReviewerWorkspaceSchema::digest($graph)), 409);

        return $graph;
    }

    private function intent(array $input, array $policy): array
    {
        return ['request_id' => $input['request_id'], 'descriptor_sha256' => $policy['descriptor_sha256']];
    }

    /** This is exactly the native initializer's immutable receipt digest, not a replacement receipt kind. */
    private function digest(array $input, array $policy, array $current): string
    {
        return ReviewerWorkspaceSchema::digest(['intent' => $this->intent($input, $policy), 'descriptor' => $current['scope']['descriptor'],
            'seed' => $policy['seed_sha256'], 'owner_id' => $current['actor']]);
    }

    private function observed(array $input, array $policy, array $current): array
    {
        $row = $this->store->row($current['scope'], true);
        if ($row === null) {
            // A delayed apply may still win; absence is never a settled rejection.
            return ['status' => 'not_observed', 'settled' => false];
        }
        $digest = $this->digest($input, $policy, $current);
        abort_unless($row->data['initial_request_id'] === $input['request_id'] && hash_equals($row->data['initial_digest'], $digest), 409);

        return ['status' => 'initialized', 'settled' => true, 'receipt' => ['request_id' => $input['request_id'], 'digest' => $digest,
            'version_id' => $policy['version_id'], 'workspace_id' => $row->id]];
    }

    private function mac(array $claims): string
    {
        return hash_hmac('sha256', 'owner-workspace-initialization-preview-v1|'.ReviewerWorkspaceSchema::canonical($claims), app('encrypter')->getKey());
    }

    private function claims(Request $request, array $input, array $policy, array $current, array $graph, int $issued, int $expires, string $observed): array
    {
        $counts = [];
        foreach (['areas', 'buildings', 'people', 'projects', 'tasks', 'tickets', 'contractors', 'plans', 'costs'] as $kind) {
            $counts[$kind] = count($graph[$kind]);
        }

        return ['schema_version' => 1, 'actor_id' => $current['actor'], 'session_sha256' => hash('sha256', $request->session()->getId()),
            'context_hash' => $current['context']['context_hash'], 'version_id' => $policy['version_id'], 'version_hash' => $policy['version_hash'],
            'workspace_id' => $policy['workspace_id'], 'descriptor_sha256' => $policy['descriptor_sha256'], 'seed_sha256' => $policy['seed_sha256'],
            'import_evidence_sha256' => $policy['import_evidence_sha256'], 'approval_sha256' => $input['approval_sha256'],
            'request_id' => $input['request_id'], 'issued_at' => $issued, 'expires_at' => $expires, 'observed' => $observed, 'counts' => $counts];
    }

    public function preview(Request $request, array $input): array
    {
        $policy = $this->approval($input, false);
        $graph = $this->graph($input, $policy);

        return DB::transaction(function () use ($request, $input, $policy, $graph): array {
            $current = $this->current($request, $policy);
            $result = $this->observed($input, $policy, $current);
            $issued = now()->timestamp;
            $expires = min($issued + 120, strtotime($policy['expires_at']), strtotime($current['scope']['descriptor']['expires_at']));
            abort_unless($expires > $issued, 410);
            $claims = $this->claims($request, $input, $policy, $current, $graph, $issued, $expires, $result['status']);

            return ['status' => 'preview', 'mutated' => false, 'observation' => $result,
                'preview' => ['claims' => $claims, 'signature' => $this->mac($claims)]];
        });
    }

    public function apply(Request $request, array $input): array
    {
        abort_unless(config('service_proposals.reviewer_workspace.owner_initialization.enabled') === true, 404);
        ReviewerWorkspaceSchema::exact($input, [...self::INPUT, 'preview']);
        $preview = $input['preview'];
        unset($input['preview']);
        $policy = $this->approval($input, false);
        $graph = $this->graph($input, $policy);
        abort_unless(is_array($preview), 422);
        ReviewerWorkspaceSchema::exact($preview, ['claims', 'signature']);
        abort_unless(is_array($preview['claims']) && ReviewerWorkspaceSchema::hash($preview['signature']), 422);
        $claims = $preview['claims'];
        abort_unless(hash_equals($this->mac($claims), $preview['signature']), 409);
        abort_unless(is_int($claims['issued_at'] ?? null) && is_int($claims['expires_at'] ?? null)
            && $claims['issued_at'] <= now()->timestamp && $claims['expires_at'] > now()->timestamp
            && $claims['expires_at'] <= $claims['issued_at'] + 120
            && in_array($claims['observed'] ?? null, ['not_observed', 'initialized'], true), 410);

        return $this->store->locked(function () use ($request, $input, $policy, $graph, $claims): array {
            $current = $this->current($request, $policy, true);
            $expected = $this->claims($request, $input, $policy, $current, $graph, $claims['issued_at'], $claims['expires_at'], $claims['observed']);
            abort_unless(hash_equals(ReviewerWorkspaceSchema::digest($expected), ReviewerWorkspaceSchema::digest($claims)), 409);
            // Recheck expiry and fresh real-owner authority immediately before the nested native initializer.
            $this->approval($input, false);
            abort_unless(now()->timestamp < $claims['expires_at'], 410);
            $this->authority->assertOwner($request, $current['context']);
            $result = $this->workspace->initialize($request, $policy['version_id'], $graph, $this->intent($input, $policy));
            $receipt = $this->observed($input, $policy, $current);
            abort_unless($receipt['settled'] === true, 503);
            // All effects remain inside this transaction if authority or the original consent expires.
            $this->approval($input, false);
            abort_unless(now()->timestamp < $claims['expires_at'], 410);
            $final = $this->current($request, $policy, true);
            abort_unless($current['actor'] === $final['actor']
                && hash_equals($current['context']['context_hash'], $final['context']['context_hash']), 409);

            return $receipt + ['replayed' => $result['replayed']];
        });
    }

    public function status(Request $request, array $input): array
    {
        $policy = $this->approval($input, true);

        return DB::transaction(fn () => $this->observed($input, $policy, $this->current($request, $policy)));
    }
}

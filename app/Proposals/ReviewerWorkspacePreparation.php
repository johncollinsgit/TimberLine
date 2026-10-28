<?php

namespace App\Proposals;

use App\Enterprise\MountUrls;
use App\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Private draft preparation only. No publication, capture, provisioning or recipient grants. */
final class ReviewerWorkspacePreparation
{
    public const MARKER = 'review_workspace_preparation';

    private const POLICY = ['schema_version', 'data_basis', 'source_draft_id', 'source_draft_revision', 'source_draft_sha256',
        'prepared_draft_id', 'predecessor_version_id', 'predecessor_version_hash', 'seed_sha256', 'import_evidence_sha256',
        'workspace_descriptor', 'collaboration_descriptor', 'build_id', 'source_hash', 'expires_at'];

    public function __construct(private Access $owners, private ReviewerWorkspaceStore $store,
        private ReviewerWorkspaceMaintenanceAuthority $authority, private CampusReviewManifest $manifest, private ProposalService $proposals) {}

    public static function parseApprovals(mixed $text): ?array
    {
        if ($text === '') {
            return [];
        }
        try {
            abort_unless(is_string($text) && strlen($text) <= 24000, 422);
            $object = json_decode($text, false, 16, JSON_THROW_ON_ERROR);
            abort_unless($object instanceof \stdClass && count(get_object_vars($object)) <= 10, 422);
            $rows = json_decode($text, true, 16, JSON_THROW_ON_ERROR);
            $reserved = [];
            foreach ($rows as $hash => $row) {
                abort_unless(ReviewerWorkspaceSchema::hash($hash) && is_array($row), 422);
                self::policy($row);
                abort_unless(hash_equals($hash, ReviewerWorkspaceSchema::digest($row)) && ! isset($reserved[$row['prepared_draft_id']]), 422);
                $reserved[$row['prepared_draft_id']] = true;
            }

            return $rows;
        } catch (Throwable) {
            return null;
        }
    }

    private static function policy(array $row): void
    {
        ReviewerWorkspaceSchema::exact($row, self::POLICY);
        abort_unless($row['schema_version'] === 1 && in_array($row['data_basis'], ['reviewed_current_edits', 'reviewed_new_fictional_baseline'], true)
            && is_int($row['source_draft_revision']) && $row['source_draft_revision'] > 0
            && ReviewerWorkspaceSchema::text($row['build_id'], 120) && ReviewerWorkspaceSchema::utc($row['expires_at']), 422);
        foreach (['source_draft_id', 'prepared_draft_id', 'predecessor_version_id'] as $key) {
            abort_unless(ReviewerWorkspaceSchema::uuid($row[$key]), 422);
        }
        foreach (['source_draft_sha256', 'predecessor_version_hash', 'seed_sha256', 'import_evidence_sha256', 'source_hash'] as $key) {
            abort_unless(ReviewerWorkspaceSchema::hash($row[$key]), 422);
        }
        abort_unless($row['source_draft_id'] !== $row['prepared_draft_id'] && is_array($row['workspace_descriptor']), 422);
        (new ReviewerWorkspaceSchema)->descriptor($row['workspace_descriptor']);
        abort_unless(hash_equals($row['workspace_descriptor']['seed_sha256'], $row['seed_sha256'])
            && strtotime($row['expires_at']) <= strtotime($row['workspace_descriptor']['expires_at']), 422);
        if ($row['collaboration_descriptor'] !== null) {
            abort_unless(is_array($row['collaboration_descriptor']), 422);
            $c = $row['collaboration_descriptor'];
            ReviewerWorkspaceSchema::exact($c, ['schema_version', 'policy_sha256', 'expires_at']);
            abort_unless($c['schema_version'] === 1 && ReviewerWorkspaceSchema::hash($c['policy_sha256'])
                && ReviewerWorkspaceSchema::utc($c['expires_at'])
                && strtotime($c['expires_at']) <= strtotime($row['workspace_descriptor']['expires_at']), 422);
        }
    }

    private function approval(string $hash): array
    {
        abort_unless(ReviewerWorkspaceSchema::hash($hash), 422);
        $rows = config('service_proposals.reviewer_workspace.owner_preparation.approvals');
        abort_unless(is_array($rows) && count($rows) <= 10, 503);
        $row = $rows[$hash] ?? null;
        abort_unless(is_array($row), 403);
        self::policy($row);
        abort_unless(hash_equals($hash, ReviewerWorkspaceSchema::digest($row)), 409);

        return $row;
    }

    /** Both marker and reserved ID survive disabled gates: never silently save/publish as browser mode. */
    public static function isPrepared(Draft $draft): bool
    {
        if (array_key_exists(self::MARKER, $draft->payload)) {
            return true;
        }
        $rows = config('service_proposals.reviewer_workspace.owner_preparation.approvals');
        foreach (is_array($rows) ? $rows : [] as $row) {
            if (is_array($row) && ($row['prepared_draft_id'] ?? null) === $draft->id) {
                return true;
            }
        }

        return false;
    }

    private function enabled(): void
    {
        abort_unless(config('service_proposals.reviewer_workspace.owner_preparation.enabled') === true, 404);
    }

    private function current(Request $request, array $policy, bool $write = false): array
    {
        abort_unless(DB::transactionLevel() > 0 && MountUrls::matches($request)
            && $request->hasSession() && $request->session()->getId() !== '', 403);
        $context = $this->store->context(false);
        $this->authority->assertOwner($request, $context);
        $tenant = $this->owners->owner($request, $write);
        abort_unless((int) $tenant->id === (int) $context['tenant']->id, 404);
        $predecessor = Version::query()->where('tenant_id', $tenant->id)->lockForUpdate()->findOrFail($policy['predecessor_version_id']);
        abort_unless(hash_equals($predecessor->content_hash, $policy['predecessor_version_hash'])
            && hash_equals($predecessor->content_hash, $this->proposals->hash($predecessor->payload))
            && ($predecessor->payload['audience'] ?? null) === 'reviewer'
            && ($predecessor->payload['client']['reference'] ?? null) === CampusReviewManifest::CLIENT_REFERENCE, 409);
        abort_if($predecessor->revoked_at !== null || ! $predecessor->expires_at->isFuture(), 410);
        abort_if(($predecessor->payload['review_workspace']['workspace_id'] ?? null) === $policy['workspace_descriptor']['workspace_id'], 409);
        $manifest = $this->manifest->current();
        abort_unless($manifest['build_id'] === $policy['build_id'] && hash_equals($manifest['source_hash'], $policy['source_hash']), 409);
        $configuration = ReviewerWorkspaceRuntimeConfiguration::parse(json_encode(['schema_version' => 1,
            'anchor_area_id' => config('service_proposals.reviewer_workspace.anchor_area_id'),
            'anchor_marker_sha256' => config('service_proposals.reviewer_workspace.anchor_marker_sha256'),
            'policies' => config('service_proposals.reviewer_workspace.policies'),
            'collaboration_policies' => config('service_proposals.reviewer_workspace.collaboration.policies', [])], JSON_THROW_ON_ERROR));
        abort_unless($configuration !== null && isset($configuration['policies'][$policy['workspace_descriptor']['policy_sha256']]), 503);
        if ($policy['collaboration_descriptor'] !== null) {
            abort_unless(isset($configuration['collaboration_policies'][$policy['collaboration_descriptor']['policy_sha256']]), 503);
        }
        $source = Draft::query()->where('tenant_id', $tenant->id)->lockForUpdate()->find($policy['source_draft_id']);
        $actor = (int) $request->user()->getKey();
        $fresh = $source !== null && (int) $source->revision === $policy['source_draft_revision']
            && hash_equals($policy['source_draft_sha256'], $this->proposals->hash($source->payload));
        if ($source !== null) {
            abort_unless($source->client_id === ($predecessor->payload['client']['id'] ?? null), 409);
        }

        return compact('context', 'tenant', 'source', 'actor', 'fresh');
    }

    /** Pure time predicate only; validated immutable descriptors convey no identity or permission. */
    public static function descriptorsFuture(array $workspace, ?array $comments, \Carbon\CarbonInterface $at): bool
    {
        return $at->lt(\Carbon\CarbonImmutable::parse($workspace['expires_at']))
            && ($comments === null || $at->lt(\Carbon\CarbonImmutable::parse($comments['expires_at'])));
    }

    private function active(array $policy, array $current): void
    {
        $this->enabled();
        abort_if(now()->timestamp >= strtotime($policy['expires_at']), 410);
        abort_unless(self::descriptorsFuture($policy['workspace_descriptor'], $policy['collaboration_descriptor'], now()), 410);
        abort_unless($current['fresh'], 409);
    }

    private function basePayload(array $payload): array
    {
        foreach (array_keys($payload) as $key) {
            abort_if(str_starts_with((string) $key, 'review_workspace') || $key === 'campus_review', 409);
        }
        CommercialSnapshot::verify($payload);
        abort_unless(($payload['client']['reference'] ?? null) === CampusReviewManifest::CLIENT_REFERENCE, 409);

        return $payload;
    }

    private function marker(array $input, array $current): array
    {
        return ['schema_version' => 1, 'approval_sha256' => $input['approval_sha256'], 'request_id' => $input['request_id'],
            'actor_id' => $current['actor'], 'context_hash' => $current['context']['context_hash']];
    }

    private function preparedPayload(array $base, array $policy): array
    {
        $payload = [...$base, 'review_workspace' => $policy['workspace_descriptor']];
        if ($policy['collaboration_descriptor'] !== null) {
            $payload['review_workspace_collaboration'] = $policy['collaboration_descriptor'];
        }

        return $payload;
    }

    private function verified(Draft $draft, array $policy, array $marker, array $current): array
    {
        abort_unless($draft->id === $policy['prepared_draft_id'] && (int) $draft->tenant_id === (int) $current['tenant']->id
            && (int) $draft->created_by === $current['actor'] && (int) $draft->revision === 1, 409);
        $payload = $draft->payload;
        abort_unless(is_array($payload[self::MARKER] ?? null)
            && ReviewerWorkspaceSchema::digest($payload[self::MARKER]) === ReviewerWorkspaceSchema::digest($marker), 409);
        unset($payload[self::MARKER]);
        $base = $payload;
        unset($base['review_workspace'], $base['review_workspace_collaboration']);
        $this->basePayload($base);
        abort_unless(hash_equals($policy['source_draft_sha256'], $this->proposals->hash($base))
            && $draft->client_id === ($base['client']['id'] ?? null)
            && $payload === $this->preparedPayload($base, $policy), 409);

        return $payload;
    }

    private function receipt(Draft $draft, array $input, array $payload): array
    {
        return ['request_id' => $input['request_id'], 'approval_sha256' => $input['approval_sha256'],
            'draft_id' => $draft->id, 'payload_sha256' => $this->proposals->hash($payload), 'revision' => 1];
    }

    private function input(array $input, bool $status): array
    {
        ReviewerWorkspaceSchema::exact($input, $status ? ['approval_sha256', 'request_id'] : ['approval_sha256', 'request_id', 'import_evidence_sha256']);
        abort_unless(ReviewerWorkspaceSchema::uuid($input['request_id']) && ReviewerWorkspaceSchema::hash($input['approval_sha256']), 422);
        $policy = $this->approval($input['approval_sha256']);
        if (! $status) {
            abort_unless(is_string($input['import_evidence_sha256']) && hash_equals($policy['import_evidence_sha256'], $input['import_evidence_sha256']), 409);
        }

        return $policy;
    }

    private function mac(array $claims): string
    {
        return hash_hmac('sha256', 'reviewer-workspace-draft-preparation-v1|'.ReviewerWorkspaceSchema::canonical($claims), app('encrypter')->getKey());
    }

    private function claims(Request $request, array $input, array $policy, array $current, int $issued, int $expires): array
    {
        return ['schema_version' => 1, 'marker' => $this->marker($input, $current), 'session_sha256' => hash('sha256', $request->session()->getId()),
            'source_draft_sha256' => $policy['source_draft_sha256'], 'prepared_draft_id' => $policy['prepared_draft_id'],
            'workspace_descriptor' => $policy['workspace_descriptor'], 'collaboration_descriptor' => $policy['collaboration_descriptor'],
            'import_evidence_sha256' => $policy['import_evidence_sha256'], 'issued_at' => $issued, 'expires_at' => $expires];
    }

    public function preview(Request $request, array $input): array
    {
        $this->enabled();
        $policy = $this->input($input, false);

        return DB::transaction(function () use ($request, $input, $policy): array {
            $current = $this->current($request, $policy);
            $this->active($policy, $current);
            $this->basePayload($current['source']->payload);
            $issued = now()->timestamp;
            $claims = $this->claims($request, $input, $policy, $current, $issued, min($issued + 120, strtotime($policy['expires_at'])));

            return ['status' => 'preview', 'mutated' => false, 'preview' => ['claims' => $claims, 'signature' => $this->mac($claims)]];
        });
    }

    public function apply(Request $request, array $input): array
    {
        $this->enabled();
        ReviewerWorkspaceSchema::exact($input, ['approval_sha256', 'request_id', 'import_evidence_sha256', 'preview']);
        $preview = $input['preview'];
        unset($input['preview']);
        $policy = $this->input($input, false);
        abort_unless(is_array($preview), 422);
        ReviewerWorkspaceSchema::exact($preview, ['claims', 'signature']);
        abort_unless(is_array($preview['claims']) && ReviewerWorkspaceSchema::hash($preview['signature'])
            && hash_equals($this->mac($preview['claims']), $preview['signature']), 409);
        $claims = $preview['claims'];

        return DB::transaction(function () use ($request, $input, $policy, $claims): array {
            $current = $this->current($request, $policy, true);
            abort_unless(is_int($claims['issued_at'] ?? null) && is_int($claims['expires_at'] ?? null), 422);
            $expected = $this->claims($request, $input, $policy, $current, $claims['issued_at'], $claims['expires_at']);
            abort_unless(ReviewerWorkspaceSchema::digest($expected) === ReviewerWorkspaceSchema::digest($claims), 409);
            $draft = Draft::query()->where('tenant_id', $current['tenant']->id)->lockForUpdate()->find($policy['prepared_draft_id']);
            if ($draft !== null) {
                $payload = $this->verified($draft, $policy, $this->marker($input, $current), $current);

                return ['status' => 'prepared', 'settled' => true, 'replayed' => true, 'receipt' => $this->receipt($draft, $input, $payload)];
            }
            $this->active($policy, $current);
            abort_unless(is_int($claims['issued_at'] ?? null) && is_int($claims['expires_at'] ?? null)
                && $claims['issued_at'] <= now()->timestamp && $claims['expires_at'] > now()->timestamp
                && $claims['expires_at'] <= $claims['issued_at'] + 120, 410);
            $expected = $this->claims($request, $input, $policy, $current, $claims['issued_at'], $claims['expires_at']);
            abort_unless(ReviewerWorkspaceSchema::digest($expected) === ReviewerWorkspaceSchema::digest($claims), 409);
            $base = $this->basePayload($current['source']->payload);
            $payload = $this->preparedPayload($base, $policy);
            // Reserved UUID is globally unique. A foreign collision fails rather than overwrites.
            $draft = Draft::create(['id' => $policy['prepared_draft_id'], 'tenant_id' => $current['tenant']->id,
                'client_id' => $current['source']->client_id, 'created_by' => $current['actor'], 'revision' => 1,
                'payload' => [...$payload, self::MARKER => $this->marker($input, $current)]]);
            $final = $this->current($request, $policy, true);
            $this->active($policy, $final);
            abort_unless(now()->timestamp < $claims['expires_at'], 410);
            $this->verified($draft, $policy, $this->marker($input, $final), $final);

            return ['status' => 'prepared', 'settled' => true, 'replayed' => false, 'receipt' => $this->receipt($draft, $input, $payload)];
        });
    }

    public function status(Request $request, array $input): array
    {
        $policy = $this->input($input, true);

        return DB::transaction(function () use ($request, $input, $policy): array {
            $current = $this->current($request, $policy);
            $draft = Draft::query()->where('tenant_id', $current['tenant']->id)->lockForUpdate()->find($policy['prepared_draft_id']);
            if ($draft === null) {
                return ['status' => 'not_observed', 'settled' => false];
            }
            $payload = $this->verified($draft, $policy, $this->marker($input, $current), $current);

            return ['status' => 'prepared', 'settled' => true, 'receipt' => $this->receipt($draft, $input, $payload),
                'publishable' => config('service_proposals.reviewer_workspace.owner_preparation.enabled') === true
                    && $current['fresh'] && now()->timestamp < strtotime($policy['expires_at'])
                    && self::descriptorsFuture($policy['workspace_descriptor'], $policy['collaboration_descriptor'], now())];
        });
    }

    /** Called in ProposalService's transaction BEFORE the prepared Draft lock, preserving authority→Version→source→prepared lock order. */
    public function publicationContext(?Request $request, Tenant $tenant, int $actor, string $id): ?array
    {
        $probe = Draft::query()->where('tenant_id', $tenant->id)->findOrFail($id);
        if (! self::isPrepared($probe)) {
            return null;
        }
        abort_unless($request !== null && $request === app('request') && $request->isMethod('POST')
            && $request->route()?->getName() === 'service-proposals.owner.publish'
            && $request->user()?->getKey() === $actor && $request->hasSession(), 403);
        $csrf = $request->input('_token') ?? $request->header('X-CSRF-TOKEN');
        abort_unless(is_string($csrf) && $request->session()->token() !== '' && hash_equals($request->session()->token(), $csrf), 419);
        abort_unless($request->header('Origin') === $request->getSchemeAndHttpHost()
            && $request->header('Sec-Fetch-Site', 'same-origin') === 'same-origin', 403);
        $marker = $probe->payload[self::MARKER] ?? null;
        abort_unless(is_array($marker), 409);
        ReviewerWorkspaceSchema::exact($marker, ['schema_version', 'approval_sha256', 'request_id', 'actor_id', 'context_hash']);
        abort_unless($marker['schema_version'] === 1 && ReviewerWorkspaceSchema::uuid($marker['request_id']), 409);
        $policy = $this->approval($marker['approval_sha256']);
        $current = $this->current($request, $policy, true);
        abort_unless((int) $tenant->id === (int) $current['tenant']->id && $actor === $current['actor'], 403);
        $input = ['approval_sha256' => $marker['approval_sha256'], 'request_id' => $marker['request_id']];
        abort_unless($marker === $this->marker($input, $current), 409);

        return compact('request', 'policy', 'current', 'marker', 'input');
    }

    public function publicationPayload(Draft $draft, array $context, bool $replay): array
    {
        $current = $this->current($context['request'], $context['policy'], true);
        if (! $replay) {
            $this->active($context['policy'], $current);
        }

        return $this->verified($draft, $context['policy'], $context['marker'], $current);
    }
}

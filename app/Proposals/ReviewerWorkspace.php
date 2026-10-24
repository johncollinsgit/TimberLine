<?php

namespace App\Proposals;

use App\Models\TeamOperationRecord;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/** Shared server-held fictional work, never native employee work or a paid/provider authority. */
final class ReviewerWorkspace
{
    public function __construct(private ReviewerWorkspaceAccess $access, private ReviewerWorkspaceStore $store,
        private ReviewerWorkspaceSchema $schema, private ReviewerWorkspaceProjector $projector,
        private ReviewerWorkspaceCommands $commands, private Access $owners) {}

    /** Explicit owner-only library seam for a later reviewed publication hook. No recipient calls this. */
    public function initialize(Request $request, string $versionId, array $seed, array $intent): array
    {
        abort_unless(config('service_proposals.reviewer_workspace.initialization_enabled') === true, 403);
        ReviewerWorkspaceSchema::exact($intent, ['request_id', 'descriptor_sha256']);
        abort_unless(ReviewerWorkspaceSchema::uuid($versionId) && ReviewerWorkspaceSchema::uuid($intent['request_id']) && ReviewerWorkspaceSchema::hash($intent['descriptor_sha256']), 422);
        $graph = $this->schema->graph($seed, true);

        return $this->store->locked(function () use ($request, $versionId, $graph, $intent): array {
            $tenant = $this->owners->owner($request, true);
            $version = Version::query()->where('tenant_id', $tenant->id)->lockForUpdate()->findOrFail($versionId);
            $scope = $this->access->publication($version);
            abort_unless(hash_equals($intent['descriptor_sha256'], ReviewerWorkspaceSchema::digest($scope['descriptor']))
                && hash_equals($scope['descriptor']['seed_sha256'], ReviewerWorkspaceSchema::digest($graph)), 409);
            $areas = array_column($graph['areas'], 'id');
            sort($areas, SORT_STRING);
            abort_unless($areas === $scope['policy']['area_ids'], 422);
            $digest = ReviewerWorkspaceSchema::digest(['intent' => $intent, 'descriptor' => $scope['descriptor'], 'seed' => ReviewerWorkspaceSchema::digest($graph), 'owner_id' => (int) $request->user()->id]);
            $row = $this->store->row($scope, true);
            if ($row !== null) {
                abort_unless($row->data['initial_request_id'] === $intent['request_id'] && hash_equals($row->data['initial_digest'], $digest), 409, 'Existing shared state cannot be reset or reseeded.');

                return ['status' => 'initialized', 'workspace_id' => $row->id, 'revision' => $row->version, 'replayed' => true];
            }
            $row = $this->store->create($scope, $graph, $intent['request_id'], $digest);
            $this->store->audit($row, 'workspace.initialized', ['seed_sha256' => $scope['descriptor']['seed_sha256'], 'request_id' => $intent['request_id']], (int) $request->user()->id);

            return ['status' => 'initialized', 'workspace_id' => $row->id, 'revision' => $row->version, 'replayed' => false];
        });
    }

    /** No recipient route. A later reviewed scheduler must provide real current owner authority. */
    public function pruneExpired(Request $request, int $limit = 100, ?string $after = null, bool $dryRun = false): array
    {
        abort_unless($limit >= 1 && $limit <= 100 && ($after === null || ReviewerWorkspaceSchema::uuid($after)), 422);
        $owner = $this->owners->owner($request);
        $context = $this->store->context(false);
        abort_unless((int) $owner->id === (int) $context['tenant']->id, 404);

        return $this->store->locked(function () use ($request, $context, $limit, $after, $dryRun) {
            app(ReviewerWorkspaceMaintenanceAuthority::class)->assertOwner($request, $context);

            return $this->store->prune((int) $request->user()->id, $limit, $after, $dryRun);
        }, false);
    }

    /** Private collaboration adapter: existing publication/graph authority only; no new grant or native actor. */
    public function collaborationContext(Request $request, string $token, array $binding, array $selection, bool $lockPublication = false): array
    {
        $current = $this->current($request, $token, $binding, $selection, $lockPublication);

        return $current + ['visible' => $this->projector->graph($current['scope'], $current['selection'], $current['graph'])];
    }

    /** Final read-only authority fence for capability-owned adapters. */
    public function collaborationUnchanged(array $old, Request $request, string $token, array $binding): void
    {
        $this->unchanged($old, $request, $token, $binding);
    }

    private function current(Request $request, string $token, array $binding, array $selection, bool $lockPublication = false): array
    {
        $scope = $this->access->scope($request, $token, $binding, $lockPublication);
        $row = $this->store->row($scope);
        $graph = $this->schema->graph($row->data['graph']);
        $selection = $this->access->selection($scope, $graph, $selection);

        return compact('scope', 'row', 'graph', 'selection') + ['controls' => ReviewerWorkspaceSchema::digest([
            'financial' => config('service_proposals.reviewer_workspace.financial_enabled'), 'writes' => config('service_proposals.reviewer_workspace.writes_enabled')])];
    }

    private function unchanged(array $old, Request $request, string $token, array $binding): void
    {
        $current = $this->current($request, $token, $binding, $old['selection']);
        abort_unless($old['row']->id === $current['row']->id && $old['row']->version === $current['row']->version
            && $old['scope']['capability_hash'] === $current['scope']['capability_hash'] && $old['controls'] === $current['controls']
            && hash_equals(ReviewerWorkspaceSchema::digest($old['graph']), ReviewerWorkspaceSchema::digest($current['graph'])), 409, 'The shared fictional work changed. Refresh current records.');
    }

    private function pin(array $current): array
    {
        return ['workspace_id' => $current['row']->id, 'revision' => $current['row']->version,
            'workspace_digest' => ReviewerWorkspaceSchema::digest($current['graph']), 'selection_digest' => ReviewerWorkspaceSchema::digest($current['selection']),
            'policy_hash' => $current['scope']['descriptor']['policy_sha256'], 'capability_hash' => $current['scope']['capability_hash'],
            'version_hash' => $current['scope']['version']->content_hash, 'build_id' => $current['scope']['manifest']['build_id'], 'source_hash' => $current['scope']['manifest']['source_hash']];
    }

    private function validatePin(array $pin): void
    {
        ReviewerWorkspaceSchema::exact($pin, ['workspace_id', 'revision', 'workspace_digest', 'selection_digest', 'snapshot_digest', 'policy_hash', 'capability_hash', 'version_hash', 'build_id', 'source_hash']);
        abort_unless(ReviewerWorkspaceSchema::uuid($pin['workspace_id']) && ReviewerWorkspaceSchema::version($pin['revision']) && ReviewerWorkspaceSchema::text($pin['build_id'], 120), 422);
        foreach (['workspace_digest', 'selection_digest', 'snapshot_digest', 'policy_hash', 'capability_hash', 'version_hash', 'source_hash'] as $key) {
            abort_unless(ReviewerWorkspaceSchema::hash($pin[$key]), 422);
        }
    }

    public function projection(Request $request, string $token, array $binding, array $selection): array
    {
        $current = $this->current($request, $token, $binding, $selection);
        $scope = $current['scope'];
        $graph = $this->projector->graph($scope, $current['selection'], $current['graph']);
        $areas = array_values(array_filter($current['graph']['areas'], fn ($area) => in_array($area['id'], $scope['policy']['area_ids'], true)));
        if ($current['selection']['persona'] === 'worker') {
            $person = collect($current['graph']['people'])->firstWhere('id', $current['selection']['person_id']);
            $areas = array_values(array_filter($areas, fn ($area) => in_array($area['id'], $person['area_ids'], true)));
        }
        $value = ['workspace_id' => $current['row']->id, 'revision' => $current['row']->version, 'workspace_digest' => ReviewerWorkspaceSchema::digest($current['graph']),
            'selection' => $current['selection'], 'graph' => $graph, 'allowed_areas' => $areas, 'permitted_actions' => $this->access->actions($scope, $current['selection']),
            'capabilities' => $this->projector->capabilities($scope, $current['selection']), 'origin' => 'server_shared_fictional', 'expires_at' => $scope['descriptor']['expires_at']];
        $this->unchanged($current, $request, $token, $binding);

        return $value;
    }

    public function facts(Request $request, string $token, array $binding, array $selection): array
    {
        $current = $this->current($request, $token, $binding, $selection);
        $snapshot = $this->projector->snapshot($current['scope'], $current['selection'], $current['graph'], $current['row']->version);
        $pin = $this->pin($current) + ['snapshot_digest' => ReviewerWorkspaceSchema::digest($snapshot)];
        $this->unchanged($current, $request, $token, $binding);

        return compact('snapshot', 'pin');
    }

    public function assertFacts(Request $request, string $token, array $binding, array $selection, array $pin): array
    {
        $this->validatePin($pin);
        $facts = $this->facts($request, $token, $binding, $selection);
        abort_unless(hash_equals(ReviewerWorkspaceSchema::digest($pin), ReviewerWorkspaceSchema::digest($facts['pin'])), 409, 'The server-held evidence changed. Review new sharing fields.');

        return $facts;
    }

    /** Fresh authority always required. Content-free mismatch lets a paid receipt remain reconcilable without stale answers. */
    public function statusFacts(Request $request, string $token, array $binding, array $selection, array $pin): array
    {
        $this->validatePin($pin);
        $current = $this->current($request, $token, $binding, $selection);
        foreach ($this->pin($current) as $key => $value) {
            if ($pin[$key] !== $value) {
                $this->unchanged($current, $request, $token, $binding);

                return ['matches' => false];
            }
        }
        try {
            $facts = $this->facts($request, $token, $binding, $selection);
        } catch (HttpExceptionInterface $error) {
            if ($error->getStatusCode() === 422) {
                return ['matches' => false];
            } throw $error;
        }

        return ['matches' => hash_equals($pin['snapshot_digest'], $facts['pin']['snapshot_digest'])];
    }

    public function command(Request $request, string $token, array $command): array
    {
        ReviewerWorkspaceSchema::exact($command, ['binding', 'selection', 'request_id', 'expected_revision', 'action', 'record_id', 'expected_record_version', 'payload']);
        abort_unless(is_array($command['binding']) && is_array($command['selection']) && ReviewerWorkspaceSchema::uuid($command['request_id'])
            && ReviewerWorkspaceSchema::version($command['expected_revision']) && is_int($command['expected_record_version']) && $command['expected_record_version'] >= 0
            && ReviewerWorkspaceSchema::uuid($command['record_id']) && ReviewerWorkspaceSchema::text($command['action'], 40) && is_array($command['payload'])
            && strlen(ReviewerWorkspaceSchema::commandCanonical($command)) <= 16384, 422);
        $digest = ReviewerWorkspaceSchema::commandDigest($command);
        $result = $this->store->locked(function () use ($request, $token, $command, $digest): array {
            $current = $this->current($request, $token, $command['binding'], $command['selection'], true);
            $prior = $this->store->command($current['scope'], $command['request_id']);
            if ($prior !== null) {
                $this->assertCommand($prior, $current['scope'], $command['request_id'], $digest);

                return ['status' => 'applied', 'replayed' => true, 'receipt' => $this->receipt($prior)];
            }
            // Only an absent receipt and a pure pre-write rejection can be called definitively unapplied.
            try {
                abort_unless($current['row']->version === $command['expected_revision'], 409, 'Refresh before applying a changed workspace.');
                $next = $this->commands->apply($current['scope'], $current['selection'], $current['graph'], $command, now()->utc()->format('Y-m-d\TH:i:s\Z'));
            } catch (HttpExceptionInterface $error) {
                if (in_array($error->getStatusCode(), [409, 422], true)) {
                    throw new ReviewerWorkspaceRejection($error->getStatusCode(), $command['request_id'], $digest, $current['row']->id);
                }
                throw $error;
            }
            $this->store->save($current['row'], $next);
            $this->store->remember($current['scope'], $command['request_id'], $digest, $command['action'], $command['record_id'], $current['row']->version);
            $this->store->audit($current['row'], 'workspace.command', ['request_id' => $command['request_id'], 'digest' => $digest, 'record_id' => $command['record_id'], 'action' => $command['action']]);

            return ['status' => 'applied', 'replayed' => false, 'receipt' => ['request_id' => $command['request_id'], 'record_id' => $command['record_id'], 'revision' => $current['row']->version, 'digest' => $digest]];
        });

        return $result + ['projection' => $this->projection($request, $token, $command['binding'], $command['selection'])];
    }

    private function assertCommand(TeamOperationRecord $row, array $scope, string $requestId, string $digest): void
    {
        $data = $row->data;
        ReviewerWorkspaceSchema::exact($data, ['workspace_id', 'version_id', 'capability_hash', 'request_id', 'digest', 'action', 'record_id', 'revision']);
        abort_unless($row->status === 'applied' && $data['workspace_id'] === $scope['descriptor']['workspace_id'] && $data['version_id'] === $scope['version']->id
            && $data['capability_hash'] === $scope['capability_hash'] && $data['request_id'] === $requestId && is_string($data['digest']) && hash_equals($data['digest'], $digest), 409);
    }

    private function receipt(TeamOperationRecord $row): array
    {
        return array_intersect_key($row->data, array_flip(['request_id', 'record_id', 'revision', 'digest']));
    }

    public function commandStatus(Request $request, string $token, array $binding, array $selection, string $requestId, string $digest): array
    {
        abort_unless(ReviewerWorkspaceSchema::uuid($requestId) && ReviewerWorkspaceSchema::hash($digest), 422);
        $current = $this->current($request, $token, $binding, $selection);
        $prior = $this->store->command($current['scope'], $requestId);
        if ($prior === null) {
            $this->unchanged($current, $request, $token, $binding);

            return ['status' => 'not_observed', 'settled' => false];
        }
        $this->assertCommand($prior, $current['scope'], $requestId, $digest);

        return ['status' => 'applied', 'settled' => true, 'receipt' => $this->receipt($prior), 'projection' => $this->projection($request, $token, $binding, $selection)];
    }
}

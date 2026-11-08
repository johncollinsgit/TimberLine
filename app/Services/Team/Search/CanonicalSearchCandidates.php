<?php

namespace App\Services\Team\Search;

use App\Models\TeamOperationRecord;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;

/** References only: SearchDocuments remains the authority for every displayed field. */
class CanonicalSearchCandidates
{
    /** Supplement older indexes when a query explicitly names an occurrence year. */
    public function eventReferences(Tenant $tenant, ?array &$coverage): \Generator
    {
        $limit = max(1, min(10000, (int) config('team_work.search_canonical_limit', 2500)));
        $seen = 0;
        foreach (TeamOperationRecord::where('tenant_id', $tenant->id)->where('kind', 'event')->orderBy('id')->cursor() as $row) {
            if ($seen++ >= $limit) {
                $coverage = ['basis' => 'saved_workspace', 'complete' => false, 'notice' => 'Saved event date search reached its coverage limit. More historical work may exist; continue in its area.'];
                break;
            }
            yield (object) ['kind' => 'operation', 'entity_id' => $row->id, 'record' => $row];
        }
    }

    public function references(Tenant $tenant, array $state, ?array &$coverage): \Generator
    {
        $limit = max(1, min(10000, (int) config('team_work.search_canonical_limit', 2500)));
        $seen = 0;
        $coverage = ['basis' => 'saved_workspace', 'complete' => true, 'notice' => 'Searches saved workspace records. Connected sources may have newer changes.'];
        $references = function () use ($tenant, $state): \Generator {
            foreach (['project' => 'projects', 'task' => 'tasks'] as $kind => $key) {
                foreach ($state[$key] as $id => $record) {
                    yield (object) ['kind' => $kind, 'entity_id' => (string) $id];
                }
            }
            foreach (DB::table('team_work_versions')->where(['tenant_id' => $tenant->id, 'entity_type' => 'project'])->whereIn('entity_id', array_keys($state['projects']))->orderBy('entity_id')->orderBy('version')->cursor() as $row) {
                yield (object) ['kind' => 'update', 'entity_id' => $row->entity_id.':'.$row->version, 'version_row' => $row];
            }
            foreach (TeamOperationRecord::where('tenant_id', $tenant->id)->orderBy('id')->cursor() as $row) {
                yield (object) ['kind' => 'operation', 'entity_id' => $row->id, 'record' => $row];
            }
            foreach (DB::table('team_file_uploads')->where('tenant_id', $tenant->id)->where('target_type', 'task')->where('status', 'ready')->orderBy('id')->select('id')->cursor() as $row) {
                yield (object) ['kind' => 'file', 'entity_id' => $row->id];
            }
            foreach (DB::table('team_source_snapshots')->where('tenant_id', $tenant->id)->whereNotNull('search_generation')
                ->whereIn('connection_id', DB::table('team_provider_connections')->where(['tenant_id' => $tenant->id, 'provider' => 'gmail', 'status' => 'connected'])->select('id'))
                ->select('connection_id', 'resource_key')->distinct()->orderBy('connection_id')->orderBy('resource_key')->cursor() as $row) {
                yield (object) ['kind' => 'source', 'entity_id' => $row->connection_id.':'.$row->resource_key];
            }
        };
        foreach ($references() as $reference) {
            if ($seen++ >= $limit) {
                $coverage = ['basis' => 'saved_workspace', 'complete' => false, 'notice' => 'Saved workspace search reached its coverage limit. More work may exist; use its area or project to continue.'];

                break;
            }
            yield $reference;
        }
    }
}

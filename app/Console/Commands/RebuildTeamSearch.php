<?php

namespace App\Console\Commands;

use App\Models\TeamOperationRecord;
use App\Models\Tenant;
use App\Services\Team\Search\SearchDocuments;
use App\Services\Team\Search\SearchIndex;
use App\Services\Team\Search\SearchMatcher;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RebuildTeamSearch extends Command
{
    protected $signature = 'team:search-rebuild {tenant : Workspace ID} {--restart : Start a new reconciliation pass} {--batch=100} {--max-batches=0 : Stop after this many committed batches; zero completes the pass}';

    protected $description = 'Build or reconcile native Teams search from stored workspace data without contacting providers';

    public function handle(SearchIndex $index, SearchDocuments $documents): int
    {
        $tenant = Tenant::findOrFail($this->argument('tenant'));
        $batch = filter_var($this->option('batch'), FILTER_VALIDATE_INT);
        $maximum = filter_var($this->option('max-batches'), FILTER_VALIDATE_INT);
        if (! $batch || $batch > 1000 || $maximum === false || $maximum < 0) {
            $this->error('Use a batch from 1 to 1000 and a nonnegative maximum.');

            return self::FAILURE;
        }
        // Build while UI remains disabled. This does not alter runtime configuration.
        config(['team_work.search_enabled' => true]);
        if (! $index->available()) {
            $this->error('Run the reviewed search migration first.');

            return self::FAILURE;
        }
        $run = DB::table('team_search_runs')->where('tenant_id', $tenant->id)->first();
        if ($this->option('restart') || ! $run || (int) $run->schema_version !== SearchMatcher::VERSION) {
            DB::table('team_search_runs')->updateOrInsert(['tenant_id' => $tenant->id], ['schema_version' => SearchMatcher::VERSION, 'source' => 'project', 'after_id' => null, 'completed_at' => null, 'warnings' => 0]);
        }
        $sources = ['project' => 'team_projects', 'task' => 'team_tasks', 'file' => 'team_file_uploads', 'operation' => 'team_operation_records', 'source' => 'team_source_snapshots'];
        $batches = 0;
        do {
            DB::transaction(function () use ($tenant, $sources, $batch, $index, $documents) {
                // Use the same lock order as canonical writes, preventing an older backfill overwriting a new edit.
                DB::table('team_work_locks')->insertOrIgnore(['tenant_id' => $tenant->id]);
                DB::table('team_work_locks')->where('tenant_id', $tenant->id)->lockForUpdate()->first();
                $run = DB::table('team_search_runs')->where('tenant_id', $tenant->id)->lockForUpdate()->first();
                if ($run->completed_at) {
                    return;
                }
                $source = $run->source;
                $query = DB::table($sources[$source])->where('tenant_id', $tenant->id)->orderBy('id');
                if ($run->after_id !== null) {
                    $query->where('id', '>', $run->after_id);
                }
                $rows = $query->limit($batch)->get();
                foreach ($rows as $row) {
                    if (in_array($source, ['project', 'task'], true)) {
                        $record = json_decode($row->payload, true, 512, JSON_THROW_ON_ERROR);
                        if (! empty($record['deleted'])) {
                            $index->remove($tenant->id, $source, $row->id);
                        } else {
                            $index->put($tenant->id, $source, $row->id, $documents->native($source, $record));
                        }
                        if ($source === 'project') {
                            foreach (DB::table('team_work_versions')->where(['tenant_id' => $tenant->id, 'entity_type' => 'project', 'entity_id' => $row->id])->orderBy('version')->cursor() as $revision) {
                                $status = json_decode($revision->snapshot, true, flags: JSON_THROW_ON_ERROR)['current_status'] ?? null;
                                if ($status && (int) $status['project_version'] === (int) $revision->version) {
                                    if (! hash_equals($revision->sha256, hash('sha256', $revision->snapshot))) {
                                        DB::table('team_search_runs')->where('tenant_id', $tenant->id)->increment('warnings');

                                        continue;
                                    }
                                    $index->put($tenant->id, 'update', $row->id.':'.$revision->version, $documents->update($record, $status));
                                }
                            }
                        }
                    } elseif ($source === 'file') {
                        if ($row->status === 'ready') {
                            $index->put($tenant->id, 'file', $row->id, $documents->file($row));
                        } else {
                            $index->remove($tenant->id, 'file', $row->id);
                        }
                    } elseif ($source === 'source') {
                        $documents->indexSnapshot(\App\Models\TeamSourceSnapshot::findOrFail($row->id));
                    } else {
                        $documents->indexOperation(TeamOperationRecord::findOrFail($row->id));
                    }
                }
                $changes = ['after_id' => $rows->last()?->id ?? $run->after_id];
                if ($rows->count() < $batch) {
                    // Reconcile physically removed sources without deleting any canonical data.
                    $stale = $source === 'source' ? collect() : DB::table('team_search_documents')->where(['tenant_id' => $tenant->id, 'kind' => $source])
                        ->whereNotIn('entity_id', DB::table($sources[$source])->select('id')->where('tenant_id', $tenant->id))->get();
                    foreach ($stale as $row) {
                        $index->remove($tenant->id, $source, $row->entity_id);
                    }
                    $next = array_keys($sources)[array_search($source, array_keys($sources), true) + 1] ?? null;
                    $changes = $next ? ['source' => $next, 'after_id' => null] : ['completed_at' => now()];
                }
                DB::table('team_search_runs')->where('tenant_id', $tenant->id)->update($changes);
            });
            $run = DB::table('team_search_runs')->where('tenant_id', $tenant->id)->first();
            $batches++;
        } while (! $run->completed_at && (! $maximum || $batches < $maximum));
        $this->info($run->completed_at ? 'Search reconciliation complete.' : 'Checkpoint saved. Rerun the same command to resume.');
        if ($run->warnings) {
            $this->warn($run->warnings.' historical updates could not be verified and remain excluded. Original history is unchanged. Review before enabling search.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}

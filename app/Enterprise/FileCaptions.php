<?php

namespace App\Enterprise;

use App\Models\TeamArea;
use App\Models\TeamOperationAttempt;
use App\Models\TeamOperationRecord;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Team\TeamFileService;
use App\Services\Team\TeamWorkRepository;
use App\Services\Team\WorkGraph;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/** Encrypted metadata only; native file and task authority remain authoritative. */
class FileCaptions
{
    public const KIND = 'ent_file_caption';

    public function __construct(private WorkspaceAccess $access, private RecordStore $store, private TeamFileService $files,
        private TeamWorkRepository $work, private WorkGraph $graph) {}

    private function task(Tenant $tenant, User $user, string $taskId, bool $write = false): array
    {
        abort_unless(config('enterprise.operations_enabled') && config('enterprise.collaboration_enabled'), 404);
        $actor = $this->access->actor($tenant, $user, $write);
        $state = $this->work->state($tenant->id);
        abort_unless($this->graph->can($state, $actor, 'task', $taskId, $write), 404);
        $task = $this->graph->read($state, $actor, 'task', $taskId);
        $this->access->area($tenant, $actor, $task['enterprise_area_id'] ?? '');

        return $task;
    }

    private function visible(Tenant $tenant, User $user, string $taskId): array
    {
        return array_values(array_filter($this->files->listing($tenant, $user, $taskId),
            fn ($file) => in_array($file['status'], ['ready', 'quarantined'], true)));
    }

    private function file(Tenant $tenant, User $user, string $id, bool $write = false): array
    {
        abort_unless(Str::isUuid($id), 404);
        $file = DB::table('team_file_uploads')->where(['tenant_id' => $tenant->id, 'id' => $id])->first();
        abort_unless($file, 404);
        $task = $this->task($tenant, $user, $file->task_id, $write);
        $listing = collect($this->visible($tenant, $user, $task['id']))->firstWhere('id', $id);
        abort_unless($listing && (! $write || ($listing['can_manage'] && (int) $file->uploader_user_id === (int) $user->id)), 404);

        return [$file, $task];
    }

    private function record(Tenant $tenant, object $file, array $task): ?TeamOperationRecord
    {
        $row = TeamOperationRecord::query()->where(['tenant_id' => $tenant->id, 'kind' => self::KIND,
            'source_key' => hash('sha256', 'ent-file-caption:'.$file->id)])->first();
        if ($row) {
            abort_unless($row->status === 'active' && $row->area_id === $task['enterprise_area_id']
                && ($row->data['file_id'] ?? null) === $file->id && ($row->data['task_id'] ?? null) === $task['id']
                && ($row->data['uploader_id'] ?? null) === (string) $file->uploader_user_id, 404);
        }

        return $row;
    }

    private function dto(string $id, ?TeamOperationRecord $row): array
    {
        return ['id' => $id, 'caption' => $row?->data['caption'] ?? '', 'version' => $row?->version ?? 0];
    }

    public function show(Tenant $tenant, User $user, string $fileId): array
    {
        [$file, $task] = $this->file($tenant, $user, $fileId);

        return $this->dto($fileId, $this->record($tenant, $file, $task));
    }

    public function forTask(Tenant $tenant, User $user, string $taskId): array
    {
        $task = $this->task($tenant, $user, $taskId);
        $result = [];
        foreach ($this->visible($tenant, $user, $taskId) as $visible) {
            $file = DB::table('team_file_uploads')->where(['tenant_id' => $tenant->id, 'id' => $visible['id'], 'task_id' => $taskId])->first();
            if ($file && ($row = $this->record($tenant, $file, $task))) {
                $result[] = $this->dto($file->id, $row);
            }
        }

        return $result;
    }

    /** Bounded search projection: one current actor/graph and two batched metadata queries. */
    public function searchable(Tenant $tenant, User $user, array $taskIds): array
    {
        abort_unless(config('enterprise.operations_enabled') && config('enterprise.collaboration_enabled'), 404);
        Validator::make(['task_ids' => $taskIds], ['task_ids' => ['array', 'max:1000'], 'task_ids.*' => ['required', 'uuid']])->validate();
        $actor = $this->access->actor($tenant, $user);
        $state = $this->work->state($tenant->id);
        $areas = TeamArea::query()->where('tenant_id', $tenant->id)->whereIn('profile', WorkspaceAccess::PROFILES)->pluck('id')->all();
        $tasks = [];
        foreach (array_unique($taskIds) as $id) {
            if (! $this->graph->can($state, $actor, 'task', $id)) {
                continue;
            }
            $task = $this->graph->read($state, $actor, 'task', $id);
            $areaId = $task['enterprise_area_id'] ?? '';
            if (in_array($areaId, $areas, true) && WorkspaceAccess::permits($actor, $areaId)) {
                $tasks[$id] = $task;
            }
        }
        if ($tasks === []) {
            return [];
        }
        $files = DB::table('team_file_uploads')->where('tenant_id', $tenant->id)->whereIn('task_id', array_keys($tasks))
            ->where(fn ($query) => $query->where('status', 'ready')->orWhere('uploader_user_id', $user->id))
            ->where('status', '!=', 'cancelled')->orderBy('created_at')->orderBy('id')->limit(1000)
            ->get(['id', 'task_id', 'filename', 'status', 'uploader_user_id']);
        $captionFiles = [];
        foreach ($files as $file) {
            if (in_array($file->status, ['ready', 'quarantined'], true)) {
                $captionFiles[hash('sha256', 'ent-file-caption:'.$file->id)] = $file;
            }
        }
        $captions = [];
        if ($captionFiles !== []) {
            $rows = TeamOperationRecord::query()->where(['tenant_id' => $tenant->id, 'kind' => self::KIND, 'status' => 'active'])
                ->whereIn('area_id', array_unique(array_column($tasks, 'enterprise_area_id')))
                ->whereIn('source_key', array_keys($captionFiles))->limit(1000)->get();
            foreach ($rows as $row) {
                $file = $captionFiles[$row->source_key];
                // Check plaintext authority before decrypting only this visible file's metadata.
                if ($row->area_id !== $tasks[$file->task_id]['enterprise_area_id']) {
                    continue;
                }
                $data = $row->data;
                if (($data['file_id'] ?? null) === $file->id && ($data['task_id'] ?? null) === $file->task_id
                    && ($data['uploader_id'] ?? null) === (string) $file->uploader_user_id && is_string($data['caption'] ?? null)) {
                    $captions[$file->id] = $data['caption'];
                }
            }
        }

        return $files->map(fn ($file) => ['id' => $file->id, 'task_id' => $file->task_id, 'filename' => $file->filename,
            'status' => $file->status, 'caption' => $captions[$file->id] ?? ''])->all();
    }

    public function save(Tenant $tenant, User $user, string $fileId, array $input): array
    {
        $input = Validator::make($input, ['caption' => ['present', 'nullable', 'string', 'max:300', 'not_regex:/\x00/'],
            'expected_version' => ['required', 'integer', 'min:0'], 'request_id' => ['required', 'uuid']])->validate();
        $input['expected_version'] = (int) $input['expected_version'];
        $input['caption'] ??= '';

        return $this->store->locked($tenant, function () use ($tenant, $user, $fileId, $input): array {
            [$file, $task] = $this->file($tenant, $user, $fileId, true);
            $row = $this->record($tenant, $file, $task);
            $hash = hash('sha256', json_encode([$fileId, $input], JSON_THROW_ON_ERROR));
            $receipt = TeamOperationAttempt::query()->where(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'request_id' => $input['request_id']])->first();
            if ($receipt) {
                abort_unless($receipt->kind === 'ent.file.caption' && $receipt->record_id === $row?->id && hash_equals($receipt->input_hash, $hash), 409);

                return $receipt->result + ['replayed' => true];
            }
            abort_unless(($row?->version ?? 0) === $input['expected_version'], 409, 'This caption changed. Reload before saving.');
            $before = $row?->data['caption'] ?? '';
            $row ??= new TeamOperationRecord(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'area_id' => $task['enterprise_area_id'],
                'kind' => self::KIND, 'source_key' => hash('sha256', 'ent-file-caption:'.$fileId), 'visibility' => 'area',
                'title' => 'Private file caption', 'status' => 'active', 'version' => 0]);
            $row->data = ['file_id' => $fileId, 'task_id' => $task['id'], 'uploader_id' => (string) $file->uploader_user_id, 'caption' => $input['caption']];
            $row->version++;
            $row->save();
            $this->store->audit($row, $user->id, 'file.caption.saved', ['before' => $before, 'after' => $input['caption'], 'file_id' => $fileId]);
            $result = $this->dto($fileId, $row);
            TeamOperationAttempt::query()->create(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'area_id' => $row->area_id,
                'record_id' => $row->id, 'user_id' => $user->id, 'kind' => 'ent.file.caption', 'status' => 'accepted',
                'request_id' => $input['request_id'], 'input_hash' => $hash, 'result' => $result]);

            return $result + ['replayed' => false];
        });
    }
}

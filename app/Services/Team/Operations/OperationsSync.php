<?php

namespace App\Services\Team\Operations;

use App\Models\TeamOperationRecord;
use App\Models\TeamProviderConnection;
use App\Models\TeamSourceSnapshot;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class OperationsSync
{
    private ?string $runId = null;

    public function __construct(private OperationsProviders $providers, private OperationsWorkspace $workspace, private SheetMapper $mapper) {}

    public function adoptCreatedEvent(TeamOperationRecord $draft, TeamProviderConnection $c, array $source): TeamOperationRecord
    {
        $gid = (string) ($source['gid'] ?? '');
        abort_unless(ctype_digit($gid), 502);

        return \Illuminate\Support\Facades\DB::transaction(function () use ($draft, $c, $source, $gid) {
            $current = TeamProviderConnection::whereKey($c->id)->lockForUpdate()->firstOrFail();
            abort_unless($current->generation === $c->generation && $current->status === 'connected', 409);
            $key = hash('sha256', 'asana:'.$c->settings['source']['asana_workspace'].':'.$gid);
            $existing = TeamOperationRecord::where(['tenant_id' => $c->tenant_id, 'area_id' => $c->area_id, 'kind' => 'event', 'source_key' => $key])->lockForUpdate()->first();
            $draft = TeamOperationRecord::whereKey($draft->id)->lockForUpdate()->firstOrFail();
            if ($existing && $existing->id !== $draft->id) {
                $draft->update(['status' => 'withdrawn', 'version' => $draft->version + 1, 'data' => [...$draft->data, 'projection_id' => $existing->id]]);
                $this->workspace->revision($draft, $c->user_id);
                foreach (TeamOperationRecord::where(['tenant_id' => $c->tenant_id, 'area_id' => $c->area_id, 'kind' => 'document'])->cursor() as $doc) {
                    if (($doc->data['target_id'] ?? null) === $draft->id) {
                        $doc->update(['data' => [...$doc->data, 'target_id' => $existing->id], 'version' => $doc->version + 1]);
                        $this->workspace->revision($doc, $c->user_id);
                    }
                }

                return $existing;
            }
            $project = (string) $c->settings['source']['asana_project'];
            $notes = (string) ($source['notes'] ?? $draft->data['notes'] ?? '');
            $data = ['source_id' => $gid, 'source_project' => $project, 'source_projects' => [$project], 'source_workspace' => (string) $c->settings['source']['asana_workspace'], 'original_notes' => $notes, 'source_url' => 'https://app.asana.com/0/'.$project.'/'.$gid, 'source_version' => $source['modified_at'] ?? now()->toIso8601String(), 'fields' => $this->extract($notes), 'reviewed_fields' => array_intersect_key($draft->data, array_flip(['venue', 'address', 'city', 'state', 'timezone', 'environment', 'organizer', 'email', 'phone', 'setup_time', 'loadout_time', 'event_hours', 'booth', 'instructions'])), '_teams_enrichment' => ['created_from_draft' => $draft->id], '_generation' => $c->generation, 'observed_at' => now()->toIso8601String()];
            $draft->update(['connection_id' => $c->id, 'source_key' => $key, 'status' => 'source', 'title' => $source['name'] ?? $draft->title, 'starts_on' => $source['start_on'] ?? $source['due_on'] ?? $draft->starts_on, 'ends_on' => $source['due_on'] ?? $draft->ends_on, 'data' => $data, 'version' => $draft->version + 1]);
            $this->workspace->revision($draft, $c->user_id);

            return $draft;
        });
    }

    public function refresh(TeamProviderConnection $c): array
    {
        return Cache::lock('team-ops-sync:'.$c->id, 180)->block(1, function () use ($c) {
            $seen = [];
            $prior = $c->sync_state ?? [];
            $scan = $prior['scan'] ?? null;
            $resumeScan = is_array($scan) && ($scan['generation'] ?? null) === $c->generation && ! empty($scan['cursor']);
            $this->runId = $resumeScan ? $scan['run_id'] : (string) Str::uuid();
            $cursor = $resumeScan ? $scan['cursor'] : null;
            $errors = $resumeScan ? ($scan['errors'] ?? []) : [];
            $pages = 0;
            $cursors = [];
            $gmailHistory = $resumeScan ? ($scan['gmail_history_id'] ?? null) : null;
            $gmailReconciled = $resumeScan && ($scan['gmail_reconciled'] ?? false);
            $started = now();
            try {
                do {
                    try {
                        $page = $this->providers->read($c, 'records', $cursor);
                    } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
                        if (! $resumeScan || ! in_array($e->getStatusCode(), [409, 422], true)) {
                            throw $e;
                        } $resumeScan = false;
                        $this->runId = (string) Str::uuid();
                        $cursor = null;
                        $errors = [];
                        $gmailHistory = null;
                        $gmailReconciled = false;
                        $page = $this->providers->read($c, 'records');
                    }
                    $pages++;
                    if (isset($page['gmail_history_id'])) {
                        $gmailHistory = ($page['gmail_reconciliation'] ?? false) ? ($gmailHistory ?? $page['gmail_history_id']) : $page['gmail_history_id'];
                    }
                    $gmailReconciled = $gmailReconciled || ($page['gmail_reconciliation'] ?? false);
                    $digest = $page['page_identity'];
                    abort_if(isset($cursors[$digest]), 502, 'Source pagination repeated. Partial results retained.');
                    $cursors[$digest] = true;
                    $this->snapshot($c, 'page:'.$digest, array_diff_key($page, ['next_cursor' => true, 'observed_at' => true]));
                    if ($c->provider === 'sheets') {
                        foreach ($page['items'] as $i => $tab) {
                            $name = $c->settings['source']['tabs'][$i] ?? '';
                            if ($name === 'Payment Criteria') {
                                $this->snapshot($c, 'payment_criteria', $tab);

                                continue;
                            }
                            $mapped = $this->mapper->rows($name, $tab['values'] ?? []);
                            $errors = [...$errors, ...$mapped['errors']];
                            foreach ($mapped['items'] as $line) {
                                $accepted = ($c->settings['mapping_approved'] ?? false) && $line['has_stable_id'] && ! $line['needs_allocation_review'];
                                $record = $this->upsert($c, 'financial', $line['source_id'], $line['title'], $accepted ? 'accepted' : 'needs_review', $line, $line['starts_on'], $line['ends_on']);
                                $seen[] = $record->id;
                                $staff = $this->upsert($c, 'staff', $line['person_id'] ?: hash('sha256', $line['person_name']), $line['person_name'], 'source', ['name' => $line['person_name'], 'person_id' => $line['person_id'], 'identity_review' => true, 'source' => 'Compensation workbook; contact/account mapping requires review.']);
                                $seen[] = $staff->id;
                            }
                        }
                    } elseif ($c->provider === 'asana') {
                        $area = \App\Models\TeamArea::query()->findOrFail($c->area_id);
                        $projectId = (string) $page['project_id'];
                        $projectAccounts = $area->profile === 'wholesale' && ($c->settings['source']['asana_account_model'] ?? 'tasks') === 'projects';
                        if ($projectAccounts) {
                            $project = $page['project'];
                            $notes = $project['notes'] ?? '';
                            $account = $this->upsert($c, 'account', 'project:'.$projectId, $project['name'] ?? 'Source account', 'source', ['source_id' => $projectId, 'source_type' => 'project', 'source_project' => $projectId, 'source_projects' => [$projectId], 'source_workspace' => (string) $c->settings['source']['asana_workspace'], 'original_notes' => $notes, 'source_version' => $project['modified_at'] ?? hash('sha256', $notes), 'fields' => $this->extract($notes), 'source_url' => 'https://app.asana.com/0/'.$projectId.'/list']);
                            $seen[] = $account->id;
                        }
                        foreach ($page['items'] as $task) {
                            $kind = $area->profile === 'events' ? 'event' : ($projectAccounts ? 'followup' : 'account');
                            $data = ['source_id' => (string) $task['gid'], 'source_project' => $projectId, 'source_workspace' => (string) $c->settings['source']['asana_workspace'], 'original_notes' => $task['notes'] ?? '', 'source_version' => $task['modified_at'] ?? hash('sha256', json_encode($task, JSON_THROW_ON_ERROR)), 'fields' => $this->extract($task['notes'] ?? ''), 'source_url' => 'https://app.asana.com/0/'.$projectId.'/'.$task['gid'], 'read_only' => true];
                            if ($projectAccounts) {
                                $data['account_id'] = $account->id;
                            }
                            $data['source_assignee_id'] = (string) ($task['assignee']['gid'] ?? '');
                            $data['source_projects'] = collect([$projectId, ...collect($task['memberships'] ?? [])->pluck('project.gid')->all()])->map(fn ($id) => (string) $id)->filter()->unique()->values()->all();
                            $record = $this->upsert($c, $kind, (string) $task['gid'], trim($task['name'] ?? '') ?: 'Untitled source record', ($task['completed'] ?? false) ? 'completed' : 'source', $data, $task['start_on'] ?? $task['due_on'] ?? null, $task['due_on'] ?? null);
                            $seen[] = $record->id;
                        }
                    } else {
                        foreach ($page['items'] as $message) {
                            foreach ($this->mail($c, $message['id']) as $id) {
                                $seen[] = $id;
                            }
                        }
                    }
                    $cursor = $page['next_cursor'];
                    $current = $c->fresh();
                    abort_unless($current?->generation === $c->generation && $current->status === 'connected', 409);
                    $checkpoint = ['run_id' => $this->runId, 'generation' => $c->generation, 'cursor' => $cursor, 'errors' => array_slice($errors, 0, 25), 'gmail_history_id' => $gmailHistory, 'gmail_reconciled' => $gmailReconciled];
                    \Illuminate\Support\Facades\DB::transaction(function () use ($c, $prior, $checkpoint, $started) {
                        $current = TeamProviderConnection::whereKey($c->id)->lockForUpdate()->firstOrFail();
                        abort_unless($current->generation === $c->generation && $current->status === 'connected', 409);
                        $current->update(['sync_state' => [...$prior, 'status' => 'partial', 'complete' => false, 'scan' => $checkpoint, 'last_attempt_at' => $started->toIso8601String()]]);
                    });
                } while ($cursor && $pages < config('team_operations.sync_pages_per_run', 5));
                $complete = ! $cursor && ! $errors;

                return \Illuminate\Support\Facades\DB::transaction(function () use ($c, $complete, $cursor, $checkpoint, $pages, $seen, $errors, $started, $gmailHistory, $gmailReconciled) {
                    $current = TeamProviderConnection::whereKey($c->id)->lockForUpdate()->firstOrFail();
                    abort_unless($current->generation === $c->generation && $current->status === 'connected', 409);
                    // Withdrawal only follows a complete source enumeration, never a transient failure.
                    if ($complete && $c->provider !== 'gmail') {
                        foreach (TeamOperationRecord::query()->where(['tenant_id' => $c->tenant_id, 'connection_id' => $c->id])->where('status', '!=', 'withdrawn')->cursor() as $old) {
                            if (($old->data['_source_run'] ?? null) === $this->runId) {
                                continue;
                            }
                            $old->update(['status' => 'withdrawn', 'version' => $old->version + 1]);
                            $this->workspace->revision($old);
                        }
                    }
                    $state = ['status' => $complete ? 'current' : 'partial', 'complete' => $complete, 'pages' => $pages, 'records' => count($seen), 'errors' => array_slice($errors, 0, 25), 'last_attempt_at' => $started->toIso8601String()];
                    if ($cursor) {
                        $state['scan'] = $checkpoint;
                    }
                    if ($c->provider === 'gmail') {
                        $state += ['gmail_history_id' => $complete && $gmailHistory ? $gmailHistory : ($c->sync_state['gmail_history_id'] ?? null), 'gmail_reconciled_at' => $complete && $gmailReconciled ? now()->toIso8601String() : ($c->sync_state['gmail_reconciled_at'] ?? null)];
                    }
                    $current->update(['sync_state' => $state, 'last_success_at' => $complete ? now() : $current->last_success_at]);

                    return $state;
                });
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\DB::transaction(function () use ($c, $started) {
                    $current = TeamProviderConnection::whereKey($c->id)->lockForUpdate()->first();
                    if ($current && $current->generation === $c->generation && $current->status === 'connected') {
                        $current->update(['sync_state' => [...($current->sync_state ?? []), 'status' => 'unavailable', 'complete' => false, 'last_attempt_at' => $started->toIso8601String(), 'notice' => 'Refresh did not complete. Previously stored work is not a complete current result.']]);
                    }
                });
                throw $e;
            }
        });
    }

    public function snapshot(TeamProviderConnection $c, string $key, array $payload): TeamSourceSnapshot
    {
        $snapshot = TeamSourceSnapshot::query()->firstOrCreate(['tenant_id' => $c->tenant_id, 'connection_id' => $c->id, 'resource_key' => hash('sha256', $key), 'checksum' => hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR))], ['id' => (string) Str::uuid(), 'payload' => $payload, 'observed_at' => now()]);
        // Only a current successful source read may attest a retained snapshot's generation.
        // Never infer this for historical evidence during a search rebuild.
        if ((config('team_work.search_enabled') || config('team_work.search_index_enabled')) && \Illuminate\Support\Facades\Schema::hasColumn('team_source_snapshots', 'search_generation')
            && $c->fresh()?->generation === $c->generation && $c->fresh()?->status === 'connected') {
            \Illuminate\Support\Facades\DB::transaction(function () use ($snapshot, $c) {
                \Illuminate\Support\Facades\DB::table('team_work_locks')->insertOrIgnore(['tenant_id' => $c->tenant_id]);
                \Illuminate\Support\Facades\DB::table('team_work_locks')->where('tenant_id', $c->tenant_id)->lockForUpdate()->first();
                $current = TeamProviderConnection::where('tenant_id', $c->tenant_id)->lockForUpdate()->find($c->id);
                if ($current?->generation !== $c->generation || $current->status !== 'connected') {
                    return;
                }
                $snapshot->update(['search_generation' => $c->generation, 'search_observed_at' => now()]);
                app(\App\Services\Team\Search\SearchDocuments::class)->indexSnapshot($snapshot);
            });
        }

        return $snapshot;
    }

    public function upsert(TeamProviderConnection $c, string $kind, string $source, string $title, string $status, array $data, ?string $start = null, ?string $end = null): TeamOperationRecord
    {
        return \Illuminate\Support\Facades\DB::transaction(function () use ($c, $kind, $source, $title, $status, $data, $start, $end) {
            \Illuminate\Support\Facades\DB::table('team_work_locks')->insertOrIgnore(['tenant_id' => $c->tenant_id]);
            \Illuminate\Support\Facades\DB::table('team_work_locks')->where('tenant_id', $c->tenant_id)->lockForUpdate()->first();
            $current = TeamProviderConnection::whereKey($c->id)->lockForUpdate()->firstOrFail();
            abort_unless($current->generation === $c->generation && $current->status === 'connected', 409);

            return $this->upsertLocked($c, $kind, $source, $title, $status, $data, $start, $end);
        }, 3);
    }

    private function upsertLocked(TeamProviderConnection $c, string $kind, string $source, string $title, string $status, array $data, ?string $start, ?string $end): TeamOperationRecord
    {
        abort_unless($c->fresh()?->generation === $c->generation && $c->fresh()?->status === 'connected', 409, 'Connection changed.');
        $identity = $c->provider === 'asana' ? 'asana:'.($c->settings['source']['asana_workspace'] ?? '').':'.$source : $c->id.'|'.$source;
        $record = TeamOperationRecord::query()->firstOrNew(['tenant_id' => $c->tenant_id, 'area_id' => $c->area_id, 'kind' => $kind, 'source_key' => hash('sha256', $identity)]);
        foreach (['reviewed_fields', '_teams_enrichment'] as $field) {
            if (isset($record->data[$field])) {
                $data[$field] = $record->data[$field];
            }
        }
        if ($c->provider === 'asana' && isset($record->data['reviewed_fields']) && ($record->data['source_version'] ?? null) !== ($data['source_version'] ?? null)) {
            $data['_teams_enrichment']['stale_reviewed_fields'] = $record->data['reviewed_fields'];
            $data['reviewed_fields'] = [];
        }
        if ($kind === 'document' && isset($record->data['matching']['reviewed_by'])) {
            $data['matching'] = $record->data['matching'];
            $data['target_id'] = $record->data['target_id'];
            $status = ($data['scan'] ?? '') === 'clean' ? 'linked' : 'needs_review';
        }
        $checksum = hash('sha256', json_encode([$c->generation, $title, $status, $data, $start, $end], JSON_THROW_ON_ERROR));
        if ($record->exists && ($record->data['_checksum'] ?? null) === $checksum) {
            if ($this->runId) {
                $record->update(['data' => [...$record->data, '_source_run' => $this->runId, 'observed_at' => now()->toIso8601String()]]);
            }

            return $record;
        }
        $record->fill(['id' => $record->id ?: (string) Str::uuid(), 'connection_id' => $c->id, 'title' => mb_substr($title, 0, 500), 'status' => $status, 'version' => ($record->version ?? 0) + 1, 'visibility' => in_array($kind, ['financial', 'staff']) || ($kind === 'document' && ($data['sensitivity'] ?? '') === 'restricted') ? 'admin' : 'area', 'starts_on' => $start, 'ends_on' => $end, 'data' => [...$data, '_checksum' => $checksum, '_generation' => $c->generation, '_source_run' => $this->runId, 'observed_at' => now()->toIso8601String()]]);
        $record->save();
        $this->workspace->revision($record);

        return $record;
    }

    public function extract(string $notes): array
    {
        $map = ['applied' => 'application_status', 'accepted' => 'acceptance_status', 'cost' => 'fee', 'worker' => 'staff', 'address' => 'address', 'set up time' => 'setup_time', 'event hours' => 'event_hours', 'email' => 'email', 'phone' => 'phone', 'booth size' => 'booth', 'attendance' => 'attendance', 'special directions' => 'instructions', 'contact' => 'contact', 'last order date' => 'last_order_on', 'last contact date' => 'last_contact_on', 'shipping address' => 'shipping', 'store address' => 'address', 'custom labels' => 'custom_labels', 'custom scents' => 'custom_scents', 'website' => 'website'];
        $fields = [];
        foreach (preg_split('/\R/u', $notes) as $line) {
            if (preg_match('/^\s*([^:]{2,35}):\s*(.+)$/u', $line, $match) && isset($map[mb_strtolower(trim($match[1]))])) {
                $fields[] = ['field' => $map[mb_strtolower(trim($match[1]))], 'value' => trim($match[2]), 'passage' => $line, 'confidence' => 'explicit_label', 'review_state' => 'needs_review'];
            }
        }

        return $fields;
    }

    private function mail(TeamProviderConnection $c, string $id): array
    {
        abort_unless(preg_match('/^[a-zA-Z0-9_-]{1,160}$/D', $id), 422);
        $mail = $this->providers->http($c, 'GET', 'https://gmail.googleapis.com/gmail/v1/users/me/messages/'.$id, ['format' => 'full']);
        $this->snapshot($c, 'gmail:'.$id, $mail);
        $headers = [];
        $marker = ['generation' => $c->generation, 'source_checksum' => hash('sha256', json_encode($mail, JSON_THROW_ON_ERROR))];
        if (TeamSourceSnapshot::where(['tenant_id' => $c->tenant_id, 'connection_id' => $c->id, 'resource_key' => hash('sha256', 'gmail-processed:'.$id), 'checksum' => hash('sha256', json_encode($marker, JSON_THROW_ON_ERROR))])->exists()) {
            return [];
        }
        foreach ($mail['payload']['headers'] ?? [] as $header) {
            $headers[strtolower($header['name'])] = $header['value'];
        }
        $ids = [];
        $walk = function (array $part) use (&$walk, $c, $id, $mail, $headers, &$ids) {
            $partHeaders = collect($part['headers'] ?? [])->mapWithKeys(fn ($h) => [strtolower($h['name']) => $h['value']])->all();
            $filename = basename($part['filename'] ?? '');
            $body = $part['body'] ?? [];
            $mime = $part['mimeType'] ?? 'application/octet-stream';
            if ($filename !== '' && isset($body['attachmentId']) && ! isset($partHeaders['content-id']) && ! str_starts_with(strtolower($partHeaders['content-disposition'] ?? ''), 'inline') && ($body['size'] ?? 0) <= config('team_work.file_max_bytes')) {
                $attachment = $this->providers->http($c, 'GET', 'https://gmail.googleapis.com/gmail/v1/users/me/messages/'.$id.'/attachments/'.rawurlencode($body['attachmentId']));
                $bytes = base64_decode(strtr($attachment['data'] ?? '', '-_', '+/'), true);
                abort_unless(is_string($bytes) && strlen($bytes) <= config('team_work.file_max_bytes'), 422, 'Attachment exceeds file limit.');
                $checksum = hash('sha256', $bytes);
                $key = 'team-operations/'.$c->tenant_id.'/'.$c->area_id.'/'.$checksum;
                $detected = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
                $extensions = ['text/plain' => ['txt'], 'image/jpeg' => ['jpg', 'jpeg'], 'image/png' => ['png'], 'application/pdf' => ['pdf']];
                $valid = strlen($bytes) > 0 && $detected === $mime && in_array(strtolower(pathinfo($filename, PATHINFO_EXTENSION)), $extensions[$mime] ?? [], true);
                if (str_starts_with($mime, 'image/')) {
                    $dimensions = @getimagesizefromstring($bytes);
                    $valid = $valid && $dimensions && $dimensions[0] * $dimensions[1] <= 20000000;
                }
                if ($mime === 'text/plain') {
                    $valid = $valid && preg_match('//u', $bytes) === 1;
                }
                if ($valid) {
                    app(\App\Services\Team\TeamFileQuota::class)->store($c->tenant_id, $key, $bytes);
                }
                $scan = $valid ? app(\App\Services\Team\TeamFileScanner::class)->scan(Storage::disk('local')->path($key), $mime, $checksum) : 'rejected';
                $match = $this->matchDocument($c, $filename, $headers['from'] ?? '', $headers['subject'] ?? '');
                $sensitive = (bool) preg_match('/\b(w[ -]?[89]|ein|tax|bank|payroll)\b/i', $filename);
                $doc = $this->upsert($c, 'document', $checksum, $filename, $scan === 'clean' && $match['target_id'] ? 'linked' : 'needs_review', ['sensitivity' => $sensitive ? 'restricted' : 'operational', 'message_id' => $id, 'thread_id' => $mail['threadId'] ?? null, 'filename' => $filename, 'mime' => $mime, 'checksum' => $checksum, 'storage_key' => $key, 'scan' => $scan, 'target_id' => $match['target_id'], 'matching' => $match, 'source_subject' => $headers['subject'] ?? '', 'source_from' => $headers['from'] ?? '', 'source_received_at' => $mail['internalDate'] ?? null]);
                $ids[] = $doc->id;
            }
            foreach ($part['parts'] ?? [] as $child) {
                $walk($child);
            }
        };
        $walk($mail['payload'] ?? []);
        $this->snapshot($c, 'gmail-processed:'.$id, $marker);

        return $ids;
    }

    public function matchDocument(TeamProviderConnection $c, string $filename, string $from, string $subject): array
    {
        $matches = [];
        $unverified = false;
        $normalize = fn ($v) => trim(preg_replace('/[^a-z0-9]+/', ' ', strtolower($v)));
        $haystack = $normalize($filename); // Per-file evidence; subject alone can refer to multiple events.
        foreach (TeamOperationRecord::query()->where(['tenant_id' => $c->tenant_id, 'area_id' => $c->area_id])->whereIn('kind', ['event', 'account'])->whereNotIn('status', ['cancelled', 'withdrawn'])->get() as $record) {
            try {
                app(OperationsAccess::class)->record(\App\Models\Tenant::query()->findOrFail($c->tenant_id), \App\Models\User::query()->findOrFail($c->user_id), $record);
            } catch (\Symfony\Component\HttpKernel\Exception\HttpException) {
                $unverified = true;

                continue;
            }
            foreach ([$record->title, ...($record->data['alias_names'] ?? [])] as $alias) {
                $needle = $normalize($alias);
                if (strlen($needle) < 8 || ! str_contains($haystack, $needle)) {
                    continue;
                }
                $dates = [$record->starts_on?->format('Y'), $record->starts_on?->format('Y-m-d')];
                $matches[$record->id] = ['id' => $record->id, 'title' => $record->title, 'evidence' => 'Filename contains a confirmed event/account name', 'date_supported' => $record->kind === 'account' || ($dates[0] && str_contains($filename, $dates[0]))];
            }
        }
        $candidates = array_values($matches);
        $area = \App\Models\TeamArea::where('tenant_id', $c->tenant_id)->findOrFail($c->area_id);
        $sourceComplete = ($area->settings['source_authority'] ?? 'teams') !== 'asana' || app(OperationsHealth::class)->area($area, $c->user_id, 'asana')['complete'];
        $unique = ! $unverified && $sourceComplete && count($candidates) === 1 && $candidates[0]['date_supported'];

        return ['target_id' => $unique ? $candidates[0]['id'] : null, 'candidates' => $candidates, 'automatic' => $unique, 'notice' => $unique ? 'Unique name and occurrence evidence.' : 'Review the occurrence and file before linking.'];
    }
}

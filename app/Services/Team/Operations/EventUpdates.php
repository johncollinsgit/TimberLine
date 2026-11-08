<?php

namespace App\Services\Team\Operations;

use App\Models\TeamArea;
use App\Models\TeamOperationAttempt;
use App\Models\TeamOperationRecord;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Team\TeamFileService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Event discussion adapter over existing operation receipts and Asana commands. */
class EventUpdates
{
    public function __construct(private OperationsAccess $access, private EventTasks $tasks, private TeamFileService $files) {}

    private function admit(Tenant $tenant, User $user, TeamOperationRecord $event, bool $write = false): void
    {
        abort_unless($event->kind === 'event', 404);
        $this->access->record($tenant, $user, $event);
        abort_unless($this->access->area($tenant, $user, $event->area_id, $write)->profile === 'events', 404);
    }

    private function source(TeamOperationRecord $event): bool
    {
        return $event->connection_id !== null || isset($event->data['source_id']) || ! empty($event->data['created_asana']) || ($event->data['source_authority'] ?? null) === 'asana';
    }

    private function authority(Tenant $tenant, TeamOperationRecord $event): array
    {
        $area = TeamArea::where('tenant_id', $tenant->id)->findOrFail($event->area_id);

        return ['area_id' => $area->id, 'area_authority' => app(\App\Services\Team\Studio\TeamsStudio::class)->authority($tenant, $area)['provider'],
            'source' => $this->source($event), 'connection_id' => $event->connection_id, 'source_key' => $event->source_key,
            'source_id' => $event->data['source_id'] ?? null, 'source_workspace' => $event->data['source_workspace'] ?? null,
            'source_authority' => $event->data['source_authority'] ?? 'teams', 'projection_id' => $event->data['projection_id'] ?? null];
    }

    private function attachments(Tenant $tenant, string $eventId, array $ids): array
    {
        if (! $ids) {
            return [];
        }
        $rows = DB::table('team_file_uploads')->where(['tenant_id' => $tenant->id, 'target_type' => 'event', 'event_id' => $eventId, 'status' => 'ready'])->whereIn('id', $ids)->get()->keyBy('id');

        return array_values(array_filter(array_map(function ($id) use ($rows, $tenant, $eventId) {
            $row = $rows[$id] ?? null;
            if (! $row) {
                return null;
            }
            $query = '?tenant='.rawurlencode($tenant->slug);

            return ['id' => $row->id, 'filename' => $row->filename, 'mime_type' => $row->mime_type, 'size' => (int) $row->expected_size,
                'download_url' => url('/team/files/'.$row->id.'/download').$query,
                'preview_url' => in_array($row->mime_type, ['image/jpeg', 'image/png'], true) ? url('/team/events/'.$eventId.'/updates/files/'.$row->id.'/preview').$query : null];
        }, $ids)));
    }

    private function item(Tenant $tenant, TeamOperationAttempt $attempt): array
    {
        $source = ($attempt->payload['origin'] ?? 'teams') === 'asana';
        $resource = $attempt->result['resource_id'] ?? null;

        return ['id' => $source ? 'asana:'.$resource : 'teams:'.$attempt->id, 'body' => $attempt->payload['body'],
            'author_name' => $attempt->payload['author_name'] ?? 'Team member', 'created_at' => $attempt->result['posted_at'] ?? $attempt->updated_at->toIso8601String(),
            'origin' => $source ? 'asana' : 'teams', 'attachments' => $this->attachments($tenant, $attempt->record_id, $attempt->payload['file_ids'] ?? [])];
    }

    public function listing(Tenant $tenant, User $user, TeamOperationRecord $event, ?string $cursor = null): array
    {
        $this->admit($tenant, $user, $event);
        $writable = $this->access->mayWrite($tenant, $user, $event->area_id);
        $source = $this->source($event);
        $detail = $source ? $this->tasks->forOccurrence($tenant, $user, $event) : null;
        $canPost = $writable && ($source ? ($detail['can_comment'] ?? false) : $this->authority($tenant, $event)['area_authority'] === 'teams');
        $result = ['items' => [], 'has_more' => false, 'next_cursor' => null];
        if ($detail) {
            $area = $this->access->area($tenant, $user, $event->area_id);
            $page = $this->tasks->detail($tenant, $user, $area, $detail['connection_id'], $detail['gid'], 'stories', $cursor);
            // Stream small encrypted receipt metadata; don't hydrate every old
            // comment/file body just to decorate this one source page.
            $storyIds = array_flip(array_map('strval', array_column($page['items'], 'gid')));
            $matched = [];
            foreach (TeamOperationAttempt::where(['tenant_id' => $tenant->id, 'record_id' => $event->id, 'kind' => 'event.update', 'status' => 'succeeded'])->select(['id', 'result'])->cursor() as $receipt) {
                if (isset($storyIds[(string) ($receipt->result['resource_id'] ?? '')])) {
                    $matched[] = $receipt->id;
                }
            }
            $receipts = TeamOperationAttempt::where('tenant_id', $tenant->id)->whereIn('id', $matched)->get()->keyBy(fn ($a) => (string) ($a->result['resource_id'] ?? ''));
            foreach ($page['items'] as $story) {
                if (($story['resource_subtype'] ?? '') !== 'comment_added') {
                    continue;
                }
                $receipt = $receipts[(string) ($story['gid'] ?? '')] ?? null;
                // A receipt proves the original post, not the current contents.
                // Source edits/deletions must not resurrect the old text/files.
                $unchanged = $receipt && (string) ($story['text'] ?? '') === ($receipt->payload['comment'] ?? null);
                $result['items'][] = $unchanged ? [...$this->item($tenant, $receipt), 'author_name' => $story['created_by']['name'] ?? $receipt->payload['author_name'] ?? 'Team member', 'created_at' => $story['created_at'] ?? $receipt->result['posted_at'] ?? null] : ['id' => 'asana:'.$story['gid'], 'body' => (string) ($story['text'] ?? ''), 'author_name' => $story['created_by']['name'] ?? 'Team member', 'created_at' => $story['created_at'] ?? null, 'origin' => 'asana', 'attachments' => []];
            }
            $result['has_more'] = $page['has_more'];
            $result['next_cursor'] = $page['next_cursor'];
        } elseif (! $source) {
            $scope = ['native-posted-v1', (string) $tenant->id, (string) $user->id, $event->area_id, $event->id];
            $query = TeamOperationAttempt::where(['tenant_id' => $tenant->id, 'area_id' => $event->area_id, 'record_id' => $event->id, 'kind' => 'event.update', 'status' => 'succeeded']);
            if ($cursor) {
                try {
                    $decoded = Crypt::decrypt($cursor);
                } catch (\Throwable) {
                    abort(422, 'Refresh the updates to continue.');
                }
                abort_unless(($decoded['scope'] ?? null) === $scope && ($decoded['expires'] ?? 0) > time() && is_array($decoded['before'] ?? null), 422, 'The updates page changed. Refresh it.');
                [$posted, $id] = $decoded['before'];
                $query->where(fn ($q) => $q->where('updated_at', '<', $posted)->orWhere(fn ($q) => $q->where('updated_at', $posted)->where('id', '<', $id)));
            }
            // Succeeded native receipts are immutable on replay/reconciliation.
            // Their updated_at is the commit time, not an earlier preparation.
            $rows = $query->orderByDesc('updated_at')->orderByDesc('id')->limit(31)->get();
            $visible = $rows->take(30);
            $result['items'] = $visible->map(fn ($a) => $this->item($tenant, $a))->all();
            $result['has_more'] = $rows->count() > 30;
            if ($result['has_more']) {
                $last = $visible->last();
                $result['next_cursor'] = Crypt::encrypt(['scope' => $scope, 'expires' => time() + 600, 'before' => [$last->updated_at->toDateTimeString(), $last->id]]);
            }
        }
        $pending = TeamOperationAttempt::where(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'record_id' => $event->id, 'kind' => 'event.update'])->whereIn('status', ['prepared', 'executing', 'unknown'])->latest()->limit(20)->get();

        return $result + ['can_post' => $canPost, 'can_upload' => $writable, 'requires_update_connection' => $source && $writable && ! $canPost,
            'notice' => ! $source ? ($writable && ! $canPost ? 'Connect this event before posting updates.' : '') : ($canPost ? 'Updates are shared with the event in Asana. Files stay protected in Teams.' : 'Connect your Asana account with comment permission to post updates. Files stay protected in Teams.'),
            'max_file_bytes' => (int) config('team_work.file_max_bytes'), 'allowed_mimes' => TeamFileService::MIMES,
            'scanner_enabled' => (bool) config('team_work.scanner.enabled'),
            'upload' => ['begin_url' => url('/team/events/'.$event->id.'/updates/files'), 'chunk_url_template' => url('/team/files/{upload}/chunks/{index}'), 'complete_url_template' => url('/team/files/{upload}/complete'), 'cancel_url_template' => url('/team/files/{upload}')],
            'pending' => $pending->map(fn ($a) => $this->receipt($tenant, $a))->all()];
    }

    private function receipt(Tenant $tenant, TeamOperationAttempt $attempt): array
    {
        $source = $attempt->payload['source'] ?? null;
        // A poster may have committed its child claim/result before updating the
        // parent envelope. Never advertise that claimed child as an unsent draft.
        if ($attempt->status === 'prepared' && $source && ($child = $this->sourceAttempt($attempt))) {
            $attempt = clone $attempt;
            $attempt->status = $child->status;
            $attempt->updated_at = $child->updated_at;
            $attempt->result = ['resource_id' => $child->result['resource_id'] ?? null, 'posted_at' => $child->result['accepted_at'] ?? null, 'not_sent' => $child->result['not_sent'] ?? false];
        }
        $unconfirmed = in_array($attempt->status, ['unknown', 'executing'], true);
        $unsent = $attempt->status === 'prepared';
        $canReview = $unsent || ($source && $unconfirmed && ($attempt->status !== 'executing' || $attempt->updated_at->isBefore(now()->subMinutes(2))));

        return ['id' => $attempt->id, 'request_id' => $attempt->request_id, 'body' => $attempt->payload['body'], 'file_ids' => $attempt->payload['file_ids'] ?? [], 'status' => $attempt->status, 'notice' => $unconfirmed ? 'Posting could not be confirmed. Check the original update before posting another copy.' : ($attempt->status === 'reviewed' ? (($attempt->result['not_sent'] ?? false) ? 'The original update was not sent. Its draft and files are retained for editing; post a new update only when ready.' : 'You reviewed the original update in Asana. Its outcome remains unconfirmed; no post was retried.') : null),
            'source_review_url' => $source ? 'https://app.asana.com/0/0/'.$source['task_id'] : null,
            'can_acknowledge' => $canReview, 'review_kind' => $canReview ? ($unsent ? 'unsent' : 'source') : null, 'review_label' => $unsent ? 'Review unsent update' : 'I reviewed the original update', 'not_sent' => (bool) ($attempt->result['not_sent'] ?? false),
            'item' => $attempt->status === 'succeeded' ? $this->item($tenant, $attempt) : null];
    }

    private function sourceAttempt(TeamOperationAttempt $parent, bool $lock = false): ?TeamOperationAttempt
    {
        $source = $parent->payload['source'] ?? null;
        if (! $source) {
            return null;
        }
        $query = TeamOperationAttempt::where(['tenant_id' => $parent->tenant_id, 'user_id' => $parent->user_id, 'area_id' => $parent->area_id, 'kind' => 'asana.update', 'request_id' => $source['request_id']]);
        if ($id = $parent->payload['source_attempt_id'] ?? null) {
            $query->whereKey($id);
        }
        $child = ($lock ? $query->lockForUpdate() : $query)->first();
        abort_if(! $child && isset($parent->payload['source_attempt_id']), 409, 'The original posting receipt is unavailable. Keep this update for review.');
        if ($child) {
            abort_unless($child->connection_id === $source['connection_id'] && ($child->payload['task_id'] ?? null) === $source['task_id'] && ($child->payload['changes']['comment'] ?? null) === $parent->payload['comment'], 409, 'The original posting identity changed. Keep this update for review.');
        }

        return $child;
    }

    private function lockedParent(Tenant $tenant, TeamOperationAttempt $parent): TeamOperationAttempt
    {
        return TeamOperationAttempt::where(['tenant_id' => $tenant->id, 'user_id' => $parent->user_id, 'record_id' => $parent->record_id, 'kind' => 'event.update'])->whereKey($parent->id)->lockForUpdate()->firstOrFail();
    }

    private function prepareSource(Tenant $tenant, User $user, TeamOperationRecord $event, TeamOperationAttempt $attempt): ?string
    {
        return DB::transaction(function () use ($tenant, $user, $event, $attempt) {
            $parent = $this->lockedParent($tenant, $attempt);
            if ($parent->status !== 'prepared') {
                return null;
            }
            $fresh = $event->fresh();
            $this->admit($tenant, $user, $fresh, true);
            abort_unless(($parent->payload['authority'] ?? null) === $this->authority($tenant, $fresh), 409, 'Event authority changed. Review the retained update first.');
            $source = $parent->payload['source'];
            $area = $this->access->area($tenant, $user, $event->area_id, true);
            $connection = $this->access->connection($tenant, $user, $source['connection_id']);
            abort_unless($connection->generation === ($source['connection_generation'] ?? null) && (string) ($connection->settings['source']['asana_workspace'] ?? '') === ($source['workspace_id'] ?? null), 409, 'The source connection changed. Review the retained update first.');
            // Only provider reads happen while the parent is locked. Its child
            // intent and link commit before any provider POST can be claimed.
            $prepared = $this->tasks->prepare($tenant, $user, $area, $source + ['changes' => ['comment' => $parent->payload['comment']]]);
            $payload = $parent->payload;
            $payload['source_attempt_id'] = $prepared['id'];
            $parent->update(['payload' => $payload]);

            return $prepared['id'];
        }, 3);
    }

    private function mirrorSource(Tenant $tenant, TeamOperationAttempt $attempt, array $receipt, bool $sourceReview = false): void
    {
        DB::transaction(function () use ($tenant, $attempt, $receipt, $sourceReview) {
            $parent = $this->lockedParent($tenant, $attempt);
            // A late poster cannot reopen a completed or explicitly reviewed
            // parent after the provider lock was released.
            if (in_array($parent->status, ['succeeded', 'reviewed'], true)) {
                return;
            }
            $parent->update(['status' => $receipt['status'], 'result' => [...($parent->result ?? []), 'resource_id' => $receipt['result']['resource_id'] ?? null, 'posted_at' => $receipt['result']['accepted_at'] ?? null, 'reviewed_by' => $receipt['result']['acknowledged_by'] ?? null, 'reviewed_at' => $receipt['result']['observed_at'] ?? null, 'not_sent' => $receipt['result']['not_sent'] ?? false, ...($sourceReview ? ['review_kind' => 'source', 'previous_status' => $parent->status] : [])]]);
        }, 3);
    }

    private function reviewUnsent(Tenant $tenant, User $user, TeamOperationAttempt $attempt): void
    {
        $review = function () use ($tenant, $user, $attempt) {
            DB::transaction(function () use ($tenant, $user, $attempt) {
                $parent = $this->lockedParent($tenant, $attempt);
                if (in_array($parent->status, ['reviewed', 'succeeded'], true)) {
                    return;
                }
                $event = TeamOperationRecord::where(['tenant_id' => $tenant->id, 'kind' => 'event'])->findOrFail($parent->record_id);
                $this->admit($tenant, $user, $event, true);
                $child = $this->sourceAttempt($parent, true);
                if ($child && $child->status !== 'prepared') {
                    // A stale prepared UI is not permission to acknowledge a
                    // provider claim. Return its actual state for source review.
                    $parent->update(['status' => $child->status, 'result' => ['resource_id' => $child->result['resource_id'] ?? null, 'posted_at' => $child->result['accepted_at'] ?? null, 'not_sent' => $child->result['not_sent'] ?? false]]);

                    return;
                }
                abort_unless($parent->status === 'prepared', 409, 'This update may have been sent. Review the original in Asana first.');
                $proof = ['not_sent' => true, 'previous_status' => 'prepared', 'review_kind' => 'unsent', 'acknowledged_by' => $user->id, 'observed_at' => now()->toIso8601String()];
                if ($source = $parent->payload['source'] ?? null) {
                    $area = $this->access->area($tenant, $user, $parent->area_id, true);
                    $child = $this->tasks->retireUnsentComment($tenant, $user, $area, $source, $parent->payload['comment'], $proof);
                    if ($child->status !== 'reviewed' || ! ($child->result['not_sent'] ?? false)) {
                        $parent->update(['status' => $child->status, 'result' => ['resource_id' => $child->result['resource_id'] ?? null, 'posted_at' => $child->result['accepted_at'] ?? null, 'not_sent' => $child->result['not_sent'] ?? false]]);

                        return;
                    }
                    $payload = $parent->payload;
                    $payload['source_attempt_id'] = $child->id;
                    $parent->payload = $payload;
                }
                $parent->update(['status' => 'reviewed', 'result' => $proof + ['reviewed_by' => $user->id, 'reviewed_at' => $proof['observed_at']]]);
            }, 3);
        };
        if ($source = $attempt->payload['source'] ?? null) {
            $this->tasks->withSourceTaskLock($tenant, (string) ($source['workspace_id'] ?? ''), $source['task_id'], $review);
        } else {
            $review();
        }
    }

    public function post(Tenant $tenant, User $user, TeamOperationRecord $event, array $input): array
    {
        $this->admit($tenant, $user, $event, true);
        $body = trim($input['body'] ?? '');
        $fileIds = array_values(array_unique($input['file_ids'] ?? []));
        sort($fileIds);
        abort_unless($body !== '' || $fileIds, 422, 'Write an update or add a file.');
        $hash = hash('sha256', json_encode([$event->id, $body, $fileIds], JSON_THROW_ON_ERROR));
        $lock = Cache::lock('event-update:'.hash('sha256', $tenant->id.':'.$user->id.':'.$input['request_id']), 180);
        abort_unless($lock->get(), 409, 'This update is still being checked. Retry the same update shortly.');
        try {
            $attempt = TeamOperationAttempt::where(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'request_id' => $input['request_id']])->first();
            if ($attempt) {
                abort_unless($attempt->kind === 'event.update' && $attempt->record_id === $event->id && $attempt->area_id === $event->area_id && hash_equals($attempt->input_hash, $hash), 409, 'This update key belongs to different content.');
                if (in_array($attempt->status, ['succeeded', 'unknown', 'executing', 'reviewed'], true)) {
                    return $this->receipt($tenant, $attempt);
                }
            } else {
                $files = DB::table('team_file_uploads')->where(['tenant_id' => $tenant->id, 'target_type' => 'event', 'event_id' => $event->id, 'uploader_user_id' => $user->id, 'status' => 'ready'])->whereIn('id', $fileIds)->get();
                abort_unless($files->count() === count($fileIds), 422, 'Finish scanning these event files before posting.');
                $detail = $this->source($event) ? $this->tasks->forOccurrence($tenant, $user, $event) : null;
                $connection = $detail ? $this->access->connection($tenant, $user, $detail['connection_id']) : null;
                $authority = $this->authority($tenant, $event);
                abort_unless(! $this->source($event) || ($detail['can_comment'] ?? false), 403, 'Enable comments on your connected Asana account before posting.');
                abort_unless($detail || $authority['area_authority'] === 'teams', 409, 'This work area is source-authoritative. Connect the event before posting.');
                $attachments = $this->attachments($tenant, $event->id, $fileIds);
                $comment = $body;
                if ($attachments) {
                    $comment .= ($comment === '' ? '' : "\n\n")."Files in Teams:\n".implode("\n", array_map(fn ($f) => $f['filename'].' — '.$f['download_url'], $attachments));
                }
                $attempt = TeamOperationAttempt::firstOrCreate(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'request_id' => $input['request_id']], ['id' => (string) Str::uuid(), 'area_id' => $event->area_id, 'record_id' => $event->id, 'kind' => 'event.update', 'status' => 'prepared', 'input_hash' => $hash,
                    'payload' => ['body' => $body, 'file_ids' => $fileIds, 'author_name' => $user->name, 'origin' => $detail ? 'asana' : 'teams', 'comment' => $comment, 'authority' => $authority, 'source' => $detail ? ['request_id' => (string) Str::uuid(), 'connection_id' => $detail['connection_id'], 'connection_generation' => $connection->generation, 'task_id' => $detail['gid'], 'workspace_id' => (string) $event->data['source_workspace'], 'version' => $detail['version']] : null]]);
            }
            // firstOrCreate handles a unique-key race even when the cache lock
            // is process-local; the persisted receipt remains the authority.
            abort_unless($attempt->kind === 'event.update' && $attempt->record_id === $event->id && $attempt->area_id === $event->area_id && hash_equals($attempt->input_hash, $hash), 409, 'This update key belongs to different content.');
            if (in_array($attempt->status, ['succeeded', 'unknown', 'executing', 'reviewed'], true)) {
                return $this->receipt($tenant, $attempt);
            }
            if ($source = $attempt->payload['source'] ?? null) {
                $area = $this->access->area($tenant, $user, $event->area_id, true);
                $childId = $this->prepareSource($tenant, $user, $event, $attempt);
                if ($childId === null) {
                    return $this->receipt($tenant, $attempt->fresh());
                }
                $this->admit($tenant, $user, $event->fresh(), true);
                $sent = $this->tasks->dispatch($tenant, $user, $area, $childId, function () use ($tenant, $user, $attempt, $childId) {
                    $parent = $this->lockedParent($tenant, $attempt);
                    $this->access->area($tenant, $user, $parent->area_id, true);
                    abort_unless($parent->status === 'prepared' && ($parent->payload['source_attempt_id'] ?? null) === $childId, 409, 'The retained update was reviewed or changed before posting.');
                });
                $this->mirrorSource($tenant, $attempt, $sent);
            } else {
                DB::transaction(function () use ($tenant, $user, $event, $attempt) {
                    DB::table('team_work_locks')->insertOrIgnore(['tenant_id' => $tenant->id]);
                    DB::table('team_work_locks')->where('tenant_id', $tenant->id)->lockForUpdate()->first();
                    TeamArea::where('tenant_id', $tenant->id)->whereKey($attempt->area_id)->lockForUpdate()->firstOrFail();
                    $fresh = TeamOperationRecord::where('tenant_id', $tenant->id)->whereKey($event->id)->lockForUpdate()->firstOrFail();
                    $authority = $this->authority($tenant, $fresh);
                    abort_unless(! $this->source($fresh) && ($attempt->payload['authority'] ?? null) === $authority && $authority['area_authority'] === 'teams', 409, 'Event authority changed. Review this update against the current event before posting.');
                    $this->admit($tenant, $user, $fresh, true);
                    $saved = TeamOperationAttempt::where('tenant_id', $tenant->id)->whereKey($attempt->id)->lockForUpdate()->firstOrFail();
                    if (in_array($saved->status, ['succeeded', 'reviewed'], true)) {
                        return;
                    }
                    abort_unless($saved->status === 'prepared' && $saved->input_hash === $attempt->input_hash, 409, 'Review the original update before posting again.');
                    $saved->update(['status' => 'succeeded', 'result' => ['posted_at' => now()->toIso8601String()]]);
                }, 3);
            }

            return $this->receipt($tenant, $attempt->fresh());
        } finally {
            $lock->release();
        }
    }

    public function reconcile(Tenant $tenant, User $user, TeamOperationRecord $event, string $id, bool $acknowledge = false, string $reviewKind = 'source'): array
    {
        $this->admit($tenant, $user, $event, true);
        $attempt = TeamOperationAttempt::where(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'record_id' => $event->id, 'kind' => 'event.update'])->findOrFail($id);
        if ($acknowledge && $reviewKind === 'unsent') {
            $this->reviewUnsent($tenant, $user, $attempt);

            return $this->receipt($tenant, $attempt->fresh());
        }
        $child = $this->sourceAttempt($attempt);
        if ($child) {
            abort_if($acknowledge && $child->status === 'prepared', 409, 'This update has not been claimed for posting. Review the unsent draft instead.');
            $area = $this->access->area($tenant, $user, $event->area_id, true);
            $receipt = $this->tasks->reconcile($tenant, $user, $area, $child->id, $acknowledge);
            $this->mirrorSource($tenant, $attempt, $receipt, $acknowledge);
        } else {
            abort_if($acknowledge, 409, 'Review the unsent draft before starting a new update.');
        }

        return $this->receipt($tenant, $attempt->fresh());
    }
}

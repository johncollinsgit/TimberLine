<?php

namespace App\Services\Team\Operations;

use App\Models\TeamOperationAttempt;
use App\Models\TeamOperationRecord;
use App\Models\TeamProviderConnection;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Teams decisions supplement source evidence; no source fields are rewritten. */
class OperationsEnrichment
{
    public function review(Tenant $tenant, User $user, TeamOperationRecord $record, array $input): TeamOperationRecord
    {
        $access = app(OperationsAccess::class);
        $access->record($tenant, $user, $record);
        $access->area($tenant, $user, $record->area_id, true);
        if (! in_array($input['action'], ['fields', 'schedule'], true)) {
            $access->financial($tenant, $user);
        }
        if ($input['action'] === 'schedule') {
            abort_unless($record->kind === 'event', 422);
            $input['schedule'] = app(EventSchedule::class)->validate($input['schedule']);
        }
        $hash = hash('sha256', json_encode([$record->id, $input], JSON_THROW_ON_ERROR));
        $prior = TeamOperationAttempt::where(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'request_id' => $input['request_id']])->first();
        if ($prior) {
            abort_unless($prior->kind === 'record.enrich' && hash_equals($prior->input_hash, $hash), 409);

            return $record->fresh();
        }
        $sessionEvidence = [];
        $fieldEvidence = null;
        if ($input['action'] === 'fields') {
            abort_if(empty($input['field_indices']), 422, 'Choose at least one source field.');
        }
        if ($input['action'] === 'fields' && $record->kind === 'event' && $record->connection_id) {
            abort_unless(isset($record->data['source_id'], $record->data['source_workspace']) && is_string($record->data['original_notes'] ?? null), 409, 'A verified source identity and readable source notes are required to review event notes.');
            abort_unless($record->version === $input['expected_version'], 409, 'The record changed. Review current source evidence first.');
            abort_unless(is_string($input['expected_fields_revision'] ?? null) && hash_equals(app(OperationsSync::class)->eventFieldsRevision($record->data), $input['expected_fields_revision']), 409, 'Field candidates changed. Refresh and review the current notes.');
            $detail = app(EventTasks::class)->forOccurrence($tenant, $user, $record);
            abort_unless($detail && $detail['source_mode'] === 'live' && ! empty($record->data['source_version']) && ($detail['modified_at'] ?? null) === $record->data['source_version'], 409, 'The source changed or is unavailable. Refresh and review the current notes.');
            $connection = TeamProviderConnection::query()->where(['id' => $detail['connection_id'], 'tenant_id' => $tenant->id, 'area_id' => $record->area_id, 'user_id' => $user->id, 'status' => 'connected'])->firstOrFail();
            $candidates = app(OperationsSync::class)->eventFields($record->data);
            $live = app(OperationsSync::class)->extract($detail['notes'] ?? '');
            foreach ($input['field_indices'] as $index) {
                $candidate = $candidates[$index] ?? null;
                $current = $live[$index] ?? null;
                abort_unless($candidate && $current && array_intersect_key($candidate, array_flip(['field', 'value', 'passage'])) === array_intersect_key($current, array_flip(['field', 'value', 'passage'])), 409, 'The selected source passage changed. Refresh and review the current notes.');
            }
            $fieldEvidence = ['fields' => $candidates, 'source_version' => $record->data['source_version'], 'connection_id' => $connection->id, 'generation' => $connection->generation];
        }
        if ($input['action'] === 'schedule') {
            $source = ! empty($input['schedule']['evidence_selections']) ? app(EventTasks::class)->calendarEvidence($tenant, $user, $record, $input['schedule']['evidence_selections']) : null;
            foreach ($input['schedule']['sessions'] ?? [] as $session) {
                $evidence = ['session_id' => $session['id'], 'status' => 'unconfirmed', 'reviewed_by' => (string) $user->id, 'reviewed_at' => now()->toIso8601String()];
                $selection = collect($input['schedule']['evidence_selections'] ?? [])->firstWhere('session_id', $session['id']);
                $attestation = collect($input['schedule']['attestations'] ?? [])->firstWhere('session_id', $session['id']);
                if ($selection) {
                    $candidate = collect($source['candidates'])->firstWhere('id', $selection['evidence_id']);
                    abort_unless($candidate && $candidate['kind'] === $session['kind'] && in_array($session['date'], $candidate['dates'], true) && ! $source['issues'], 409, 'The selected source passage changed or does not verify this session kind and date. Refresh the source or record a staff attestation.');
                    $evidence = array_replace($evidence, array_intersect_key($candidate, array_flip(['task_gid', 'source_version', 'source_modified_at', 'field', 'section', 'passage', 'source_story_id', 'year_context', 'year_evidence', 'range_context'])), ['status' => 'source_verified_at_review', 'verified_fields' => ['kind', 'date'], 'hours_verified' => false, 'evidence_id' => $candidate['id'], 'url' => $source['source_url']]);
                } elseif ($attestation) {
                    $evidence = array_replace($evidence, ['status' => 'staff_attested', 'passage' => $attestation['passage'], 'url' => $attestation['url'] ?? null]);
                }
                $sessionEvidence[] = $evidence;
            }
        }

        return DB::transaction(function () use ($tenant, $user, $record, $input, $hash, $sessionEvidence, $fieldEvidence) {
            DB::table('team_work_locks')->insertOrIgnore(['tenant_id' => $tenant->id]);
            DB::table('team_work_locks')->where('tenant_id', $tenant->id)->lockForUpdate()->first();
            $receipt = TeamOperationAttempt::where(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'request_id' => $input['request_id']])->first();
            if ($receipt) {
                abort_unless($receipt->kind === 'record.enrich' && hash_equals($receipt->input_hash, $hash), 409);

                return $record->fresh();
            }
            $record = TeamOperationRecord::whereKey($record->id)->lockForUpdate()->firstOrFail();
            abort_unless($record->version === $input['expected_version'], 409, 'The record changed. Review current source evidence first.');
            $access = app(OperationsAccess::class);
            $access->area($tenant, $user, $record->area_id, true);
            $data = $record->data;
            if ($input['action'] === 'schedule') {
                $data['_teams_enrichment']['calendar'] = $input['schedule'] + ['session_evidence' => $sessionEvidence, 'reviewed_by' => (string) $user->id, 'reviewed_at' => now()->toIso8601String(), 'source_version' => $data['source_version'] ?? null];
            } elseif ($input['action'] === 'fields') {
                abort_unless(! isset($data['reviewed_fields']) || is_array($data['reviewed_fields']), 409, 'Stored reviewed fields need repair before approving notes.');
                if ($fieldEvidence) {
                    $connection = TeamProviderConnection::query()->where(['id' => $fieldEvidence['connection_id'], 'tenant_id' => $tenant->id, 'area_id' => $record->area_id, 'user_id' => $user->id, 'status' => 'connected'])->lockForUpdate()->first();
                    abort_unless($connection && $connection->generation === $fieldEvidence['generation'] && ($data['source_version'] ?? null) === $fieldEvidence['source_version'] && app(OperationsSync::class)->eventFields($data) === $fieldEvidence['fields'], 409, 'The source or selection changed. Refresh and review the current notes.');
                    $data['fields'] = $fieldEvidence['fields'];
                }
                $allowed = ['application_status', 'acceptance_status', 'fee', 'staff', 'venue', 'address', 'setup_time', 'event_hours', 'email', 'phone', 'booth', 'booth_number', 'booth_size', 'attendance', 'instructions', 'contact', 'last_order_on', 'last_contact_on', 'shipping', 'custom_labels', 'custom_scents', 'website'];
                foreach ($input['field_indices'] as $index) {
                    $candidate = $data['fields'][$index] ?? null;
                    abort_unless($candidate && in_array($candidate['field'], $allowed, true), 422);
                    abort_if(array_key_exists($candidate['field'], $data['reviewed_fields'] ?? []) && $data['reviewed_fields'][$candidate['field']] !== $candidate['value'], 409, 'A different reviewed value already exists. Keep it until an explicit correction is reviewed.');
                    if (($candidate['review_state'] ?? null) === 'approved' && array_key_exists($candidate['field'], $data['reviewed_fields'] ?? [])) {
                        continue;
                    }
                    $candidate['review_state'] = 'approved';
                    $candidate['reviewed_by'] = $user->id;
                    $candidate['reviewed_at'] = now()->toIso8601String();
                    $candidate['source_version'] = $data['source_version'] ?? null;
                    $data['fields'][$index] = $candidate;
                    $data['reviewed_fields'][$candidate['field']] = $candidate['value'];
                    $data['_teams_enrichment']['field_reviews'][] = $candidate;
                }
            } elseif ($input['action'] === 'forecast') {
                abort_unless($record->kind === 'event' && $input['low_cents'] <= $input['base_cents'] && $input['base_cents'] <= $input['high_cents'], 422);
                $data['_teams_enrichment']['forecast_override'] = ['profit_cents' => ['low' => $input['low_cents'], 'base' => $input['base_cents'], 'high' => $input['high_cents']], 'reason' => $input['reason'], 'reviewed_by' => $user->id, 'reviewed_at' => now()->toIso8601String(), 'source_version' => $data['source_version'] ?? null, 'starts_on' => $record->starts_on?->toDateString(), 'ends_on' => $record->ends_on?->toDateString()];
            } else {
                abort_unless($input['action'] === 'square' && $record->kind === 'event', 422);
                $ids = array_values(array_unique($input['order_ids']));
                abort_unless(\App\Models\SquareOrder::forTenantId($tenant->id)->whereIn('square_order_id', $ids)->count() === count($ids), 422, 'Choose orders owned by this tenant.');
                $data['_teams_enrichment']['square_orders'] = ['ids' => $ids, 'reviewed_by' => $user->id, 'reviewed_at' => now()->toIso8601String()];
            }
            $record->update(['data' => $data, 'version' => $record->version + 1]);
            app(OperationsWorkspace::class)->revision($record, $user->id);
            TeamOperationAttempt::create(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'area_id' => $record->area_id, 'user_id' => $user->id, 'record_id' => $record->id, 'kind' => 'record.enrich', 'status' => 'accepted', 'request_id' => $input['request_id'], 'input_hash' => $hash, 'payload' => $input, 'result' => ['version' => $record->version]]);

            return $record;
        }, 3);
    }
}

<?php

namespace App\Enterprise;

use App\Models\TeamOperationAttempt;
use App\Models\TeamOperationRecord;
use App\Models\Tenant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/** Private managerial metadata and derived attention. Never validates coverage or sends reminders. */
class ContractorCertificates
{
    public const KIND = 'ent_contractor_cert';

    public const INPUTS = ['action', 'id', 'area_id', 'request_id', 'expected_version', 'contractor_id', 'title',
        'renewal_contact', 'warning_lead_days', 'review_due_on', 'replacement_id', 'document_reference', 'expires_on', 'decision', 'reason', 'confirmed'];

    public const TIMEZONE = 'America/New_York';

    public function __construct(private WorkspaceAccess $access, private RecordStore $store, private Planning $planning) {}

    private function timezone(): string
    {
        $timezone = config('enterprise.certificates_timezone', self::TIMEZONE);
        abort_unless(is_string($timezone) && in_array($timezone, \DateTimeZone::listIdentifiers(\DateTimeZone::ALL_WITH_BC), true), 503, 'The certificate calendar timezone is not configured correctly.');

        return $timezone;
    }

    private function actor(Tenant $tenant, User $user, bool $write = false): array
    {
        abort_unless(config('enterprise.certificates_enabled'), 404);

        return $this->access->actor($tenant, $user, $write);
    }

    private function area(Tenant $tenant, array $actor, string $areaId, bool $write = false): void
    {
        $this->access->area($tenant, $actor, $areaId);
        // Managerial READ is independent of the writes-paused switch, including shared areas.
        abort_unless(in_array($actor['role'], ['owner', 'admin', 'member'], true)
            && WorkspaceAccess::permits($actor, $areaId, $write, true), 404);
    }

    private function row(Tenant $tenant, array $actor, string $id): TeamOperationRecord
    {
        $row = TeamOperationRecord::query()->where(['tenant_id' => $tenant->id, 'kind' => self::KIND])->findOrFail($id);
        $this->area($tenant, $actor, $row->area_id);

        return $row;
    }

    private function contractor(Tenant $tenant, array $actor, string $areaId, string $id): TeamOperationRecord
    {
        $row = $this->planning->record($tenant, $actor, 'contractor', $id);
        abort_unless($row->area_id === $areaId, 404);

        return $row;
    }

    /** Labels describe dates and missing metadata, not insurance validity or permission to work. */
    private function attention(array $data, string $status): array
    {
        if ($status === 'archived') {
            return ['reminder_reasons' => [], 'expiry_state' => 'archived'];
        }
        $today = CarbonImmutable::now($this->timezone())->startOfDay();
        $accepted = $data['accepted'] ?? null;
        $reasons = [];
        if (! $accepted) {
            $reasons[] = 'unreviewed';
        } elseif ($data['pending'] ?? null) {
            $reasons[] = 'awaiting_replacement_review';
        }
        if (empty($accepted['document_reference'])) {
            $reasons[] = 'document_reference_missing';
        }
        if (empty($data['renewal_contact'])) {
            $reasons[] = 'renewal_contact_missing';
        }
        if (($data['warning_lead_days'] ?? null) === null) {
            $reasons[] = 'warning_not_configured';
        }
        if (empty($data['review_due_on'])) {
            $reasons[] = 'review_not_scheduled';
        } elseif ($data['review_due_on'] <= $today->toDateString()) {
            $reasons[] = 'review_due';
        }
        $expiryState = $accepted ? 'unknown' : 'unreviewed';
        if (empty($accepted['expires_on'])) {
            $reasons[] = 'expiry_unknown';
        } else {
            $expiry = CarbonImmutable::createFromFormat('!Y-m-d', $accepted['expires_on'], $this->timezone());
            $expiryState = 'outside_warning';
            if ($expiry->lt($today)) {
                $reasons[] = $expiryState = 'expired';
            } elseif ($expiry->equalTo($today)) {
                $reasons[] = $expiryState = 'expires_today';
            } elseif (($data['warning_lead_days'] ?? null) === null) {
                $expiryState = 'not_configured';
            } elseif ($expiry->lte($today->addDays($data['warning_lead_days']))) {
                $reasons[] = 'expiry_warning';
                $expiryState = 'warning';
            }
        }

        return ['reminder_reasons' => $reasons, 'expiry_state' => $expiryState];
    }

    private function dto(Tenant $tenant, array $actor, TeamOperationRecord $row): array
    {
        $this->area($tenant, $actor, $row->area_id);
        $data = $row->data;
        $contractor = $this->contractor($tenant, $actor, $row->area_id, $data['contractor_id']);

        return ['id' => $row->id, 'area_id' => $row->area_id, 'title' => $row->title, 'status' => $row->status, 'version' => $row->version,
            'contractor_id' => $contractor->id, 'contractor_title' => $contractor->title, 'contractor_status' => $contractor->status,
            'renewal_contact' => $data['renewal_contact'], 'warning_lead_days' => $data['warning_lead_days'], 'review_due_on' => $data['review_due_on'],
            'accepted' => $data['accepted'], 'pending' => $data['pending'], 'last_review' => $data['last_review'],
            ...$this->attention($data, $row->status), 'can_manage' => $row->status !== 'archived' && WorkspaceAccess::permits($actor, $row->area_id, true, true),
            'as_of_on' => CarbonImmutable::now($this->timezone())->toDateString(), 'timezone' => $this->timezone(),
            'updated_at' => $row->updated_at?->toIso8601String()];
    }

    public function show(Tenant $tenant, User $user, string $id): array
    {
        $actor = $this->actor($tenant, $user);

        return $this->dto($tenant, $actor, $this->row($tenant, $actor, $id));
    }

    public function listing(Tenant $tenant, User $user, string $areaId, ?string $contractorId = null): array
    {
        return $this->projection($tenant, $user, $areaId, $contractorId, false);
    }

    public function reminders(Tenant $tenant, User $user, string $areaId): array
    {
        return $this->projection($tenant, $user, $areaId, null, true);
    }

    private function projection(Tenant $tenant, User $user, string $areaId, ?string $contractorId, bool $attentionOnly): array
    {
        $actor = $this->actor($tenant, $user);
        $this->area($tenant, $actor, $areaId);
        if ($contractorId !== null) {
            $this->contractor($tenant, $actor, $areaId, $contractorId);
        }
        $items = [];
        foreach (TeamOperationRecord::query()->where(['tenant_id' => $tenant->id, 'area_id' => $areaId, 'kind' => self::KIND])
            ->orderBy('title')->orderBy('id')->limit(1000)->cursor() as $row) {
            if ($contractorId !== null && ($row->data['contractor_id'] ?? null) !== $contractorId) {
                continue;
            }
            try {
                $dto = $this->dto($tenant, $actor, $row);
            } catch (\Symfony\Component\HttpKernel\Exception\HttpException|\Illuminate\Database\Eloquent\ModelNotFoundException $error) {
                if ($error instanceof \Symfony\Component\HttpKernel\Exception\HttpException && $error->getStatusCode() !== 404) {
                    throw $error;
                }

                continue;
            }
            if ($attentionOnly && $dto['reminder_reasons'] === []) {
                continue;
            }
            $items[] = $dto;
            if (count($items) > 200) {
                break;
            }
        }
        $contacts = TeamOperationRecord::query()->where(['tenant_id' => $tenant->id, 'area_id' => $areaId, 'kind' => Planning::KINDS['contractor']])
            ->orderBy('title')->orderBy('id')->limit(201)->get(['id', 'title', 'status']);
        $visible = array_slice($items, 0, 200);

        return ['items' => $visible, 'counts' => ['returned' => count($visible), 'attention' => count(array_filter($visible, fn ($item) => $item['reminder_reasons'] !== []))],
            'contractors' => $contacts->take(200)->map(fn ($contact) => ['id' => $contact->id, 'title' => $contact->title, 'status' => $contact->status])->values()->all(),
            'has_more_contractors' => $contacts->count() > 200, 'has_more' => count($items) > 200, 'partial' => true,
            'as_of_on' => CarbonImmutable::now($this->timezone())->toDateString(), 'timezone' => $this->timezone(),
            'can_manage' => WorkspaceAccess::permits($actor, $areaId, true, true),
            'coverage' => 'Private managerial metadata only: up to 200 matching authorized certificates from a scan of at most 1000, and up to 200 same-workspace contractor contacts. Counts describe returned records only. Reminder reasons are derived at read time; no sends, scheduler, coverage determination or permission to work.'];
    }

    public function history(Tenant $tenant, User $user, string $id): array
    {
        $this->show($tenant, $user, $id);
        $rows = DB::table('team_operation_revisions')->where(['tenant_id' => $tenant->id, 'record_id' => $id])->orderByDesc('version')->limit(100)->get();

        return ['items' => $rows->map(fn ($row) => ['version' => $row->version, 'actor_id' => $row->user_id, 'at' => $row->created_at,
            'event' => json_decode(Crypt::decryptString($row->payload), true, 64, JSON_THROW_ON_ERROR)])->all(),
            'coverage' => 'Latest 100 encrypted certificate revisions; current same-workspace manager authority is required.'];
    }

    private function validate(array $input): array
    {
        $rules = ['action' => ['required', 'in:certificate.propose,certificate.metadata,certificate.review,certificate.archive'],
            'id' => ['required', 'uuid'], 'area_id' => ['required', 'uuid'], 'request_id' => ['required', 'uuid'],
            'expected_version' => ['required', 'integer', 'min:0']];
        $rules += match ($input['action'] ?? '') {
            'certificate.propose' => ['replacement_id' => ['required', 'uuid'], 'contractor_id' => ['sometimes', 'uuid'], 'title' => ['sometimes', 'string', 'min:1', 'max:150'],
                'renewal_contact' => ['sometimes', 'nullable', 'string', 'max:500'], 'warning_lead_days' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:3650'],
                'review_due_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'], 'document_reference' => ['present', 'nullable', 'string', 'max:500'], 'expires_on' => ['present', 'nullable', 'date_format:Y-m-d']],
            'certificate.metadata' => ['title' => ['required', 'string', 'max:150'], 'renewal_contact' => ['present', 'nullable', 'string', 'max:500'],
                'warning_lead_days' => ['present', 'nullable', 'integer', 'min:0', 'max:3650'], 'review_due_on' => ['present', 'nullable', 'date_format:Y-m-d']],
            'certificate.review' => ['replacement_id' => ['required', 'uuid'], 'decision' => ['required', 'in:accept,reject'],
                'reason' => ['required', 'string', 'max:1000'], 'confirmed' => ['required', 'accepted']],
            'certificate.archive' => ['reason' => ['required', 'string', 'max:1000'], 'confirmed' => ['required', 'accepted']],
            default => [],
        };
        abort_unless(array_diff(array_keys($input), array_keys($rules)) === [], 422, 'Unexpected certificate command fields.');
        $input = Validator::make($input, $rules)->validate();
        $input['expected_version'] = (int) $input['expected_version'];
        if (isset($input['warning_lead_days'])) {
            $input['warning_lead_days'] = (int) $input['warning_lead_days'];
        }
        if (isset($input['confirmed'])) {
            $input['confirmed'] = true;
        }
        foreach (['title', 'reason'] as $requiredText) {
            abort_if(isset($input[$requiredText]) && trim($input[$requiredText]) === '', 422, 'Enter nonblank certificate text.');
        }

        return $input;
    }

    public function execute(Tenant $tenant, User $user, array $input): array
    {
        $this->actor($tenant, $user, true);
        $input = $this->validate($input);

        return $this->store->locked($tenant, function () use ($tenant, $user, $input): array {
            $actor = $this->actor($tenant, $user, true);
            $this->area($tenant, $actor, $input['area_id'], true);
            $exists = TeamOperationRecord::query()->whereKey($input['id'])->exists();
            $row = $exists ? $this->row($tenant, $actor, $input['id']) : null;
            abort_if($row && $row->area_id !== $input['area_id'], 404);
            abort_unless($row || $input['action'] === 'certificate.propose', 404);
            $data = $row?->data;
            $contractorId = $data['contractor_id'] ?? $input['contractor_id'] ?? null;
            abort_unless($contractorId, 422, 'Choose an existing same-workspace contractor.');
            abort_if(isset($input['contractor_id']) && $input['contractor_id'] !== $contractorId, 404);
            $contractor = $this->contractor($tenant, $actor, $input['area_id'], $contractorId);
            $hash = hash('sha256', json_encode($input, JSON_THROW_ON_ERROR));
            $receipt = TeamOperationAttempt::query()->where(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'request_id' => $input['request_id']])->first();
            if ($receipt) {
                abort_unless($receipt->kind === self::KIND && $receipt->record_id === $row?->id && hash_equals($receipt->input_hash, $hash), 409);

                return $this->dto($tenant, $actor, $row) + ['replayed' => true];
            }
            abort_unless(($row?->version ?? 0) === $input['expected_version'], 409, 'This certificate changed. Review the current record before saving.');
            abort_if($row?->status === 'archived', 409, 'Archived certificates preserve their history and cannot be edited.');
            $before = $row ? ['title' => $row->title, 'status' => $row->status, 'data' => $data] : null;
            if (! $row) {
                abort_unless(isset($input['title']) && trim($input['title']) !== '', 422, 'Enter a certificate record title.');
                $row = new TeamOperationRecord(['id' => $input['id'], 'tenant_id' => $tenant->id, 'area_id' => $input['area_id'], 'kind' => self::KIND,
                    'title' => trim($input['title']), 'status' => 'active', 'version' => 0, 'visibility' => 'manager', 'source_key' => hash('sha256', 'contractor-certificate:'.$input['id'])]);
                $data = ['contractor_id' => $contractorId, 'renewal_contact' => trim($input['renewal_contact'] ?? '') ?: null,
                    'warning_lead_days' => $input['warning_lead_days'] ?? null, 'review_due_on' => $input['review_due_on'] ?? null,
                    'accepted' => null, 'pending' => null, 'last_review' => null];
            }
            $now = now()->utc()->toIso8601String();
            if ($input['action'] === 'certificate.propose') {
                abort_unless($contractor->status === 'active', 422, 'New proposals require an active existing contractor contact.');
                abort_if($data['pending'] !== null, 409, 'Review or reject the pending replacement before proposing another.');
                if ($row->exists) {
                    abort_if(array_intersect(array_keys($input), ['title', 'renewal_contact', 'warning_lead_days', 'review_due_on']) !== [], 422, 'Use the metadata command to change reminder settings.');
                }
                abort_if(in_array($input['replacement_id'], array_filter([$data['accepted']['replacement_id'] ?? null, $data['last_review']['replacement_id'] ?? null]), true), 409, 'Use a new replacement identity.');
                $data['pending'] = ['replacement_id' => $input['replacement_id'], 'document_reference' => trim($input['document_reference'] ?? '') ?: null,
                    'expires_on' => $input['expires_on'], 'proposed_by' => (string) $user->id, 'proposed_at' => $now];
            } elseif ($input['action'] === 'certificate.metadata') {
                $row->title = trim($input['title']);
                $data = array_replace($data, ['renewal_contact' => trim($input['renewal_contact'] ?? '') ?: null,
                    'warning_lead_days' => $input['warning_lead_days'], 'review_due_on' => $input['review_due_on']]);
            } elseif ($input['action'] === 'certificate.review') {
                abort_unless($data['pending'] && $data['pending']['replacement_id'] === $input['replacement_id'], 409, 'This pending replacement changed.');
                if ($input['decision'] === 'accept') {
                    abort_unless($data['pending']['expires_on'] !== null && $data['pending']['document_reference'] !== null, 422, 'Record an expiry date and private document reference before accepting replacement metadata.');
                    $data['accepted'] = $data['pending'] + ['reviewed_by' => (string) $user->id, 'reviewed_at' => $now, 'review_reason' => trim($input['reason'])];
                }
                $data['last_review'] = ['replacement_id' => $input['replacement_id'], 'decision' => $input['decision'], 'reason' => trim($input['reason']),
                    'reviewed_by' => (string) $user->id, 'reviewed_at' => $now];
                $data['pending'] = null;
            } elseif ($input['action'] === 'certificate.archive') {
                $row->status = 'archived';
                $data['archive'] = ['reason' => trim($input['reason']), 'by' => (string) $user->id, 'at' => $now];
            }
            $row->data = $data;
            $row->ends_on = $data['accepted']['expires_on'] ?? null;
            $row->starts_on = $data['review_due_on'];
            $row->version++;
            $row->save();
            $this->store->audit($row, $user->id, $input['action'], ['before' => $before,
                'after' => ['title' => $row->title, 'status' => $row->status, 'data' => $data]]);
            TeamOperationAttempt::query()->create(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'area_id' => $row->area_id,
                'record_id' => $row->id, 'user_id' => $user->id, 'kind' => self::KIND, 'status' => 'accepted', 'request_id' => $input['request_id'],
                'input_hash' => $hash, 'result' => ['id' => $row->id]]);

            return $this->dto($tenant, $actor, $row);
        });
    }
}

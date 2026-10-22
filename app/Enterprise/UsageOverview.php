<?php

namespace App\Enterprise;

use App\Models\TeamArea;
use App\Models\TeamOperationRecord;
use App\Models\Tenant;
use App\Models\User;

/** Read-only aggregates from the existing private ledger. Never an invoice, quota or account spend report. */
class UsageOverview
{
    public const SCAN_LIMIT = 1000;

    public const DEPARTMENT_LIMIT = 200;

    private const PURPOSES = ['ask' => 'Ask Everbranch', 'briefing' => 'Project briefing', 'workflow_draft' => 'Workflow draft', 'other' => 'Unclassified purpose'];

    public function __construct(private WorkspaceAccess $access, private Intelligence $intelligence) {}

    private function actor(Tenant $tenant, User $user): array
    {
        abort_unless(config('enterprise.operations_enabled'), 404);
        $actor = $this->access->actor($tenant, $user);
        abort_unless(Planning::financial($actor), 404);

        return $actor;
    }

    private function emptyTotals(): array
    {
        return ['requests' => 0, 'completed' => 0, 'reserved' => 0, 'uncertain' => 0, 'cancelled' => 0, 'unreconciled' => 0,
            'input_tokens' => 0, 'output_tokens' => 0, 'reported_token_records' => 0, 'token_unknown_records' => 0,
            'original_reserved_microusd' => 0, 'original_reservation_unknown_records' => 0, 'settled_usage_derived_microusd' => 0, 'reserved_exposure_microusd' => 0,
            'uncertain_reserved_microusd' => 0, 'estimated_total_exposure_microusd' => 0, 'exposure_unknown_records' => 0];
    }

    private function quantity(mixed $value): ?int
    {
        return is_int($value) && $value >= 0 && $value <= 1000000000 ? $value : null;
    }

    private function contribution(string $status, array $data): array
    {
        $out = $this->emptyTotals();
        $out['requests'] = 1;
        $reserved = $this->quantity($data['reserved_microusd'] ?? null);
        $charged = $this->quantity($data['charged_microusd'] ?? null);
        $input = $this->quantity($data['input_tokens'] ?? null);
        $output = $this->quantity($data['output_tokens'] ?? null);
        $out['original_reserved_microusd'] = $reserved ?? 0;
        $out['original_reservation_unknown_records'] = $reserved === null ? 1 : 0;
        if ($status === 'completed' && $input !== null && $output !== null) {
            $out['input_tokens'] = $input;
            $out['output_tokens'] = $output;
            $out['reported_token_records'] = 1;
        } else {
            $out['token_unknown_records'] = 1;
        }
        if ($status === 'completed' && $charged !== null && $input !== null && $output !== null) {
            $out['completed'] = 1;
            $out['settled_usage_derived_microusd'] = $charged;
            $out['estimated_total_exposure_microusd'] = $charged;
        } elseif ($status === 'cancelled' && $charged === 0) {
            $out['cancelled'] = 1;
        } else {
            $category = in_array($status, ['reserved', 'uncertain'], true) ? $status : 'unreconciled';
            $out[$category] = 1;
            // Malformed/unknown outcomes remain conservative, never silently settled at zero.
            $exposure = $category === 'unreconciled' ? ($reserved === null && $charged === null ? null : max($reserved ?? 0, $charged ?? 0)) : $reserved;
            $out['reserved_exposure_microusd'] = $exposure ?? 0;
            $out['estimated_total_exposure_microusd'] = $exposure ?? 0;
            $out['exposure_unknown_records'] = $exposure === null ? 1 : 0;
            if ($status === 'uncertain') {
                $out['uncertain_reserved_microusd'] = $reserved ?? 0;
            }
        }

        return $out;
    }

    private function add(array &$totals, array $contribution): void
    {
        foreach ($contribution as $key => $value) {
            $totals[$key] += $value;
        }
    }

    public function build(Tenant $tenant, User $user, ?string $areaId = null): array
    {
        $actor = $this->actor($tenant, $user);
        if ($areaId !== null) {
            $this->access->area($tenant, $actor, $areaId);
        }
        $areas = TeamArea::query()->where('tenant_id', $tenant->id)->whereIn('profile', WorkspaceAccess::PROFILES)
            ->when($areaId !== null, fn ($query) => $query->whereKey($areaId))->orderBy('id')->limit(self::DEPARTMENT_LIMIT + 1)->get()
            ->filter(fn ($area) => WorkspaceAccess::permits($actor, $area->id));
        $moreDepartments = $areas->count() > self::DEPARTMENT_LIMIT;
        $areaIds = $areas->take(self::DEPARTMENT_LIMIT)->pluck('id')->all();
        $rows = TeamOperationRecord::query()->where(['tenant_id' => $tenant->id, 'kind' => 'ent_ai_usage'])->whereIn('area_id', $areaIds)
            ->orderByDesc('created_at')->orderBy('id')->limit(self::SCAN_LIMIT + 1)->get(['area_id', 'status', 'data']);
        $moreRecords = $rows->count() > self::SCAN_LIMIT;

        // Recheck current authority after reading encrypted rows, before exposing even totals.
        $actor = $this->actor($tenant, $user);
        if ($areaId !== null) {
            $this->access->area($tenant, $actor, $areaId);
        }
        $currentAreas = TeamArea::query()->where('tenant_id', $tenant->id)->whereIn('id', $areaIds)->whereIn('profile', WorkspaceAccess::PROFILES)->orderBy('name')->orderBy('id')->get()
            ->filter(fn ($area) => WorkspaceAccess::permits($actor, $area->id));
        abort_unless(array_diff($areaIds, $currentAreas->pluck('id')->all()) === [], 409, 'The authorized workspace scope changed. Reload the current usage view.');
        $departments = [];
        foreach ($currentAreas as $area) {
            $departments[$area->id] = ['id' => $area->id, 'name' => $area->name, 'totals' => $this->emptyTotals(), 'purposes' => []];
        }
        $totals = $this->emptyTotals();
        foreach ($rows->take(self::SCAN_LIMIT) as $row) {
            if (! isset($departments[$row->area_id])) {
                continue;
            }
            $data = is_array($row->data) ? $row->data : [];
            $purpose = in_array($data['purpose'] ?? null, ['ask', 'briefing', 'workflow_draft'], true) ? $data['purpose'] : 'other';
            $contribution = $this->contribution($row->status, $data);
            $departments[$row->area_id]['purposes'][$purpose] ??= ['purpose' => $purpose, 'label' => self::PURPOSES[$purpose], 'totals' => $this->emptyTotals()];
            $this->add($departments[$row->area_id]['purposes'][$purpose]['totals'], $contribution);
            $this->add($departments[$row->area_id]['totals'], $contribution);
            $this->add($totals, $contribution);
        }
        foreach ($departments as &$department) {
            ksort($department['purposes']);
            $department['purposes'] = array_values($department['purposes']);
        }
        unset($department);
        $readiness = $this->intelligence->status($tenant);

        return ['scope' => ['type' => $areaId === null ? 'authorized_departments' : 'department', 'area_id' => $areaId],
            'as_of' => now()->utc()->toIso8601String(), 'currency' => 'USD', 'money_unit' => 'microusd',
            'ledger_state' => $totals['requests'] === 0 ? 'no_records_in_scope' : 'records_in_scope', 'totals' => $totals, 'departments' => array_values($departments),
            'coverage' => ['record_scan_limit' => self::SCAN_LIMIT, 'scanned_records' => min($rows->count(), self::SCAN_LIMIT),
                'department_limit' => self::DEPARTMENT_LIMIT, 'returned_departments' => count($departments), 'has_more_records' => $moreRecords,
                'has_more_departments' => $moreDepartments, 'partial' => $moreRecords || $moreDepartments, 'period' => 'all_recorded_time_within_bounded_scan'],
            'technical_budget' => ['ready' => (bool) $readiness['ready'], 'status' => $readiness['status'], 'provider_calls_verified' => false],
            'provider_invoice_verified' => false, 'read_only' => true,
            'basis' => ['source' => 'Existing permission-filtered ent_ai_usage ledger; no provider account or billing query.',
                'settled_cost' => 'Derived from recorded provider-reported input/output token usage and request-time configured rates; not a verified provider invoice.',
                'reserved_exposure' => 'Known maximum cost retained for pending, uncertain or unreconciled records; unknown exposure is separately counted.',
                'tokens' => 'Only recorded completed-request input/output tokens are summed. Missing, pending and uncertain usage is unknown; cached tokens are not persisted in this ledger.',
                'zero_records' => 'No ledger records in this checked scope is not proof of zero provider-account spend.',
                'commercial' => 'Existing technical readiness only. No college price, billing quota, subscription, new cap or allowance is set.',
                'snapshot' => 'Sources are read during generation, not reconstructed as an atomic historical account statement.']];
    }
}

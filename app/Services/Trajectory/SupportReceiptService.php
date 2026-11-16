<?php

namespace App\Services\Trajectory;

use App\Models\Trajectory\Space;
use Illuminate\Support\Collection;

class SupportReceiptService
{
    /** Summarize scoped cash receipts only; mappings never establish complete coverage. */
    public function build(Space $household, Collection $entries, Collection $records, array $authorizedBusinessIds): array
    {
        $result = [
            'amount_cents' => null, 'payroll_cents' => 0, 'draws_cents' => 0, 'other_cents' => 0,
            'evidence_ids' => [], 'source_record_ids' => [], 'business_totals' => [],
            'unattributed_ids' => [], 'missing' => [],
        ];
        $householdId = $this->positiveId($household->id);
        if ($household->kind !== 'household' || $householdId === null) {
            $result['missing'][] = 'Family receipts require an authorized household space.';

            return $result;
        }

        $authorized = [];
        foreach ($authorizedBusinessIds as $id) {
            if (($id = $this->positiveId($id)) !== null) {
                $authorized[$id] = true;
            }
        }
        $sources = [];
        $businesses = [];
        foreach ($records as $record) {
            $data = data_get($record, 'data');
            $recordId = $this->positiveId(data_get($record, 'id'));
            $businessId = is_array($data) ? $this->positiveId($data['business_space_id'] ?? null) : null;
            if (data_get($record, 'kind') !== 'company_support_source'
                || data_get($record, 'active') !== true
                || $this->positiveId(data_get($record, 'space_id')) !== $householdId
                || ! is_array($data) || ($data['confirmed'] ?? false) !== true
                || $recordId === null || $businessId === null || ! isset($authorized[$businessId])
                || ! in_array($data['source_type'] ?? null, ['category', 'merchant'], true)
                || ! in_array($data['support_kind'] ?? null, ['net_payroll', 'owner_draw', 'other_support'], true)
                || ! is_string($data['source_value'] ?? null) || trim($data['source_value']) === '') {
                continue;
            }
            $sources[] = [
                'id' => $recordId, 'business_id' => $businessId, 'type' => $data['source_type'],
                'value' => $data['source_type'] === 'merchant' ? ClassificationService::merchant($data['source_value']) : $data['source_value'],
                'support_kind' => $data['support_kind'],
            ];
            $result['source_record_ids'][] = $recordId;
            $businesses[$businessId] ??= [
                'business_space_id' => $businessId, 'amount_cents' => 0,
                'payroll_cents' => 0, 'draws_cents' => 0, 'other_cents' => 0,
                'evidence_ids' => [], 'source_record_ids' => [],
            ];
            $businesses[$businessId]['source_record_ids'][] = $recordId;
        }
        if ($sources) {
            $result['amount_cents'] = 0;
        } else {
            $result['missing'][] = 'Confirm receipt sources for a linked business before attributing family support.';
        }

        // The caller supplies its selected period. Foreign rows are discarded before
        // collecting IDs so accidental mixed-space input cannot expose their evidence.
        $scoped = $entries->filter(fn ($entry) => is_array($entry)
            && (! array_key_exists('space_id', $entry) || $this->positiveId($entry['space_id']) === $householdId)
            && $this->positiveId($entry['id'] ?? null) !== null);
        foreach ($scoped->groupBy(fn ($entry) => (int) $entry['id']) as $id => $rows) {
            $eligible = $rows->filter(fn ($entry) => ($entry['reviewed'] ?? false) === true
                && is_int($entry['amount_cents'] ?? null) && $entry['amount_cents'] > 0
                && in_array($entry['flow'] ?? null, ['income', 'owner_wages', 'owner_distribution'], true)
                && ! ($entry['pending'] ?? false) && ! ($entry['removed'] ?? false));
            if ($eligible->isEmpty()) {
                continue;
            }
            $id = (int) $id;
            $signatures = $rows->map(fn ($entry) => [
                $entry['amount_cents'] ?? null, $entry['flow'] ?? null, $entry['reviewed'] ?? false,
                $entry['category'] ?? null, ClassificationService::merchant((string) ($entry['merchant'] ?? '')),
                (bool) ($entry['pending'] ?? false), (bool) ($entry['removed'] ?? false),
            ])->uniqueStrict();
            if ($signatures->count() > 1) {
                $result['unattributed_ids'][] = $id;
                $result['missing'][] = 'Repeated receipt evidence disagrees and needs reconciliation before attribution.';

                continue;
            }
            $entry = $eligible->first();
            $merchant = ClassificationService::merchant((string) ($entry['merchant'] ?? ''));
            $matches = collect($sources)->filter(fn ($source) => $source['type'] === 'category'
                ? $source['value'] === ($entry['category'] ?? null)
                : $source['value'] === $merchant);
            if ($matches->isEmpty()) {
                $result['unattributed_ids'][] = $id;

                continue;
            }
            if ($matches->pluck('business_id')->uniqueStrict()->count() > 1) {
                $result['unattributed_ids'][] = $id;
                $result['missing'][] = 'Some receipts match more than one linked business and were excluded pending source review.';

                continue;
            }
            // A specific payroll merchant can refine a generic company category,
            // but can never override attribution to a different company.
            $specific = $matches->where('type', 'merchant');
            $selected = $specific->isNotEmpty() ? $specific : $matches;
            if ($selected->pluck('support_kind')->uniqueStrict()->count() > 1) {
                $result['unattributed_ids'][] = $id;
                $result['missing'][] = 'Some receipt sources disagree about the support type and were excluded pending review.';

                continue;
            }
            $source = $selected->first();
            $bucket = match ($source['support_kind']) {
                'net_payroll' => 'payroll_cents', 'owner_draw' => 'draws_cents', default => 'other_cents',
            };
            $amount = $entry['amount_cents'];
            $result['amount_cents'] += $amount;
            $result[$bucket] += $amount;
            $result['evidence_ids'][] = $id;
            $businesses[$source['business_id']]['amount_cents'] += $amount;
            $businesses[$source['business_id']][$bucket] += $amount;
            $businesses[$source['business_id']]['evidence_ids'][] = $id;
        }
        foreach (['evidence_ids', 'source_record_ids', 'unattributed_ids'] as $key) {
            $result[$key] = $this->ids($result[$key]);
        }
        ksort($businesses, SORT_NUMERIC);
        foreach ($businesses as &$business) {
            $business['evidence_ids'] = $this->ids($business['evidence_ids']);
            $business['source_record_ids'] = $this->ids($business['source_record_ids']);
        }
        unset($business);
        $result['business_totals'] = array_values($businesses);
        $result['missing'][] = 'Receipt mappings do not establish complete company coverage or company-paid household costs.';
        $result['missing'] = array_values(array_unique($result['missing']));

        return $result;
    }

    private function positiveId(mixed $value): ?int
    {
        if (! is_int($value) && (! is_string($value) || ! ctype_digit($value))) {
            return null;
        }

        return (int) $value > 0 ? (int) $value : null;
    }

    private function ids(array $ids): array
    {
        $ids = array_values(array_unique($ids));
        sort($ids, SORT_NUMERIC);

        return $ids;
    }
}

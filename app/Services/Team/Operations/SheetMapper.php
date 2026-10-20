<?php

namespace App\Services\Team\Operations;

use Carbon\CarbonImmutable;

/** Workbook values are evidence, not instructions or a replacement payroll calculator. */
class SheetMapper
{
    public function cents(mixed $value): ?int
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }
        $text = str_replace([',', '$', ' '], '', trim((string) $value));
        if (in_array($text, ['-', '—'], true)) {
            return null;
        }
        $negative = str_starts_with($text, '(') && str_ends_with($text, ')');
        $text = trim($text, '()');
        if (! preg_match('/^(-?)(\d{1,12})(?:\.(\d{1,12}))?$/D', $text, $m)) {
            return null;
        }
        $fraction = str_pad($m[3] ?? '', 3, '0');
        $cents = (int) $m[2] * 100 + (int) substr($fraction, 0, 2) + ((int) $fraction[2] >= 5 ? 1 : 0);

        return ($negative || $m[1] === '-' ? -1 : 1) * $cents;
    }

    public function date(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_numeric($value) && (float) $value > 30000 && (float) $value < 80000) {
            return CarbonImmutable::create(1899, 12, 30, 0, 0, 0, 'UTC')->addDays((int) $value)->toDateString();
        }
        foreach (['Y-m-d', 'm.d.y', 'n/j/Y', 'n/j/y'] as $format) {
            try {
                $d = CarbonImmutable::createFromFormat('!'.$format, trim((string) $value), 'UTC');
                if ($d && $d->format($format) === trim((string) $value)) {
                    return $d->toDateString();
                }
            } catch (\Throwable) {
            }
        }

        return null;
    }

    public function rows(string $tab, array $values): array
    {
        $headers = array_map(fn ($h) => strtolower(trim((string) $h)), $values[0] ?? []);
        if (! in_array('event', $headers, true)) {
            return ['items' => [], 'errors' => ['No Event header found. Map this tab before importing.']];
        }
        $items = [];
        $errors = [];
        $seen = [];
        foreach (array_slice($values, 1) as $index => $row) {
            $r = array_combine($headers, array_pad(array_slice($row, 0, count($headers)), count($headers), null));
            $name = trim((string) ($row[0] ?? ''));
            $title = trim((string) ($r['event'] ?? ''));
            if ($title === '' || $name === '') {
                continue;
            }
            $start = $this->date($r['start date'] ?? null);
            $end = $this->date($r['ending date'] ?? null);
            if (! $start || ! $end || $end < $start) {
                $errors[] = 'Row '.($index + 2).': invalid or incomplete event dates.';

                continue;
            }
            $money = fn ($key) => $this->cents($r[$key] ?? null);
            $sourceId = trim((string) ($r['_teams_row_id'] ?? ''));
            if ($sourceId !== '' && isset($seen[$sourceId])) {
                $errors[] = 'Row '.($index + 2).': duplicate integration row ID.';

                continue;
            }
            if ($sourceId !== '') {
                $seen[$sourceId] = true;
            }
            $eventId = trim((string) ($r['_teams_event_id'] ?? ''));
            $identity = $sourceId ?: hash('sha256', $tab.'|'.$name.'|'.$title.'|'.$start.'|'.$end.'|'.$index);
            $payroll = $money('total payroll owed');
            $mileage = $money('mileage owed');
            $perDiem = $money('per diem owed');
            $reimbursement = $money('expense owed');
            $components = ['staffing' => $payroll === null ? null : $payroll - ($mileage ?? 0) - ($perDiem ?? 0) - ($reimbursement ?? 0), 'mileage' => $mileage, 'per_diem' => $perDiem, 'reimbursements' => $reimbursement, 'lodging' => $money('lodging'), 'event_fees' => $money('event fees'), 'other' => $money('other'), 'cogs' => $money('cogs $'), 'rental_cars' => null, 'parking' => null];
            $items[] = ['source_id' => $identity, 'source_row' => $index + 2, 'source_tab' => $tab, 'has_stable_id' => $sourceId !== '', 'event_id' => $eventId ?: null, 'person_id' => trim((string) ($r['_teams_person_id'] ?? '')) ?: null, 'person_name' => $name, 'title' => $title, 'starts_on' => $start, 'ends_on' => $end, 'state' => $r['state'] ?? null, 'net_sales_cents' => $money('net sales'), 'total_expense_cents' => $money('total expense'), 'profit_cents' => $money('event profit/(loss)'), 'costs' => $components, 'hours' => is_numeric($r['hours worked'] ?? null) ? (string) $r['hours worked'] : null, 'payroll_cents' => $payroll, 'pay_type' => $r['pay type'] ?? null, 'pay_week' => $r['pay week'] ?? $r['pay week posted'] ?? null, 'selling_days' => is_numeric($r['total days'] ?? null) ? max(0, (int) $r['total days']) : null, 'source_values' => $r];
        }
        // Same-period staff lines may repeat shared sales/fees. Never guess an allocation.
        $counts = [];
        foreach ($items as $item) {
            $key = ($item['event_id'] ?: strtolower($item['title'])).'|'.$item['starts_on'].'|'.$item['ends_on'];
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }
        foreach ($items as &$item) {
            $key = ($item['event_id'] ?: strtolower($item['title'])).'|'.$item['starts_on'].'|'.$item['ends_on'];
            $item['needs_allocation_review'] = $counts[$key] > 1;
        }

        return ['items' => $items, 'errors' => $errors];
    }
}

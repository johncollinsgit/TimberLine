<?php

namespace App\Services\Trajectory;

use App\Models\Trajectory\Record;
use App\Models\Trajectory\Space;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/** Budget targets only: these rows never create income, spending, or bank balances. */
class BudgetImportService
{
    public function read(string $path, Space $space, string $format = 'template', array $mapping = [], array $defaults = []): array
    {
        abort_unless(in_array($format, ['template', 'everydollar', 'mapped'], true), 422, 'Choose the budget template or explicitly map a budget CSV.');
        $handle = fopen($path, 'r');
        abort_unless($handle, 422, 'Cannot read budget CSV.');
        $raw = [];
        $rowNumbers = [];
        $recordNumber = 1;
        try {
            $headers = array_map(fn ($h) => trim(ltrim((string) $h, "\xEF\xBB\xBF")), fgetcsv($handle, 0, ',', '"', '') ?: []);
            abort_unless($headers && count($headers) <= 60 && ! in_array('', $headers, true) && count(array_unique($headers)) === count($headers), 422, 'Use distinct nonempty CSV column names.');
            while (($cells = fgetcsv($handle, 0, ',', '"', '')) !== false) {
                $recordNumber++;
                if (! array_filter($cells, fn ($v) => $v !== null && trim((string) $v) !== '')) {
                    continue;
                }
                abort_unless(count($cells) === count($headers), 422, 'Budget CSV column count differs on row '.$recordNumber.'.');
                $raw[] = array_combine($headers, array_map(fn ($v) => trim((string) $v), $cells));
                $rowNumbers[] = $recordNumber;
                abort_if(count($raw) > 5000, 422, 'Import at most 5,000 budget rows.');
            }
        } finally {
            fclose($handle);
        }
        abort_unless($raw, 422, 'The file contains no budget targets.');
        $hash = hash_file('sha256', $path);
        $base = ['format' => $format, 'file_fingerprint' => $hash, 'columns' => $headers, 'sample' => array_slice($raw, 0, 5), 'rows' => [], 'unmapped_categories' => [], 'missing_fields' => [], 'mapping_required' => false, 'warnings' => []];
        if ($format === 'template') {
            $required = ['name', 'category', 'monthly_cents', 'purpose', 'effective_on'];
            abort_if(array_diff($required, $headers), 422, 'Use template columns: name,category,monthly_cents,purpose,effective_on,source,reviewed,source_id.');
            $mapping = array_intersect_key(array_combine($headers, $headers), array_flip(['name', 'category', 'purpose', 'effective_on', 'source', 'reviewed', 'source_id']));
            $mapping['amount'] = 'monthly_cents';
            $units = 'cents';
        } else {
            // EveryDollar export versions are not guessed. The user chooses the
            // actual planned column and source grouping from this file's headers.
            foreach ($mapping as $role => $column) {
                abort_unless(in_array($role, ['name', 'category', 'amount', 'purpose', 'effective_on', 'source', 'reviewed', 'source_id'], true) && in_array($column, $headers, true), 422, 'Map each field to an actual CSV header.');
            }
            foreach (['name', 'category', 'amount', 'purpose', 'effective_on'] as $role) {
                if (! isset($mapping[$role]) && ! ($role !== 'amount' && array_key_exists($role, $defaults))) {
                    $base['missing_fields'][] = $role;
                }
            }
            $units = $defaults['amount_units'] ?? null;
            if (! in_array($units, ['cents', 'dollars'], true)) {
                $base['missing_fields'][] = 'amount_units';
            }
            if (($defaults['amount_basis'] ?? null) !== 'planned') {
                $base['missing_fields'][] = 'amount_basis';
            }
            if ($base['missing_fields']) {
                return [...$base, 'mapping_required' => true];
            }
            abort_if(preg_match('/spent|actual|remaining|received|transaction/i', $mapping['amount']), 422, 'Select planned budget amounts, not spent, actual, remaining, received, or transaction amounts.');
        }
        $categories = app(WorkspaceService::class)->categories($space);
        $existing = Record::where('space_id', $space->id)->where('kind', 'budget')->get();
        $seen = [];
        foreach ($raw as $index => $rawRow) {
            $number = $rowNumbers[$index];
            $get = fn ($role, $fallback = null) => isset($mapping[$role]) ? $rawRow[$mapping[$role]] : ($defaults[$role] ?? $fallback);
            $amount = (string) $get('amount');
            if ($units === 'cents') {
                abort_unless(preg_match('/^\d{1,12}$/', $amount), 422, 'Use nonnegative integer monthly_cents on row '.$number.'.');
                $cents = (int) $amount;
            } else {
                abort_unless(preg_match('/^\$?(?:\d+|\d{1,3}(?:,\d{3})+)(?:\.\d{1,2})?$/', $amount), 422, 'Use a nonnegative planned dollar amount with at most two decimal places on row '.$number.'.');
                $cents = Money::cents(str_replace(['$', ','], '', $amount));
            }
            $reviewed = $get('reviewed', '0');
            if ($reviewed === '') {
                $reviewed = '0';
            }
            $source = $get('source', '');
            if ($source === '') {
                $source = $format === 'everydollar' ? 'EveryDollar CSV — explicitly mapped planned targets' : 'Budget CSV — planned targets';
            }
            abort_unless(in_array($reviewed, [false, true, 0, 1, '0', '1', 'true', 'false'], true), 422, 'Use reviewed 0 or 1 on row '.$number.'.');
            $sourceId = (string) $get('source_id', '');
            if ($sourceId === '') {
                $sourceId = 'budget:csv:'.$hash.':row:'.$number;
                $base['warnings']['generated_ids'] = 'Generated source IDs replay the same file. Use explicit stable source_id values to link corrected or reordered exports.';
            }
            abort_if(isset($seen[$sourceId]), 422, 'Duplicate budget source_id in this CSV.');
            $seen[$sourceId] = true;
            $row = Validator::make([
                'name' => $get('name'), 'category' => $get('category'), 'monthly_cents' => $cents,
                'purpose' => $get('purpose'), 'effective_on' => $get('effective_on'),
                'source' => $source,
                'reviewed' => in_array($reviewed, [true, 1, '1', 'true'], true), 'source_id' => $sourceId,
            ], [
                'name' => 'required|string|max:160', 'category' => 'required|string|max:160',
                'monthly_cents' => 'required|integer|between:0,100000000000',
                'purpose' => ['required', Rule::in(['spending', 'savings', 'debt_payment', 'income'])],
                'effective_on' => 'required|date_format:Y-m-d|after_or_equal:1900-01-01|before:2200-01-01',
                'source' => 'required|string|max:160', 'reviewed' => 'required|boolean', 'source_id' => 'required|string|max:160',
            ])->validate();
            $category = $row['category'];
            if (! in_array($category, $categories, true)) {
                $base['unmapped_categories'][] = $category;
            }
            $matches = $existing->filter(fn ($r) => ($r->data['source_id'] ?? null) === $sourceId);
            abort_if($matches->count() > 1, 409, 'This source reference already has duplicate budget records; review them first.');
            $match = $matches->first();
            $base['rows'][] = [...$row, '_row_number' => $number, '_source_category' => $category, '_existing_record_id' => $match?->id, '_existing_version' => $match?->version];
        }
        $base['unmapped_categories'] = array_values(array_unique($base['unmapped_categories']));
        $base['mapping_required'] = (bool) $base['unmapped_categories'];
        $base['warnings'] = array_values($base['warnings']);

        return $base;
    }

    /** Resolve explicit category choices against the current space's allowed categories. */
    public function resolve(Space $space, array $preview, array $categoryMap = []): array
    {
        abort_if(! empty($preview['missing_fields']) || empty($preview['rows']), 422, 'Finish the column, planned-amount, purpose and effective-date mappings first.');
        $allowed = app(WorkspaceService::class)->categories($space);
        $rows = [];
        foreach ($preview['rows'] as $row) {
            $original = $row['_source_category'];
            $row['category'] = $categoryMap[$original] ?? $row['category'];
            abort_unless(in_array($row['category'], $allowed, true), 422, 'Choose a Trajectory category for '.$original.'.');
            $rows[] = $row;
        }

        return $rows;
    }

    public function confirm(User $user, Space $space, array $rows): array
    {
        app(FinanceAccess::class)->authorize($user, $space);
        abort_unless(config('trajectory_records.budget.source_id'), 503, 'Budget source references must be enabled before importing.');
        abort_unless($rows && count($rows) <= 5000, 422, 'Choose a nonempty budget preview with at most 5,000 rows.');

        return DB::transaction(function () use ($user, $space, $rows): array {
            Space::whereKey($space->id)->lockForUpdate()->firstOrFail();
            $records = Record::where('space_id', $space->id)->where('kind', 'budget')->lockForUpdate()->get();
            $created = 0;
            $skipped = 0;
            $seen = [];
            foreach ($rows as $row) {
                $name = Validator::make($row, ['name' => 'required|string|max:160', 'source_id' => 'required|string|max:160'])->validate()['name'];
                abort_if(isset($seen[$row['source_id']]), 422, 'Duplicate budget source_id in this preview.');
                $seen[$row['source_id']] = true;
                $data = app(RecordService::class)->validate($space, 'budget', $row);
                $matches = $records->filter(fn ($r) => ($r->data['source_id'] ?? null) === $data['source_id']);
                abort_if($matches->count() > 1, 409, 'Review duplicate budget source references first.');
                $other = $matches->first();
                if ($other) {
                    if (($row['_existing_record_id'] ?? null) !== null) {
                        abort_unless($row['_existing_record_id'] === $other->id && $row['_existing_version'] === $other->version, 409, 'A budget target changed after this preview. Preview again.');
                    }
                    abort_unless($other->active && $other->name === $name && $other->data == $data, 409, 'This imported budget target was changed or archived. Edit the existing target; import will not overwrite it.');
                    $skipped++;

                    continue;
                }
                abort_if(($row['_existing_record_id'] ?? null) !== null, 409, 'The previewed budget target changed. Preview again.');
                foreach ($records as $record) {
                    $d = $record->data;
                    abort_if($record->name === $name && ($d['category'] ?? null) === $data['category'] && ($d['purpose'] ?? null) === $data['purpose'] && ($d['effective_on'] ?? null) === $data['effective_on'] && ($d['source'] ?? null) === $data['source'], 409, 'A matching budget target already exists under another source reference. Review it before importing again.');
                }
                $record = app(RecordService::class)->save($user, $space, 'budget', $name, $data);
                $records->push($record);
                $created++;
            }

            return ['created' => $created, 'skipped' => $skipped, 'count' => count($rows)];
        });
    }
}

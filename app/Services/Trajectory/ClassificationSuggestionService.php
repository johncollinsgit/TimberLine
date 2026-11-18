<?php

namespace App\Services\Trajectory;

use App\Models\Trajectory\Account;
use App\Models\Trajectory\Record;
use App\Models\Trajectory\Space;
use App\Models\Trajectory\Transaction;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;

/** Read-only proposals. Only the existing versioned categorization APIs can apply a choice. */
class ClassificationSuggestionService
{
    private const EXCLUDED_CATEGORIES = ['uncategorized', 'income', 'taxes', 'tax_penalties', 'tax_fees'];

    public function preview(User $user, Space $space, array $input): array
    {
        $access = app(FinanceAccess::class);
        $space = Space::findOrFail($space->id);
        $access->authorize($user, $space);
        $data = Validator::make($input, [
            'rows' => 'required|array|min:1|max:50', 'rows.*.id' => 'required|integer|min:1',
            'rows.*.version' => 'required|integer|min:1', 'rows.*.attribution_detail_id' => 'nullable|integer|min:1',
            'account_id' => 'nullable|integer|min:1', 'program_id' => 'nullable|integer|min:1',
        ])->validate();
        $data['rows'] = array_map(fn ($row) => ['id' => (int) $row['id'], 'version' => (int) $row['version'],
            'attribution_detail_id' => isset($row['attribution_detail_id']) ? (int) $row['attribution_detail_id'] : null], $data['rows']);
        $keys = array_map(fn ($r) => $r['id'].':'.($r['attribution_detail_id'] ?? 0), $data['rows']);
        abort_unless(count($keys) === count(array_unique($keys)), 422, 'Choose each transaction detail only once.');
        $accountId = isset($data['account_id']) ? (int) $data['account_id'] : null;
        if ($accountId !== null) {
            Account::where('space_id', $space->id)->whereIn('kind', ['cash', 'credit'])->findOrFail($accountId);
        }
        $records = Record::where('space_id', $space->id)->where('active', true)
            ->whereIn('kind', ['rule', 'category_definition', 'program'])->get();
        $programId = isset($data['program_id']) ? (int) $data['program_id'] : null;
        if ($programId !== null) {
            abort_unless($records->contains(fn ($r) => $r->id === $programId && $r->kind === 'program'), 422, 'Choose an active program in this space.');
        }
        $definitions = $records->where('kind', 'category_definition')->filter(fn ($r) => ($r->data['direction'] ?? null) === 'expense');
        $labels = collect(config('trajectory.categories'))->reject(fn ($key) => in_array($key, self::EXCLUDED_CATEGORIES, true))
            ->mapWithKeys(fn ($key) => [$key => str($key)->replace('_', ' ')->headline()->toString()])->all();
        foreach ($definitions as $definition) {
            $labels[$definition->data['key']] = $definition->name;
        }
        foreach (self::EXCLUDED_CATEGORIES as $excluded) {
            unset($labels[$excluded]);
        }
        $children = [];
        $definitionsById = $definitions->keyBy('id');
        foreach ($definitions as $definition) {
            $parent = $definitionsById->get($definition->data['parent_record_id'] ?? null);
            if ($parent && isset($labels[$definition->data['key']])) {
                $children[$parent->data['key']][] = $definition->data['key'];
            }
        }

        $ids = array_values(array_unique(array_column($data['rows'], 'id')));
        $sources = $this->sourceQuery($space, $accountId)->whereIn('t.id', $ids)->get()->keyBy('id');
        abort_unless($sources->count() === count($ids), 404);
        foreach ($data['rows'] as $requested) {
            abort_unless((int) $sources[$requested['id']]->version === $requested['version'], 409, 'A transaction changed. Refresh before reviewing suggestions.');
        }
        $sourceSpaces = Space::whereIn('id', $this->sourceQuery($space)->distinct()->pluck('t.space_id')->push($space->id)->unique())->get()->keyBy('id');
        $allowed = [];
        foreach ($sourceSpaces as $sourceSpace) {
            $allowed[$sourceSpace->id] = $access->allows($user, $sourceSpace);
        }
        $bound = $this->medicalIds(array_keys(array_filter($allowed)));
        $attribution = app(AttributionService::class);
        $entries = collect($attribution->expandEntries($space, $this->entries($sources, $space, $allowed), $programId, $user));

        // One bounded history read for the entire batch; provider hints are never treated as new receipts.
        $oldest = $sources->min(fn ($tx) => $tx->posted_on->toDateString());
        $latest = $sources->max(fn ($tx) => $tx->posted_on->toDateString());
        $historySources = $this->sourceQuery($space)->where('t.reviewed', true)->where('t.pending', false)
            ->whereIn('t.space_id', array_keys(array_filter($allowed)))
            ->whereIn('t.flow', ['expense', 'refund'])->whereNotIn('t.id', $ids)
            ->whereDate('t.posted_on', '>=', \Carbon\CarbonImmutable::parse($oldest)->subYears(3)->toDateString())
            ->whereDate('t.posted_on', '<=', $latest)->orderByDesc('t.posted_on')->orderByDesc('t.id')->limit(5000)->get()->keyBy('id');
        $history = collect($attribution->expandEntries($space, $this->entries($historySources, $space, $allowed), $programId, $user))
            ->filter(fn ($entry) => $entry['reviewed'] && $entry['amount_cents'] !== 0 && $entry['attribution_status'] !== 'needs_review'
                && ($entry['editable'] || $entry['attribution_detail_id'] !== null)
                && isset($labels[$entry['category']]) && ! in_array($entry['id'], $bound, true));
        $rules = $records->where('kind', 'rule')->filter(fn ($r) => in_array($r->data['classification']['flow'] ?? null, ['expense', 'refund'], true)
            && isset($labels[$r->data['classification']['category'] ?? '']));

        $rows = [];
        foreach ($data['rows'] as $requested) {
            $tx = $sources[$requested['id']];
            $detailId = $requested['attribution_detail_id'] ?? null;
            $entry = $entries->first(fn ($e) => $e['id'] === $tx->id && $e['attribution_detail_id'] === $detailId);
            abort_unless($entry, 409, 'This economic detail or program changed. Refresh before reviewing suggestions.');
            $canReadSource = $allowed[$tx->space_id] ?? false;
            $base = ['key' => $tx->id.':'.$tx->version.':'.($detailId ?? 0), 'transaction_id' => $tx->id,
                'source_transaction_id' => $tx->id, 'source_space_id' => $canReadSource ? $tx->space_id : null,
                'version' => (int) $tx->version, 'attribution_detail_id' => $detailId, 'category' => null, 'category_label' => null,
                'confidence' => 'none', 'reason' => '', 'has_conflicts' => false, 'editable' => false,
                'evidence_count' => 0, 'evidence_ids' => [], 'pending' => (bool) $tx->pending];
            if (! $canReadSource) {
                $rows[] = [...$base, 'reason' => 'The payment source is private. Ask someone with access to categorize this shared cost.'];

                continue;
            }
            if (! $entry['editable'] && $detailId === null) {
                $rows[] = [...$base, 'reason' => 'Review this shared cost’s economic attribution first; the payment account’s category does not establish its purpose here.'];

                continue;
            }
            $parentChildren = $entry['reviewed'] ? ($children[$entry['category']] ?? []) : [];
            $eligible = in_array($tx->flow, ['expense', 'refund'], true) && $entry['amount_cents'] !== 0
                && ! in_array($tx->id, $bound, true) && ! in_array($entry['category'], ['taxes', 'tax_penalties', 'tax_fees'], true)
                && $entry['attribution_status'] !== 'needs_review' && (! $entry['reviewed'] || $entry['category'] === 'uncategorized' || $parentChildren !== []);
            if (! $eligible) {
                $rows[] = [...$base, 'reason' => 'This item is already reviewed or needs its dedicated financial-purpose review.'];

                continue;
            }
            $merchant = ClassificationService::merchant($tx->merchant);
            $matches = $history->filter(fn ($e) => ClassificationService::merchant($e['merchant']) === $merchant && $e['date'] <= $tx->posted_on->toDateString());
            $sameAccount = $matches->where('account_id', $tx->account_id);
            $selected = $sameAccount->isNotEmpty() ? $sameAccount : $matches;
            $categories = $selected->pluck('category')->unique()->values();
            $exactRules = $rules->filter(fn ($r) => ClassificationService::merchant($r->data['merchant'] ?? '') === $merchant);
            $ruleCategories = $exactRules->map(fn ($r) => $r->data['classification']['category'])->unique();
            $allCategories = $categories->concat($ruleCategories)->unique()->values();
            if ($parentChildren !== []) {
                // A generic parent is not evidence for any particular child. Keep
                // incompatible historical purposes visible rather than selecting
                // a convenient child and silently ignoring the conflict.
                $allCategories = $allCategories->reject(fn ($key) => $key === $entry['category'])->values();
            }
            $evidenceIds = ($parentChildren === [] ? $selected : $selected->reject(fn ($historyEntry) => $historyEntry['category'] === $entry['category']))
                ->pluck('id')->unique()->values()->all();
            $base = [...$base, 'editable' => true, 'evidence_count' => count($evidenceIds), 'evidence_ids' => array_slice($evidenceIds, 0, 20)];
            if ($allCategories->count() > 1 || ($parentChildren !== [] && $allCategories->contains(fn ($key) => ! in_array($key, $parentChildren, true)))) {
                $rows[] = [...$base, 'has_conflicts' => true, 'reason' => 'Previous reviewed purchases or exact merchant rules use different categories. Choose this purchase’s purpose.'];

                continue;
            }
            $category = $allCategories->first();
            $mixed = ClassificationService::isAmbiguousMerchant($tx->merchant);
            if ($category) {
                $reason = $exactRules->isNotEmpty() ? 'An exact merchant rule you saved proposes this category.'
                    : 'Previous reviewed purchases '.($sameAccount->isNotEmpty() ? 'on this payment account' : 'allocated to this space').' used this category.';
                if ($programId !== null) {
                    $reason .= ' History is limited to the selected program.';
                }
                $rows[] = [...$base, 'category' => $category, 'category_label' => $labels[$category],
                    'confidence' => $mixed ? 'low' : ($exactRules->isNotEmpty() || count($evidenceIds) >= 2 ? 'high' : 'medium'),
                    'reason' => $reason.($mixed ? ' This merchant serves multiple purposes; confirm this purchase.' : ' Confirm or change before saving.')];

                continue;
            }
            $hint = $parentChildren === [] ? $this->titleHint($tx->merchant) : null;
            $category = $hint && isset($labels[$hint]) ? $hint : null;
            $rows[] = [...$base, 'category' => $category, 'category_label' => $category ? $labels[$category] : null,
                'confidence' => $category ? 'low' : 'none',
                'reason' => $parentChildren !== [] ? 'This purchase is reviewed at the parent category. Choose a native subcategory; its existing purpose remains unchanged.'
                    : ($category ? 'Merchant-title hint only; no matching reviewed history. Confirm the actual purpose.'
                    : ($mixed ? 'This merchant serves multiple purposes; there is no matching reviewed history. Choose the actual purpose.' : 'No matching reviewed history or clear merchant-title hint. Choose the purpose.'))];
        }

        return ['space_id' => $space->id, 'readonly' => true, 'rows' => $rows];
    }

    private function sourceQuery(Space $space, ?int $accountId = null)
    {
        return Transaction::query()->from('trajectory_transactions as t')
            ->join('trajectory_allocations as a', 'a.transaction_id', '=', 't.id')
            ->join('trajectory_accounts as account', function ($join): void {
                $join->on('account.id', '=', 't.account_id')->on('account.space_id', '=', 't.space_id');
            })->where('a.space_id', $space->id)->where('t.removed', false)->whereIn('account.kind', ['cash', 'credit'])
            ->when($accountId !== null, fn ($q) => $q->where('t.space_id', $space->id)->where('t.account_id', $accountId))
            ->select(['t.*', 'a.id as allocation_id', 'a.amount_cents as allocated_cents']);
    }

    private function entries(Collection $sources, Space $space, array $allowed): array
    {
        return $sources->map(fn ($tx) => ['id' => $tx->id, 'allocation_id' => (int) $tx->allocation_id,
            'amount_cents' => (int) $tx->allocated_cents, 'date' => $tx->posted_on->toDateString(),
            'merchant' => ($allowed[$tx->space_id] ?? false) ? $tx->merchant : 'Shared cost',
            'category' => $tx->category, 'flow' => $tx->flow, 'reviewed' => (bool) $tx->reviewed,
            'version' => (int) $tx->version, 'editable' => $tx->space_id === $space->id,
            'account_id' => ($allowed[$tx->space_id] ?? false) ? $tx->account_id : null])->values()->all();
    }

    private function medicalIds(array $spaceIds): array
    {
        return Record::whereIn('space_id', $spaceIds)->where('active', true)
            ->whereIn('kind', ['medical_payment', 'medical_share', 'medical_membership'])->get()
            ->map(fn ($r) => $r->data['transaction_id'] ?? null)->filter()->unique()->values()->all();
    }

    private function titleHint(string $merchant): ?string
    {
        return match (true) {
            (bool) preg_match('/\b(mcdonald[’\x27]?s|taco bell|chipotle|burger king|wendy[’\x27]?s|chick[ -]fil[ -]a|domino[’\x27]?s|starbucks|caribou coffee|waffle house|cracker barrel)\b/iu', $merchant) => 'dining',
            (bool) preg_match('/\b(publix|aldi|ingles markets?|trader joe[’\x27]?s|whole foods market)\b/iu', $merchant) => 'groceries',
            default => null,
        };
    }
}

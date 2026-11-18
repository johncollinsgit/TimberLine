<?php

namespace App\Services\Trajectory;

use App\Models\Trajectory\Account;
use App\Models\Trajectory\Record;
use App\Models\Trajectory\Space;
use App\Models\Trajectory\Transaction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/** One scoped evidence set supplies comparison bars, pies and the editable ledger. */
class AnalysisService
{
    public function build(User $viewer, Space $space, array $options = []): array
    {
        app(FinanceAccess::class)->authorize($viewer, $space);
        $today = CarbonImmutable::now($space->timezone)->startOfDay();
        $from = $options['from'] ?? $today->startOfMonth()->toDateString();
        $through = $options['through'] ?? $today->toDateString();
        $year = (int) ($options['year'] ?? substr($from, 0, 4));
        $grain = $options['grain'] ?? 'month';
        $basis = $options['basis'] ?? 'spending';
        $programId = isset($options['program_id']) ? (int) $options['program_id'] : null;
        $accountId = isset($options['account_id']) ? (int) $options['account_id'] : null;
        $accounts = Account::where('space_id', $space->id)->get();
        abort_if($accountId !== null && ! $accounts->contains('id', $accountId), 404);
        $programs = Record::where('space_id', $space->id)->where('kind', 'program')->where('active', true)->get();
        abort_if($programId !== null && ! $programs->contains('id', $programId), 404);
        $entries = collect(app(LedgerService::class)->entries($space, null, $today->toDateString(), accountId: $accountId));
        $economic = app(AttributionService::class)->expandEntries($space, $entries->all(), $programId, $viewer);
        $economic = collect($economic);
        $cashAccounts = $accounts->where('kind', 'cash')->when($accountId, fn ($q) => $q->where('id', $accountId));
        $cashIds = Transaction::where('space_id', $space->id)->whereIn('account_id', $cashAccounts->pluck('id'))
            ->whereDate('posted_on', '<=', $today->toDateString())->where('pending', false)->where('removed', false)->pluck('id')->all();
        $cash = collect(app(MoneyFlowService::class)->cashEvidence($space, $entries->all(), $cashIds));
        // Build source classifications once, rather than rescanning for each comparison week.
        $projection = app(MoneyFlowService::class)->build($space, $entries, $accounts, [], $entries, viewer: $viewer);
        $incomeMap = $outgoingMap = $receiptTypes = [];
        $receiptProjection = $projection['family_inflows'] ?? null;
        foreach (($receiptProjection['inflows'] ?? $projection['views']['cash']['inflows']) as $group) {
            foreach ($group['ids'] as $id) {
                $incomeMap[$id] = ['key' => $group['key'], 'label' => $group['label'], 'kind' => $group['kind']];
            }
        }
        foreach (($receiptProjection['outflows'] ?? []) as $group) {
            foreach ($group['ids'] as $id) {
                $receiptTypes[$id] = ['key' => $group['key'], 'label' => $group['label']];
            }
        }
        foreach ($projection['views']['cash']['outflows'] as $group) {
            foreach ($group['ids'] as $id) {
                $outgoingMap[$id] = ['key' => $group['key'], 'label' => $group['label']];
            }
        }
        if ($programId !== null) {
            // Programs have attributed movement, not a second full bank balance.
            $cash = $economic->filter(fn ($e) => ($e['editable'] ?? false) && $cashAccounts->contains('id', $e['account_id'] ?? null));
        }
        $cash = $cash->map(function ($entry) use ($incomeMap, $outgoingMap, $receiptTypes) {
            $entry['entry_key'] ??= 'cash:'.$entry['id'];
            $entry['amount_basis'] = 'owned_bank_cash';
            $entry['analysis_source'] = $incomeMap[$entry['id']] ?? null;
            $entry['analysis_source_type'] = $receiptTypes[$entry['id']] ?? null;
            $entry['analysis_category'] = $outgoingMap[$entry['id']]['key'] ?? null;
            $entry['analysis_label'] = $outgoingMap[$entry['id']]['label'] ?? null;

            return $entry;
        });
        $expenses = app(EconomicActivityService::class)->expenses($economic)->map(function ($entry) {
            $entry['entry_key'] ??= 'economic:'.$entry['id'].':'.($entry['economic_component'] ?? 'whole');
            $entry['amount_basis'] = 'economic_cost';

            return $entry;
        });
        $selected = $this->selection($cash, $expenses, $economic, $from, $through, $basis);
        $selectedAccounts = $accountId ? $accounts->where('id', $accountId) : $accounts;
        $selectedCovered = $selectedAccounts->isNotEmpty() && $selectedAccounts->every(fn ($account) => $account->history_start
            && $account->history_start->toDateString() <= $from && $account->observed_at && $account->observed_at->toDateString() >= $through);
        $selectedHasEvidence = $selected['cash']->isNotEmpty() || $selected['economic']->isNotEmpty();
        $selectedAvailable = $selectedCovered || $selectedHasEvidence;
        $definitions = app(AnalysisCategoryService::class)->definitions($space);
        $categories = app(AnalysisCategoryService::class)->group($selected['expenses']->all(), $definitions, $basis === 'cash');
        $inflows = [];
        foreach ($selected['receipts'] as $entry) {
            $source = $entry['analysis_source'];
            $key = $source['key'];
            $inflows[$key] ??= ['key' => $key, 'label' => $source['label'], 'kind' => $source['kind'], 'amount_cents' => 0, 'ids' => [], 'entry_keys' => [], 'children' => []];
            $inflows[$key]['amount_cents'] += $entry['amount_cents'];
            $inflows[$key]['ids'][] = $entry['id'];
            $inflows[$key]['entry_keys'][] = $entry['entry_key'];
            if ($type = $entry['analysis_source_type']) {
                $inflows[$key]['children'][$type['key']] ??= ['key' => $type['key'], 'label' => $type['label'], 'amount_cents' => 0, 'ids' => [], 'entry_keys' => []];
                $inflows[$key]['children'][$type['key']]['amount_cents'] += $entry['amount_cents'];
                $inflows[$key]['children'][$type['key']]['ids'][] = $entry['id'];
                $inflows[$key]['children'][$type['key']]['entry_keys'][] = $entry['entry_key'];
            }
        }
        foreach ($inflows as &$source) {
            $source['children'] = array_values($source['children']);
        }
        unset($source);
        $inflows = array_values($inflows);
        usort($inflows, fn ($a, $b) => $b['amount_cents'] <=> $a['amount_cents']);
        $periods = $this->periods($year, $grain, $today, (int) ($options['comparison_year'] ?? $year - 1));
        $scopeAccounts = $accountId ? $accounts->where('id', $accountId) : $accounts;
        foreach ($periods as &$period) {
            foreach (['current', 'comparison'] as $part) {
                $range = $part === 'current' ? $period : $period['comparison'];
                $rows = $this->selection($cash, $expenses, $economic, $range['from'], $range['through'], $basis);
                $hasEvidence = $rows['cash']->isNotEmpty() || $rows['economic']->isNotEmpty();
                $covered = $scopeAccounts->isNotEmpty() && $scopeAccounts->every(fn ($a) => $a->history_start && $a->history_start->toDateString() <= $range['from'] && $a->observed_at && $a->observed_at->toDateString() >= $range['through']);
                $available = ! ($range['future'] ?? false) && ($hasEvidence || $covered);
                $values = ['inflow_cents' => $available ? (int) $rows['receipts']->sum('amount_cents') : null,
                    'spending_cents' => $available && $rows['complete'] ? -(int) $rows['expenses']->sum('amount_cents') : null,
                    'ids' => $rows['expenses']->pluck('id')->unique()->values()->all(), 'coverage' => $covered ? 'recorded' : 'partial'];
                if ($part === 'current') {
                    $period = [...$period, ...$values];
                } else {
                    $period['comparison'] = [...$period['comparison'], ...$values];
                }
            }
        }
        unset($period);
        $selectedRows = [...$selected['expenses']->map(fn ($e) => [...$e, 'direction' => 'out'])->values()->all(),
            ...$selected['receipts']->map(fn ($e) => [...$e, 'direction' => 'in'])->values()->all()];

        return ['range' => ['from' => $from, 'through' => $through, 'start' => $from, 'end' => $through],
            'scope' => ['space_id' => $space->id, 'space_name' => $space->name, 'kind' => $space->kind, 'account_id' => $accountId, 'program_id' => $programId, 'basis' => $basis],
            'currency' => 'USD', 'today' => $today->toDateString(), 'year' => $year, 'grain' => $grain,
            'summary' => ['inflow_cents' => $selectedAvailable ? (int) $selected['receipts']->sum('amount_cents') : null,
                'spending_cents' => $selectedAvailable && $selected['complete'] ? -(int) $selected['expenses']->sum('amount_cents') : null],
            'inflows' => $inflows, 'categories' => $categories, 'periods' => $periods, 'entries' => $selectedRows,
            'category_options' => app(WorkspaceService::class)->categories($space), 'category_definitions' => $definitions,
            'program_options' => $programs->map(fn ($r) => ['id' => $r->id, 'name' => $r->name])->all(),
            'account_options' => $accounts->map(fn ($a) => ['id' => $a->id, 'name' => $a->name, 'kind' => $a->kind])->all(),
            'coverage' => ['complete' => false, 'status' => $selectedAvailable ? 'partial' : 'unavailable',
                'selected_range_covered' => $selectedCovered, 'selected_range_has_evidence' => $selectedHasEvidence, 'notes' => array_values(array_unique([
                    'Recorded activity only. Missing accounts or history can change these amounts.',
                    $basis === 'cash' ? 'Cash movement includes card and loan payments; it is not household consumption.' : 'Spending counts purchases and refunds, including card purchases. Repayments and investments are separate cash movements.',
                    'Current periods compare with the equivalent elapsed period; unknown history remains unavailable.',
                    ...($programId ? ['Program figures are attributed portions, not a separate bank balance.'] : []),
                    ...($space->settings['coverage_notes'] ?? []),
                ]))]];
    }

    private function selection(Collection $cash, Collection $expenses, Collection $economic, string $from, string $through, string $basis): array
    {
        $slice = fn ($rows) => $rows->where('date', '>=', $from)->where('date', '<=', $through)->values();
        $c = $slice($cash);
        $e = $slice($economic);

        return ['cash' => $c, 'economic' => $e,
            'receipts' => $c->filter(fn ($r) => $r['amount_cents'] > 0 && $r['analysis_source'] !== null)->values(),
            'expenses' => $basis === 'cash' ? $c->filter(fn ($r) => $r['amount_cents'] < 0 && $r['analysis_category'] !== null)->values() : $slice($expenses),
            'complete' => $basis === 'cash' || app(EconomicActivityService::class)->unresolved($e)->isEmpty()];
    }

    public function periods(int $year, string $grain, CarbonImmutable $today, int $comparisonYear): array
    {
        $result = [];
        $cursor = CarbonImmutable::create($year, 1, 1, 0, 0, 0, $today->timezone);
        if ($grain === 'year') {
            $ranges = array_map(fn ($y) => [$cursor->setYear($y)->startOfYear(), $cursor->setYear($y)->endOfYear(), (string) $y], range($year - 2, $year));
        } elseif ($grain === 'week') {
            $ranges = [];
            $cursor = $cursor->startOfWeek();
            while ($cursor->year <= $year) {
                $ranges[] = [$cursor, $cursor->endOfWeek(), $cursor->format('M j')];
                $cursor = $cursor->addWeek();
            }
        } else {
            $ranges = array_map(fn ($m) => [$cursor->setMonth($m)->startOfMonth(), $cursor->setMonth($m)->endOfMonth(), $cursor->setMonth($m)->format('M')], range(1, 12));
        }
        foreach ($ranges as [$start, $end, $label]) {
            $future = $start->gt($today);
            $partial = ! $future && $end->gt($today);
            $through = $partial ? $today : $end;
            if ($grain === 'week') {
                $prior = $start->setISODate($comparisonYear, (int) $start->format('W'), 1);
                $priorEnd = $prior->addDays($partial ? (int) $start->diffInDays($through) : 6);
                // An ISO week absent from the reference year must remain unavailable.
                $comparisonMissing = (int) $prior->format('o') !== $comparisonYear;
            } else {
                $yearsBack = $grain === 'year' ? 1 : $year - $comparisonYear;
                $prior = $start->subYearsNoOverflow($yearsBack);
                $priorEnd = $through->subYearsNoOverflow($yearsBack);
                $comparisonMissing = false;
            }
            $result[] = ['key' => $start->toDateString(), 'label' => $label, 'from' => $start->toDateString(), 'through' => $through->toDateString(),
                'partial' => $partial, 'future' => $future, 'comparison' => ['from' => $prior->toDateString(), 'through' => $priorEnd->toDateString(), 'future' => $comparisonMissing || $future]];
        }

        return $result;
    }
}

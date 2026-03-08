<?php

namespace App\Livewire\Intake;

use App\Models\MappingException;
use App\Models\OrderLine;
use App\Models\Scent;
use App\Models\ScentAlias;
use App\Models\Size;
use App\Models\WholesaleCustomScent;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\QueryException;
use Livewire\Component;
use Throwable;

class ProgressiveMapper extends Component
{
    public array $exceptionIds = [];

    // Legacy properties kept for Livewire snapshot compatibility after removing multi-step mode.
    public int $step = 1;
    public string $classification = '';
    /** @var array<int,array<string,mixed>> */
    public array $guesses = [];
    public bool $manualMode = false;

    public string $existingScentSearch = '';
    public ?int $selectedScentId = null;

    public ?int $sizeId = null;
    public ?string $wickType = null;

    /** @var array<int,int> */
    public array $sameNameExceptionIds = [];
    public bool $applySameName = false;
    /** @var array<int,array<string,mixed>> */
    public array $sameNameExceptionPreview = [];

    public function mount(array $exceptionIds = []): void
    {
        $this->exceptionIds = $exceptionIds;
        $this->prefillFromException();
        $this->loadSameNameCandidates();
    }

    public function selectScent(int $scentId): void
    {
        $this->selectedScentId = $scentId;

        $selected = Scent::query()->find($scentId, ['name', 'display_name']);
        if ($selected) {
            $this->existingScentSearch = (string) ($selected->display_name ?: $selected->name ?: '');
        }
    }

    public function selectOnlyMatch(): void
    {
        $context = $this->mappingContext();
        $matches = $this->matchingScents($context);
        if ($matches->isEmpty()) {
            return;
        }

        if ($matches->count() === 1) {
            $candidate = (array) $matches->first();
            $this->selectScent((int) ($candidate['id'] ?? 0));
            return;
        }

        $needle = $this->normalizeSearchText($this->existingScentSearch);
        if ($needle === '') {
            return;
        }

        $exact = $matches->first(function (array $candidate) use ($needle): bool {
            return $this->normalizeSearchText((string) ($candidate['name'] ?? '')) === $needle;
        });

        if ($exact) {
            $this->selectScent((int) ($exact['id'] ?? 0));
        }
    }

    public function save(): void
    {
        if ($this->exceptionIds === []) {
            return;
        }

        $scentId = $this->selectedScentId;
        if (! $scentId && trim($this->existingScentSearch) !== '') {
            $context = $this->mappingContext();
            $candidates = $this->matchingScents($context);
            $searchNeedle = $this->normalizeSearchText($this->existingScentSearch);

            $exact = $candidates->first(function (array $candidate) use ($searchNeedle): bool {
                return $this->normalizeSearchText((string) ($candidate['name'] ?? '')) === $searchNeedle;
            });

            if ($exact) {
                $scentId = (int) ($exact['id'] ?? 0) ?: null;
            } elseif ($candidates->count() === 1) {
                $scentId = (int) ($candidates->first()['id'] ?? 0) ?: null;
            }
        }

        if (! $scentId && trim($this->existingScentSearch) !== '') {
            $existing = $this->findExistingScent($this->existingScentSearch);
            $scentId = $existing?->id;
        }

        if (! $scentId) {
            $this->dispatch('toast', [
                'type' => 'warning',
                'message' => 'Search and select a scent before mapping.',
            ]);

            return;
        }

        $targetIds = $this->exceptionIds;
        if ($this->applySameName && $this->sameNameExceptionIds !== []) {
            $targetIds = array_values(array_unique(array_merge($targetIds, $this->sameNameExceptionIds)));
        }

        $exceptions = MappingException::query()
            ->whereIn('id', $targetIds)
            ->get();

        if ($exceptions->isEmpty()) {
            $this->dispatch('toast', [
                'type' => 'warning',
                'message' => 'No unresolved exceptions found for this mapping.',
            ]);

            return;
        }

        try {
            DB::transaction(function () use ($exceptions, $scentId): void {
                // Core mapping must stay atomic.
                $this->applyMappingToExceptions($exceptions, $scentId);
            });
        } catch (Throwable $e) {
            Log::error('ProgressiveMapperSaveFailed', [
                'exception_class' => get_class($e),
                'exception_message' => $e->getMessage(),
                'exception_ids' => $this->exceptionIds,
                'selected_scent_id' => $scentId,
                'size_id' => $this->sizeId,
                'wick_type' => $this->wickType,
            ]);

            $this->dispatch('toast', [
                'type' => 'error',
                'message' => 'Mapping failed due to a server error. Please try again.',
            ]);

            return;
        }

        // These enrichments should never block the primary mapping flow.
        try {
            $this->syncAliases($exceptions, $scentId);
        } catch (Throwable $e) {
            Log::warning('ProgressiveMapperAliasSyncFailed', [
                'exception_class' => get_class($e),
                'exception_message' => $e->getMessage(),
                'exception_ids' => $exceptions->pluck('id')->all(),
                'selected_scent_id' => $scentId,
            ]);
        }

        try {
            $this->syncWholesaleCustomMappings($exceptions, $scentId);
        } catch (Throwable $e) {
            Log::warning('ProgressiveMapperWholesaleCustomSyncFailed', [
                'exception_class' => get_class($e),
                'exception_message' => $e->getMessage(),
                'exception_ids' => $exceptions->pluck('id')->all(),
                'selected_scent_id' => $scentId,
            ]);
        }

        $mappedCount = $exceptions->count();
        $message = "Mapped {$mappedCount} exception".($mappedCount === 1 ? '' : 's').'.';

        if ($this->applySameName && $this->sameNameExceptionIds !== []) {
            $extraCount = max(0, $mappedCount - count($this->exceptionIds));
            if ($extraCount > 0) {
                $message .= " Also applied to {$extraCount} matching item".($extraCount === 1 ? '' : 's').'.';
            }
        }

        $this->dispatch('toast', [
            'type' => 'success',
            'message' => $message,
        ]);
        $this->dispatch('intake-done');
    }

    protected function applyMappingToExceptions(Collection $exceptions, int $scentId): void
    {
        $hasOrderLineScentId = Schema::hasColumn('order_lines', 'scent_id');
        $hasOrderLineSizeId = Schema::hasColumn('order_lines', 'size_id');
        $hasOrderLineWickType = Schema::hasColumn('order_lines', 'wick_type');
        $hasOrderLineScentName = Schema::hasColumn('order_lines', 'scent_name');
        $hasOrderLineSizeCode = Schema::hasColumn('order_lines', 'size_code');
        $hasMappingExceptionResolvedAt = Schema::hasColumn('mapping_exceptions', 'resolved_at');
        $hasMappingExceptionResolvedBy = Schema::hasColumn('mapping_exceptions', 'resolved_by');
        $hasMappingExceptionCanonicalScentId = Schema::hasColumn('mapping_exceptions', 'canonical_scent_id');
        $hasOrderRequiresShippingReview = Schema::hasColumn('orders', 'requires_shipping_review');

        $scentName = $hasOrderLineScentName
            ? (Scent::query()->find($scentId)?->name)
            : null;

        $sizeCode = ($this->sizeId && $hasOrderLineSizeCode)
            ? (Size::query()->find($this->sizeId)?->code)
            : null;

        foreach ($exceptions as $exception) {
            $line = $exception->order_line_id
                ? OrderLine::query()->find($exception->order_line_id)
                : null;

            if (! $line) {
                continue;
            }

            if ($hasOrderLineScentId) {
                $line->scent_id = $scentId;
            }
            if ($this->sizeId && $hasOrderLineSizeId) {
                $line->size_id = $this->sizeId;
            }
            if ($this->wickType && $hasOrderLineWickType) {
                $line->wick_type = $this->wickType;
            }
            if ($hasOrderLineScentName) {
                $line->scent_name = $scentName;
            }
            if ($this->sizeId && $hasOrderLineSizeCode) {
                $line->size_code = $sizeCode;
            }
            try {
                $line->save();
            } catch (QueryException $e) {
                $targetScentId = $hasOrderLineScentId
                    ? (int) ($line->scent_id ?? 0)
                    : 0;
                $targetSizeId = $hasOrderLineSizeId
                    ? (int) ($line->size_id ?? 0)
                    : 0;

                if (! $this->isOrderScentSizeUniqueViolation($e) || $targetScentId <= 0 || $targetSizeId <= 0) {
                    throw $e;
                }

                $line = $this->mergeLineIntoExistingScentSize($line, $targetScentId, $targetSizeId);
            }

            if (! empty($line->scent_id) && ! empty($line->size_id)) {
                if ($hasMappingExceptionResolvedAt) {
                    $exception->resolved_at = now();
                }
                if ($hasMappingExceptionResolvedBy) {
                    $exception->resolved_by = auth()->id();
                }
                if ($hasMappingExceptionCanonicalScentId) {
                    $exception->canonical_scent_id = $line->scent_id;
                }
                if ($exception->isDirty()) {
                    $exception->save();
                }

                if ($exception->order_id && $hasOrderRequiresShippingReview && $hasMappingExceptionResolvedAt) {
                    $hasOpen = MappingException::query()
                        ->where('order_id', $exception->order_id)
                        ->whereNull('resolved_at')
                        ->exists();

                    if (! $hasOpen) {
                        \App\Models\Order::query()
                            ->whereKey($exception->order_id)
                            ->update(['requires_shipping_review' => false]);
                    }
                }
            }
        }
    }

    protected function prefillFromException(): void
    {
        $exception = $this->currentExceptionSample();
        if (! $exception) {
            return;
        }

        $line = $exception->order_line_id
            ? OrderLine::query()->find($exception->order_line_id)
            : null;

        if (! $line) {
            return;
        }

        if (! $this->sizeId && $line->size_id) {
            $this->sizeId = (int) $line->size_id;
        }

        if (! $this->wickType && ! empty($line->wick_type)) {
            $this->wickType = (string) $line->wick_type;
        }

        if (! $this->selectedScentId && ! empty($line->scent_id)) {
            $this->selectedScentId = (int) $line->scent_id;
        }

        if (! $this->sizeId) {
            $rawVariant = (string) ($exception->raw_variant ?? '');
            $sizeId = $this->detectSizeId($rawVariant ?: (string) ($exception->raw_title ?? ''));
            if ($sizeId) {
                $this->sizeId = $sizeId;
            }
        }

        if (! $this->wickType) {
            $rawText = (string) ($exception->raw_variant ?? '').' '.(string) ($exception->raw_title ?? '');
            $this->wickType = $this->detectWick($rawText);
        }
    }

    protected function loadSameNameCandidates(): void
    {
        $this->sameNameExceptionIds = [];
        $this->applySameName = false;
        $this->sameNameExceptionPreview = [];

        $sample = $this->currentExceptionSample();
        if (! $sample) {
            return;
        }

        $sampleLabel = $this->exceptionLookupLabel($sample);
        $sampleLabelNormalized = $this->normalizeSearchText($sampleLabel);
        if ($sampleLabelNormalized === '') {
            return;
        }

        $query = MappingException::query()
            ->whereNull('resolved_at')
            ->whereNull('excluded_at')
            ->whereNotIn('id', $this->exceptionIds)
            ->where('store_key', (string) ($sample->store_key ?? ''));

        $account = trim((string) ($sample->account_name ?? ''));
        if ($account !== '') {
            $query->whereRaw("lower(coalesce(account_name, '')) = ?", [mb_strtolower($account)]);
        }

        $rows = $query
            ->with(['order:id,order_number,order_label,customer_name'])
            ->orderByDesc('id')
            ->limit(400)
            ->get(['id', 'order_id', 'raw_title', 'raw_variant', 'raw_scent_name', 'account_name']);

        $rows = $rows
            ->filter(function (MappingException $row) use ($sampleLabelNormalized): bool {
                return $this->normalizeSearchText($this->exceptionLookupLabel($row)) === $sampleLabelNormalized;
            })
            ->values();

        $this->sameNameExceptionIds = $rows
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->values()
            ->all();

        $this->sameNameExceptionPreview = $rows
            ->take(8)
            ->map(function (MappingException $row): array {
                return [
                    'id' => (int) $row->id,
                    'label' => $this->exceptionLookupLabel($row) ?: 'Unlabeled',
                    'variant' => trim((string) ($row->raw_variant ?? '')),
                    'account_name' => trim((string) ($row->account_name ?? '')),
                    'order_number' => trim((string) ($row->order?->order_number ?? '')),
                    'order_customer' => trim((string) (($row->order?->order_label ?: $row->order?->customer_name) ?? '')),
                ];
            })
            ->values()
            ->all();

        $this->applySameName = count($this->sameNameExceptionIds) > 0;
    }

    public function render()
    {
        $exception = $this->currentExceptionSample();
        $context = $this->mappingContext($exception);

        return view('livewire.intake.progressive-mapper', [
            'contextException' => $exception,
            'mappingContext' => $context,
            'matchingScents' => $this->matchingScents($context),
            'sameNameExceptionPreview' => $this->sameNameExceptionPreview,
            'wizardUrl' => $this->wizardUrl($context),
        ]);
    }

    /**
     * @param  array<string,mixed>  $context
     */
    protected function wizardUrl(array $context): string
    {
        $query = array_filter([
            'raw' => (string) ($context['raw_label'] ?? ''),
            'variant' => (string) ($context['raw_variant'] ?? ''),
            'account' => (string) ($context['account_name'] ?? ''),
            'store' => (string) ($context['store_key'] ?? ''),
            'return_to' => route('admin.index', ['tab' => 'scent-intake']),
        ], fn ($value) => $value !== '');

        return route('admin.scent-wizard', $query);
    }

    protected function currentExceptionSample(): ?MappingException
    {
        if ($this->exceptionIds === []) {
            return null;
        }

        return MappingException::query()
            ->with(['order:id,order_type'])
            ->whereIn('id', $this->exceptionIds)
            ->first();
    }

    /**
     * @return array<string,mixed>
     */
    protected function mappingContext(?MappingException $exception = null): array
    {
        $exception ??= $this->currentExceptionSample();

        $storeKey = (string) ($exception?->store_key ?? '');
        $orderType = (string) ($exception?->order?->order_type ?? '');
        $accountName = trim((string) ($exception?->account_name ?? ''));
        $rawTitle = trim((string) ($exception?->raw_title ?? ''));
        $rawVariant = trim((string) ($exception?->raw_variant ?? ''));
        $rawScentName = trim((string) ($exception?->raw_scent_name ?? ''));
        $rawLabel = $exception ? $this->exceptionLookupLabel($exception) : ($rawScentName !== '' ? $rawScentName : $rawTitle);

        return [
            'store_key' => $storeKey,
            'order_type' => $orderType,
            'account_name' => $accountName,
            'raw_title' => $rawTitle,
            'raw_variant' => $rawVariant,
            'raw_scent_name' => $rawScentName,
            'raw_label' => $rawLabel,
            'is_wholesale' => $storeKey === 'wholesale' || $orderType === 'wholesale' || $accountName !== '',
        ];
    }

    /**
     * @param  array<string,mixed>  $context
     * @return \Illuminate\Support\Collection<int,array<string,mixed>>
     */
    protected function matchingScents(array $context): Collection
    {
        $search = trim($this->existingScentSearch);
        if ($search === '') {
            return collect();
        }

        $isWholesale = (bool) ($context['is_wholesale'] ?? false);
        $needle = $this->normalizeSearchText($search);
        $tokenSource = $this->compactSearchText($needle);
        $tokens = collect(explode(' ', $tokenSource))
            ->map(fn (string $token): string => trim($token))
            ->filter(fn (string $token): bool => $token !== '')
            ->unique()
            ->values()
            ->all();

        if ($needle === '' || $tokens === []) {
            return collect();
        }

        /** @var array<int,array<string,mixed>> $candidates */
        $candidates = [];

        $scentSearchColumns = ['display_name', 'name'];
        if (Schema::hasColumn('scents', 'abbreviation')) {
            $scentSearchColumns[] = 'abbreviation';
        }
        if (Schema::hasColumn('scents', 'oil_reference_name')) {
            $scentSearchColumns[] = 'oil_reference_name';
        }

        $canonicalRows = Scent::query()
            ->with(['oilBlend:id,name'])
            ->where(function ($query) use ($tokens, $scentSearchColumns): void {
                $this->applyLooseTextSearch($query, $tokens, $scentSearchColumns);
            })
            ->orderByRaw('COALESCE(display_name, name)')
            ->limit(180)
            ->get(['id', 'name', 'display_name', 'abbreviation', 'oil_reference_name', 'is_wholesale_custom', 'is_blend', 'is_candle_club', 'oil_blend_id']);

        foreach ($canonicalRows as $scent) {
            $name = (string) ($scent->display_name ?: $scent->name ?: '');
            if ($name === '') {
                continue;
            }

            $score = $this->candidateScoreFromFields($needle, [
                $name,
                (string) ($scent->name ?? ''),
                (string) ($scent->abbreviation ?? ''),
                (string) ($scent->oil_reference_name ?? ''),
                (string) ($scent->oilBlend?->name ?? ''),
            ]);

            if ($score < 0.08) {
                continue;
            }

            $type = $this->candidateType($scent, false);
            if ($isWholesale && str_contains($type, 'Wholesale')) {
                $score += 0.08;
            }

            $this->upsertCandidate($candidates, [
                'id' => (int) $scent->id,
                'name' => $name,
                'mapping_type' => $type,
                'score' => $score,
                'why' => 'matched canonical scent fields',
            ]);
        }

        if (Schema::hasTable('wholesale_custom_scents')) {
            $customSearchColumns = ['custom_scent_name'];
            if (Schema::hasColumn('wholesale_custom_scents', 'account_name')) {
                $customSearchColumns[] = 'account_name';
            }
            if (Schema::hasColumn('wholesale_custom_scents', 'notes')) {
                $customSearchColumns[] = 'notes';
            }

            $customQuery = WholesaleCustomScent::query()
                ->with(['canonicalScent:id,name,display_name,is_wholesale_custom,is_blend,is_candle_club,oil_blend_id'])
                ->where('active', true)
                ->whereNotNull('canonical_scent_id')
                ->where(function ($query) use ($tokens, $customSearchColumns, $scentSearchColumns): void {
                    $this->applyLooseTextSearch($query, $tokens, $customSearchColumns);
                    $query->orWhereHas('canonicalScent', function ($canonicalQuery) use ($tokens, $scentSearchColumns): void {
                        $this->applyLooseTextSearch($canonicalQuery, $tokens, $scentSearchColumns);
                    });
                })
                ->limit(300)
                ->get();

            $accountNeedle = WholesaleCustomScent::normalizeAccountName((string) ($context['account_name'] ?? ''));

            foreach ($customQuery as $row) {
                $scent = $row->canonicalScent;
                if (! $scent) {
                    continue;
                }

                $name = (string) ($scent->display_name ?: $scent->name ?: '');
                if ($name === '') {
                    continue;
                }

                $customName = trim((string) $row->custom_scent_name);
                $score = $this->candidateScoreFromFields($needle, [
                    $customName,
                    $name,
                    (string) ($scent->name ?? ''),
                    (string) ($row->notes ?? ''),
                    (string) ($row->account_name ?? ''),
                ]) + 0.14;

                if ($score < 0.1) {
                    continue;
                }

                $why = 'matched wholesale custom scent name';
                if ($accountNeedle !== '' && WholesaleCustomScent::normalizeAccountName((string) $row->account_name) === $accountNeedle) {
                    $score += 0.2;
                    $why = 'matched wholesale custom scent name + account history';
                }

                $this->upsertCandidate($candidates, [
                    'id' => (int) $scent->id,
                    'name' => $name,
                    'mapping_type' => $this->candidateType($scent, true),
                    'score' => $score,
                    'why' => $why,
                ]);
            }
        }

        if (Schema::hasTable('scent_aliases')) {
            $aliasScopes = ['markets', 'retail', 'global'];
            if ($isWholesale) {
                $aliasScopes[] = 'wholesale';
                $aliasScopes[] = 'order_type:wholesale';
                if (! empty($context['account_name'])) {
                    $aliasScopes[] = 'account:'.WholesaleCustomScent::normalizeAccountName((string) $context['account_name']);
                }
            }

            $aliases = ScentAlias::query()
                ->with(['scent:id,name,display_name,is_wholesale_custom,is_blend,is_candle_club,oil_blend_id'])
                ->whereIn('scope', array_values(array_unique($aliasScopes)))
                ->where(function ($query) use ($tokens): void {
                    $this->applyLooseTextSearch($query, $tokens, ['alias']);
                })
                ->limit(220)
                ->get();

            foreach ($aliases as $alias) {
                $scent = $alias->scent;
                if (! $scent) {
                    continue;
                }

                $name = (string) ($scent->display_name ?: $scent->name ?: '');
                if ($name === '') {
                    continue;
                }

                $score = max(
                    $this->candidateScoreFromFields($needle, [
                        (string) $alias->alias,
                        $name,
                        (string) ($scent->name ?? ''),
                    ]),
                    0.0
                ) + 0.12;

                if ($score < 0.1) {
                    continue;
                }

                $this->upsertCandidate($candidates, [
                    'id' => (int) $scent->id,
                    'name' => $name,
                    'mapping_type' => $this->candidateType($scent, str_contains((string) $alias->scope, 'wholesale') || str_starts_with((string) $alias->scope, 'account:')),
                    'score' => $score,
                    'why' => 'matched alias rule',
                ]);
            }
        }

        $rows = array_values($candidates);
        usort($rows, function (array $a, array $b) use ($isWholesale): int {
            $aType = (string) ($a['mapping_type'] ?? '');
            $bType = (string) ($b['mapping_type'] ?? '');

            $aPref = $isWholesale && str_contains($aType, 'Wholesale') ? 1 : 0;
            $bPref = $isWholesale && str_contains($bType, 'Wholesale') ? 1 : 0;

            return [$bPref, (float) ($b['score'] ?? 0), (string) ($a['name'] ?? '')]
                <=> [$aPref, (float) ($a['score'] ?? 0), (string) ($b['name'] ?? '')];
        });

        return collect(array_slice($rows, 0, 8))
            ->map(function (array $row): array {
                $row['score'] = (int) max(1, min(99, round(((float) ($row['score'] ?? 0)) * 100)));
                return $row;
            })
            ->values();
    }

    protected function compactSearchText(string $value): string
    {
        $compact = $this->normalizeSearchText($value);
        $compact = preg_replace('/[^a-z0-9]+/i', ' ', $compact) ?? $compact;
        $compact = preg_replace('/\s+/', ' ', $compact) ?? $compact;
        return trim($compact);
    }

    /**
     * @param  array<int,string>  $tokens
     * @param  array<int,string>  $columns
     */
    protected function applyLooseTextSearch($query, array $tokens, array $columns): void
    {
        if ($tokens === [] || $columns === []) {
            return;
        }

        foreach ($tokens as $token) {
            $like = '%'.$token.'%';
            $query->where(function ($tokenQuery) use ($columns, $like): void {
                foreach ($columns as $index => $column) {
                    if ($index === 0) {
                        $tokenQuery->whereRaw("lower(coalesce({$column}, '')) like ?", [$like]);
                    } else {
                        $tokenQuery->orWhereRaw("lower(coalesce({$column}, '')) like ?", [$like]);
                    }
                }
            });
        }
    }

    /**
     * @param  array<int,string>  $fields
     */
    protected function candidateScoreFromFields(string $needle, array $fields): float
    {
        $best = 0.0;
        foreach ($fields as $value) {
            $normalized = $this->normalizeSearchText($value);
            if ($normalized === '') {
                continue;
            }

            $score = $this->textSimilarity($needle, $normalized);
            if ($score > $best) {
                $best = $score;
            }
        }

        return $best;
    }

    protected function candidateType(Scent $scent, bool $viaWholesaleCustom): string
    {
        if ((bool) ($scent->is_candle_club ?? false)) {
            return 'Subscription Drop';
        }

        if ($viaWholesaleCustom || (bool) ($scent->is_wholesale_custom ?? false)) {
            return (bool) ($scent->is_blend ?? false)
                ? 'Wholesale Custom Blend'
                : 'Wholesale Custom Scent';
        }

        return (bool) ($scent->is_blend ?? false)
            ? 'Canonical Blend'
            : 'Canonical Scent';
    }

    /**
     * @param  array<int,array<string,mixed>>  $candidates
     * @param  array<string,mixed>  $candidate
     */
    protected function upsertCandidate(array &$candidates, array $candidate): void
    {
        $id = (int) ($candidate['id'] ?? 0);
        if ($id <= 0) {
            return;
        }

        if (! isset($candidates[$id])) {
            $candidates[$id] = $candidate;
            return;
        }

        if ((float) ($candidate['score'] ?? 0) > (float) ($candidates[$id]['score'] ?? 0)) {
            $candidates[$id] = $candidate;
            return;
        }

        if ((string) ($candidates[$id]['why'] ?? '') === '' && (string) ($candidate['why'] ?? '') !== '') {
            $candidates[$id]['why'] = $candidate['why'];
        }
    }

    protected function findExistingScent(?string $value): ?Scent
    {
        $needle = $this->normalizeSearchText((string) $value);
        if ($needle === '') {
            return null;
        }

        return Scent::query()
            ->get(['id', 'name', 'display_name'])
            ->first(function (Scent $scent) use ($needle): bool {
                return $this->normalizeSearchText((string) $scent->name) === $needle
                    || $this->normalizeSearchText((string) ($scent->display_name ?? '')) === $needle;
            });
    }

    protected function normalizeSearchText(?string $value): string
    {
        $clean = strtolower(trim((string) $value));
        $clean = preg_replace('/\b(wholesale|retail|market|event)\b/i', '', $clean) ?? $clean;
        $clean = str_replace('&', 'and', $clean);
        $clean = preg_replace('/\s+/', ' ', $clean) ?? $clean;
        return trim($clean);
    }

    protected function textSimilarity(string $left, string $right): float
    {
        $left = trim($left);
        $right = trim($right);

        if ($left === '' || $right === '') {
            return 0.0;
        }

        if ($left === $right) {
            return 1.0;
        }

        if (str_contains($left, $right) || str_contains($right, $left)) {
            return 0.92;
        }

        $similar = 0.0;
        similar_text($left, $right, $similar);
        $similarScore = $similar / 100.0;

        $maxLen = max(strlen($left), strlen($right));
        $distanceScore = $maxLen > 0
            ? 1.0 - (levenshtein($left, $right) / $maxLen)
            : 0.0;

        return max(0.0, min(1.0, max($similarScore, $distanceScore)));
    }

    protected function detectSizeId(string $value): ?int
    {
        $needle = $this->normalizeSize($value);
        if ($needle === '') {
            return null;
        }

        $sizes = Size::query()->select(['id', 'code', 'label'])->get();
        foreach ($sizes as $size) {
            $keys = [
                $this->normalizeSize((string) $size->code),
                $this->normalizeSize((string) $size->label),
            ];
            if (in_array($needle, $keys, true)) {
                return (int) $size->id;
            }
        }

        return null;
    }

    protected function normalizeSize(string $value): string
    {
        $lower = strtolower($value);
        $lower = str_replace([' ', '-', '_'], '', $lower);
        $lower = str_replace(['ounces', 'ounce'], 'oz', $lower);
        $lower = str_replace('o z', 'oz', $lower);
        $lower = preg_replace('/[^a-z0-9]+/i', '', $lower) ?? '';

        if ($lower === 'waxmelt') {
            $lower = 'waxmelts';
        }

        if ($lower === 'roomspray') {
            $lower = 'roomsprays';
        }

        return $lower;
    }

    protected function detectWick(string $value): ?string
    {
        $lower = strtolower($value);
        if (str_contains($lower, 'cedar') || str_contains($lower, 'wood')) {
            return 'cedar';
        }

        if (str_contains($lower, 'cotton')) {
            return 'cotton';
        }

        return null;
    }

    protected function syncAliases(Collection $exceptions, int $scentId): void
    {
        if (! Schema::hasTable('scent_aliases')) {
            return;
        }

        $sample = $this->currentExceptionSample();
        $isWholesale = (bool) ($sample && ($sample->store_key === 'wholesale' || filled($sample->account_name)));

        $scopes = ['markets'];
        if ($isWholesale) {
            $scopes[] = 'wholesale';
            $scopes[] = 'order_type:wholesale';

            $account = trim((string) ($sample?->account_name ?? ''));
            if ($account !== '') {
                $scopes[] = 'account:'.WholesaleCustomScent::normalizeAccountName($account);
            }
        }

        $scent = Scent::query()->find($scentId, ['name', 'display_name']);
        $canonicalValues = collect([
            trim((string) ($scent?->name ?? '')),
            trim((string) ($scent?->display_name ?? '')),
        ])->filter()->all();

        $aliases = $exceptions
            ->flatMap(function (MappingException $exception): array {
                return [
                    trim((string) ($exception->raw_scent_name ?? '')),
                    trim((string) ($exception->raw_title ?? '')),
                    $this->exceptionLookupLabel($exception),
                ];
            })
            ->filter()
            ->unique()
            ->values();

        foreach ($aliases as $alias) {
            $alias = mb_substr($alias, 0, 255);
            if (in_array($alias, $canonicalValues, true)) {
                continue;
            }

            if ($alias === '') {
                continue;
            }

            foreach (array_values(array_unique($scopes)) as $scope) {
                ScentAlias::query()->updateOrCreate(
                    ['alias' => $alias, 'scope' => $scope],
                    ['scent_id' => $scentId]
                );
            }
        }
    }

    protected function syncWholesaleCustomMappings(Collection $exceptions, int $scentId): void
    {
        if (! Schema::hasTable('wholesale_custom_scents')) {
            return;
        }

        $exceptions
            ->filter(fn (MappingException $exception): bool => filled($exception->account_name))
            ->each(function (MappingException $exception) use ($scentId): void {
                $accountName = mb_substr(trim((string) $exception->account_name), 0, 255);
                $customName = mb_substr(trim($this->exceptionLookupLabel($exception)), 0, 255);

                if ($accountName === '' || $customName === '') {
                    return;
                }

                WholesaleCustomScent::query()->updateOrCreate(
                    [
                        'account_name' => $accountName,
                        'custom_scent_name' => $customName,
                    ],
                    [
                        'canonical_scent_id' => $scentId,
                        'active' => true,
                    ]
                );
            });
    }

    protected function exceptionLookupLabel(MappingException $exception): string
    {
        $rawScentName = trim((string) ($exception->raw_scent_name ?? ''));
        $rawTitle = trim((string) ($exception->raw_title ?? ''));
        $rawVariant = trim((string) ($exception->raw_variant ?? ''));

        $primary = $rawScentName !== '' ? $rawScentName : $rawTitle;
        if ($primary !== '' && ! $this->isVariantDrivenTitle($primary)) {
            return $primary;
        }

        $fromVariant = $this->extractScentLabelFromVariant($rawVariant);
        if ($fromVariant !== '') {
            return $fromVariant;
        }

        return $primary;
    }

    protected function isVariantDrivenTitle(string $value): bool
    {
        $normalized = mb_strtolower(trim($value));
        if ($normalized === '') {
            return false;
        }

        $normalized = preg_replace('/\s+/', ' ', $normalized) ?? $normalized;

        return preg_match('/\b(sale candles?|custom scents?|house blends?)\b/u', $normalized) === 1;
    }

    protected function extractScentLabelFromVariant(string $value): string
    {
        $clean = trim($value);
        if ($clean === '') {
            return '';
        }

        $clean = preg_replace('/\b(\d+(?:\.\d+)?)\s*oz\b/iu', '', $clean) ?? $clean;
        $clean = preg_replace('/\b(cotton|wood|cedar)\s*wick\b/iu', '', $clean) ?? $clean;
        $clean = preg_replace('/\b(wax\s*melts?|room\s*sprays?)\b/iu', '', $clean) ?? $clean;
        $clean = preg_replace('/\b(jar|tin)\b/iu', '', $clean) ?? $clean;
        $clean = preg_replace('/[\-–—|\\/]+/u', ' ', $clean) ?? $clean;
        $clean = preg_replace('/\s{2,}/u', ' ', $clean) ?? $clean;
        $clean = trim($clean, " \t\n\r\0\x0B,.");

        return $clean;
    }

    protected function isOrderScentSizeUniqueViolation(QueryException $e): bool
    {
        $sqlState = (string) ($e->errorInfo[0] ?? '');
        $message = strtolower((string) $e->getMessage());

        if (in_array($sqlState, ['23000', '23505'], true) && str_contains($message, 'order_lines')) {
            return true;
        }

        return str_contains($message, 'order_lines_unique_order_scent_size_not_null')
            || str_contains($message, 'order_lines_order_scent_size_unique')
            || str_contains($message, 'duplicate entry')
            || str_contains($message, 'unique constraint failed: order_lines.order_id, order_lines.scent_id, order_lines.size_id');
    }

    protected function mergeLineIntoExistingScentSize(OrderLine $line, int $scentId, int $sizeId): OrderLine
    {
        $hasQuantity = Schema::hasColumn('order_lines', 'quantity');
        $hasExtraQty = Schema::hasColumn('order_lines', 'extra_qty');

        $existing = OrderLine::query()
            ->where('order_id', $line->order_id)
            ->where('scent_id', $scentId)
            ->where('size_id', $sizeId)
            ->whereKeyNot($line->id)
            ->first();

        if (! $existing) {
            // No merge target found; propagate the original failure.
            throw new \RuntimeException('Unable to merge duplicate order line mapping for this scent/size.');
        }

        $existing->ordered_qty = (int) ($existing->ordered_qty ?? 0) + (int) ($line->ordered_qty ?? 0);

        if ($hasQuantity) {
            $existing->quantity = (int) ($existing->quantity ?? 0) + (int) ($line->quantity ?? $line->ordered_qty ?? 0);
        }

        if ($hasExtraQty) {
            $existing->extra_qty = (int) ($existing->extra_qty ?? 0) + (int) ($line->extra_qty ?? 0);
        }

        if (Schema::hasColumn('order_lines', 'scent_name') && blank($existing->scent_name) && filled($line->scent_name)) {
            $existing->scent_name = $line->scent_name;
        }
        if (Schema::hasColumn('order_lines', 'size_code') && blank($existing->size_code) && filled($line->size_code)) {
            $existing->size_code = $line->size_code;
        }
        if (Schema::hasColumn('order_lines', 'wick_type') && blank($existing->wick_type) && filled($line->wick_type)) {
            $existing->wick_type = $line->wick_type;
        }

        $existing->save();

        MappingException::query()
            ->where('order_line_id', $line->id)
            ->update(['order_line_id' => $existing->id]);

        $line->delete();

        return $existing;
    }
}

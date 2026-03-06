<?php

namespace App\Livewire\Intake;

use App\Models\CandleClubScent;
use App\Models\MappingException;
use App\Models\OrderLine;
use App\Models\Scent;
use App\Models\ScentAlias;
use App\Models\Size;
use App\Models\WholesaleCustomScent;
use App\Services\ScentGuessEngine;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Component;

class ProgressiveMapper extends Component
{
    public array $exceptionIds = [];
    public int $step = 1;
    public string $classification = '';

    /** @var array<int,array<string,mixed>> */
    public array $guesses = [];

    public ?int $selectedScentId = null;
    public bool $manualMode = false;
    public string $existingScentSearch = '';

    public ?int $sizeId = null;
    public ?string $wickType = null;

    public string $candleClubMonth = '';
    public string $candleClubYear = '';
    public string $candleClubScent = '';
    public string $candleClubOil = '';
    public string $newScentName = '';
    public string $newScentDisplay = '';
    public string $newScentAbbr = '';
    public string $newScentOil = '';
    public bool $newScentIsBlend = false;
    public ?int $newScentBlendCount = null;

    public array $relatedExceptionIds = [];
    public array $applyExceptionIds = [];
    public bool $applyAllRelated = false;

    /** @var array<int,int> */
    public array $batchExceptionIds = [];
    public bool $batchApplyRemaining = false;
    public string $batchScope = 'none'; // none|this_import|this_account|order_type|all_wholesale|subscription_drop
    public string $preferredGuessType = '';

    public function mount(array $exceptionIds = []): void
    {
        $this->exceptionIds = $exceptionIds;
        $this->step = 1;
        $this->candleClubMonth = (string) now()->month;
        $this->candleClubYear = (string) now()->year;
        $this->refreshContextDefaults();
    }

    public function toggleAdvanced(): void
    {
        $this->manualMode = ! $this->manualMode;

        if ($this->manualMode && $this->step < 3) {
            $this->step = 3;
            $this->prefillFromException();
            $this->loadRelatedExceptions();
            $this->loadBatchExceptionCandidates();
        }
    }

    public function classify(string $classification): void
    {
        $this->classification = $classification;
        $this->preferredGuessType = '';

        if (in_array($classification, ['wholesale-custom-new-scent', 'wholesale-custom-new-blend'], true)) {
            $this->manualMode = true;
            $this->step = 3;
            $this->newScentIsBlend = $classification === 'wholesale-custom-new-blend';
            if ($this->newScentIsBlend && !$this->newScentBlendCount) {
                $this->newScentBlendCount = 2;
            }
            $this->prefillFromException();
            $this->loadRelatedExceptions();
            $this->loadBatchExceptionCandidates();

            return;
        }

        $this->preferredGuessType = match ($classification) {
            'wholesale-custom-existing' => 'wholesale_custom_scent',
            'wholesale-custom-blend-existing' => 'wholesale_custom_blend',
            'subscription' => 'subscription_drop',
            default => '',
        };

        $this->step = 2;
        $this->buildGuesses();
        $this->prefillFromException();
        $this->loadRelatedExceptions();
        $this->loadBatchExceptionCandidates();
    }

    public function acceptGuess(int $scentId): void
    {
        $this->selectedScentId = $scentId;
        $selected = Scent::query()->find($scentId, ['name', 'display_name']);
        $this->existingScentSearch = (string) ($selected?->display_name ?: $selected?->name ?: '');
        $this->step = 3;
        $this->loadBatchExceptionCandidates();
    }

    public function manualSearch(): void
    {
        $this->manualMode = true;
        $this->step = 3;
        $this->prefillFromException();
        $this->loadRelatedExceptions();
        $this->loadBatchExceptionCandidates();
    }

    public function save(): void
    {
        if (empty($this->exceptionIds)) {
            return;
        }

        $targetIds = $this->exceptionIds;
        if ($this->classification === 'subscription' && ! empty($this->relatedExceptionIds)) {
            if ($this->applyAllRelated) {
                $targetIds = array_values(array_unique(array_merge($targetIds, $this->relatedExceptionIds)));
            } elseif (! empty($this->applyExceptionIds)) {
                $targetIds = array_values(array_unique(array_merge($targetIds, $this->applyExceptionIds)));
            }
        }

        $exceptions = MappingException::query()->whereIn('id', $targetIds)->get();

        if ($this->classification === 'non-candle') {
            MappingException::query()->whereIn('id', $targetIds)->update([
                'excluded_at' => now(),
                'excluded_by' => auth()->user()?->email ?? 'system',
                'excluded_reason' => 'not_a_product',
            ]);
            $this->dispatch('toast', ['type' => 'success', 'message' => 'Excluded non-candle item.']);
            $this->dispatch('intake-done');

            return;
        }

        $scentId = $this->selectedScentId;
        if (! $scentId && $this->classification === 'subscription') {
            $name = trim($this->candleClubScent);
            if ($name === '' || $this->candleClubMonth === '' || $this->candleClubYear === '' || trim($this->candleClubOil) === '') {
                $this->dispatch('toast', ['type' => 'warning', 'message' => 'Month, Year, Scent and Oil required.']);

                return;
            }
            $monthName = \Carbon\Carbon::create()->month((int) $this->candleClubMonth)->format('F');
            $display = $monthName.' '.$this->candleClubYear.' Candle Club — '.$name;
            $normalized = Scent::normalizeName($display);
            $scent = Scent::query()->firstOrCreate(
                ['name' => $normalized],
                [
                    'display_name' => $display,
                    'oil_reference_name' => trim($this->candleClubOil),
                    'is_candle_club' => true,
                    'is_active' => true,
                ]
            );
            $scent->forceFill([
                'display_name' => $display,
                'oil_reference_name' => trim($this->candleClubOil),
                'is_candle_club' => true,
                'is_active' => true,
            ])->save();
            CandleClubScent::query()->updateOrCreate(
                ['month' => (int) $this->candleClubMonth, 'year' => (int) $this->candleClubYear],
                ['scent_id' => $scent->id]
            );
            $scentId = $scent->id;
        }

        if (! $scentId) {
            $scent = $this->resolveSelectedOrNewScent($exceptions);
            if (! $scent) {
                return;
            }

            $scentId = $scent->id;
        }

        $allTouchedExceptions = $exceptions;
        $batchApplied = 0;

        DB::transaction(function () use ($exceptions, $scentId, &$allTouchedExceptions, &$batchApplied): void {
            $this->applyMappingToExceptions($exceptions, $scentId);

            if ($this->batchApplyRemaining) {
                $batchExceptions = $this->batchExceptionsForApplication();
                if ($batchExceptions->isNotEmpty()) {
                    $this->applyMappingToExceptions($batchExceptions, $scentId);
                    $batchApplied = $batchExceptions->count();
                    $allTouchedExceptions = $exceptions
                        ->concat($batchExceptions)
                        ->unique('id')
                        ->values();
                }
            }
        });

        $this->syncAliases($allTouchedExceptions, $scentId);
        $this->syncWholesaleCustomMappings($allTouchedExceptions, $scentId);
        $this->persistBatchRule($allTouchedExceptions, $scentId);

        $message = 'Mapping applied.';
        if ($batchApplied > 0) {
            $message .= " Also updated {$batchApplied} matching exceptions.";
        }

        $this->dispatch('toast', ['type' => 'success', 'message' => $message]);
        $this->dispatch('intake-done');
    }

    protected function applyMappingToExceptions(Collection $exceptions, int $scentId): void
    {
        $scentName = Schema::hasColumn('order_lines', 'scent_name')
            ? (Scent::query()->find($scentId)?->name)
            : null;

        $sizeCode = ($this->sizeId && Schema::hasColumn('order_lines', 'size_code'))
            ? (Size::query()->find($this->sizeId)?->code)
            : null;

        foreach ($exceptions as $exception) {
            $line = $exception->order_line_id ? OrderLine::query()->find($exception->order_line_id) : null;
            if (! $line) {
                continue;
            }

            $line->scent_id = $scentId;
            if ($this->sizeId) {
                $line->size_id = $this->sizeId;
            }
            if ($this->wickType) {
                $line->wick_type = $this->wickType;
            }
            if (Schema::hasColumn('order_lines', 'scent_name')) {
                $line->scent_name = $scentName;
            }
            if ($this->sizeId && Schema::hasColumn('order_lines', 'size_code')) {
                $line->size_code = $sizeCode;
            }
            $line->save();

            if (! empty($line->scent_id) && ! empty($line->size_id)) {
                $exception->resolved_at = now();
                $exception->resolved_by = auth()->id();
                $exception->canonical_scent_id = $line->scent_id;
                $exception->save();

                if ($exception->order_id) {
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

    protected function buildGuesses(): void
    {
        $context = $this->mappingContext();
        $candidates = $this->rankedCandidates(null, 10, true, $context);

        if ($this->preferredGuessType !== '') {
            $preferred = array_values(array_filter($candidates, fn (array $candidate): bool => (string) ($candidate['type_key'] ?? '') === $this->preferredGuessType));
            $others = array_values(array_filter($candidates, fn (array $candidate): bool => (string) ($candidate['type_key'] ?? '') !== $this->preferredGuessType));
            $candidates = array_merge($preferred, $others);
        }

        $this->guesses = array_slice($candidates, 0, 5);

        if ($this->guesses === []) {
            $exception = $this->currentExceptionSample();
            $payload = $exception?->payload_json ?? null;
            $properties = is_array($payload) ? ($payload['properties'] ?? null) : null;
            $engine = app(ScentGuessEngine::class);
            $fallback = $engine->guess($exception?->raw_title, $exception?->raw_variant, $properties, 3);

            $this->guesses = collect($fallback)
                ->map(function (array $guess): array {
                    return [
                        'id' => (int) ($guess['id'] ?? 0),
                        'name' => (string) ($guess['name'] ?? ''),
                        'score' => (int) round((float) ($guess['score'] ?? 0)),
                        'type_key' => 'canonical_scent',
                        'mapping_type' => 'Canonical Scent',
                        'reasons' => ['similar title'],
                    ];
                })
                ->filter(fn (array $guess): bool => (int) $guess['id'] > 0)
                ->values()
                ->all();
        }
    }

    protected function loadRelatedExceptions(): void
    {
        $this->relatedExceptionIds = [];
        $this->applyExceptionIds = [];
        $this->applyAllRelated = false;

        $exception = $this->currentExceptionSample();
        if (! $exception) {
            return;
        }
        if (($exception->reason ?? '') !== 'candle_club') {
            return;
        }

        $rawTitle = (string) $exception->raw_title;
        if ($rawTitle === '') {
            return;
        }

        $related = MappingException::query()
            ->where('reason', 'candle_club')
            ->whereNull('resolved_at')
            ->where('raw_title', $rawTitle)
            ->pluck('id')
            ->all();

        $this->relatedExceptionIds = array_values(array_diff($related, $this->exceptionIds));
    }

    protected function loadBatchExceptionCandidates(): void
    {
        $this->batchExceptionIds = [];

        $context = $this->mappingContext();
        $rawLabel = trim((string) ($context['raw_label'] ?? ''));
        if ($rawLabel === '') {
            $this->batchApplyRemaining = false;

            return;
        }

        $query = MappingException::query()
            ->whereNull('resolved_at')
            ->whereNull('excluded_at')
            ->whereNotIn('id', $this->exceptionIds)
            ->where(function ($inner) use ($rawLabel): void {
                $inner->where('raw_scent_name', $rawLabel)
                    ->orWhere('raw_title', $rawLabel);
            });

        if (! empty($context['store_key'])) {
            $query->where('store_key', $context['store_key']);
        }

        $this->batchExceptionIds = $query
            ->orderByDesc('id')
            ->limit(250)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->values()
            ->all();

        if ($this->batchScope === 'none') {
            if ((bool) ($context['is_wholesale'] ?? false) && ! empty($context['account_name'])) {
                $this->batchScope = 'this_account';
            } elseif ((bool) ($context['is_subscription_like'] ?? false)) {
                $this->batchScope = 'subscription_drop';
            }
        }

        $this->batchApplyRemaining = count($this->batchExceptionIds) > 0
            && ((bool) ($context['is_wholesale'] ?? false) || (bool) ($context['is_subscription_like'] ?? false));
    }

    protected function batchExceptionsForApplication(): Collection
    {
        if ($this->batchExceptionIds === []) {
            return collect();
        }

        $context = $this->mappingContext();

        $query = MappingException::query()
            ->whereIn('id', $this->batchExceptionIds)
            ->whereNull('resolved_at')
            ->whereNull('excluded_at');

        if ($this->batchScope === 'this_account' && ! empty($context['account_name'])) {
            $normalizedAccount = WholesaleCustomScent::normalizeAccountName((string) $context['account_name']);
            $query->whereRaw('lower(coalesce(account_name, "")) = ?', [mb_strtolower($normalizedAccount)]);
        }

        if ($this->batchScope === 'subscription_drop') {
            $query->where(function ($inner): void {
                $inner->where('reason', 'candle_club')
                    ->orWhere('raw_title', 'like', '%Scent of the Month%')
                    ->orWhere('raw_title', 'like', '%Candle Club%')
                    ->orWhere('raw_title', 'like', '%Subscription%');
            });
        }

        return $query->get();
    }

    protected function prefillFromException(): void
    {
        $exception = $this->currentExceptionSample();
        if (! $exception) {
            return;
        }

        $line = $exception->order_line_id ? OrderLine::query()->find($exception->order_line_id) : null;
        if ($line) {
            if (! $this->sizeId && $line->size_id) {
                $this->sizeId = $line->size_id;
            }
            if (! $this->wickType && ! empty($line->wick_type)) {
                $this->wickType = $line->wick_type;
            }
            if (! $this->selectedScentId && ! empty($line->scent_id)) {
                $this->selectedScentId = $line->scent_id;
            }
        }

        $rawTitle = (string) ($exception->raw_title ?? '');
        $rawVariant = (string) ($exception->raw_variant ?? '');

        if (! $this->wickType) {
            $wick = $this->detectWick($rawVariant.' '.$rawTitle);
            if ($wick) {
                $this->wickType = $wick;
            }
        }

        if (! $this->sizeId) {
            $sizeId = $this->detectSizeId($rawVariant ?: $rawTitle);
            if ($sizeId) {
                $this->sizeId = $sizeId;
            }
        }

        if (! $this->selectedScentId && ! empty($this->guesses)) {
            $this->selectedScentId = (int) ($this->guesses[0]['id'] ?? 0) ?: null;
        }

        if ($this->selectedScentId && $this->existingScentSearch === '') {
            $selected = Scent::query()->find($this->selectedScentId, ['name', 'display_name']);
            $this->existingScentSearch = (string) ($selected?->display_name ?: $selected?->name ?: '');
        }

        if (in_array($this->classification, ['wholesale-custom-new-blend'], true)) {
            $this->newScentIsBlend = true;
            if (! $this->newScentBlendCount) {
                $this->newScentBlendCount = 2;
            }
        }

        $this->prefillNewScentFields($exception);
        $this->refreshContextDefaults();
    }

    protected function refreshContextDefaults(): void
    {
        $context = $this->mappingContext();
        if ((bool) ($context['is_subscription_like'] ?? false) && $this->classification === '') {
            $this->classification = 'subscription';
        }
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
                return $size->id;
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

    public function render()
    {
        $contextException = $this->currentExceptionSample();
        $mappingContext = $this->mappingContext($contextException);

        return view('livewire.intake.progressive-mapper', [
            'sizes' => Size::query()->orderBy('label')->orderBy('code')->get(),
            'matchingScents' => $this->matchingScents($mappingContext),
            'contextException' => $contextException,
            'mappingContext' => $mappingContext,
            'batchScopeOptions' => $this->batchScopeOptions($mappingContext),
        ]);
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
        $rawLabel = $rawScentName !== '' ? $rawScentName : $rawTitle;

        $combined = trim(implode(' ', array_filter([$rawLabel, $rawVariant, $rawTitle])));

        $isWholesale = $storeKey === 'wholesale'
            || $orderType === 'wholesale'
            || $accountName !== '';

        $isSubscriptionLike = $this->looksLikeSubscriptionText($combined)
            || (($exception?->reason ?? '') === 'candle_club');

        $isBlendLike = $this->looksLikeBlendText($combined);

        $aliasScopes = ['markets'];
        if ($isWholesale) {
            $aliasScopes[] = 'wholesale';
            $aliasScopes[] = 'order_type:wholesale';
            if ($accountName !== '') {
                $aliasScopes[] = 'account:'.WholesaleCustomScent::normalizeAccountName($accountName);
            }
        }
        if ($isSubscriptionLike) {
            $aliasScopes[] = 'subscription_drop';
        }

        return [
            'store_key' => $storeKey,
            'order_type' => $orderType,
            'account_name' => $accountName,
            'raw_title' => $rawTitle,
            'raw_variant' => $rawVariant,
            'raw_scent_name' => $rawScentName,
            'raw_label' => $rawLabel,
            'combined_text' => $combined,
            'is_wholesale' => $isWholesale,
            'is_subscription_like' => $isSubscriptionLike,
            'is_blend_like' => $isBlendLike,
            'alias_scopes' => array_values(array_unique($aliasScopes)),
        ];
    }

    protected function looksLikeSubscriptionText(string $value): bool
    {
        $lower = strtolower($value);

        foreach (['scent of the month', 'candle club', 'subscription', 'monthly scent', 'club scent'] as $needle) {
            if (str_contains($lower, $needle)) {
                return true;
            }
        }

        return false;
    }

    protected function looksLikeBlendText(string $value): bool
    {
        $lower = strtolower($value);

        foreach (['blend', 'mix', 'fusion', '12 same', '6 + 6'] as $needle) {
            if (str_contains($lower, $needle)) {
                return true;
            }
        }

        return false;
    }

    protected function resolveSelectedOrNewScent(Collection $exceptions): ?Scent
    {
        if ($this->selectedScentId) {
            $selected = Scent::query()->find($this->selectedScentId);
            if ($selected) {
                return $selected;
            }
        }

        $existing = $this->findExistingScent($this->existingScentSearch);
        if ($existing) {
            $this->selectedScentId = (int) $existing->id;

            return $existing;
        }

        $name = trim($this->newScentName);
        if ($name === '') {
            $this->dispatch('toast', ['type' => 'warning', 'message' => 'Pick an existing scent or enter a new scent name.']);

            return null;
        }

        $normalized = Scent::normalizeName($name);
        $displayName = trim($this->newScentDisplay) !== '' ? trim($this->newScentDisplay) : $name;
        $scent = $this->findExistingScent($name);

        $context = $this->mappingContext();
        $wholesaleClassification = in_array($this->classification, [
            'wholesale-custom-existing',
            'wholesale-custom-blend-existing',
            'wholesale-custom-new-scent',
            'wholesale-custom-new-blend',
        ], true);

        if (! $scent) {
            $scent = Scent::query()->create([
                'name' => $normalized,
                'display_name' => $displayName,
                'abbreviation' => trim($this->newScentAbbr) !== '' ? trim($this->newScentAbbr) : null,
                'oil_reference_name' => trim($this->newScentOil) !== '' ? trim($this->newScentOil) : null,
                'is_blend' => $this->newScentIsBlend,
                'blend_oil_count' => $this->newScentIsBlend ? ($this->newScentBlendCount ?: null) : null,
                'is_wholesale_custom' => $wholesaleClassification || (bool) ($context['is_wholesale'] ?? false) || $exceptions->contains(fn (MappingException $exception) => filled($exception->account_name)),
                'is_candle_club' => $this->classification === 'subscription' || (bool) ($context['is_subscription_like'] ?? false),
                'is_active' => true,
            ]);
        } else {
            $scent->fill(array_filter([
                'display_name' => $scent->display_name ?: $displayName,
                'abbreviation' => $scent->abbreviation ?: (trim($this->newScentAbbr) !== '' ? trim($this->newScentAbbr) : null),
                'oil_reference_name' => $scent->oil_reference_name ?: (trim($this->newScentOil) !== '' ? trim($this->newScentOil) : null),
                'blend_oil_count' => $scent->blend_oil_count ?: ($this->newScentIsBlend ? ($this->newScentBlendCount ?: null) : null),
            ], fn ($value) => $value !== null && $value !== ''));
            if ($this->newScentIsBlend || in_array($this->classification, ['wholesale-custom-new-blend', 'wholesale-custom-blend-existing'], true)) {
                $scent->is_blend = true;
            }
            if ($wholesaleClassification || (bool) ($context['is_wholesale'] ?? false) || $exceptions->contains(fn (MappingException $exception) => filled($exception->account_name))) {
                $scent->is_wholesale_custom = true;
            }
            if ($this->classification === 'subscription' || (bool) ($context['is_subscription_like'] ?? false)) {
                $scent->is_candle_club = true;
            }
            $scent->is_active = true;
            if ($scent->isDirty()) {
                $scent->save();
            }
        }

        $this->selectedScentId = (int) $scent->id;

        return $scent;
    }

    protected function findExistingScent(?string $value): ?Scent
    {
        $needle = Scent::normalizeName((string) $value);
        if ($needle === '') {
            return null;
        }

        return Scent::query()
            ->get()
            ->first(function (Scent $scent) use ($needle): bool {
                return Scent::normalizeName((string) $scent->name) === $needle
                    || Scent::normalizeName((string) ($scent->display_name ?? '')) === $needle;
            });
    }

    /**
     * @return Collection<int,array<string,mixed>>
     */
    protected function matchingScents(array $context): Collection
    {
        $search = trim($this->existingScentSearch);

        return collect($this->rankedCandidates($search !== '' ? $search : null, 12, false, $context));
    }

    /**
     * @param  array<string,mixed>  $context
     * @return array<int,array<string,mixed>>
     */
    protected function rankedCandidates(?string $searchTerm, int $limit, bool $guided, array $context): array
    {
        $needle = $this->normalizeSearchText($searchTerm !== null ? $searchTerm : (string) ($context['combined_text'] ?? ''));
        $rawLabelNeedle = $this->normalizeSearchText((string) ($context['raw_label'] ?? ''));
        $blendLike = (bool) ($context['is_blend_like'] ?? false);
        $isWholesale = (bool) ($context['is_wholesale'] ?? false);
        $isSubscription = (bool) ($context['is_subscription_like'] ?? false);

        /** @var array<int,array<string,mixed>> $candidateMap */
        $candidateMap = [];

        $scentRows = Scent::query()
            ->select(['id', 'name', 'display_name', 'abbreviation', 'oil_reference_name', 'is_blend', 'is_wholesale_custom', 'is_candle_club', 'is_active'])
            ->where(function ($query) use ($searchTerm): void {
                $search = trim((string) $searchTerm);
                if ($search === '') {
                    return;
                }

                $like = '%'.$search.'%';
                $query->where('name', 'like', $like)
                    ->orWhere('display_name', 'like', $like)
                    ->orWhere('abbreviation', 'like', $like)
                    ->orWhere('oil_reference_name', 'like', $like);
            })
            ->when(trim((string) $searchTerm) === '', fn ($query) => $query->limit(400))
            ->orderByRaw('COALESCE(display_name, name)')
            ->get();

        foreach ($scentRows as $scent) {
            $label = (string) ($scent->display_name ?: $scent->name ?: '');
            if ($label === '') {
                continue;
            }

            $normalizedLabel = $this->normalizeSearchText($label);
            $normalizedAbbr = $this->normalizeSearchText((string) ($scent->abbreviation ?? ''));
            $normalizedOil = $this->normalizeSearchText((string) ($scent->oil_reference_name ?? ''));

            $score = max(
                $this->textSimilarity($needle, $normalizedLabel),
                $this->textSimilarity($rawLabelNeedle, $normalizedLabel),
                $this->textSimilarity($needle, $normalizedAbbr),
                $this->textSimilarity($needle, $normalizedOil)
            );

            $reasons = [];
            if ($score >= 0.5) {
                $reasons[] = 'similar title';
            }
            if ($normalizedOil !== '' && $this->textSimilarity($needle, $normalizedOil) >= 0.6) {
                $score += 0.08;
                $reasons[] = 'same oil reference';
            }
            if ($isWholesale && (bool) $scent->is_wholesale_custom) {
                $score += 0.12;
                $reasons[] = 'prior wholesale mapping';
            }
            if ($isSubscription && (bool) $scent->is_candle_club) {
                $score += 0.24;
                $reasons[] = 'recurring monthly pattern';
            }
            if ($blendLike && (bool) $scent->is_blend) {
                $score += 0.1;
                $reasons[] = 'blend keyword match';
            }

            if ($searchTerm !== null && $searchTerm !== '') {
                if ($score < 0.12 && ! str_contains($normalizedLabel, $needle)) {
                    continue;
                }
            } elseif ($guided && $score < 0.24) {
                continue;
            }

            $this->upsertCandidate($candidateMap, [
                'id' => (int) $scent->id,
                'name' => $label,
                'score' => $score,
                'type_key' => $this->candidateTypeKey($scent, false, $isSubscription),
                'mapping_type' => $this->candidateTypeLabel($scent, false, $isSubscription),
                'reasons' => $reasons,
            ]);
        }

        if (Schema::hasTable('wholesale_custom_scents')) {
            $customQuery = WholesaleCustomScent::query()
                ->with(['canonicalScent:id,name,display_name,abbreviation,oil_reference_name,is_blend,is_wholesale_custom,is_candle_club'])
                ->where('active', true)
                ->whereNotNull('canonical_scent_id');

            $accountName = trim((string) ($context['account_name'] ?? ''));
            if ($isWholesale && $accountName !== '') {
                $customQuery->whereRaw('lower(account_name) = ?', [mb_strtolower(WholesaleCustomScent::normalizeAccountName($accountName))]);
            } elseif ($searchTerm !== null && trim($searchTerm) !== '') {
                $like = '%'.trim($searchTerm).'%';
                $customQuery->where(function ($query) use ($like): void {
                    $query->where('custom_scent_name', 'like', $like)
                        ->orWhere('account_name', 'like', $like)
                        ->orWhere('notes', 'like', $like);
                });
            } else {
                $customQuery->limit(300);
            }

            foreach ($customQuery->get() as $row) {
                $scent = $row->canonicalScent;
                if (! $scent) {
                    continue;
                }

                $customName = trim((string) $row->custom_scent_name);
                $normalizedCustom = $this->normalizeSearchText($customName);
                $score = max(
                    $this->textSimilarity($needle, $normalizedCustom),
                    $this->textSimilarity($rawLabelNeedle, $normalizedCustom)
                );

                $reasons = [];
                if ($score >= 0.5) {
                    $reasons[] = 'similar title';
                }

                if ($isWholesale) {
                    $score += 0.16;
                    $reasons[] = 'prior wholesale mapping';
                }

                if ($accountName !== '' && WholesaleCustomScent::normalizeAccountName((string) $row->account_name) === WholesaleCustomScent::normalizeAccountName($accountName)) {
                    $score += 0.3;
                    $reasons[] = 'same account used before';
                }

                if ($score < 0.12 && ! $guided) {
                    continue;
                }

                $this->upsertCandidate($candidateMap, [
                    'id' => (int) $scent->id,
                    'name' => (string) ($scent->display_name ?: $scent->name),
                    'score' => $score,
                    'type_key' => $this->candidateTypeKey($scent, true, $isSubscription),
                    'mapping_type' => $this->candidateTypeLabel($scent, true, $isSubscription),
                    'reasons' => array_merge($reasons, $customName !== '' ? ["custom name: {$customName}"] : []),
                ]);
            }
        }

        if (Schema::hasTable('scent_aliases')) {
            $aliasScopes = (array) ($context['alias_scopes'] ?? ['markets']);
            $aliasQuery = ScentAlias::query()
                ->with(['scent:id,name,display_name,abbreviation,oil_reference_name,is_blend,is_wholesale_custom,is_candle_club'])
                ->whereIn('scope', $aliasScopes);

            if ($searchTerm !== null && trim($searchTerm) !== '') {
                $aliasQuery->where('alias', 'like', '%'.trim($searchTerm).'%');
            } else {
                $rawLabel = trim((string) ($context['raw_label'] ?? ''));
                if ($rawLabel !== '') {
                    $aliasQuery->whereRaw('lower(alias) = ?', [mb_strtolower($rawLabel)]);
                }
            }

            foreach ($aliasQuery->limit(200)->get() as $alias) {
                if (! $alias->scent) {
                    continue;
                }

                $aliasNeedle = $this->normalizeSearchText((string) $alias->alias);
                $score = max(
                    $this->textSimilarity($needle, $aliasNeedle),
                    $this->textSimilarity($rawLabelNeedle, $aliasNeedle)
                );

                if ($score < 0.14 && ! $guided) {
                    continue;
                }

                $bonus = str_starts_with((string) $alias->scope, 'account:') ? 0.3 : 0.18;
                $this->upsertCandidate($candidateMap, [
                    'id' => (int) $alias->scent_id,
                    'name' => (string) ($alias->scent->display_name ?: $alias->scent->name),
                    'score' => $score + $bonus,
                    'type_key' => $this->candidateTypeKey($alias->scent, str_contains((string) $alias->scope, 'wholesale') || str_starts_with((string) $alias->scope, 'account:'), $isSubscription),
                    'mapping_type' => $this->candidateTypeLabel($alias->scent, str_contains((string) $alias->scope, 'wholesale') || str_starts_with((string) $alias->scope, 'account:'), $isSubscription),
                    'reasons' => [
                        'prior alias rule',
                        (string) ('scope: '.$alias->scope),
                    ],
                ]);
            }
        }

        $candidates = array_values($candidateMap);

        usort($candidates, function (array $a, array $b) use ($isWholesale, $isSubscription): int {
            $aPreferred = $this->candidatePriority((string) ($a['type_key'] ?? ''), $isWholesale, $isSubscription);
            $bPreferred = $this->candidatePriority((string) ($b['type_key'] ?? ''), $isWholesale, $isSubscription);

            return [$bPreferred, (float) ($b['score'] ?? 0), (string) ($a['name'] ?? '')]
                <=> [$aPreferred, (float) ($a['score'] ?? 0), (string) ($b['name'] ?? '')];
        });

        return array_map(function (array $candidate): array {
            $candidate['score'] = (int) max(1, min(99, round(((float) ($candidate['score'] ?? 0)) * 100)));
            $candidate['reasons'] = array_values(array_unique(array_filter(array_map('trim', (array) ($candidate['reasons'] ?? [])))));

            return $candidate;
        }, array_slice($candidates, 0, $limit));
    }

    protected function candidatePriority(string $typeKey, bool $isWholesale, bool $isSubscription): int
    {
        $priority = 0;

        if ($this->preferredGuessType !== '' && $typeKey === $this->preferredGuessType) {
            $priority += 3;
        }

        if ($isWholesale && str_starts_with($typeKey, 'wholesale_custom')) {
            $priority += 2;
        }

        if ($isSubscription && $typeKey === 'subscription_drop') {
            $priority += 2;
        }

        return $priority;
    }

    protected function candidateTypeKey(Scent $scent, bool $viaWholesaleCustom, bool $subscriptionLike): string
    {
        if ($subscriptionLike && (bool) ($scent->is_candle_club ?? false)) {
            return 'subscription_drop';
        }

        if ($viaWholesaleCustom || (bool) ($scent->is_wholesale_custom ?? false)) {
            return (bool) ($scent->is_blend ?? false)
                ? 'wholesale_custom_blend'
                : 'wholesale_custom_scent';
        }

        return (bool) ($scent->is_blend ?? false)
            ? 'canonical_blend'
            : 'canonical_scent';
    }

    protected function candidateTypeLabel(Scent $scent, bool $viaWholesaleCustom, bool $subscriptionLike): string
    {
        return match ($this->candidateTypeKey($scent, $viaWholesaleCustom, $subscriptionLike)) {
            'subscription_drop' => 'Subscription Drop',
            'wholesale_custom_blend' => 'Wholesale Custom Blend',
            'wholesale_custom_scent' => 'Wholesale Custom Scent',
            'canonical_blend' => 'Canonical Blend',
            default => 'Canonical Scent',
        };
    }

    /**
     * @param  array<int,array<string,mixed>>  $candidateMap
     * @param  array<string,mixed>  $candidate
     */
    protected function upsertCandidate(array &$candidateMap, array $candidate): void
    {
        $id = (int) ($candidate['id'] ?? 0);
        if ($id <= 0) {
            return;
        }

        if (! isset($candidateMap[$id])) {
            $candidateMap[$id] = $candidate;

            return;
        }

        if ((float) ($candidate['score'] ?? 0) > (float) ($candidateMap[$id]['score'] ?? 0)) {
            $candidateMap[$id]['score'] = $candidate['score'];
            $candidateMap[$id]['name'] = $candidate['name'];
            $candidateMap[$id]['type_key'] = $candidate['type_key'];
            $candidateMap[$id]['mapping_type'] = $candidate['mapping_type'];
        }

        $candidateMap[$id]['reasons'] = array_values(array_unique(array_merge(
            (array) ($candidateMap[$id]['reasons'] ?? []),
            (array) ($candidate['reasons'] ?? [])
        )));
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
            return 0.94;
        }

        $similarity = 0.0;
        similar_text($left, $right, $similarity);
        $similarityScore = $similarity / 100.0;

        $maxLength = max(strlen($left), strlen($right));
        $distanceScore = 0.0;
        if ($maxLength > 0) {
            $distanceScore = 1.0 - (levenshtein($left, $right) / $maxLength);
        }

        return max(0.0, min(1.0, max($similarityScore, $distanceScore)));
    }

    protected function prefillNewScentFields(?MappingException $exception): void
    {
        $rawLabel = trim((string) ($exception?->raw_scent_name ?: $exception?->raw_title ?: ''));
        if ($rawLabel === '') {
            return;
        }

        if ($this->newScentName === '') {
            $this->newScentName = $rawLabel;
        }

        if ($this->newScentDisplay === '') {
            $this->newScentDisplay = $rawLabel;
        }
    }

    protected function syncAliases(Collection $exceptions, int $scentId): void
    {
        if (! Schema::hasTable('scent_aliases')) {
            return;
        }

        $scent = Scent::query()->find($scentId, ['name', 'display_name']);
        $canonicalValues = collect([
            trim((string) ($scent?->name ?? '')),
            trim((string) ($scent?->display_name ?? '')),
        ])->filter()->all();

        $context = $this->mappingContext();
        $scopes = ['markets'];
        if ((bool) ($context['is_wholesale'] ?? false)) {
            $scopes[] = 'wholesale';
            $scopes[] = 'order_type:wholesale';
            if (! empty($context['account_name'])) {
                $scopes[] = 'account:'.WholesaleCustomScent::normalizeAccountName((string) $context['account_name']);
            }
        }
        if ((bool) ($context['is_subscription_like'] ?? false)) {
            $scopes[] = 'subscription_drop';
        }

        $aliases = $exceptions
            ->flatMap(function (MappingException $exception): array {
                return [
                    trim((string) ($exception->raw_scent_name ?? '')),
                    trim((string) ($exception->raw_title ?? '')),
                ];
            })
            ->filter()
            ->unique()
            ->values();

        foreach ($aliases as $alias) {
            if (in_array($alias, $canonicalValues, true)) {
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
                $accountName = trim((string) $exception->account_name);
                $customName = trim((string) ($exception->raw_scent_name ?: $exception->raw_title ?: ''));

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

    protected function persistBatchRule(Collection $exceptions, int $scentId): void
    {
        if ($this->batchScope === 'none' || $this->batchScope === 'this_import') {
            return;
        }

        $aliases = $exceptions
            ->flatMap(function (MappingException $exception): array {
                return [
                    trim((string) ($exception->raw_scent_name ?? '')),
                    trim((string) ($exception->raw_title ?? '')),
                ];
            })
            ->filter()
            ->unique()
            ->values();

        if ($aliases->isEmpty()) {
            return;
        }

        $context = $this->mappingContext();

        if ($this->batchScope === 'this_account' && Schema::hasTable('wholesale_custom_scents')) {
            $accounts = $exceptions
                ->pluck('account_name')
                ->filter()
                ->map(fn ($value) => trim((string) $value))
                ->filter()
                ->unique()
                ->values();

            foreach ($accounts as $accountName) {
                foreach ($aliases as $alias) {
                    WholesaleCustomScent::query()->updateOrCreate(
                        [
                            'account_name' => $accountName,
                            'custom_scent_name' => $alias,
                        ],
                        [
                            'canonical_scent_id' => $scentId,
                            'active' => true,
                        ]
                    );
                }
            }
        }

        if (! Schema::hasTable('scent_aliases')) {
            return;
        }

        $scopes = match ($this->batchScope) {
            'this_account' => [
                ! empty($context['account_name'])
                    ? 'account:'.WholesaleCustomScent::normalizeAccountName((string) $context['account_name'])
                    : null,
                'wholesale',
            ],
            'order_type' => [
                'order_type:'.($context['order_type'] !== '' ? $context['order_type'] : ($context['is_wholesale'] ? 'wholesale' : 'retail')),
            ],
            'all_wholesale' => ['wholesale', 'order_type:wholesale'],
            'subscription_drop' => ['subscription_drop'],
            default => [],
        };

        $scopes = array_values(array_filter($scopes));
        foreach ($aliases as $alias) {
            foreach ($scopes as $scope) {
                ScentAlias::query()->updateOrCreate(
                    ['alias' => $alias, 'scope' => $scope],
                    ['scent_id' => $scentId]
                );
            }
        }
    }

    /**
     * @param  array<string,mixed>  $context
     * @return array<int,array<string,string>>
     */
    protected function batchScopeOptions(array $context): array
    {
        $options = [
            ['value' => 'none', 'label' => 'No reusable rule'],
            ['value' => 'this_import', 'label' => 'This import only'],
            ['value' => 'order_type', 'label' => 'This order type only'],
        ];

        if ((bool) ($context['is_wholesale'] ?? false)) {
            $options[] = ['value' => 'this_account', 'label' => 'This account only'];
            $options[] = ['value' => 'all_wholesale', 'label' => 'All wholesale imports'];
        }

        if ((bool) ($context['is_subscription_like'] ?? false)) {
            $options[] = ['value' => 'subscription_drop', 'label' => 'Candle Club / Subscription Drop'];
        }

        return $options;
    }
}

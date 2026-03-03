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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Component;

class ProgressiveMapper extends Component
{
    public array $exceptionIds = [];
    public int $step = 1;
    public string $classification = '';
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

    public function mount(array $exceptionIds = []): void
    {
        $this->exceptionIds = $exceptionIds;
        $this->step = 1;
        $this->candleClubMonth = (string) now()->month;
        $this->candleClubYear = (string) now()->year;
    }

    public function toggleAdvanced(): void
    {
        $this->manualMode = !$this->manualMode;
        if ($this->manualMode && $this->step < 3) {
            $this->step = 3;
        }
    }

    public function classify(string $classification): void
    {
        $this->classification = $classification;
        $this->step = 2;
        $this->buildGuesses();
        $this->prefillFromException();
        $this->loadRelatedExceptions();
    }

    public function acceptGuess(int $scentId): void
    {
        $this->selectedScentId = $scentId;
        $selected = Scent::query()->find($scentId, ['name', 'display_name']);
        $this->existingScentSearch = (string) ($selected?->display_name ?: $selected?->name ?: '');
        $this->step = 3;
    }

    public function manualSearch(): void
    {
        $this->manualMode = true;
        $this->step = 3;
        $this->prefillFromException();
        $this->loadRelatedExceptions();
    }

    public function save(): void
    {
        if (empty($this->exceptionIds)) {
            return;
        }

        $targetIds = $this->exceptionIds;
        if ($this->classification === 'subscription' && !empty($this->relatedExceptionIds)) {
            if ($this->applyAllRelated) {
                $targetIds = array_values(array_unique(array_merge($targetIds, $this->relatedExceptionIds)));
            } elseif (!empty($this->applyExceptionIds)) {
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
        if (!$scentId && $this->classification === 'subscription') {
            $name = trim($this->candleClubScent);
            if ($name === '' || $this->candleClubMonth === '' || $this->candleClubYear === '' || trim($this->candleClubOil) === '') {
                $this->dispatch('toast', ['type' => 'warning', 'message' => 'Month, Year, Scent and Oil required.']);
                return;
            }
            $monthName = \Carbon\Carbon::create()->month((int) $this->candleClubMonth)->format('F');
            $display = $monthName . ' ' . $this->candleClubYear . ' Candle Club — ' . $name;
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

        if (!$scentId) {
            $scent = $this->resolveSelectedOrNewScent($exceptions);
            if (!$scent) {
                return;
            }

            $scentId = $scent->id;
        }

        DB::transaction(function () use ($exceptions, $scentId): void {
            foreach ($exceptions as $exception) {
                $line = $exception->order_line_id ? OrderLine::query()->find($exception->order_line_id) : null;
                if (!$line) {
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
                    $line->scent_name = Scent::query()->find($scentId)?->name;
                }
                if ($this->sizeId && Schema::hasColumn('order_lines', 'size_code')) {
                    $line->size_code = Size::query()->find($this->sizeId)?->code;
                }
                $line->save();

                if (!empty($line->scent_id) && !empty($line->size_id)) {
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
        });

        $this->syncAliases($exceptions, $scentId);
        $this->syncWholesaleCustomMappings($exceptions, $scentId);

        $this->dispatch('toast', ['type' => 'success', 'message' => 'Mapping applied.']);
        $this->dispatch('intake-done');
    }

    protected function buildGuesses(): void
    {
        $exception = MappingException::query()->whereIn('id', $this->exceptionIds)->first();
        $rawTitle = $exception?->raw_title;
        $rawVariant = $exception?->raw_variant;
        $payload = $exception?->payload_json ?? null;
        $properties = is_array($payload) ? ($payload['properties'] ?? null) : null;

        $engine = app(ScentGuessEngine::class);
        $this->guesses = $engine->guess($rawTitle, $rawVariant, $properties, 3);
    }

    protected function loadRelatedExceptions(): void
    {
        $this->relatedExceptionIds = [];
        $this->applyExceptionIds = [];
        $this->applyAllRelated = false;

        $exception = MappingException::query()->whereIn('id', $this->exceptionIds)->first();
        if (!$exception) {
            return;
        }
        if (($exception->reason ?? '') !== 'candle_club') {
            return;
        }

        $rawTitle = $exception->raw_title;
        if (!$rawTitle) {
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

    protected function prefillFromException(): void
    {
        $exception = MappingException::query()->whereIn('id', $this->exceptionIds)->first();
        if (!$exception) {
            return;
        }
        $line = $exception->order_line_id ? OrderLine::query()->find($exception->order_line_id) : null;
        if ($line) {
            if (!$this->sizeId && $line->size_id) {
                $this->sizeId = $line->size_id;
            }
            if (!$this->wickType && !empty($line->wick_type)) {
                $this->wickType = $line->wick_type;
            }
            if (!$this->selectedScentId && !empty($line->scent_id)) {
                $this->selectedScentId = $line->scent_id;
            }
        }

        $rawTitle = (string) ($exception->raw_title ?? '');
        $rawVariant = (string) ($exception->raw_variant ?? '');

        if (!$this->wickType) {
            $wick = $this->detectWick($rawVariant . ' ' . $rawTitle);
            if ($wick) {
                $this->wickType = $wick;
            }
        }

        if (!$this->sizeId) {
            $sizeId = $this->detectSizeId($rawVariant ?: $rawTitle);
            if ($sizeId) {
                $this->sizeId = $sizeId;
            }
        }

        if (!$this->selectedScentId && !empty($this->guesses)) {
            $this->selectedScentId = $this->guesses[0]['id'] ?? null;
        }

        if ($this->selectedScentId && $this->existingScentSearch === '') {
            $selected = Scent::query()->find($this->selectedScentId, ['name', 'display_name']);
            $this->existingScentSearch = (string) ($selected?->display_name ?: $selected?->name ?: '');
        }

        $this->prefillNewScentFields($exception);
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
        return view('livewire.intake.progressive-mapper', [
            'sizes' => Size::query()->orderBy('label')->orderBy('code')->get(),
            'matchingScents' => $this->matchingScents(),
        ]);
    }

    protected function resolveSelectedOrNewScent($exceptions): ?Scent
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

        if (! $scent) {
            $scent = Scent::query()->create([
                'name' => $normalized,
                'display_name' => $displayName,
                'abbreviation' => trim($this->newScentAbbr) !== '' ? trim($this->newScentAbbr) : null,
                'oil_reference_name' => trim($this->newScentOil) !== '' ? trim($this->newScentOil) : null,
                'is_blend' => $this->newScentIsBlend,
                'blend_oil_count' => $this->newScentIsBlend ? ($this->newScentBlendCount ?: null) : null,
                'is_wholesale_custom' => $exceptions->contains(fn (MappingException $exception) => filled($exception->account_name)),
                'is_active' => true,
            ]);
        } else {
            $scent->fill(array_filter([
                'display_name' => $scent->display_name ?: $displayName,
                'abbreviation' => $scent->abbreviation ?: (trim($this->newScentAbbr) !== '' ? trim($this->newScentAbbr) : null),
                'oil_reference_name' => $scent->oil_reference_name ?: (trim($this->newScentOil) !== '' ? trim($this->newScentOil) : null),
                'blend_oil_count' => $scent->blend_oil_count ?: ($this->newScentIsBlend ? ($this->newScentBlendCount ?: null) : null),
            ], fn ($value) => $value !== null && $value !== ''));
            if ($this->newScentIsBlend) {
                $scent->is_blend = true;
            }
            if ($exceptions->contains(fn (MappingException $exception) => filled($exception->account_name))) {
                $scent->is_wholesale_custom = true;
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
     * @return \Illuminate\Support\Collection<int,Scent>
     */
    protected function matchingScents()
    {
        return Scent::query()
            ->when(trim($this->existingScentSearch) !== '', function ($query): void {
                $search = '%'.trim($this->existingScentSearch).'%';
                $query->where(function ($inner) use ($search): void {
                    $inner->where('name', 'like', $search)
                        ->orWhere('display_name', 'like', $search)
                        ->orWhere('abbreviation', 'like', $search);
                });
            })
            ->orderByRaw('COALESCE(display_name, name)')
            ->limit(8)
            ->get(['id', 'name', 'display_name', 'abbreviation']);
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

    protected function syncAliases($exceptions, int $scentId): void
    {
        if (! Schema::hasTable('scent_aliases')) {
            return;
        }

        $scent = Scent::query()->find($scentId, ['name', 'display_name']);
        $canonicalValues = collect([
            trim((string) ($scent?->name ?? '')),
            trim((string) ($scent?->display_name ?? '')),
        ])->filter()->all();

        $exceptions
            ->flatMap(function (MappingException $exception): array {
                return [
                    trim((string) ($exception->raw_scent_name ?? '')),
                    trim((string) ($exception->raw_title ?? '')),
                ];
            })
            ->filter()
            ->unique()
            ->each(function (string $alias) use ($canonicalValues, $scentId): void {
                if (in_array($alias, $canonicalValues, true)) {
                    return;
                }

                ScentAlias::query()->updateOrCreate(
                    ['alias' => $alias, 'scope' => 'markets'],
                    ['scent_id' => $scentId]
                );
            });
    }

    protected function syncWholesaleCustomMappings($exceptions, int $scentId): void
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
}

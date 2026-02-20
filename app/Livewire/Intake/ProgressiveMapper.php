<?php

namespace App\Livewire\Intake;

use App\Models\MappingException;
use App\Models\OrderLine;
use App\Models\Scent;
use App\Models\Size;
use App\Services\ScentGuessEngine;
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

    public ?int $sizeId = null;
    public ?string $wickType = null;

    public string $candleClubMonth = '';
    public string $candleClubYear = '';
    public string $candleClubScent = '';
    public string $candleClubOil = '';

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
            $scent = Scent::query()->create([
                'name' => $display,
                'display_name' => $display,
                'oil_reference_name' => $this->candleClubOil,
                'is_candle_club' => true,
                'is_active' => true,
            ]);
            $scentId = $scent->id;
        }

        foreach ($exceptions as $exception) {
            $line = $exception->order_line_id ? OrderLine::query()->find($exception->order_line_id) : null;
            if (!$line) {
                continue;
            }
            if ($scentId) {
                $line->scent_id = $scentId;
            }
            if ($this->sizeId) {
                $line->size_id = $this->sizeId;
            }
            if ($this->wickType) {
                $line->wick_type = $this->wickType;
            }
            $line->save();

            if (!empty($line->scent_id) && !empty($line->size_id)) {
                $exception->resolved_at = now();
                $exception->resolved_by = auth()->id();
                $exception->canonical_scent_id = $line->scent_id;
                $exception->save();
            }
        }

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
        ]);
    }
}

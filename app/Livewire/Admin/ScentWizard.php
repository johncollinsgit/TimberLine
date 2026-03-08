<?php

namespace App\Livewire\Admin;

use App\Actions\ScentGovernance\CreateScentAction;
use App\Actions\ScentGovernance\CreateScentAliasAction;
use App\Models\BaseOil;
use App\Models\MappingException;
use App\Models\Blend;
use App\Models\Scent;
use App\Models\ScentRecipeComponent;
use App\Models\WholesaleCustomScent;
use App\Services\ScentGovernance\ResolveScentMatchService;
use App\Services\ScentGovernance\ScentLifecycleService;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

class ScentWizard extends Component
{
    public const INTENT_NEW = 'new_scent';
    public const INTENT_MAP = 'map_existing';
    public const INTENT_CUSTOMER_ALIAS = 'customer_alias';
    public const RECIPE_TYPE_SINGLE_OIL = 'single_oil';
    public const RECIPE_TYPE_BLEND_BACKED = 'blend_backed';

    public int $step = 1;
    public string $intent = self::INTENT_NEW;
    public string $search = '';
    public ?int $selectedExistingScentId = null;

    public array $form = [
        'name' => '',
        'display_name' => '',
        'abbreviation' => '',
        'oil_reference_name' => '',
        'base_oil_id' => null,
        'recipe_type' => self::RECIPE_TYPE_SINGLE_OIL,
        'recipe_components' => [],
        'notes' => '',
        'lifecycle_status' => 'draft',
        'is_blend' => false,
        'oil_blend_id' => null,
        'blend_oil_count' => null,
        'is_wholesale_custom' => false,
        'is_candle_club' => false,
        'is_active' => false,
        'availability' => [
            'retail' => false,
            'wholesale' => false,
            'candle_club' => false,
            'room_spray' => false,
            'wax_melt' => false,
        ],
    ];

    public array $alias = [
        'create_global_alias' => false,
        'global_alias' => '',
        'create_customer_alias' => false,
        'customer_alias' => '',
        'save_raw_as_alias' => true,
    ];

    public array $context = [
        'raw_name' => '',
        'raw_variant' => '',
        'account_name' => '',
        'store_key' => '',
        'source_context' => '',
        'channel_hint' => '',
        'product_form_hint' => '',
    ];

    public string $returnTo = '';
    public array $completion = [];
    public array $reviewWarnings = [];

    public function mount(): void
    {
        $raw = trim((string) request()->query('raw', request()->query('name', '')));
        $variant = trim((string) request()->query('variant', ''));
        $account = trim((string) request()->query('account', ''));
        $storeKey = trim((string) request()->query('store', ''));
        $sourceContext = trim((string) request()->query('source_context', request()->query('source', '')));
        $channelHint = trim((string) request()->query('channel_hint', request()->query('channel', '')));
        $productFormHint = trim((string) request()->query('product_form_hint', request()->query('product_form', '')));

        $cleanName = $this->cleanName($raw);
        $this->form['name'] = $cleanName;
        $this->form['display_name'] = $cleanName;
        $this->form['is_wholesale_custom'] = $account !== '' || $storeKey === 'wholesale';
        $this->form['is_candle_club'] = $channelHint === 'candle_club';

        $availability = $this->form['availability'];
        $availability['retail'] = $channelHint === 'retail' || ($storeKey !== 'wholesale' && $channelHint === '');
        $availability['wholesale'] = $channelHint === 'wholesale' || $storeKey === 'wholesale' || $account !== '';
        $availability['candle_club'] = $channelHint === 'candle_club';
        $availability['room_spray'] = $productFormHint === 'room_spray';
        $availability['wax_melt'] = $productFormHint === 'wax_melt';
        $this->form['availability'] = $availability;

        $this->search = $cleanName !== '' ? $cleanName : $raw;
        $this->alias['global_alias'] = $raw;
        $this->alias['customer_alias'] = $raw;
        $this->alias['create_global_alias'] = $raw !== '' && mb_strtolower($raw) !== mb_strtolower($cleanName);
        $this->alias['create_customer_alias'] = $account !== '' && $raw !== '';
        $this->alias['save_raw_as_alias'] = $raw !== '';

        $this->context = [
            'raw_name' => $raw,
            'raw_variant' => $variant,
            'account_name' => $account,
            'store_key' => $storeKey,
            'source_context' => $sourceContext,
            'channel_hint' => $channelHint,
            'product_form_hint' => $productFormHint,
        ];

        $fallbackReturn = route('admin.index', ['tab' => 'master-data', 'resource' => 'scents']);
        $requestedReturn = (string) request()->query('return_to', $fallbackReturn);
        $this->returnTo = str_starts_with($requestedReturn, '/')
            ? url($requestedReturn)
            : ($requestedReturn !== '' ? $requestedReturn : $fallbackReturn);

        if ($account !== '') {
            $this->intent = self::INTENT_CUSTOMER_ALIAS;
        } elseif ($raw !== '') {
            $this->intent = self::INTENT_MAP;
        }

        if (! in_array($this->intent, $this->intentOptions(), true)) {
            $this->intent = self::INTENT_NEW;
        }

        $this->seedExistingSelection();
    }

    public function updatedSearch(): void
    {
        $this->selectedExistingScentId = null;
        $this->resetValidation(['selectedExistingScentId']);
        $this->seedExistingSelection();
    }

    public function updatedIntent(string $value): void
    {
        if (! in_array($value, $this->intentOptions(), true)) {
            $this->intent = self::INTENT_NEW;
        }
    }

    public function updatedFormRecipeType(string $value): void
    {
        if (! in_array($value, [self::RECIPE_TYPE_SINGLE_OIL, self::RECIPE_TYPE_BLEND_BACKED], true)) {
            $this->form['recipe_type'] = self::RECIPE_TYPE_SINGLE_OIL;
        }

        if (($this->form['recipe_type'] ?? self::RECIPE_TYPE_SINGLE_OIL) === self::RECIPE_TYPE_SINGLE_OIL) {
            $this->form['is_blend'] = false;
            $this->form['recipe_components'] = [];
            $this->form['oil_blend_id'] = null;
            $this->form['blend_oil_count'] = null;
            return;
        }

        $this->form['is_blend'] = true;
        $this->form['base_oil_id'] = null;
        $this->form['oil_reference_name'] = '';

        if (! is_array($this->form['recipe_components'] ?? null) || $this->form['recipe_components'] === []) {
            $this->form['recipe_components'] = [$this->blankRecipeComponent()];
        }
    }

    public function updatedFormBaseOilId($value): void
    {
        $id = blank($value) ? null : (int) $value;
        $this->form['base_oil_id'] = $id;

        if (! $id) {
            $this->form['oil_reference_name'] = '';
            return;
        }

        $name = BaseOil::query()->whereKey($id)->value('name');
        $this->form['oil_reference_name'] = $name ? (string) $name : '';
    }

    public function addRecipeComponent(): void
    {
        if (($this->form['recipe_type'] ?? self::RECIPE_TYPE_SINGLE_OIL) !== self::RECIPE_TYPE_BLEND_BACKED) {
            $this->form['recipe_type'] = self::RECIPE_TYPE_BLEND_BACKED;
        }

        $rows = is_array($this->form['recipe_components'] ?? null) ? $this->form['recipe_components'] : [];
        $rows[] = $this->blankRecipeComponent();
        $this->form['recipe_components'] = array_values($rows);
        $this->form['is_blend'] = true;
    }

    public function removeRecipeComponent(int $index): void
    {
        $rows = is_array($this->form['recipe_components'] ?? null) ? $this->form['recipe_components'] : [];
        if (! array_key_exists($index, $rows)) {
            return;
        }

        unset($rows[$index]);
        $rows = array_values($rows);
        $this->form['recipe_components'] = $rows;

        if ($rows === []) {
            $this->form['recipe_components'] = [$this->blankRecipeComponent()];
        }
    }

    public function nextStep(): void
    {
        if ($this->step === 1) {
            $this->validateStepOne();
            if ($this->getErrorBag()->isNotEmpty()) {
                return;
            }

            $this->step = $this->intent === self::INTENT_NEW ? 2 : 3;
            return;
        }

        if ($this->step === 2) {
            $this->validateStepTwo();
            if ($this->getErrorBag()->isEmpty()) {
                $this->step = 3;
            }
            return;
        }
    }

    public function previousStep(): void
    {
        if ($this->step <= 1) {
            return;
        }

        if ($this->step === 3 && $this->intent !== self::INTENT_NEW) {
            $this->step = 1;
            return;
        }

        $this->step--;
    }

    public function jumpToStep(int $target): void
    {
        if ($target < 1 || $target > 4) {
            return;
        }

        if ($target <= $this->step) {
            $this->step = $target;
            return;
        }

        while ($this->step < $target) {
            $before = $this->step;
            $this->nextStep();
            if ($this->step === $before) {
                break;
            }
        }
    }

    public function complete(): void
    {
        $this->validateStepOne();
        if ($this->intent === self::INTENT_NEW) {
            $this->validateStepTwo();
        }
        if ($this->getErrorBag()->isNotEmpty()) {
            $this->step = $this->intent === self::INTENT_NEW ? 2 : 1;
            return;
        }

        $this->reviewWarnings = $this->buildReviewWarnings();

        $mappedExisting = $this->intent !== self::INTENT_NEW && $this->selectedExistingScentId;
        $scent = $mappedExisting
            ? Scent::query()->find($this->selectedExistingScentId)
            : null;

        if ($mappedExisting && ! $scent) {
            $this->addError('selectedExistingScentId', 'Select an existing scent before continuing.');
            $this->step = 1;
            return;
        }

        if (! $mappedExisting) {
            try {
                $payload = $this->validatedPayload();
                $scent = app(CreateScentAction::class)->execute($payload, 'form.');
            } catch (ValidationException $e) {
                $this->resetValidation();
                foreach ($e->errors() as $key => $messages) {
                    if (! empty($messages)) {
                        $this->addError($key, (string) $messages[0]);
                    }
                }
                $this->step = 2;
                return;
            }
        }

        if (! $scent) {
            $this->addError('form.name', 'Unable to resolve target scent.');
            $this->step = 1;
            return;
        }

        $aliasSummary = $this->syncAliases($scent);
        $mappingCreated = $this->syncOptionalWholesaleMapping($scent);

        $result = $mappedExisting ? 'Mapped to existing scent' : 'Created new canonical scent';
        $this->completion = [
            'mode' => $mappedExisting ? 'mapped' : 'created',
            'scent_id' => (int) $scent->id,
            'scent_name' => (string) ($scent->display_name ?: $scent->name),
            'aliases' => $aliasSummary,
            'wholesale_mapping_created' => $mappingCreated,
            'message' => $result.' “'.($scent->display_name ?: $scent->name).'”.',
        ];

        $this->dispatch('toast', [
            'type' => 'success',
            'message' => $this->completion['message'],
        ]);

        $this->step = 4;
    }

    public function finish(): void
    {
        $message = (string) ($this->completion['message'] ?? 'Scent wizard completed.');
        session()->flash('toast', ['type' => 'success', 'message' => $message]);
        $this->redirect($this->returnTo, navigate: true);
    }

    public function selectExistingScent(int $scentId): void
    {
        $this->selectedExistingScentId = $scentId > 0 ? $scentId : null;
        $selected = $this->selectedScent();
        if ($selected) {
            $this->search = (string) ($selected->display_name ?: $selected->name ?: $this->search);
        }
        $this->resetValidation(['selectedExistingScentId']);
    }

    protected function validateStepOne(): void
    {
        $this->resetValidation(['intent', 'selectedExistingScentId', 'search']);

        if (! in_array($this->intent, $this->intentOptions(), true)) {
            $this->addError('intent', 'Select how to handle this scent.');
        }

        if (in_array($this->intent, [self::INTENT_MAP, self::INTENT_CUSTOMER_ALIAS], true)
            && ! $this->selectedExistingScentId) {
            $this->addError('selectedExistingScentId', 'Select an existing scent to map before continuing.');
        }
    }

    protected function validateStepTwo(): void
    {
        $rules = [
            'form.name' => ['required', 'string', 'max:255'],
            'form.display_name' => ['nullable', 'string', 'max:255'],
            'form.abbreviation' => ['nullable', 'string', 'max:64'],
            'form.oil_reference_name' => ['nullable', 'string', 'max:255'],
            'form.base_oil_id' => ['nullable', 'integer', 'exists:base_oils,id'],
            'form.recipe_type' => ['required', 'string', 'in:single_oil,blend_backed'],
            'form.recipe_components' => ['array'],
            'form.recipe_components.*.component_type' => ['nullable', 'string', 'in:oil,blend_template'],
            'form.recipe_components.*.base_oil_id' => ['nullable', 'integer', 'exists:base_oils,id'],
            'form.recipe_components.*.blend_template_id' => ['nullable', 'integer', 'exists:blends,id'],
            'form.recipe_components.*.parts' => ['nullable', 'numeric', 'gt:0'],
            'form.recipe_components.*.percentage' => ['nullable', 'numeric', 'gt:0', 'max:100'],
            'form.notes' => ['nullable', 'string', 'max:1000'],
            'form.lifecycle_status' => ['required', 'string', 'in:draft,active,inactive,archived'],
            'form.is_blend' => ['boolean'],
            'form.oil_blend_id' => ['nullable', 'integer', 'exists:blends,id'],
            'form.blend_oil_count' => ['nullable', 'integer', 'min:1'],
            'form.is_wholesale_custom' => ['boolean'],
            'form.is_candle_club' => ['boolean'],
            'form.availability.retail' => ['boolean'],
            'form.availability.wholesale' => ['boolean'],
            'form.availability.candle_club' => ['boolean'],
            'form.availability.room_spray' => ['boolean'],
            'form.availability.wax_melt' => ['boolean'],
        ];
        validator(['form' => $this->form], $rules)->validate();

        $recipeType = (string) ($this->form['recipe_type'] ?? self::RECIPE_TYPE_SINGLE_OIL);
        if ($recipeType === self::RECIPE_TYPE_SINGLE_OIL) {
            if (! (int) ($this->form['base_oil_id'] ?? 0)) {
                $this->addError('form.base_oil_id', 'Select an existing base oil for single-oil scents.');
            }

            $selectedOilName = BaseOil::query()
                ->whereKey((int) ($this->form['base_oil_id'] ?? 0))
                ->value('name');
            $this->form['oil_reference_name'] = $selectedOilName ? (string) $selectedOilName : '';
            $this->form['is_blend'] = false;
            $this->form['recipe_components'] = [];
            $this->form['oil_blend_id'] = null;
            $this->form['blend_oil_count'] = null;
        }

        if ($recipeType === self::RECIPE_TYPE_BLEND_BACKED) {
            $normalizedComponents = $this->normalizeRecipeComponents();
            if ($normalizedComponents === []) {
                $this->addError('form.recipe_components', 'Add at least one governed recipe component.');
            }

            foreach ($normalizedComponents as $index => $row) {
                $type = (string) ($row['component_type'] ?? '');
                if ($type === ScentRecipeComponent::TYPE_OIL && ! (int) ($row['base_oil_id'] ?? 0)) {
                    $this->addError("form.recipe_components.{$index}.base_oil_id", 'Select an existing oil.');
                }
                if ($type === ScentRecipeComponent::TYPE_BLEND_TEMPLATE && ! (int) ($row['blend_template_id'] ?? 0)) {
                    $this->addError("form.recipe_components.{$index}.blend_template_id", 'Select an existing blend template.');
                }
            }

            $this->form['is_blend'] = true;
            $this->form['base_oil_id'] = null;
            $this->form['oil_reference_name'] = '';
            $this->form['oil_blend_id'] = null;
            $this->form['blend_oil_count'] = count($normalizedComponents) > 0 ? count($normalizedComponents) : null;
            $this->form['recipe_components'] = $normalizedComponents;
        }

        $candidateName = trim((string) ($this->form['name'] ?? ''));
        if ($candidateName === '') {
            return;
        }

        $exact = app(ResolveScentMatchService::class)->findExistingScent($candidateName, $this->matchContext());
        if ($exact && mb_strtolower($exact->name) === mb_strtolower(Scent::normalizeName($candidateName))) {
            $this->addError('form.name', 'A matching scent already exists. Choose “Map to existing scent” instead.');
        }
    }

    /**
     * @return array<string,mixed>
     */
    protected function validatedPayload(): array
    {
        $recipeType = (string) ($this->form['recipe_type'] ?? self::RECIPE_TYPE_SINGLE_OIL);
        $baseOilId = blank($this->form['base_oil_id'] ?? null) ? null : (int) $this->form['base_oil_id'];
        $baseOilName = $baseOilId
            ? (string) (BaseOil::query()->whereKey($baseOilId)->value('name') ?? '')
            : '';

        $recipeComponents = $recipeType === self::RECIPE_TYPE_BLEND_BACKED
            ? $this->normalizeRecipeComponents()
            : ($baseOilId
                ? [[
                    'component_type' => ScentRecipeComponent::TYPE_OIL,
                    'base_oil_id' => $baseOilId,
                    'parts' => 1,
                    'percentage' => 100,
                ]]
                : []);

        return [
            'name' => (string) ($this->form['name'] ?? ''),
            'display_name' => (string) ($this->form['display_name'] ?? ''),
            'abbreviation' => (string) ($this->form['abbreviation'] ?? ''),
            'oil_reference_name' => $baseOilName !== '' ? $baseOilName : (string) ($this->form['oil_reference_name'] ?? ''),
            'notes' => (string) ($this->form['notes'] ?? ''),
            'is_blend' => $recipeType === self::RECIPE_TYPE_BLEND_BACKED,
            'oil_blend_id' => null,
            'blend_oil_count' => $recipeType === self::RECIPE_TYPE_BLEND_BACKED ? count($recipeComponents) : null,
            'recipe_components' => $recipeComponents,
            'is_wholesale_custom' => (bool) ($this->form['is_wholesale_custom'] ?? false),
            'is_candle_club' => (bool) ($this->form['is_candle_club'] ?? false),
            'lifecycle_status' => (string) ($this->form['lifecycle_status'] ?? ScentLifecycleService::STATUS_DRAFT),
            'availability_json' => $this->form['availability'] ?? null,
            'source_context' => (string) ($this->context['source_context'] ?? 'wizard'),
        ];
    }

    /**
     * @return array<int,array<string,string>>
     */
    protected function syncAliases(Scent $scent): array
    {
        if (! Schema::hasTable('scent_aliases')) {
            return [];
        }

        if ($this->intent === self::INTENT_NEW) {
            return [];
        }

        $canonicalValues = [
            trim((string) ($scent->name ?? '')),
            trim((string) ($scent->display_name ?? '')),
        ];

        $action = app(CreateScentAliasAction::class);
        $saved = [];

        $rawAlias = trim((string) ($this->context['raw_name'] ?? ''));
        if ($rawAlias === '') {
            return $saved;
        }

        if ($this->intent === self::INTENT_CUSTOMER_ALIAS) {
            $account = trim((string) ($this->context['account_name'] ?? ''));
            if ($account === '') {
                return $saved;
            }

            $scope = 'account:'.WholesaleCustomScent::normalizeAccountName($account);
            $record = $action->execute($scent, $rawAlias, $scope, $canonicalValues);
            if ($record) {
                $saved[] = ['alias' => $record->alias, 'scope' => $record->scope];
            }

            return $saved;
        }

        $scopes = $this->rawAliasScopes();
        $recordsAdded = $action->syncAcrossScopes($scent, [$rawAlias], $scopes, $canonicalValues);
        if ($recordsAdded > 0) {
            foreach ($scopes as $scope) {
                $saved[] = [
                    'alias' => Scent::normalizeName($rawAlias),
                    'scope' => $scope,
                ];
            }
        }

        return $saved;
    }

    protected function syncOptionalWholesaleMapping(Scent $scent): bool
    {
        if (! Schema::hasTable('wholesale_custom_scents')) {
            return false;
        }

        $accountName = trim((string) ($this->context['account_name'] ?? ''));
        $customScentName = trim((string) ($this->context['raw_name'] ?? ''));
        if ($accountName === '' || $customScentName === '') {
            return false;
        }

        WholesaleCustomScent::query()->updateOrCreate(
            [
                'account_name' => mb_substr($accountName, 0, 255),
                'custom_scent_name' => mb_substr($customScentName, 0, 255),
            ],
            [
                'canonical_scent_id' => $scent->id,
                'active' => true,
            ]
        );

        return true;
    }

    /**
     * @return array<int,string>
     */
    protected function rawAliasScopes(): array
    {
        $scopes = [];
        $channel = trim((string) ($this->context['channel_hint'] ?? ''));
        $store = trim((string) ($this->context['store_key'] ?? ''));
        $account = trim((string) ($this->context['account_name'] ?? ''));

        if ($channel === 'wholesale' || $store === 'wholesale' || $account !== '') {
            $scopes[] = 'wholesale';
            $scopes[] = 'order_type:wholesale';
        } else {
            $scopes[] = 'retail';
            $scopes[] = 'markets';
        }

        if ($account !== '') {
            $scopes[] = 'account:'.WholesaleCustomScent::normalizeAccountName($account);
        }

        return array_values(array_unique($scopes));
    }

    /**
     * @return array<string,mixed>
     */
    protected function blankRecipeComponent(): array
    {
        return [
            'component_type' => ScentRecipeComponent::TYPE_OIL,
            'base_oil_id' => null,
            'blend_template_id' => null,
            'parts' => 1,
            'percentage' => null,
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    protected function normalizeRecipeComponents(): array
    {
        $rows = $this->form['recipe_components'] ?? [];
        if (! is_array($rows)) {
            return [];
        }

        $normalized = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $type = (string) ($row['component_type'] ?? '');
            if (! in_array($type, [ScentRecipeComponent::TYPE_OIL, ScentRecipeComponent::TYPE_BLEND_TEMPLATE], true)) {
                continue;
            }

            $component = [
                'component_type' => $type,
                'base_oil_id' => null,
                'blend_template_id' => null,
                'parts' => blank($row['parts'] ?? null) ? null : (float) $row['parts'],
                'percentage' => blank($row['percentage'] ?? null) ? null : (float) $row['percentage'],
            ];

            if ($type === ScentRecipeComponent::TYPE_OIL) {
                $component['base_oil_id'] = blank($row['base_oil_id'] ?? null) ? null : (int) $row['base_oil_id'];
            }

            if ($type === ScentRecipeComponent::TYPE_BLEND_TEMPLATE) {
                $component['blend_template_id'] = blank($row['blend_template_id'] ?? null) ? null : (int) $row['blend_template_id'];
            }

            $normalized[] = $component;
        }

        return $normalized;
    }

    /**
     * @return array<int,array{type:string,message:string}>
     */
    protected function buildReviewWarnings(): array
    {
        $warnings = [];
        if ($this->intent === self::INTENT_NEW) {
            $candidates = $this->matchCandidates();
            $top = $candidates->first();
            if ($top && (int) ($top['score'] ?? 0) >= 92) {
                $warnings[] = [
                    'type' => 'warning',
                    'message' => 'Very close existing match found: '.$top['name'].' ('.$top['score'].'%). Consider mapping instead of creating.',
                ];
            }
        }

        return $warnings;
    }

    protected function seedExistingSelection(): void
    {
        $needle = trim($this->search);
        if ($needle === '') {
            return;
        }

        $id = app(ResolveScentMatchService::class)->resolveSingleCandidateId($needle, $this->matchContext(), 93);
        if ($id) {
            $this->selectedExistingScentId = $id;
        }
    }

    protected function selectedScent(): ?Scent
    {
        if (! $this->selectedExistingScentId) {
            return null;
        }

        return Scent::query()->find($this->selectedExistingScentId);
    }

    /**
     * @return array<int,string>
     */
    protected function intentOptions(): array
    {
        return [
            self::INTENT_NEW,
            self::INTENT_MAP,
            self::INTENT_CUSTOMER_ALIAS,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    protected function matchContext(): array
    {
        $isWholesale = ($this->context['store_key'] ?? '') === 'wholesale'
            || ($this->context['channel_hint'] ?? '') === 'wholesale'
            || trim((string) ($this->context['account_name'] ?? '')) !== '';

        return [
            'store_key' => (string) ($this->context['store_key'] ?? ''),
            'is_wholesale' => $isWholesale,
            'account_name' => (string) ($this->context['account_name'] ?? ''),
        ];
    }

    protected function contextExceptionPreview(): ?MappingException
    {
        $raw = trim((string) ($this->context['raw_name'] ?? ''));
        $account = trim((string) ($this->context['account_name'] ?? ''));
        if ($raw === '' || ! Schema::hasTable('mapping_exceptions')) {
            return null;
        }

        return MappingException::query()
            ->where(function ($query) use ($raw): void {
                $query->where('raw_scent_name', $raw)
                    ->orWhere('raw_title', $raw);
            })
            ->when($account !== '', fn ($query) => $query->where('account_name', $account))
            ->latest('id')
            ->first();
    }

    protected function cleanName(string $value): string
    {
        $clean = trim($value);
        $clean = preg_replace('/\b(sale candles?|custom scents?|house blends?)\b/ui', '', $clean) ?? $clean;
        $clean = preg_replace('/\b(\d+(?:\.\d+)?)\s*oz\b/ui', '', $clean) ?? $clean;
        $clean = preg_replace('/\b(cotton|wood|cedar)\s*wick\b/ui', '', $clean) ?? $clean;
        $clean = preg_replace('/\s{2,}/u', ' ', $clean) ?? $clean;
        $clean = trim($clean, " \t\n\r\0\x0B,.-");

        return $clean;
    }

    public function render()
    {
        $matches = $this->matchCandidates();
        $selectedScent = $this->selectedScent();
        $lifecycle = app(ScentLifecycleService::class);

        return view('livewire.admin.scent-wizard', [
            'matches' => $matches,
            'selectedScent' => $selectedScent,
            'intentOptions' => $this->intentOptions(),
            'lifecycleStatuses' => $lifecycle->statuses(),
            'contextExceptionPreview' => $this->contextExceptionPreview(),
            'blends' => Blend::query()->orderBy('name')->get(['id', 'name']),
            'baseOils' => BaseOil::query()
                ->orderBy('name')
                ->get(['id', 'name']),
            'plannedAliasScopes' => $this->rawAliasScopes(),
        ])->layout('layouts.app');
    }

    protected function matchCandidates()
    {
        $term = trim($this->search);
        if ($term === '') {
            $term = trim((string) ($this->context['raw_name'] ?? ''));
        }

        if ($term === '') {
            return collect();
        }

        return app(ResolveScentMatchService::class)->resolveCandidates($term, $this->matchContext());
    }
}

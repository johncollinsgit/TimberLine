<?php

namespace App\Livewire\Admin;

use App\Actions\ScentGovernance\CreateScentAction;
use App\Actions\ScentGovernance\CreateScentAliasAction;
use App\Models\MappingException;
use App\Models\Blend;
use App\Models\Scent;
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
    public const INTENT_BLEND_PLACEHOLDER = 'blend_template_placeholder';

    public int $step = 1;
    public string $intent = self::INTENT_NEW;
    public string $search = '';
    public ?int $selectedExistingScentId = null;

    public array $form = [
        'name' => '',
        'display_name' => '',
        'abbreviation' => '',
        'oil_reference_name' => '',
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

        if ($this->step === 3) {
            $this->validateStepThree();
            if ($this->getErrorBag()->isEmpty()) {
                $this->reviewWarnings = $this->buildReviewWarnings();
                $this->step = 4;
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
        if ($target < 1 || $target > 5) {
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
        $this->validateStepThree();
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

        $this->step = 5;
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

        $candidateName = trim((string) ($this->form['name'] ?? ''));
        if ($candidateName === '') {
            return;
        }

        $exact = app(ResolveScentMatchService::class)->findExistingScent($candidateName, $this->matchContext());
        if ($exact && mb_strtolower($exact->name) === mb_strtolower(Scent::normalizeName($candidateName))) {
            $this->addError('form.name', 'A matching scent already exists. Choose “Map to existing scent” instead.');
        }
    }

    protected function validateStepThree(): void
    {
        $rules = [
            'alias.create_global_alias' => ['boolean'],
            'alias.global_alias' => ['nullable', 'string', 'max:255'],
            'alias.create_customer_alias' => ['boolean'],
            'alias.customer_alias' => ['nullable', 'string', 'max:255'],
            'alias.save_raw_as_alias' => ['boolean'],
        ];
        validator(['alias' => $this->alias], $rules)->validate();

        if (($this->alias['create_global_alias'] ?? false) && trim((string) ($this->alias['global_alias'] ?? '')) === '') {
            $this->addError('alias.global_alias', 'Enter a global alias or disable this option.');
        }

        if (($this->alias['create_customer_alias'] ?? false) && trim((string) ($this->context['account_name'] ?? '')) === '') {
            $this->addError('alias.create_customer_alias', 'Customer-scoped alias requires an account context.');
        }

        if (($this->alias['create_customer_alias'] ?? false) && trim((string) ($this->alias['customer_alias'] ?? '')) === '') {
            $this->addError('alias.customer_alias', 'Enter a customer alias or disable this option.');
        }
    }

    /**
     * @return array<string,mixed>
     */
    protected function validatedPayload(): array
    {
        return [
            'name' => (string) ($this->form['name'] ?? ''),
            'display_name' => (string) ($this->form['display_name'] ?? ''),
            'abbreviation' => (string) ($this->form['abbreviation'] ?? ''),
            'oil_reference_name' => (string) ($this->form['oil_reference_name'] ?? ''),
            'notes' => (string) ($this->form['notes'] ?? ''),
            'is_blend' => (bool) ($this->form['is_blend'] ?? false),
            'oil_blend_id' => $this->form['oil_blend_id'] ?? null,
            'blend_oil_count' => $this->form['blend_oil_count'] ?? null,
            'is_wholesale_custom' => (bool) ($this->form['is_wholesale_custom'] ?? false),
            'is_candle_club' => (bool) ($this->form['is_candle_club'] ?? false),
            'lifecycle_status' => (string) ($this->form['lifecycle_status'] ?? ScentLifecycleService::STATUS_DRAFT),
            'availability_json' => $this->form['availability'] ?? null,
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

        $canonicalValues = [
            trim((string) ($scent->name ?? '')),
            trim((string) ($scent->display_name ?? '')),
        ];

        $action = app(CreateScentAliasAction::class);
        $saved = [];

        if ((bool) ($this->alias['create_global_alias'] ?? false)) {
            $alias = trim((string) ($this->alias['global_alias'] ?? ''));
            if ($alias !== '') {
                $record = $action->execute($scent, $alias, 'global', $canonicalValues);
                if ($record) {
                    $saved[] = ['alias' => $record->alias, 'scope' => $record->scope];
                }
            }
        }

        if ((bool) ($this->alias['create_customer_alias'] ?? false)) {
            $alias = trim((string) ($this->alias['customer_alias'] ?? ''));
            $account = trim((string) ($this->context['account_name'] ?? ''));
            if ($alias !== '' && $account !== '') {
                $scope = 'account:'.WholesaleCustomScent::normalizeAccountName($account);
                $record = $action->execute($scent, $alias, $scope, $canonicalValues);
                if ($record) {
                    $saved[] = ['alias' => $record->alias, 'scope' => $record->scope];
                }
            }
        }

        if ((bool) ($this->alias['save_raw_as_alias'] ?? false)) {
            $rawAlias = trim((string) ($this->context['raw_name'] ?? ''));
            if ($rawAlias !== '') {
                $recordsAdded = $action->syncAcrossScopes(
                    $scent,
                    [$rawAlias],
                    $this->rawAliasScopes(),
                    $canonicalValues
                );

                if ($recordsAdded > 0) {
                    foreach ($this->rawAliasScopes() as $scope) {
                        $saved[] = [
                            'alias' => Scent::normalizeName($rawAlias),
                            'scope' => $scope,
                        ];
                    }
                }
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

        if (($this->alias['create_customer_alias'] ?? false) && trim((string) ($this->context['account_name'] ?? '')) === '') {
            $warnings[] = [
                'type' => 'warning',
                'message' => 'Customer alias selected without account context. It will not be created.',
            ];
        }

        if ($this->intent === self::INTENT_BLEND_PLACEHOLDER) {
            $warnings[] = [
                'type' => 'info',
                'message' => 'Blend-template authoring is still a future block. Use existing blend template references for now.',
            ];
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
            self::INTENT_BLEND_PLACEHOLDER,
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

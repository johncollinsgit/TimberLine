<?php

namespace App\Livewire\Admin\Catalog;

use App\Models\BaseOil;
use App\Models\Blend;
use App\Models\BlendComponent;
use App\Models\OrderLine;
use App\Models\Scent;
use App\Models\ScentAlias;
use App\Models\WholesaleCustomScent;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithPagination;
use Throwable;

class ScentsCrud extends Component
{
    use WithPagination;

    public string $search = '';
    public string $sort = 'name';
    public string $dir = 'asc';
    public int $perPage = 25;
    public bool $showCreate = false;

    public array $create = [
        'name' => '',
        'display_name' => '',
        'abbreviation' => '',
        'oil_reference_name' => '',
        'is_blend' => false,
        'oil_blend_id' => null,
        'blend_oil_count' => null,
        'canonical_scent_id' => null,
        'source_wholesale_custom_scent_id' => null,
        'recipe_components' => [],
        'create_inline_blend' => false,
        'inline_blend_name' => '',
        'is_active' => true,
    ];

    public bool $showEdit = false;
    public ?int $editingId = null;
    public array $edit = [];

    public bool $showDelete = false;
    public ?int $deletingId = null;

    public ?int $inlineRowId = null;
    public ?string $inlineField = null;
    public mixed $inlineValue = null;
    public ?int $focusedRowId = null;
    public ?string $focusedField = null;
    /** @var array<string,string> */
    public array $inlineErrors = [];
    /** @var array<string,bool> */
    public array $inlineSaving = [];
    /** @var array<string,bool> */
    public array $inlineSaved = [];

    public ?string $createErrorBanner = null;
    public ?string $editErrorBanner = null;

    public ?int $createCanonicalSuggestionId = null;
    public ?string $createCanonicalSuggestionLabel = null;
    public ?int $editCanonicalSuggestionId = null;
    public ?string $editCanonicalSuggestionLabel = null;

    protected $queryString = [
        'search' => ['except' => ''],
        'sort' => ['except' => 'name'],
        'dir' => ['except' => 'asc'],
        'perPage' => ['except' => 25],
    ];

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function startInlineEdit(int $scentId, string $field): void
    {
        if (! in_array($field, $this->inlineEditableFields(), true)) {
            return;
        }

        $scent = Scent::query()
            ->with(['canonicalScent:id,name,display_name', 'sourceWholesaleCustomScent:id,account_name,custom_scent_name', 'oilBlend:id,name'])
            ->find($scentId);

        if (! $scent) {
            return;
        }

        $this->inlineRowId = $scentId;
        $this->inlineField = $field;
        $this->inlineValue = $this->inlineDisplayValue($scent, $field);
        $this->focusedRowId = $scentId;
        $this->focusedField = $field;
        unset($this->inlineErrors[$this->inlineCellKey($scentId, $field)]);
    }

    public function cancelInlineEdit(): void
    {
        $this->inlineRowId = null;
        $this->inlineField = null;
        $this->inlineValue = null;
    }

    public function focusInlineCell(int $scentId, string $field): void
    {
        if (! in_array($field, $this->inlineEditableFields(), true)) {
            return;
        }

        $this->focusedRowId = $scentId;
        $this->focusedField = $field;
    }

    public function commitInlineEdit(string $direction = 'stay'): void
    {
        if (! $this->inlineRowId || ! $this->inlineField) {
            return;
        }

        $scentId = (int) $this->inlineRowId;
        $field = (string) $this->inlineField;
        $cellKey = $this->inlineCellKey($scentId, $field);
        $this->inlineSaving[$cellKey] = true;
        unset($this->inlineErrors[$cellKey], $this->inlineSaved[$cellKey]);

        try {
            $scent = Scent::query()->findOrFail($scentId);
            $payload = $this->normalizedPayload($this->payloadFromScent($scent));
            $currentValue = $payload[$field] ?? null;
            $payload[$field] = $this->normalizeInlineFieldValue($field, $this->inlineValue);

            if ($field === 'oil_blend_id' && ! blank($payload['oil_blend_id'] ?? null)) {
                $payload['is_blend'] = true;
            }

            if ($field === 'blend_oil_count' && ! blank($payload['blend_oil_count'] ?? null)) {
                $payload['is_blend'] = true;
            }

            if (! (bool) ($payload['is_blend'] ?? false)) {
                $payload['oil_blend_id'] = null;
                $payload['blend_oil_count'] = null;
            }

            if ($this->inlineValueUnchanged($field, $currentValue, $payload[$field] ?? null)) {
                $this->inlineSaved[$cellKey] = true;
                if ($direction === 'next' || $direction === 'prev') {
                    $this->moveInlineCursor($direction === 'next' ? 1 : -1);
                } else {
                    $this->cancelInlineEdit();
                }

                return;
            }

            $validator = validator(['edit' => $payload], $this->rulesFor('edit'));
            $this->validateRecipeRules($validator, $payload, 'edit', $scentId);
            $data = $validator->validate()['edit'];
            $this->assertUniqueFields($data, 'edit', $scentId);

            DB::transaction(function () use ($scent, $data): void {
                $this->persistScent($scent, $data, 'edit');
            });

            $this->inlineSaved[$cellKey] = true;
            if ($direction === 'next' || $direction === 'prev') {
                $this->moveInlineCursor($direction === 'next' ? 1 : -1);
            } else {
                $this->cancelInlineEdit();
            }
        } catch (ValidationException $e) {
            $this->inlineErrors[$cellKey] = $this->inlineValidationErrorMessage($e, $field);
            $this->dispatch('toast', ['message' => $this->inlineErrors[$cellKey], 'style' => 'error']);
        } catch (QueryException $e) {
            $this->inlineErrors[$cellKey] = $this->databaseErrorMessageForException($e, $field);
            $this->dispatch('toast', ['message' => $this->inlineErrors[$cellKey], 'style' => 'error']);
        } catch (Throwable $e) {
            Log::error('admin.catalog.scents.inline.failed', [
                'scent_id' => $scentId,
                'field' => $field,
                'error' => $e->getMessage(),
            ]);
            $this->inlineErrors[$cellKey] = 'Save failed due to a server error.';
            $this->dispatch('toast', ['message' => $this->inlineErrors[$cellKey], 'style' => 'error']);
        } finally {
            unset($this->inlineSaving[$cellKey]);
        }
    }

    public function setSort(string $field): void
    {
        if ($this->sort === $field) {
            $this->dir = $this->dir === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sort = $field;
            $this->dir = 'asc';
        }
    }

    #[On('scentSelected')]
    public function handleScentSelected(string $key, ?int $scentId = null): void
    {
        if ($key === 'catalog-scent-create-canonical') {
            $this->create['canonical_scent_id'] = $scentId;
            return;
        }

        if ($key === 'catalog-scent-edit-canonical') {
            $this->edit['canonical_scent_id'] = $scentId;
        }
    }

    public function openCreate(): void
    {
        if ($this->showCreate) {
            $this->closeCreate();
            return;
        }

        $this->createErrorBanner = null;
        $this->resetValidation();
        $this->showCreate = true;
        $this->ensureRecipeSeed('create');
    }

    public function closeCreate(): void
    {
        $this->showCreate = false;
        $this->createErrorBanner = null;
        $this->createCanonicalSuggestionId = null;
        $this->createCanonicalSuggestionLabel = null;
        $this->resetCreateState();
        $this->resetValidation();
    }

    public function create(): void
    {
        $this->createErrorBanner = null;
        $this->resetValidation();

        Log::info('admin.catalog.scents.create.request', [
            'name' => $this->create['name'] ?? null,
            'display_name' => $this->create['display_name'] ?? null,
            'abbreviation' => $this->create['abbreviation'] ?? null,
            'is_blend' => (bool) ($this->create['is_blend'] ?? false),
            'canonical_scent_id' => $this->create['canonical_scent_id'] ?? null,
            'source_wholesale_custom_scent_id' => $this->create['source_wholesale_custom_scent_id'] ?? null,
        ]);

        try {
            $data = $this->validateCreate();
            $this->assertUniqueFields($data, 'create');

            DB::transaction(function () use ($data): void {
                $this->persistScent(null, $data, 'create');
            });

            $this->closeCreate();
            $this->dispatch('toast', ['message' => 'Scent created.', 'style' => 'success']);
        } catch (ValidationException $e) {
            Log::warning('admin.catalog.scents.create.validation_failed', [
                'errors' => $e->errors(),
                'name' => $this->create['name'] ?? null,
                'abbreviation' => $this->create['abbreviation'] ?? null,
            ]);

            $this->createErrorBanner = 'Could not save scent. Fix the highlighted fields and try again.';
            $this->focusFirstInvalidField($e);
            throw $e;
        } catch (QueryException $e) {
            Log::error('admin.catalog.scents.create.query_failed', [
                'error' => $e->getMessage(),
                'name' => $this->create['name'] ?? null,
                'abbreviation' => $this->create['abbreviation'] ?? null,
            ]);

            $this->applyDatabaseExceptionAsFieldError($e, 'create');
            $this->createErrorBanner = 'Could not save scent due to a database conflict. Review the fields and try again.';
        } catch (Throwable $e) {
            Log::error('admin.catalog.scents.create.failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $this->createErrorBanner = 'Save failed due to a server error. Please try again.';
            $this->dispatch('toast', ['message' => $this->createErrorBanner, 'style' => 'error']);
        }
    }

    public function openEdit(int $id): void
    {
        $scent = Scent::query()->findOrFail($id);
        $this->editingId = $id;
        $this->editErrorBanner = null;
        $this->editCanonicalSuggestionId = null;
        $this->editCanonicalSuggestionLabel = null;
        $this->resetValidation();

        $this->edit = [
            'name' => $scent->name,
            'display_name' => $scent->display_name,
            'abbreviation' => $scent->abbreviation,
            'oil_reference_name' => $scent->oil_reference_name,
            'is_blend' => (bool) $scent->is_blend,
            'oil_blend_id' => $scent->oil_blend_id,
            'blend_oil_count' => $scent->blend_oil_count,
            'canonical_scent_id' => $scent->canonical_scent_id,
            'source_wholesale_custom_scent_id' => $scent->source_wholesale_custom_scent_id,
            'recipe_components' => $this->recipeComponentsForForm($scent),
            'create_inline_blend' => false,
            'inline_blend_name' => '',
            'is_active' => (bool) $scent->is_active,
        ];

        $this->ensureRecipeSeed('edit');
        $this->showEdit = true;
    }

    public function closeEdit(): void
    {
        $this->showEdit = false;
        $this->editingId = null;
        $this->edit = [];
        $this->editErrorBanner = null;
        $this->editCanonicalSuggestionId = null;
        $this->editCanonicalSuggestionLabel = null;
        $this->resetValidation();
    }

    public function save(): void
    {
        if (! $this->editingId) {
            return;
        }

        $this->editErrorBanner = null;
        $this->resetValidation();

        Log::info('admin.catalog.scents.update.request', [
            'scent_id' => $this->editingId,
            'name' => $this->edit['name'] ?? null,
            'display_name' => $this->edit['display_name'] ?? null,
            'abbreviation' => $this->edit['abbreviation'] ?? null,
            'is_blend' => (bool) ($this->edit['is_blend'] ?? false),
            'canonical_scent_id' => $this->edit['canonical_scent_id'] ?? null,
            'source_wholesale_custom_scent_id' => $this->edit['source_wholesale_custom_scent_id'] ?? null,
        ]);

        try {
            $data = $this->validateEdit();
            $this->assertUniqueFields($data, 'edit', $this->editingId);

            DB::transaction(function () use ($data): void {
                $scent = Scent::query()->findOrFail($this->editingId);
                $this->persistScent($scent, $data, 'edit');
            });

            $this->closeEdit();
            $this->dispatch('toast', ['message' => 'Scent updated.', 'style' => 'success']);
        } catch (ValidationException $e) {
            Log::warning('admin.catalog.scents.update.validation_failed', [
                'scent_id' => $this->editingId,
                'errors' => $e->errors(),
                'name' => $this->edit['name'] ?? null,
                'abbreviation' => $this->edit['abbreviation'] ?? null,
            ]);

            $this->editErrorBanner = 'Could not save scent. Fix the highlighted fields and try again.';
            $this->focusFirstInvalidField($e);
            throw $e;
        } catch (QueryException $e) {
            Log::error('admin.catalog.scents.update.query_failed', [
                'scent_id' => $this->editingId,
                'error' => $e->getMessage(),
            ]);

            $this->applyDatabaseExceptionAsFieldError($e, 'edit');
            $this->editErrorBanner = 'Could not save scent due to a database conflict. Review the fields and try again.';
        } catch (Throwable $e) {
            Log::error('admin.catalog.scents.update.failed', [
                'scent_id' => $this->editingId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $this->editErrorBanner = 'Save failed due to a server error. Please try again.';
            $this->dispatch('toast', ['message' => $this->editErrorBanner, 'style' => 'error']);
        }
    }

    public function openDelete(int $id): void
    {
        $this->deletingId = $id;
        $this->showDelete = true;
    }

    public function destroy(): void
    {
        if (! $this->deletingId) {
            return;
        }

        $inUse = OrderLine::query()->where('scent_id', $this->deletingId)->exists();
        if ($inUse) {
            $this->dispatch('toast', [
                'message' => 'Cannot delete: this scent is used by existing order lines. Deactivate it instead.',
                'style' => 'warning',
            ]);
            $this->showDelete = false;
            return;
        }

        Scent::query()->whereKey($this->deletingId)->delete();
        $this->showDelete = false;
        $this->dispatch('toast', ['message' => 'Scent deleted.', 'style' => 'success']);
    }

    public function addRecipeComponent(string $target): void
    {
        if ($target === 'create') {
            $this->create['recipe_components'][] = $this->blankRecipeComponent();
            return;
        }

        if ($target === 'edit') {
            $this->edit['recipe_components'][] = $this->blankRecipeComponent();
        }
    }

    public function removeRecipeComponent(string $target, int $index): void
    {
        if ($target === 'create') {
            unset($this->create['recipe_components'][$index]);
            $this->create['recipe_components'] = array_values($this->create['recipe_components']);
            return;
        }

        if ($target === 'edit') {
            unset($this->edit['recipe_components'][$index]);
            $this->edit['recipe_components'] = array_values($this->edit['recipe_components']);
        }
    }

    public function applySelectedWholesaleSource(string $target): void
    {
        $payload = $target === 'edit' ? $this->edit : $this->create;
        $sourceId = $payload['source_wholesale_custom_scent_id'] ?? null;
        $sourceId = blank($sourceId) ? null : (int) $sourceId;
        if (! $sourceId) {
            return;
        }

        $source = WholesaleCustomScent::query()->find($sourceId);
        if (! $source) {
            return;
        }

        $mapped = $this->hydrateFromWholesaleSource($payload, $source);
        if ($target === 'edit') {
            $this->edit = array_merge($this->edit, $mapped);
            $this->ensureRecipeSeed('edit');
        } else {
            $this->create = array_merge($this->create, $mapped);
            $this->ensureRecipeSeed('create');
        }
    }

    public function applyCanonicalSuggestion(string $target): void
    {
        if ($target === 'create' && $this->createCanonicalSuggestionId) {
            $this->create['canonical_scent_id'] = $this->createCanonicalSuggestionId;
            return;
        }

        if ($target === 'edit' && $this->editCanonicalSuggestionId) {
            $this->edit['canonical_scent_id'] = $this->editCanonicalSuggestionId;
        }
    }

    public function updatedCreateName($value): void
    {
        [$id, $label] = $this->canonicalSuggestionForName((string) $value);
        $this->createCanonicalSuggestionId = $id;
        $this->createCanonicalSuggestionLabel = $label;
    }

    public function updatedEditName($value): void
    {
        [$id, $label] = $this->canonicalSuggestionForName((string) $value, $this->editingId);
        $this->editCanonicalSuggestionId = $id;
        $this->editCanonicalSuggestionLabel = $label;
    }

    public function updatedCreateIsBlend($value): void
    {
        if (! filter_var($value, FILTER_VALIDATE_BOOL)) {
            $this->clearBlendFields('create');
            return;
        }

        $this->ensureRecipeSeed('create');
    }

    public function updatedEditIsBlend($value): void
    {
        if (! filter_var($value, FILTER_VALIDATE_BOOL)) {
            $this->clearBlendFields('edit');
            return;
        }

        $this->ensureRecipeSeed('edit');
    }

    protected function validateCreate(): array
    {
        $payload = $this->normalizedPayload($this->create);
        $validator = validator(['create' => $payload], $this->rulesFor('create'));
        $this->validateRecipeRules($validator, $payload, 'create');
        $validated = $validator->validate();

        return $validated['create'];
    }

    protected function validateEdit(): array
    {
        $payload = $this->normalizedPayload($this->edit);
        $validator = validator(['edit' => $payload], $this->rulesFor('edit'));
        $this->validateRecipeRules($validator, $payload, 'edit', $this->editingId);
        $validated = $validator->validate();

        return $validated['edit'];
    }

    protected function rulesFor(string $prefix): array
    {
        return [
            "{$prefix}.name" => ['required', 'string', 'max:255'],
            "{$prefix}.display_name" => ['nullable', 'string', 'max:255'],
            "{$prefix}.abbreviation" => ['nullable', 'string', 'max:64'],
            "{$prefix}.oil_reference_name" => ['nullable', 'string', 'max:255'],
            "{$prefix}.is_blend" => ['boolean'],
            "{$prefix}.oil_blend_id" => [
                Rule::excludeIf(! (bool) data_get($this->{$prefix}, 'is_blend', false)),
                'nullable',
                'exists:blends,id',
            ],
            "{$prefix}.blend_oil_count" => [
                Rule::excludeIf(! (bool) data_get($this->{$prefix}, 'is_blend', false)),
                'nullable',
                'integer',
                'min:1',
            ],
            "{$prefix}.canonical_scent_id" => ['nullable', 'exists:scents,id'],
            "{$prefix}.source_wholesale_custom_scent_id" => ['nullable', 'exists:wholesale_custom_scents,id'],
            "{$prefix}.recipe_components" => ['array'],
            "{$prefix}.recipe_components.*.type" => ['nullable', Rule::in(['base_oil', 'blend'])],
            "{$prefix}.recipe_components.*.id" => ['nullable', 'integer'],
            "{$prefix}.recipe_components.*.ratio_weight" => ['nullable', 'integer', 'min:1'],
            "{$prefix}.create_inline_blend" => ['boolean'],
            "{$prefix}.inline_blend_name" => ['nullable', 'string', 'max:255'],
            "{$prefix}.is_active" => ['boolean'],
        ];
    }

    protected function validateRecipeRules($validator, array $payload, string $prefix, ?int $ignoreScentId = null): void
    {
        $validator->after(function ($validator) use ($payload, $prefix, $ignoreScentId): void {
            $isBlend = (bool) ($payload['is_blend'] ?? false);
            $rows = collect($payload['recipe_components'] ?? [])
                ->filter(fn (array $row): bool => ($row['id'] ?? null) !== null);

            foreach ($rows as $index => $row) {
                $type = $row['type'] ?? null;
                $id = $row['id'] ?? null;
                if ($type === 'base_oil' && $id && ! BaseOil::query()->whereKey($id)->exists()) {
                    $validator->errors()->add("{$prefix}.recipe_components.{$index}.id", 'Selected oil no longer exists.');
                }
                if ($type === 'blend' && $id && ! Blend::query()->whereKey($id)->exists()) {
                    $validator->errors()->add("{$prefix}.recipe_components.{$index}.id", 'Selected blend no longer exists.');
                }
            }

            if (! $isBlend) {
                return;
            }

            $hasBlendLink = ! blank($payload['oil_blend_id'] ?? null);
            $inlineBlend = (bool) ($payload['create_inline_blend'] ?? false);
            if (! $hasBlendLink && ! $inlineBlend) {
                $validator->errors()->add(
                    "{$prefix}.oil_blend_id",
                    'Blend scents must link to an existing blend or create one from recipe sources.'
                );
            }

            if ($inlineBlend && trim((string) ($payload['inline_blend_name'] ?? '')) === '') {
                $validator->errors()->add("{$prefix}.inline_blend_name", 'Blend name is required when creating a new blend.');
            }

            if ($inlineBlend && $rows->isEmpty()) {
                $validator->errors()->add(
                    "{$prefix}.recipe_components",
                    'Add at least one oil or blend source before creating a new blend.'
                );
            }

            if ($ignoreScentId && (int) ($payload['canonical_scent_id'] ?? 0) === (int) $ignoreScentId) {
                $validator->errors()->add("{$prefix}.canonical_scent_id", 'A scent cannot map to itself as canonical.');
            }
        });
    }

    protected function assertUniqueFields(array $payload, string $prefix, ?int $ignoreId = null): void
    {
        $name = trim((string) ($payload['name'] ?? ''));
        if ($name === '') {
            return;
        }

        $normalizedName = Scent::normalizeName($name);
        $nameQuery = Scent::query()->whereRaw('lower(name) = ?', [$normalizedName]);
        if ($ignoreId) {
            $nameQuery->where('id', '!=', $ignoreId);
        }
        if ($nameQuery->exists()) {
            throw ValidationException::withMessages([
                "{$prefix}.name" => 'A scent with this name already exists.',
            ]);
        }

        $displayName = trim((string) ($payload['display_name'] ?? ''));
        if ($displayName !== '') {
            $displayQuery = Scent::query()->whereRaw('lower(display_name) = ?', [mb_strtolower($displayName)]);
            if ($ignoreId) {
                $displayQuery->where('id', '!=', $ignoreId);
            }
            if ($displayQuery->exists()) {
                throw ValidationException::withMessages([
                    "{$prefix}.display_name" => 'This display name is already used by another scent.',
                ]);
            }
        }

        $abbreviation = trim((string) ($payload['abbreviation'] ?? ''));
        if ($abbreviation !== '') {
            $abbreviationQuery = Scent::query()->whereRaw('lower(abbreviation) = ?', [mb_strtolower($abbreviation)]);
            if ($ignoreId) {
                $abbreviationQuery->where('id', '!=', $ignoreId);
            }
            if ($abbreviationQuery->exists()) {
                throw ValidationException::withMessages([
                    "{$prefix}.abbreviation" => 'This abbreviation is already used by another scent.',
                ]);
            }
        }
    }

    protected function persistScent(?Scent $scent, array $payload, string $prefix): Scent
    {
        $payload = $this->applyWholesaleSourceToPayload($payload);
        $resolvedRows = $this->resolveRecipeRows($payload['recipe_components'] ?? [], $prefix);

        $oilBlendId = $payload['oil_blend_id'] ?? null;
        $blendOilCount = $payload['blend_oil_count'] ?? null;

        if ((bool) ($payload['is_blend'] ?? false) && (bool) ($payload['create_inline_blend'] ?? false)) {
            $inlineBlendName = trim((string) ($payload['inline_blend_name'] ?? ''));
            $blend = $this->createInlineBlendFromResolvedRows($inlineBlendName, $resolvedRows, $prefix);
            $oilBlendId = $blend->id;
            $blendOilCount = $blend->components()->count();
        }

        if ((bool) ($payload['is_blend'] ?? false) && $oilBlendId && ! $blendOilCount) {
            $blendOilCount = BlendComponent::query()->where('blend_id', $oilBlendId)->count() ?: null;
        }

        if (! (bool) ($payload['is_blend'] ?? false)) {
            $oilBlendId = null;
            $blendOilCount = null;
            $resolvedRows = [];
        }

        $oilReference = trim((string) ($payload['oil_reference_name'] ?? ''));
        if ($oilReference === '' && $resolvedRows !== []) {
            $oilReference = $this->recipeSummary($resolvedRows);
        }

        $canonicalId = blank($payload['canonical_scent_id'] ?? null) ? null : (int) $payload['canonical_scent_id'];
        if ($scent && $canonicalId === (int) $scent->id) {
            $canonicalId = null;
        }

        if (! $scent) {
            $scent = new Scent();
        }

        $scent->fill([
            'name' => Scent::normalizeName((string) ($payload['name'] ?? '')),
            'display_name' => blank($payload['display_name'] ?? null) ? null : trim((string) $payload['display_name']),
            'abbreviation' => blank($payload['abbreviation'] ?? null) ? null : trim((string) $payload['abbreviation']),
            'oil_reference_name' => $oilReference === '' ? null : $oilReference,
            'is_blend' => (bool) ($payload['is_blend'] ?? false),
            'oil_blend_id' => $oilBlendId,
            'blend_oil_count' => $blendOilCount,
            'canonical_scent_id' => $canonicalId,
            'source_wholesale_custom_scent_id' => blank($payload['source_wholesale_custom_scent_id'] ?? null)
                ? null
                : (int) $payload['source_wholesale_custom_scent_id'],
            'recipe_components_json' => $resolvedRows === [] ? null : $resolvedRows,
            'is_active' => (bool) ($payload['is_active'] ?? true),
        ]);
        $scent->save();

        $this->syncCanonicalAlias($scent, $canonicalId);

        return $scent;
    }

    protected function createInlineBlendFromResolvedRows(string $blendName, array $resolvedRows, string $prefix): Blend
    {
        $existing = Blend::query()->whereRaw('lower(name) = ?', [mb_strtolower($blendName)])->exists();
        if ($existing) {
            throw ValidationException::withMessages([
                "{$prefix}.inline_blend_name" => 'A blend with this name already exists.',
            ]);
        }

        $baseOilWeights = $this->expandToBaseOilWeights($resolvedRows, $prefix);
        if ($baseOilWeights === []) {
            throw ValidationException::withMessages([
                "{$prefix}.recipe_components" => 'Could not resolve this recipe into base oils.',
            ]);
        }

        $blend = Blend::query()->create([
            'name' => $blendName,
            'is_blend' => true,
        ]);

        foreach ($baseOilWeights as $baseOilId => $weight) {
            $ratio = max(1, (int) round($weight * 100));
            BlendComponent::query()->create([
                'blend_id' => $blend->id,
                'base_oil_id' => (int) $baseOilId,
                'ratio_weight' => $ratio,
            ]);
        }

        return $blend;
    }

    protected function expandToBaseOilWeights(array $rows, string $prefix): array
    {
        $weights = [];
        foreach ($rows as $row) {
            $type = (string) ($row['type'] ?? '');
            $weight = (float) ($row['ratio_weight'] ?? 0.0);
            if ($weight <= 0) {
                continue;
            }

            if ($type === 'base_oil') {
                $baseOilId = (int) ($row['id'] ?? 0);
                if ($baseOilId <= 0) {
                    continue;
                }
                $weights[$baseOilId] = ($weights[$baseOilId] ?? 0.0) + $weight;
                continue;
            }

            if ($type === 'blend') {
                $blend = Blend::query()->with('components')->find((int) ($row['id'] ?? 0));
                if (! $blend || $blend->components->isEmpty()) {
                    throw ValidationException::withMessages([
                        "{$prefix}.recipe_components" => 'A selected blend has no base-oil components configured.',
                    ]);
                }

                $total = (float) $blend->components->sum('ratio_weight');
                if ($total <= 0) {
                    throw ValidationException::withMessages([
                        "{$prefix}.recipe_components" => 'A selected blend has invalid component weights.',
                    ]);
                }

                foreach ($blend->components as $component) {
                    $componentWeight = $weight * ((float) $component->ratio_weight / $total);
                    $weights[$component->base_oil_id] = ($weights[$component->base_oil_id] ?? 0.0) + $componentWeight;
                }
            }
        }

        return $weights;
    }

    protected function resolveRecipeRows(array $rows, string $prefix): array
    {
        $resolved = [];
        foreach ($rows as $row) {
            $type = (string) ($row['type'] ?? '');
            $id = (int) ($row['id'] ?? 0);
            $weight = (int) ($row['ratio_weight'] ?? 0);
            if ($id <= 0 || $weight <= 0 || ! in_array($type, ['base_oil', 'blend'], true)) {
                continue;
            }

            if ($type === 'base_oil') {
                $oil = BaseOil::query()->find($id);
                if (! $oil) {
                    throw ValidationException::withMessages([
                        "{$prefix}.recipe_components" => 'One or more selected oils no longer exist.',
                    ]);
                }

                $resolved[] = [
                    'type' => 'base_oil',
                    'id' => $oil->id,
                    'name' => (string) $oil->name,
                    'ratio_weight' => $weight,
                ];
                continue;
            }

            $blend = Blend::query()->find($id);
            if (! $blend) {
                throw ValidationException::withMessages([
                    "{$prefix}.recipe_components" => 'One or more selected blends no longer exist.',
                ]);
            }

            $resolved[] = [
                'type' => 'blend',
                'id' => $blend->id,
                'name' => (string) $blend->name,
                'ratio_weight' => $weight,
            ];
        }

        return $resolved;
    }

    protected function applyWholesaleSourceToPayload(array $payload): array
    {
        $sourceId = blank($payload['source_wholesale_custom_scent_id'] ?? null)
            ? null
            : (int) $payload['source_wholesale_custom_scent_id'];

        if (! $sourceId) {
            return $payload;
        }

        $source = WholesaleCustomScent::query()->find($sourceId);
        if (! $source) {
            return $payload;
        }

        return $this->hydrateFromWholesaleSource($payload, $source);
    }

    protected function hydrateFromWholesaleSource(array $payload, WholesaleCustomScent $source): array
    {
        if (blank($payload['canonical_scent_id'] ?? null) && $source->canonical_scent_id) {
            $payload['canonical_scent_id'] = (int) $source->canonical_scent_id;
        }

        if (trim((string) ($payload['oil_reference_name'] ?? '')) === '') {
            $oilSummary = collect([$source->oil_1, $source->oil_2, $source->oil_3])
                ->filter(fn ($value) => ! blank($value))
                ->map(fn ($value) => trim((string) $value))
                ->values()
                ->implode(' + ');
            if ($oilSummary !== '') {
                $payload['oil_reference_name'] = $oilSummary;
            }
        }

        $rows = collect($payload['recipe_components'] ?? [])
            ->filter(fn (array $row): bool => ! blank($row['id'] ?? null))
            ->values()
            ->all();

        if (($payload['is_blend'] ?? false) && $rows === [] && blank($payload['oil_blend_id'] ?? null)) {
            $payload['recipe_components'] = $this->deriveRecipeComponentsFromWholesale($source);
            if ($payload['recipe_components'] !== []) {
                $payload['is_blend'] = true;
            }
        }

        return $payload;
    }

    protected function deriveRecipeComponentsFromWholesale(WholesaleCustomScent $source): array
    {
        $candidates = [];
        $components = $source->top_level_recipe_json['components'] ?? null;
        if (is_array($components) && $components !== []) {
            foreach ($components as $component) {
                if (! is_array($component)) {
                    continue;
                }
                $name = trim((string) ($component['name'] ?? ''));
                $weight = (int) max(1, (float) ($component['weight'] ?? 1));
                if ($name !== '') {
                    $candidates[] = ['name' => $name, 'weight' => $weight];
                }
            }
        }

        if ($candidates === []) {
            foreach ([$source->oil_1, $source->oil_2, $source->oil_3] as $slot) {
                $name = trim((string) $slot);
                if ($name !== '') {
                    $candidates[] = ['name' => $name, 'weight' => 1];
                }
            }
        }

        if ($candidates === []) {
            return [];
        }

        $blendMap = Blend::query()
            ->orderBy('name')
            ->get(['id', 'name'])
            ->keyBy(fn (Blend $blend): string => mb_strtolower(trim($blend->name)));

        $oilMap = BaseOil::query()
            ->orderBy('name')
            ->get(['id', 'name'])
            ->keyBy(fn (BaseOil $oil): string => mb_strtolower(trim($oil->name)));

        $rows = [];
        foreach ($candidates as $candidate) {
            $key = mb_strtolower(trim((string) ($candidate['name'] ?? '')));
            if ($key === '') {
                continue;
            }

            $weight = (int) max(1, (int) ($candidate['weight'] ?? 1));
            if ($blendMap->has($key)) {
                $rows[] = [
                    'type' => 'blend',
                    'id' => (int) $blendMap[$key]->id,
                    'ratio_weight' => $weight,
                ];
                continue;
            }

            if ($oilMap->has($key)) {
                $rows[] = [
                    'type' => 'base_oil',
                    'id' => (int) $oilMap[$key]->id,
                    'ratio_weight' => $weight,
                ];
            }
        }

        return $rows;
    }

    protected function recipeSummary(array $rows): string
    {
        return collect($rows)
            ->take(4)
            ->map(fn (array $row): string => (string) ($row['name'] ?? ''))
            ->filter(fn (string $name): bool => $name !== '')
            ->implode(' + ');
    }

    protected function syncCanonicalAlias(Scent $scent, ?int $canonicalId): void
    {
        if (! Schema::hasTable('scent_aliases')) {
            return;
        }

        $aliasValue = ScentAlias::normalizeLabel((string) $scent->name);
        if ($aliasValue === '') {
            return;
        }

        ScentAlias::query()
            ->where('alias', $aliasValue)
            ->where('scope', 'catalog')
            ->delete();

        if (! $canonicalId) {
            return;
        }

        ScentAlias::query()->updateOrCreate(
            ['alias' => $aliasValue, 'scope' => 'catalog'],
            ['scent_id' => $canonicalId]
        );
    }

    protected function recipeComponentsForForm(Scent $scent): array
    {
        $fromJson = is_array($scent->recipe_components_json) ? $scent->recipe_components_json : [];
        $rows = collect($fromJson)
            ->filter(fn ($row) => is_array($row))
            ->map(function (array $row): array {
                return [
                    'type' => (string) ($row['type'] ?? ''),
                    'id' => blank($row['id'] ?? null) ? null : (int) $row['id'],
                    'ratio_weight' => blank($row['ratio_weight'] ?? null) ? 1 : (int) $row['ratio_weight'],
                ];
            })
            ->filter(fn (array $row): bool => in_array($row['type'], ['base_oil', 'blend'], true))
            ->values()
            ->all();

        if ($rows !== []) {
            return $rows;
        }

        if ($scent->oil_blend_id) {
            return [[
                'type' => 'blend',
                'id' => (int) $scent->oil_blend_id,
                'ratio_weight' => 1,
            ]];
        }

        return [];
    }

    protected function canonicalSuggestionForName(string $value, ?int $ignoreId = null): array
    {
        $normalized = trim(Scent::normalizeName($value));
        if ($normalized === '' || mb_strlen($normalized) < 3) {
            return [null, null];
        }

        $candidate = Scent::query()
            ->when($ignoreId, fn ($query) => $query->where('id', '!=', $ignoreId))
            ->where(function ($query) use ($normalized): void {
                $like = '%' . $normalized . '%';
                $query->whereRaw('lower(name) like ?', [$like])
                    ->orWhereRaw('lower(coalesce(display_name, \'\')) like ?', [$like]);
            })
            ->orderByRaw('case when lower(name) = ? then 0 else 1 end', [$normalized])
            ->orderByRaw('coalesce(display_name, name)')
            ->first(['id', 'name', 'display_name']);

        if (! $candidate) {
            return [null, null];
        }

        $label = (string) ($candidate->display_name ?: $candidate->name);
        if (mb_strtolower($label) === mb_strtolower(trim($value))) {
            return [null, null];
        }

        return [(int) $candidate->id, $label];
    }

    protected function applyDatabaseExceptionAsFieldError(QueryException $exception, string $prefix): void
    {
        $message = mb_strtolower($exception->getMessage());

        if (str_contains($message, 'scents.name') || str_contains($message, 'scents_name_unique')) {
            $this->addError("{$prefix}.name", 'A scent with this name already exists.');
            return;
        }

        if (str_contains($message, 'scents.abbreviation')) {
            $this->addError("{$prefix}.abbreviation", 'This abbreviation is already used by another scent.');
            return;
        }

        if (str_contains($message, 'scents.display_name')) {
            $this->addError("{$prefix}.display_name", 'This display name is already used by another scent.');
            return;
        }

        $this->addError("{$prefix}.name", 'Save failed. Please review your entries and try again.');
    }

    protected function focusFirstInvalidField(ValidationException $exception): void
    {
        $firstError = array_key_first($exception->errors());
        if (! $firstError) {
            return;
        }

        $this->dispatch('catalog-scent-focus-invalid', ['field' => $firstError]);
    }

    protected function clearBlendFields(string $target): void
    {
        if ($target === 'create') {
            $this->create['oil_blend_id'] = null;
            $this->create['blend_oil_count'] = null;
            $this->create['recipe_components'] = [];
            $this->create['create_inline_blend'] = false;
            $this->create['inline_blend_name'] = '';
            return;
        }

        if ($target === 'edit') {
            $this->edit['oil_blend_id'] = null;
            $this->edit['blend_oil_count'] = null;
            $this->edit['recipe_components'] = [];
            $this->edit['create_inline_blend'] = false;
            $this->edit['inline_blend_name'] = '';
        }
    }

    protected function ensureRecipeSeed(string $target): void
    {
        if ($target === 'create' && (bool) ($this->create['is_blend'] ?? false) && empty($this->create['recipe_components'])) {
            $this->create['recipe_components'][] = $this->blankRecipeComponent();
            return;
        }

        if ($target === 'edit' && (bool) ($this->edit['is_blend'] ?? false) && empty($this->edit['recipe_components'])) {
            $this->edit['recipe_components'][] = $this->blankRecipeComponent();
        }
    }

    protected function blankRecipeComponent(): array
    {
        return [
            'type' => 'base_oil',
            'id' => null,
            'ratio_weight' => 1,
        ];
    }

    protected function resetCreateState(): void
    {
        $this->create = [
            'name' => '',
            'display_name' => '',
            'abbreviation' => '',
            'oil_reference_name' => '',
            'is_blend' => false,
            'oil_blend_id' => null,
            'blend_oil_count' => null,
            'canonical_scent_id' => null,
            'source_wholesale_custom_scent_id' => null,
            'recipe_components' => [],
            'create_inline_blend' => false,
            'inline_blend_name' => '',
            'is_active' => true,
        ];
    }

    protected function normalizedPayload(array $payload): array
    {
        $normalized = [
            'name' => trim((string) ($payload['name'] ?? '')),
            'display_name' => blank($payload['display_name'] ?? null) ? null : trim((string) $payload['display_name']),
            'abbreviation' => blank($payload['abbreviation'] ?? null) ? null : trim((string) $payload['abbreviation']),
            'oil_reference_name' => blank($payload['oil_reference_name'] ?? null) ? null : trim((string) $payload['oil_reference_name']),
            'is_blend' => (bool) ($payload['is_blend'] ?? false),
            'oil_blend_id' => blank($payload['oil_blend_id'] ?? null) ? null : (int) $payload['oil_blend_id'],
            'blend_oil_count' => blank($payload['blend_oil_count'] ?? null) ? null : (int) $payload['blend_oil_count'],
            'canonical_scent_id' => blank($payload['canonical_scent_id'] ?? null) ? null : (int) $payload['canonical_scent_id'],
            'source_wholesale_custom_scent_id' => blank($payload['source_wholesale_custom_scent_id'] ?? null)
                ? null
                : (int) $payload['source_wholesale_custom_scent_id'],
            'recipe_components' => $this->normalizeRecipeComponents($payload['recipe_components'] ?? []),
            'create_inline_blend' => (bool) ($payload['create_inline_blend'] ?? false),
            'inline_blend_name' => trim((string) ($payload['inline_blend_name'] ?? '')),
            'is_active' => (bool) ($payload['is_active'] ?? true),
        ];

        if (! $normalized['is_blend']) {
            $normalized['oil_blend_id'] = null;
            $normalized['blend_oil_count'] = null;
            $normalized['recipe_components'] = [];
            $normalized['create_inline_blend'] = false;
            $normalized['inline_blend_name'] = '';
        }

        return $normalized;
    }

    protected function normalizeRecipeComponents(array $rows): array
    {
        return array_values(array_map(function ($row): array {
            return [
                'type' => in_array(($row['type'] ?? null), ['base_oil', 'blend'], true)
                    ? (string) $row['type']
                    : 'base_oil',
                'id' => blank($row['id'] ?? null) ? null : (int) $row['id'],
                'ratio_weight' => blank($row['ratio_weight'] ?? null) ? 1 : max(1, (int) $row['ratio_weight']),
            ];
        }, $rows));
    }

    protected function inlineEditableFields(): array
    {
        return [
            'name',
            'display_name',
            'abbreviation',
            'oil_reference_name',
            'canonical_scent_id',
            'source_wholesale_custom_scent_id',
            'is_blend',
            'oil_blend_id',
            'blend_oil_count',
            'is_active',
        ];
    }

    protected function inlineCellKey(int $id, string $field): string
    {
        return $id . ':' . $field;
    }

    protected function payloadFromScent(Scent $scent): array
    {
        return [
            'name' => $scent->name,
            'display_name' => $scent->display_name,
            'abbreviation' => $scent->abbreviation,
            'oil_reference_name' => $scent->oil_reference_name,
            'is_blend' => (bool) $scent->is_blend,
            'oil_blend_id' => $scent->oil_blend_id,
            'blend_oil_count' => $scent->blend_oil_count,
            'canonical_scent_id' => $scent->canonical_scent_id,
            'source_wholesale_custom_scent_id' => $scent->source_wholesale_custom_scent_id,
            'recipe_components' => $this->recipeComponentsForForm($scent),
            'create_inline_blend' => false,
            'inline_blend_name' => '',
            'is_active' => (bool) $scent->is_active,
        ];
    }

    protected function inlineDisplayValue(Scent $scent, string $field): mixed
    {
        return match ($field) {
            'canonical_scent_id' => $scent->canonicalScent
                ? ($scent->canonicalScent->display_name ?: $scent->canonicalScent->name)
                : '',
            'source_wholesale_custom_scent_id' => $scent->sourceWholesaleCustomScent
                ? ($scent->sourceWholesaleCustomScent->custom_scent_name . ' · ' . $scent->sourceWholesaleCustomScent->account_name)
                : '',
            'oil_blend_id' => $scent->oilBlend?->name ?? '',
            default => $scent->getAttribute($field),
        };
    }

    protected function normalizeInlineFieldValue(string $field, mixed $value): mixed
    {
        if (in_array($field, ['is_blend', 'is_active'], true)) {
            if (is_bool($value)) {
                return $value;
            }
            $normalized = mb_strtolower(trim((string) $value));
            return in_array($normalized, ['1', 'true', 'yes', 'y', 'on', 'blend', 'active'], true);
        }

        if ($field === 'blend_oil_count') {
            if (blank($value)) {
                return null;
            }
            return max(1, (int) $value);
        }

        if ($field === 'oil_blend_id') {
            if (blank($value)) {
                return null;
            }
            $fromToken = $this->extractTokenId($value);
            if ($fromToken && Blend::query()->whereKey($fromToken)->exists()) {
                return $fromToken;
            }
            if (is_numeric($value) && Blend::query()->whereKey((int) $value)->exists()) {
                return (int) $value;
            }
            $normalized = mb_strtolower(trim((string) $value));
            $match = Blend::query()
                ->whereRaw('lower(name) = ?', [$normalized])
                ->orWhereRaw('lower(name) like ?', ['%' . $normalized . '%'])
                ->value('id');
            return $match ? (int) $match : null;
        }

        if ($field === 'canonical_scent_id') {
            if (blank($value)) {
                return null;
            }
            $fromToken = $this->extractTokenId($value);
            if ($fromToken && Scent::query()->whereKey($fromToken)->exists()) {
                return $fromToken;
            }
            $normalized = mb_strtolower(trim((string) $value));
            $match = Scent::query()
                ->where(function ($query) use ($normalized): void {
                    $query->whereRaw('lower(name) = ?', [$normalized])
                        ->orWhereRaw('lower(display_name) = ?', [$normalized])
                        ->orWhereRaw('lower(name) like ?', ['%' . $normalized . '%'])
                        ->orWhereRaw('lower(coalesce(display_name, \'\')) like ?', ['%' . $normalized . '%']);
                })
                ->orderByRaw('case when lower(name) = ? then 0 when lower(display_name) = ? then 0 else 1 end', [$normalized, $normalized])
                ->value('id');
            return $match ? (int) $match : null;
        }

        if ($field === 'source_wholesale_custom_scent_id') {
            if (blank($value)) {
                return null;
            }
            $fromToken = $this->extractTokenId($value);
            if ($fromToken && WholesaleCustomScent::query()->whereKey($fromToken)->exists()) {
                return $fromToken;
            }
            if (is_numeric($value) && WholesaleCustomScent::query()->whereKey((int) $value)->exists()) {
                return (int) $value;
            }
            $normalized = mb_strtolower(trim((string) $value));
            $match = WholesaleCustomScent::query()
                ->whereRaw("lower(concat(custom_scent_name, ' · ', account_name)) = ?", [$normalized])
                ->orWhereRaw('lower(custom_scent_name) = ?', [$normalized])
                ->orWhereRaw('lower(custom_scent_name) like ?', ['%' . $normalized . '%'])
                ->orWhereRaw('lower(account_name) like ?', ['%' . $normalized . '%'])
                ->value('id');
            return $match ? (int) $match : null;
        }

        return is_string($value) ? trim($value) : $value;
    }

    protected function extractTokenId(mixed $value): ?int
    {
        if (! is_string($value)) {
            return null;
        }

        if (! preg_match('/^\s*(\d+)\s*::/', $value, $matches)) {
            return null;
        }

        $id = (int) ($matches[1] ?? 0);

        return $id > 0 ? $id : null;
    }

    protected function inlineValueUnchanged(string $field, mixed $before, mixed $after): bool
    {
        if (in_array($field, ['is_blend', 'is_active'], true)) {
            return (bool) $before === (bool) $after;
        }

        if ($field === 'blend_oil_count') {
            if (blank($before) && blank($after)) {
                return true;
            }

            return (int) $before === (int) $after;
        }

        if (in_array($field, ['oil_blend_id', 'canonical_scent_id', 'source_wholesale_custom_scent_id'], true)) {
            if (blank($before) && blank($after)) {
                return true;
            }

            return (int) $before === (int) $after;
        }

        return trim((string) ($before ?? '')) === trim((string) ($after ?? ''));
    }

    protected function inlineValidationErrorMessage(ValidationException $exception, string $field): string
    {
        $errors = $exception->errors();
        $preferredKey = 'edit.' . $field;

        if (! empty($errors[$preferredKey])) {
            return (string) $errors[$preferredKey][0];
        }

        return (string) collect($errors)->flatten()->first() ?: 'Could not save this field.';
    }

    protected function moveInlineCursor(int $direction): void
    {
        if (! $this->inlineRowId || ! $this->inlineField) {
            return;
        }

        $fields = $this->inlineEditableFields();
        $index = array_search($this->inlineField, $fields, true);
        if ($index === false) {
            $this->cancelInlineEdit();
            return;
        }

        $next = $index + $direction;
        if (! isset($fields[$next])) {
            $this->cancelInlineEdit();
            return;
        }

        $this->startInlineEdit((int) $this->inlineRowId, $fields[$next]);
    }

    protected function databaseErrorMessageForException(QueryException $e, string $field): string
    {
        $message = mb_strtolower($e->getMessage());
        if (str_contains($message, 'abbreviation')) {
            return 'Abbrev already exists.';
        }
        if (str_contains($message, 'display_name')) {
            return 'Display Name already exists.';
        }
        if (str_contains($message, 'name')) {
            return 'Name already exists.';
        }

        return 'Could not save this field.';
    }

    public function render()
    {
        $scents = Scent::query()
            ->with([
                'canonicalScent:id,name,display_name',
                'sourceWholesaleCustomScent:id,account_name,custom_scent_name',
            ])
            ->when($this->search !== '', function ($query) {
                $s = '%' . $this->search . '%';
                $query->where(function ($inner) use ($s): void {
                    $inner->where('name', 'like', $s)
                        ->orWhere('display_name', 'like', $s)
                        ->orWhere('abbreviation', 'like', $s)
                        ->orWhere('oil_reference_name', 'like', $s);
                });
            })
            ->orderBy($this->sort, $this->dir)
            ->paginate($this->perPage);

        $wholesaleSources = WholesaleCustomScent::query()
            ->orderBy('account_name')
            ->orderBy('custom_scent_name')
            ->get([
                'id',
                'account_name',
                'custom_scent_name',
                'canonical_scent_id',
                'oil_1',
                'oil_2',
                'oil_3',
                'total_oils',
                'top_level_recipe_json',
            ]);

        return view('livewire.admin.catalog.scents', [
            'scents' => $scents,
            'blends' => Blend::query()->orderBy('name')->get(['id', 'name']),
            'baseOils' => BaseOil::query()->orderBy('name')->get(['id', 'name']),
            'canonicalScents' => Scent::query()->orderByRaw('coalesce(display_name, name)')->get(['id', 'name', 'display_name']),
            'wholesaleSources' => $wholesaleSources,
        ])->layout('layouts.app');
    }
}

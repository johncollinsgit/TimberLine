<?php

namespace App\Livewire\Admin\Wholesale;

use App\Models\WholesaleCustomScent;
use App\Models\Scent;
use App\Services\ScentGovernance\ResolveScentMatchService;
use App\Services\Recipes\NestedOilRecipeResolver;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;
use Throwable;

class CustomScentsCrud extends Component
{
    use WithFileUploads;
    use WithPagination;

    public string $search = '';
    public string $filter = 'all'; // all | mapped | unmapped
    public int $perPage = 25;
    public bool $showCreate = false;

    public array $create = [
        'account_name' => '',
        'custom_scent_name' => '',
        'oil_1' => '',
        'oil_2' => '',
        'oil_3' => '',
        'total_oils' => null,
        'abbreviation' => '',
        'canonical_scent_id' => null,
        'notes' => '',
        'active' => true,
    ];

    public bool $showEdit = false;
    public ?int $editingId = null;
    public array $edit = [];

    public bool $showDelete = false;
    public ?int $deletingId = null;
    public $masterCsvUpload = null;

    protected $queryString = [
        'search' => ['except' => ''],
        'filter' => ['except' => 'all'],
        'perPage' => ['except' => 25],
    ];

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedFilter(): void
    {
        $this->resetPage();
    }

    public function updatedPerPage(): void
    {
        $this->resetPage();
    }

    public function openCreate(): void
    {
        $this->showCreate = !$this->showCreate;
    }

    #[On('scentSelected')]
    public function handleScentSelected(string $key, ?int $scentId = null, ?string $scentName = null): void
    {
        if ($key === 'wholesale-create') {
            $this->create['canonical_scent_id'] = $scentId;
            return;
        }

        if ($key === 'wholesale-edit') {
            $this->edit['canonical_scent_id'] = $scentId;
        }
    }

    public function create(): void
    {
        $data = $this->validateCreate();
        $data['canonical_scent_id'] = $this->resolveCanonicalScentId($data);
        $this->assertUnique($data['account_name'], $data['custom_scent_name']);

        WholesaleCustomScent::query()->create($this->withRecipePayload($data));

        $this->reset('create');
        $this->create['active'] = true;

        $this->dispatch('toast', ['message' => 'Wholesale custom scent added.', 'style' => 'success']);
    }

    public function openEdit(int $id): void
    {
        if ($this->showEdit && (int) $this->editingId === $id) {
            $this->closeEdit();
            return;
        }

        $record = WholesaleCustomScent::query()->findOrFail($id);
        $this->editingId = $id;
        $this->edit = [
            'account_name' => $record->account_name,
            'custom_scent_name' => $record->custom_scent_name,
            'oil_1' => $record->oil_1,
            'oil_2' => $record->oil_2,
            'oil_3' => $record->oil_3,
            'total_oils' => $record->total_oils,
            'abbreviation' => $record->abbreviation,
            'canonical_scent_id' => $record->canonical_scent_id,
            'notes' => $record->notes,
            'active' => (bool) $record->active,
        ];
        $this->showEdit = true;
    }

    public function closeEdit(): void
    {
        $this->showEdit = false;
        $this->editingId = null;
        $this->edit = [];
    }

    public function save(): void
    {
        if (!$this->editingId) {
            return;
        }

        $data = $this->validateEdit();
        $data['canonical_scent_id'] = $this->resolveCanonicalScentId($data);
        $this->assertUnique($data['account_name'], $data['custom_scent_name'], $this->editingId);

        WholesaleCustomScent::query()->whereKey($this->editingId)->update(
            $this->withRecipePayload($data, $this->editingId)
        );

        $this->closeEdit();

        $this->dispatch('toast', ['message' => 'Wholesale custom scent updated.', 'style' => 'success']);
    }

    public function openDelete(int $id): void
    {
        $this->deletingId = $id;
        $this->showDelete = true;
    }

    public function destroy(): void
    {
        if (!$this->deletingId) {
            return;
        }

        WholesaleCustomScent::query()->whereKey($this->deletingId)->delete();
        $this->showDelete = false;
        $this->dispatch('toast', ['message' => 'Wholesale custom scent deleted.', 'style' => 'success']);
    }

    public function updatedMasterCsvUpload(): void
    {
        if (! $this->masterCsvUpload) {
            return;
        }

        $this->syncMasterCsv();
    }

    public function syncMasterCsv(): void
    {
        $this->validate([
            'masterCsvUpload' => ['required', 'file', 'max:10240', 'mimes:csv,txt'],
        ]);

        $absolutePath = $this->masterCsvUpload?->getRealPath();
        if (! is_string($absolutePath) || $absolutePath === '' || ! is_file($absolutePath)) {
            $this->dispatch('toast', [
                'type' => 'error',
                'message' => 'CSV upload failed. Please pick the file again.',
            ]);
            $this->reset('masterCsvUpload');
            return;
        }

        try {
            $exitCode = Artisan::call('wholesale-custom:sync-master', [
                'csv' => $absolutePath,
                '--replace' => true,
            ]);

            if ($exitCode !== 0) {
                throw new \RuntimeException(trim(Artisan::output()) ?: 'Master CSV sync failed.');
            }

            $this->closeEdit();
            $this->showCreate = false;
            $this->resetPage();
            $this->dispatch('toast', [
                'type' => 'success',
                'message' => 'Wholesale custom master CSV synced. Canonical scents were not auto-created (wizard-governed).',
            ]);
        } catch (Throwable $e) {
            $this->dispatch('toast', [
                'type' => 'error',
                'message' => 'CSV sync failed: '.$e->getMessage(),
            ]);
        } finally {
            $this->reset('masterCsvUpload');
        }
    }

    protected function validateCreate(): array
    {
        return validator($this->create, [
            'account_name' => ['required', 'string', 'max:255'],
            'custom_scent_name' => ['required', 'string', 'max:255'],
            'oil_1' => ['nullable', 'string', 'max:255'],
            'oil_2' => ['nullable', 'string', 'max:255'],
            'oil_3' => ['nullable', 'string', 'max:255'],
            'total_oils' => ['nullable', 'integer', 'min:0', 'max:999'],
            'abbreviation' => ['nullable', 'string', 'max:50'],
            'canonical_scent_id' => ['nullable', 'exists:scents,id'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'active' => ['boolean'],
        ])->validate();
    }

    protected function validateEdit(): array
    {
        return validator($this->edit, [
            'account_name' => ['required', 'string', 'max:255'],
            'custom_scent_name' => ['required', 'string', 'max:255'],
            'oil_1' => ['nullable', 'string', 'max:255'],
            'oil_2' => ['nullable', 'string', 'max:255'],
            'oil_3' => ['nullable', 'string', 'max:255'],
            'total_oils' => ['nullable', 'integer', 'min:0', 'max:999'],
            'abbreviation' => ['nullable', 'string', 'max:50'],
            'canonical_scent_id' => ['nullable', 'exists:scents,id'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'active' => ['boolean'],
        ])->validate();
    }

    protected function assertUnique(string $account, string $custom, ?int $ignoreId = null): void
    {
        $accountNorm = WholesaleCustomScent::normalizeAccountName($account);
        $customNorm = WholesaleCustomScent::normalizeScentName($custom);

        $exists = WholesaleCustomScent::query()
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->get()
            ->first(function (WholesaleCustomScent $row) use ($accountNorm, $customNorm) {
                return WholesaleCustomScent::normalizeAccountName($row->account_name) === $accountNorm
                    && WholesaleCustomScent::normalizeScentName($row->custom_scent_name) === $customNorm;
            });

        if ($exists) {
            throw ValidationException::withMessages([
                'custom_scent_name' => 'A custom scent for this account already exists (case-insensitive).',
            ]);
        }
    }

    public function render()
    {
        $query = WholesaleCustomScent::query()->with([
            'canonicalScent',
            'canonicalScent.oilBlend.components.baseOil',
        ]);

        if ($this->search !== '') {
            $s = '%' . $this->search . '%';
            $query->where(function ($q) use ($s) {
                $q->where('account_name', 'like', $s)
                  ->orWhere('custom_scent_name', 'like', $s);
            });
        }

        if ($this->filter === 'mapped') {
            $query->whereNotNull('canonical_scent_id');
        } elseif ($this->filter === 'unmapped') {
            $query->whereNull('canonical_scent_id');
        }

        $records = $query->orderBy('account_name')
            ->orderBy('custom_scent_name')
            ->paginate($this->perPage);

        return view('livewire.admin.wholesale.custom-scents', [
            'records' => $records,
        ])->layout('layouts.app');
    }

    protected function withRecipePayload(array $data, ?int $ignoreId = null): array
    {
        $payload = [
            'account_name' => trim((string) ($data['account_name'] ?? '')),
            'custom_scent_name' => trim((string) ($data['custom_scent_name'] ?? '')),
            'oil_1' => blank($data['oil_1'] ?? null) ? null : trim((string) $data['oil_1']),
            'oil_2' => blank($data['oil_2'] ?? null) ? null : trim((string) $data['oil_2']),
            'oil_3' => blank($data['oil_3'] ?? null) ? null : trim((string) $data['oil_3']),
            'total_oils' => isset($data['total_oils']) && $data['total_oils'] !== '' ? (int) $data['total_oils'] : null,
            'abbreviation' => blank($data['abbreviation'] ?? null) ? null : trim((string) $data['abbreviation']),
            'canonical_scent_id' => $data['canonical_scent_id'] ?: null,
            'notes' => blank($data['notes'] ?? null) ? null : trim((string) $data['notes']),
            'active' => (bool) ($data['active'] ?? true),
        ];

        $resolver = app(NestedOilRecipeResolver::class);
        $topLevelComponents = $resolver->parseTopLevelComponents([
            (string) ($payload['oil_1'] ?? ''),
            (string) ($payload['oil_2'] ?? ''),
            (string) ($payload['oil_3'] ?? ''),
        ]);

        if ($topLevelComponents !== []) {
            $definitions = $this->recipeDefinitionMap($ignoreId, $resolver);
            $lookupKey = $resolver->lookupKey((string) $payload['custom_scent_name']);
            if ($lookupKey !== '') {
                $definitions[$lookupKey] = $topLevelComponents;
            }

            $resolved = $resolver->resolveToBaseOils($topLevelComponents, $definitions);

            $payload['top_level_recipe_json'] = [
                'version' => 1,
                'slots' => [
                    'oil_1' => $payload['oil_1'],
                    'oil_2' => $payload['oil_2'],
                    'oil_3' => $payload['oil_3'],
                ],
                'components' => array_map(fn (array $component): array => [
                    'name' => (string) ($component['name'] ?? ''),
                    'weight' => (float) ($component['weight'] ?? 0.0),
                ], $topLevelComponents),
            ];
            $payload['resolved_recipe_json'] = [
                'version' => 1,
                'components' => array_map(fn (array $component): array => [
                    'name' => (string) ($component['name'] ?? ''),
                    'weight' => (float) ($component['weight'] ?? 0.0),
                    'percent' => (float) ($component['percent'] ?? 0.0),
                ], $resolved['components'] ?? []),
                'warnings' => array_values(array_unique(array_map('strval', $resolved['errors'] ?? []))),
            ];

            if ($payload['total_oils'] === null) {
                $payload['total_oils'] = count($topLevelComponents);
            }
        } else {
            $payload['top_level_recipe_json'] = null;
            $payload['resolved_recipe_json'] = null;
        }

        return $payload;
    }

    /**
     * Prefer matching existing canonical scents before leaving this unmapped.
     *
     * @param  array<string,mixed>  $data
     */
    protected function resolveCanonicalScentId(array $data): ?int
    {
        $explicit = blank($data['canonical_scent_id'] ?? null) ? null : (int) $data['canonical_scent_id'];
        if ($explicit) {
            return $explicit;
        }

        $name = trim((string) ($data['custom_scent_name'] ?? ''));
        if ($name === '') {
            return null;
        }

        $context = [
            'store_key' => 'wholesale',
            'is_wholesale' => true,
            'account_name' => trim((string) ($data['account_name'] ?? '')),
        ];

        $resolver = app(ResolveScentMatchService::class);

        return $resolver->resolveSingleCandidateId($name, $context, 96)
            ?? $resolver->findExistingScent($name, $context)?->id;
    }

    /**
     * @return array<string,array<int,array{name:string,weight:float}>>
     */
    protected function recipeDefinitionMap(?int $ignoreId, NestedOilRecipeResolver $resolver): array
    {
        $definitions = [];

        WholesaleCustomScent::query()
            ->when($ignoreId, fn ($query) => $query->where('id', '!=', $ignoreId))
            ->get(['id', 'custom_scent_name', 'oil_1', 'oil_2', 'oil_3', 'top_level_recipe_json'])
            ->each(function (WholesaleCustomScent $record) use (&$definitions, $resolver): void {
                $key = $resolver->lookupKey((string) $record->custom_scent_name);
                if ($key === '' || isset($definitions[$key])) {
                    return;
                }

                $jsonComponents = $record->top_level_recipe_json['components'] ?? null;
                if (is_array($jsonComponents) && $jsonComponents !== []) {
                    $components = array_values(array_filter(array_map(function ($component): ?array {
                        if (! is_array($component)) {
                            return null;
                        }

                        $name = trim((string) ($component['name'] ?? ''));
                        $weight = (float) ($component['weight'] ?? 0.0);
                        if ($name === '' || $weight <= 0) {
                            return null;
                        }

                        return ['name' => $name, 'weight' => $weight];
                    }, $jsonComponents)));

                    if ($components !== []) {
                        $definitions[$key] = $components;
                        return;
                    }
                }

                $parsed = $resolver->parseTopLevelComponents([
                    (string) ($record->oil_1 ?? ''),
                    (string) ($record->oil_2 ?? ''),
                    (string) ($record->oil_3 ?? ''),
                ]);

                if ($parsed !== []) {
                    $definitions[$key] = $parsed;
                }
            });

        return $definitions;
    }
}

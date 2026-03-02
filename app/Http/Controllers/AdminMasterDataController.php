<?php

namespace App\Http\Controllers;

use App\Models\BaseOil;
use App\Models\Blend;
use App\Models\BlendComponent;
use App\Models\OilAbbreviation;
use App\Models\Scent;
use App\Models\ScentAlias;
use App\Models\Size;
use App\Models\Wick;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class AdminMasterDataController extends Controller
{
    public function index(Request $request): View
    {
        $resources = $this->availableResources();
        $activeResource = (string) $request->query('resource', array_key_first($resources) ?: 'scents');

        if (! array_key_exists($activeResource, $resources)) {
            $activeResource = (string) (array_key_first($resources) ?: 'scents');
        }

        return view('admin.master-data', [
            'resources' => array_values(array_map(
                fn (array $definition, string $key): array => [
                    'key' => $key,
                    'label' => (string) ($definition['label'] ?? $key),
                    'description' => (string) ($definition['description'] ?? ''),
                ],
                $resources,
                array_keys($resources)
            )),
            'activeResource' => $activeResource,
            'baseEndpoint' => url('/admin/master'),
        ]);
    }

    public function list(Request $request, string $resource): JsonResponse
    {
        $definition = $this->resourceDefinition($resource);
        $columns = $this->fieldKeys($definition);
        $perPage = max(10, min(100, (int) $request->integer('per_page', 25)));
        $search = $this->normalizeText((string) $request->query('search', ''));
        $active = $request->query('active');
        $sort = (string) $request->query('sort', (string) ($definition['default_sort'] ?? 'id'));
        $dir = strtolower((string) $request->query('dir', 'asc')) === 'desc' ? 'desc' : 'asc';
        $sortable = array_map('strval', (array) ($definition['sort'] ?? ['id']));

        if (! in_array($sort, $sortable, true)) {
            $sort = (string) ($definition['default_sort'] ?? 'id');
        }

        /** @var Builder $query */
        $query = $this->newQuery($definition)
            ->select(array_values(array_unique(array_merge(['id'], $columns))));

        $this->applySearch($query, $resource, $definition, $search);

        $activeField = $definition['active_field'] ?? null;
        if (is_string($activeField) && $active !== null && $active !== '') {
            $query->where($activeField, filter_var($active, FILTER_VALIDATE_BOOL));
        }

        $query->orderBy($sort, $dir)->orderBy('id');

        $paginator = $query->paginate($perPage)->withQueryString();
        $rows = collect($paginator->items())
            ->map(fn (Model $record): array => $this->serializeRecord($resource, $definition, $record))
            ->values()
            ->all();

        return response()->json([
            'data' => $rows,
            'meta' => [
                'resource' => $resource,
                'label' => (string) ($definition['label'] ?? $resource),
                'columns' => $this->columnMeta($definition),
                'pagination' => [
                    'page' => $paginator->currentPage(),
                    'per_page' => $paginator->perPage(),
                    'total' => $paginator->total(),
                    'last_page' => $paginator->lastPage(),
                ],
                'filters' => [
                    'search' => $search,
                    'active' => $active,
                    'sort' => $sort,
                    'dir' => $dir,
                ],
                'supports_active_filter' => $activeField !== null,
            ],
        ]);
    }

    public function store(Request $request, string $resource): JsonResponse
    {
        $definition = $this->resourceDefinition($resource);
        /** @var Model $model */
        $model = new $definition['model']();
        $defaults = array_replace(
            $this->defaultPayload($definition),
            $this->createDefaults($resource)
        );
        $incoming = $this->normalizePayload($definition, $request->all(), true);
        $payload = array_replace($defaults, $incoming);

        validator($payload, $this->rulesFor($resource, $definition, false))->validate();
        $model->fill($payload);
        $model->save();

        return response()->json([
            'data' => $this->serializeRecord($resource, $definition, $model->fresh()),
        ], 201);
    }

    public function update(Request $request, string $resource, int $record): JsonResponse
    {
        $definition = $this->resourceDefinition($resource);
        /** @var Model $model */
        $model = $this->newQuery($definition)->findOrFail($record);
        $payload = $this->normalizePayload($definition, $request->all(), true);

        if ($payload === []) {
            return response()->json([
                'data' => $this->serializeRecord($resource, $definition, $model),
            ]);
        }

        validator($payload, $this->rulesFor($resource, $definition, true, $model->getKey()))->validate();

        try {
            $model->fill($payload);
            $model->save();
        } catch (QueryException $e) {
            return response()->json([
                'message' => 'Could not save that row.',
                'detail' => $e->getMessage(),
            ], 422);
        }

        return response()->json([
            'data' => $this->serializeRecord($resource, $definition, $model->fresh()),
        ]);
    }

    public function destroy(string $resource, int $record): JsonResponse
    {
        $definition = $this->resourceDefinition($resource);
        /** @var Model $model */
        $model = $this->newQuery($definition)->findOrFail($record);

        try {
            $model->delete();
        } catch (QueryException $e) {
            return response()->json([
                'message' => 'Could not delete that row.',
                'detail' => $e->getMessage(),
            ], 422);
        }

        return response()->json(['deleted' => true]);
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    protected function availableResources(): array
    {
        return array_filter(
            $this->resourceDefinitions(),
            fn (array $definition): bool => $this->resourceIsAvailable($definition)
        );
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    protected function resourceDefinitions(): array
    {
        return [
            'scents' => [
                'label' => 'Scents',
                'description' => 'Canonical candle catalog and blend links.',
                'model' => Scent::class,
                'search' => ['name', 'display_name', 'abbreviation'],
                'sort' => ['name', 'display_name', 'sort_order', 'updated_at'],
                'default_sort' => 'name',
                'active_field' => 'is_active',
                'fields' => [
                    ['key' => 'name', 'label' => 'Name', 'type' => 'text', 'default' => '', 'rules' => ['required', 'string', 'max:255']],
                    ['key' => 'display_name', 'label' => 'Display', 'type' => 'text', 'default' => '', 'nullable' => true, 'rules' => ['nullable', 'string', 'max:255']],
                    ['key' => 'abbreviation', 'label' => 'Abbr', 'type' => 'text', 'default' => '', 'nullable' => true, 'rules' => ['nullable', 'string', 'max:64']],
                    ['key' => 'oil_reference_name', 'label' => 'Oil Ref', 'type' => 'text', 'default' => '', 'nullable' => true, 'rules' => ['nullable', 'string', 'max:255']],
                    ['key' => 'oil_blend_id', 'label' => 'Blend', 'type' => 'select', 'default' => null, 'nullable' => true, 'options' => 'blends', 'rules' => ['nullable', 'integer', 'exists:blends,id']],
                    ['key' => 'is_blend', 'label' => 'Is Blend', 'type' => 'checkbox', 'default' => false, 'rules' => ['boolean']],
                    ['key' => 'blend_oil_count', 'label' => 'Blend Oils', 'type' => 'number', 'default' => null, 'nullable' => true, 'rules' => ['nullable', 'integer', 'min:1']],
                    ['key' => 'is_wholesale_custom', 'label' => 'Wholesale', 'type' => 'checkbox', 'default' => false, 'rules' => ['boolean']],
                    ['key' => 'is_candle_club', 'label' => 'Candle Club', 'type' => 'checkbox', 'default' => false, 'rules' => ['boolean']],
                    ['key' => 'is_active', 'label' => 'Active', 'type' => 'checkbox', 'default' => true, 'rules' => ['boolean']],
                    ['key' => 'sort_order', 'label' => 'Sort', 'type' => 'number', 'default' => 0, 'rules' => ['integer', 'min:0']],
                ],
            ],
            'base-oils' => [
                'label' => 'Base Oils',
                'description' => 'Master oil inventory names and stock thresholds.',
                'model' => BaseOil::class,
                'search' => ['name', 'supplier'],
                'sort' => ['name', 'grams_on_hand', 'updated_at'],
                'default_sort' => 'name',
                'active_field' => 'active',
                'fields' => [
                    ['key' => 'name', 'label' => 'Name', 'type' => 'text', 'default' => '', 'rules' => ['required', 'string', 'max:255']],
                    ['key' => 'grams_on_hand', 'label' => 'On Hand', 'type' => 'number', 'default' => 0, 'rules' => ['numeric', 'min:0']],
                    ['key' => 'reorder_threshold', 'label' => 'Reorder At', 'type' => 'number', 'default' => 0, 'rules' => ['numeric', 'min:0']],
                    ['key' => 'jug_size_grams', 'label' => 'Jug Grams', 'type' => 'number', 'default' => 2263, 'rules' => ['numeric', 'min:0']],
                    ['key' => 'supplier', 'label' => 'Supplier', 'type' => 'text', 'default' => '', 'nullable' => true, 'rules' => ['nullable', 'string', 'max:255']],
                    ['key' => 'cost_per_jug', 'label' => 'Cost', 'type' => 'number', 'default' => null, 'nullable' => true, 'rules' => ['nullable', 'numeric', 'min:0']],
                    ['key' => 'active', 'label' => 'Active', 'type' => 'checkbox', 'default' => true, 'rules' => ['boolean']],
                ],
            ],
            'blends' => [
                'label' => 'Blends',
                'description' => 'Blend headers. Components are edited in the next tab.',
                'model' => Blend::class,
                'search' => ['name'],
                'sort' => ['name', 'updated_at'],
                'default_sort' => 'name',
                'fields' => [
                    ['key' => 'name', 'label' => 'Name', 'type' => 'text', 'default' => '', 'rules' => ['required', 'string', 'max:255']],
                    ['key' => 'is_blend', 'label' => 'Active Blend', 'type' => 'checkbox', 'default' => true, 'rules' => ['boolean']],
                ],
            ],
            'blend-components' => [
                'label' => 'Blend Components',
                'description' => 'Blend to base oil ratios.',
                'model' => BlendComponent::class,
                'sort' => ['blend_id', 'base_oil_id', 'ratio_weight', 'updated_at'],
                'default_sort' => 'blend_id',
                'fields' => [
                    ['key' => 'blend_id', 'label' => 'Blend', 'type' => 'select', 'default' => null, 'options' => 'blends', 'rules' => ['required', 'integer', 'exists:blends,id']],
                    ['key' => 'base_oil_id', 'label' => 'Base Oil', 'type' => 'select', 'default' => null, 'options' => 'base-oils', 'rules' => ['required', 'integer', 'exists:base_oils,id']],
                    ['key' => 'ratio_weight', 'label' => 'Ratio', 'type' => 'number', 'default' => 1, 'rules' => ['required', 'integer', 'min:1']],
                ],
            ],
            'oil-abbreviations' => [
                'label' => 'Oil Abbreviations',
                'description' => 'Short codes used in pour-room recipe references.',
                'model' => OilAbbreviation::class,
                'search' => ['name', 'abbreviation'],
                'sort' => ['name', 'abbreviation', 'updated_at'],
                'default_sort' => 'name',
                'active_field' => 'is_active',
                'fields' => [
                    ['key' => 'name', 'label' => 'Name', 'type' => 'text', 'default' => '', 'rules' => ['required', 'string', 'max:255']],
                    ['key' => 'abbreviation', 'label' => 'Abbr', 'type' => 'text', 'default' => '', 'nullable' => true, 'rules' => ['nullable', 'string', 'max:255']],
                    ['key' => 'is_active', 'label' => 'Active', 'type' => 'checkbox', 'default' => true, 'rules' => ['boolean']],
                ],
            ],
            'sizes' => [
                'label' => 'Sizes',
                'description' => 'Canonical jar and wax melt sizes.',
                'model' => Size::class,
                'search' => ['code', 'label'],
                'sort' => ['sort_order', 'code', 'updated_at'],
                'default_sort' => 'sort_order',
                'active_field' => 'is_active',
                'fields' => [
                    ['key' => 'code', 'label' => 'Code', 'type' => 'text', 'default' => '', 'rules' => ['required', 'string', 'max:255']],
                    ['key' => 'label', 'label' => 'Label', 'type' => 'text', 'default' => '', 'nullable' => true, 'rules' => ['nullable', 'string', 'max:255']],
                    ['key' => 'wholesale_price', 'label' => 'Wholesale', 'type' => 'number', 'default' => null, 'nullable' => true, 'rules' => ['nullable', 'numeric', 'min:0']],
                    ['key' => 'retail_price', 'label' => 'Retail', 'type' => 'number', 'default' => null, 'nullable' => true, 'rules' => ['nullable', 'numeric', 'min:0']],
                    ['key' => 'is_active', 'label' => 'Active', 'type' => 'checkbox', 'default' => true, 'rules' => ['boolean']],
                    ['key' => 'sort_order', 'label' => 'Sort', 'type' => 'number', 'default' => 0, 'rules' => ['integer', 'min:0']],
                ],
            ],
            'wicks' => [
                'label' => 'Wicks',
                'description' => 'Master wick types.',
                'model' => Wick::class,
                'search' => ['name'],
                'sort' => ['name', 'updated_at'],
                'default_sort' => 'name',
                'active_field' => 'is_active',
                'fields' => [
                    ['key' => 'name', 'label' => 'Name', 'type' => 'text', 'default' => '', 'rules' => ['required', 'string', 'max:255']],
                    ['key' => 'is_active', 'label' => 'Active', 'type' => 'checkbox', 'default' => true, 'rules' => ['boolean']],
                ],
            ],
            'scent-aliases' => [
                'label' => 'Scent Aliases',
                'description' => 'Optional admin-only label aliases for historical data.',
                'model' => ScentAlias::class,
                'search' => ['alias', 'scope'],
                'sort' => ['alias', 'scope', 'updated_at'],
                'default_sort' => 'alias',
                'fields' => [
                    ['key' => 'alias', 'label' => 'Alias', 'type' => 'text', 'default' => '', 'rules' => ['required', 'string', 'max:255', Rule::unique('scent_aliases', 'alias')->where(fn ($query) => $query->where('scope', request('scope', 'markets')))]],
                    ['key' => 'scent_id', 'label' => 'Scent', 'type' => 'select', 'default' => null, 'options' => 'scents', 'rules' => ['required', 'integer', 'exists:scents,id']],
                    ['key' => 'scope', 'label' => 'Scope', 'type' => 'text', 'default' => 'markets', 'rules' => ['required', 'string', 'max:255']],
                ],
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    protected function resourceDefinition(string $resource): array
    {
        $definitions = $this->availableResources();

        abort_unless(array_key_exists($resource, $definitions), 404);

        return $definitions[$resource];
    }

    /**
     * @param  array<string,mixed>  $definition
     */
    protected function resourceIsAvailable(array $definition): bool
    {
        $table = $definition['table'] ?? null;

        if (! is_string($table) || $table === '') {
            /** @var Model $model */
            $model = new $definition['model']();
            $table = $model->getTable();
        }

        return Schema::hasTable($table);
    }

    /**
     * @param  array<string,mixed>  $definition
     * @return array<int,string>
     */
    protected function fieldKeys(array $definition): array
    {
        return array_map(
            fn (array $field): string => (string) $field['key'],
            (array) ($definition['fields'] ?? [])
        );
    }

    /**
     * @param  array<string,mixed>  $definition
     */
    protected function newQuery(array $definition): Builder
    {
        /** @var class-string<Model> $modelClass */
        $modelClass = $definition['model'];

        return $modelClass::query();
    }

    protected function applySearch(Builder $query, string $resource, array $definition, string $search): void
    {
        if ($search === '') {
            return;
        }

        if ($resource === 'blend-components') {
            $query->where(function (Builder $searchQuery) use ($search): void {
                $searchQuery->whereHas('blend', fn (Builder $blendQuery) => $blendQuery->where('name', 'like', '%'.$search.'%'))
                    ->orWhereHas('baseOil', fn (Builder $oilQuery) => $oilQuery->where('name', 'like', '%'.$search.'%'));
            });

            return;
        }

        $fields = array_values(array_filter(array_map('strval', (array) ($definition['search'] ?? []))));
        if ($fields === []) {
            return;
        }

        $query->where(function (Builder $searchQuery) use ($fields, $search): void {
            foreach ($fields as $index => $field) {
                if ($index === 0) {
                    $searchQuery->where($field, 'like', '%'.$search.'%');
                    continue;
                }

                $searchQuery->orWhere($field, 'like', '%'.$search.'%');
            }
        });
    }

    /**
     * @param  array<string,mixed>  $definition
     * @return array<int,array<string,mixed>>
     */
    protected function columnMeta(array $definition): array
    {
        return array_map(function (array $field): array {
            $meta = Arr::only($field, ['key', 'label', 'type', 'nullable']);

            if (($field['type'] ?? null) === 'select' && is_string($field['options'] ?? null)) {
                $meta['options'] = $this->selectOptions((string) $field['options']);
            }

            return $meta;
        }, (array) ($definition['fields'] ?? []));
    }

    /**
     * @param  array<string,mixed>  $definition
     */
    protected function defaultPayload(array $definition): array
    {
        $payload = [];

        foreach ((array) ($definition['fields'] ?? []) as $field) {
            $payload[(string) $field['key']] = $field['default'] ?? null;
        }

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    protected function createDefaults(string $resource): array
    {
        return match ($resource) {
            'scents' => [
                'name' => $this->nextUniqueValue(Scent::class, 'name', 'New Scent'),
                'display_name' => $this->nextUniqueValue(Scent::class, 'display_name', 'New Scent'),
            ],
            'base-oils' => [
                'name' => $this->nextUniqueValue(BaseOil::class, 'name', 'New Base Oil'),
            ],
            'blends' => [
                'name' => $this->nextUniqueValue(Blend::class, 'name', 'New Blend'),
            ],
            'blend-components' => [
                'blend_id' => Blend::query()->orderBy('name')->value('id'),
                'base_oil_id' => BaseOil::query()->orderBy('name')->value('id'),
                'ratio_weight' => 1,
            ],
            'oil-abbreviations' => [
                'name' => $this->nextUniqueValue(OilAbbreviation::class, 'name', 'New Oil Reference'),
            ],
            'sizes' => [
                'code' => $this->nextUniqueValue(Size::class, 'code', 'NEW-SIZE'),
                'label' => 'New Size',
            ],
            'wicks' => [
                'name' => $this->nextUniqueValue(Wick::class, 'name', 'New Wick'),
            ],
            'scent-aliases' => [
                'alias' => $this->nextUniqueValue(ScentAlias::class, 'alias', 'new alias'),
                'scent_id' => Scent::query()->orderByRaw('COALESCE(display_name, name)')->value('id'),
                'scope' => 'markets',
            ],
            default => [],
        };
    }

    /**
     * @param  array<string,mixed>  $definition
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    protected function normalizePayload(array $definition, array $input, bool $partial): array
    {
        $normalized = [];

        foreach ((array) ($definition['fields'] ?? []) as $field) {
            $key = (string) $field['key'];
            if ($partial && ! array_key_exists($key, $input)) {
                continue;
            }

            $value = $input[$key] ?? null;
            $type = (string) ($field['type'] ?? 'text');
            $nullable = (bool) ($field['nullable'] ?? false);

            if ($type === 'checkbox') {
                $normalized[$key] = filter_var($value, FILTER_VALIDATE_BOOL);
                continue;
            }

            if ($type === 'number') {
                if ($value === '' || $value === null) {
                    $normalized[$key] = $nullable ? null : ($field['default'] ?? 0);
                    continue;
                }

                $numeric = is_numeric($value) ? $value + 0 : $value;
                $normalized[$key] = $numeric;
                continue;
            }

            if ($type === 'select') {
                if ($value === '' || $value === null) {
                    $normalized[$key] = $nullable ? null : null;
                    continue;
                }

                $normalized[$key] = (int) $value;
                continue;
            }

            $text = $this->normalizeText((string) $value);
            $normalized[$key] = $text === '' && $nullable ? null : $text;
        }

        return $normalized;
    }

    /**
     * @param  array<string,mixed>  $definition
     * @return array<string,mixed>
     */
    protected function rulesFor(string $resource, array $definition, bool $partial, ?int $recordId = null): array
    {
        $rules = [];

        foreach ((array) ($definition['fields'] ?? []) as $field) {
            $key = (string) $field['key'];
            $fieldRules = (array) ($field['rules'] ?? []);

            if (in_array($resource, ['scents', 'base-oils', 'blends', 'sizes', 'wicks'], true) && $key === 'name') {
                $table = $resource === 'base-oils' ? 'base_oils' : ($resource === 'wicks' ? 'wicks' : $resource);
                $fieldRules[] = Rule::unique($table, 'name')->ignore($recordId);
            }

            if ($resource === 'sizes' && $key === 'code') {
                $fieldRules[] = Rule::unique('sizes', 'code')->ignore($recordId);
            }

            if ($resource === 'scent-aliases' && $key === 'alias') {
                $fieldRules = [
                    'required',
                    'string',
                    'max:255',
                    Rule::unique('scent_aliases', 'alias')
                        ->where(fn ($query) => $query->where('scope', request()->input('scope', 'markets')))
                        ->ignore($recordId),
                ];
            }

            if ($partial) {
                array_unshift($fieldRules, 'sometimes');
            }

            $rules[$key] = $fieldRules;
        }

        return $rules;
    }

    /**
     * @param  array<string,mixed>  $definition
     */
    protected function serializeRecord(string $resource, array $definition, Model $record): array
    {
        $row = ['id' => (int) $record->getKey()];

        foreach ($this->fieldKeys($definition) as $field) {
            $row[$field] = $record->getAttribute($field);
        }

        if ($resource === 'blend-components') {
            $record->loadMissing(['blend:id,name', 'baseOil:id,name']);
            $row['blend_label'] = (string) ($record->blend?->name ?? '');
            $row['base_oil_label'] = (string) ($record->baseOil?->name ?? '');
        }

        if ($resource === 'scent-aliases') {
            $record->loadMissing(['scent:id,name,display_name']);
            $row['scent_label'] = (string) ($record->scent?->display_name ?: $record->scent?->name ?: '');
        }

        return $row;
    }

    /**
     * @return array<int,array{value:int|string,label:string}>
     */
    protected function selectOptions(string $key): array
    {
        return match ($key) {
            'blends' => Blend::query()
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (Blend $blend): array => ['value' => (int) $blend->id, 'label' => (string) $blend->name])
                ->all(),
            'base-oils' => BaseOil::query()
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (BaseOil $oil): array => ['value' => (int) $oil->id, 'label' => (string) $oil->name])
                ->all(),
            'scents' => Scent::query()
                ->orderByRaw('COALESCE(display_name, name)')
                ->get(['id', 'name', 'display_name'])
                ->map(fn (Scent $scent): array => ['value' => (int) $scent->id, 'label' => (string) ($scent->display_name ?: $scent->name)])
                ->all(),
            default => [],
        };
    }

    protected function normalizeText(string $value): string
    {
        $value = trim($value);
        $value = preg_replace('/\s+/', ' ', $value);

        return is_string($value) ? $value : '';
    }

    /**
     * @param  class-string<Model>  $modelClass
     */
    protected function nextUniqueValue(string $modelClass, string $column, string $seed): string
    {
        $value = $seed;
        $suffix = 2;

        while ($modelClass::query()->where($column, $value)->exists()) {
            $value = "{$seed} {$suffix}";
            $suffix++;
        }

        return $value;
    }
}

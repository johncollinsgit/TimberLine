<?php

namespace App\Livewire\Admin;

use App\Models\Blend;
use App\Models\Scent;
use App\Models\ScentAlias;
use App\Models\WholesaleCustomScent;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

class ScentWizard extends Component
{
    public array $form = [
        'name' => '',
        'display_name' => '',
        'abbreviation' => '',
        'oil_reference_name' => '',
        'is_blend' => false,
        'oil_blend_id' => null,
        'blend_oil_count' => null,
        'is_wholesale_custom' => false,
        'is_candle_club' => false,
        'is_active' => true,
        'create_alias' => true,
    ];

    public array $context = [
        'raw_name' => '',
        'raw_variant' => '',
        'account_name' => '',
        'store_key' => '',
    ];

    public string $returnTo = '';

    public function mount(): void
    {
        $raw = trim((string) request()->query('raw', request()->query('name', '')));
        $variant = trim((string) request()->query('variant', ''));
        $account = trim((string) request()->query('account', ''));
        $storeKey = trim((string) request()->query('store', ''));

        $cleanName = $this->cleanName($raw);
        $this->form['name'] = $cleanName;
        $this->form['display_name'] = $cleanName;
        $this->form['is_wholesale_custom'] = $account !== '' || $storeKey === 'wholesale';

        $this->context = [
            'raw_name' => $raw,
            'raw_variant' => $variant,
            'account_name' => $account,
            'store_key' => $storeKey,
        ];

        $fallbackReturn = route('admin.index', ['tab' => 'master-data', 'resource' => 'scents']);
        $requestedReturn = (string) request()->query('return_to', $fallbackReturn);
        $this->returnTo = str_starts_with($requestedReturn, '/')
            ? url($requestedReturn)
            : ($requestedReturn !== '' ? $requestedReturn : $fallbackReturn);
    }

    public function save(): void
    {
        $payload = $this->validatedPayload();
        $normalizedName = Scent::normalizeName((string) $payload['name']);

        $existingByName = Scent::query()
            ->whereRaw('lower(name) = ?', [mb_strtolower($normalizedName)])
            ->first();

        if ($existingByName) {
            throw ValidationException::withMessages([
                'form.name' => 'A scent with this name already exists. Open it in Master Data instead.',
            ]);
        }

        $abbreviation = trim((string) ($payload['abbreviation'] ?? ''));
        if ($abbreviation !== '') {
            $duplicateAbbrev = Scent::query()
                ->whereRaw('lower(abbreviation) = ?', [mb_strtolower($abbreviation)])
                ->exists();
            if ($duplicateAbbrev) {
                throw ValidationException::withMessages([
                    'form.abbreviation' => "Abbrev '{$abbreviation}' is already used by another scent.",
                ]);
            }
        }

        $blendOilCount = null;
        if ((bool) ($payload['is_blend'] ?? false)) {
            if (! empty($payload['oil_blend_id'])) {
                $blendOilCount = Blend::query()->find((int) $payload['oil_blend_id'])?->components()->count();
            }
            $blendOilCount ??= blank($payload['blend_oil_count'] ?? null) ? null : (int) $payload['blend_oil_count'];
        }

        $scent = Scent::query()->create([
            'name' => $normalizedName,
            'display_name' => blank($payload['display_name'] ?? null) ? null : trim((string) $payload['display_name']),
            'abbreviation' => $abbreviation !== '' ? $abbreviation : null,
            'oil_reference_name' => blank($payload['oil_reference_name'] ?? null) ? null : trim((string) $payload['oil_reference_name']),
            'is_blend' => (bool) ($payload['is_blend'] ?? false),
            'oil_blend_id' => blank($payload['oil_blend_id'] ?? null) ? null : (int) $payload['oil_blend_id'],
            'blend_oil_count' => $blendOilCount,
            'is_wholesale_custom' => (bool) ($payload['is_wholesale_custom'] ?? false),
            'is_candle_club' => (bool) ($payload['is_candle_club'] ?? false),
            'is_active' => (bool) ($payload['is_active'] ?? true),
        ]);

        $this->syncOptionalAlias($scent);
        $this->syncOptionalWholesaleMapping($scent);

        $this->dispatch('toast', [
            'type' => 'success',
            'message' => 'New scent created. You can now map intake rows to it.',
        ]);

        $this->redirect($this->returnTo, navigate: true);
    }

    protected function validatedPayload(): array
    {
        $rules = [
            'form.name' => ['required', 'string', 'max:255'],
            'form.display_name' => ['nullable', 'string', 'max:255'],
            'form.abbreviation' => ['nullable', 'string', 'max:64'],
            'form.oil_reference_name' => ['nullable', 'string', 'max:255'],
            'form.is_blend' => ['boolean'],
            'form.oil_blend_id' => [
                Rule::excludeIf(! (bool) ($this->form['is_blend'] ?? false)),
                'nullable',
                'integer',
                'exists:blends,id',
            ],
            'form.blend_oil_count' => [
                Rule::excludeIf(! (bool) ($this->form['is_blend'] ?? false)),
                'nullable',
                'integer',
                'min:1',
            ],
            'form.is_wholesale_custom' => ['boolean'],
            'form.is_candle_club' => ['boolean'],
            'form.is_active' => ['boolean'],
            'form.create_alias' => ['boolean'],
        ];

        return validator(['form' => $this->form], $rules)->validate()['form'];
    }

    protected function syncOptionalAlias(Scent $scent): void
    {
        if (! Schema::hasTable('scent_aliases')) {
            return;
        }

        if (! (bool) ($this->form['create_alias'] ?? false)) {
            return;
        }

        $alias = trim((string) ($this->context['raw_name'] ?? ''));
        if ($alias === '') {
            return;
        }

        $canonicalValues = [
            trim((string) ($scent->name ?? '')),
            trim((string) ($scent->display_name ?? '')),
        ];
        if (in_array($alias, $canonicalValues, true)) {
            return;
        }

        $scopes = ['markets'];
        $storeKey = trim((string) ($this->context['store_key'] ?? ''));
        $accountName = trim((string) ($this->context['account_name'] ?? ''));
        if ($storeKey === 'wholesale' || $accountName !== '') {
            $scopes[] = 'wholesale';
            $scopes[] = 'order_type:wholesale';
            if ($accountName !== '') {
                $scopes[] = 'account:'.WholesaleCustomScent::normalizeAccountName($accountName);
            }
        }

        foreach (array_values(array_unique($scopes)) as $scope) {
            ScentAlias::query()->updateOrCreate(
                ['alias' => $alias, 'scope' => $scope],
                ['scent_id' => $scent->id]
            );
        }
    }

    protected function syncOptionalWholesaleMapping(Scent $scent): void
    {
        if (! Schema::hasTable('wholesale_custom_scents')) {
            return;
        }

        $accountName = trim((string) ($this->context['account_name'] ?? ''));
        $customScentName = trim((string) ($this->context['raw_name'] ?? ''));

        if ($accountName === '' || $customScentName === '') {
            return;
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
        return view('livewire.admin.scent-wizard', [
            'blends' => Blend::query()->orderBy('name')->get(['id', 'name']),
        ])->layout('layouts.app');
    }
}


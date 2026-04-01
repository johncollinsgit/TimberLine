<?php

namespace App\Http\Requests\Landlord;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class UpdateTenantModuleEntitlementRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null && Gate::forUser($user)->allows('manage-landlord-commercial');
    }

    /**
     * @return array<string,mixed>
     */
    public function rules(): array
    {
        $catalogKeys = array_keys((array) config('module_catalog.modules', []));

        return [
            'module_key' => ['required', 'string', 'max:120', Rule::in($catalogKeys)],
            'availability_status' => ['required', 'string', 'in:available,requested,unavailable,disabled'],
            'enabled_status' => ['required', 'string', 'in:inherit,enabled,disabled'],
            'billing_status' => ['nullable', 'string', 'in:included_in_plan,add_on_paid,add_on_comped,custom_contract,trial,unavailable,pending_billing'],
            'price_override_cents' => ['nullable', 'integer', 'min:0'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'module_key' => strtolower(trim((string) ($this->route('moduleKey') ?? $this->input('module_key')))),
        ]);
    }
}

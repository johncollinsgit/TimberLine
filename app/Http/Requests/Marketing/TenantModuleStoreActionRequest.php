<?php

namespace App\Http\Requests\Marketing;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class TenantModuleStoreActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $tenantId = $this->attributes->get('current_tenant_id');

        return $user !== null
            && is_numeric($tenantId)
            && Gate::forUser($user)->allows('mutate-tenant-module-store', (int) $tenantId);
    }

    /**
     * @return array<string,mixed>
     */
    public function rules(): array
    {
        return [
            'moduleKey' => ['nullable', 'string', 'max:120'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'moduleKey' => strtolower(trim((string) $this->route('moduleKey'))),
        ]);
    }
}

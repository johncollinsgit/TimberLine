<?php

namespace App\Services\Forms;

use App\Models\FormTemplate;
use App\Models\Tenant;
use App\Models\TenantForm;
use RuntimeException;

class TenantFormProvisioningService
{
    public function ensureTemplate(string $templateKey): FormTemplate
    {
        $definition = $this->templateDefinition($templateKey);

        return FormTemplate::query()->updateOrCreate(
            ['key' => $templateKey],
            [
                'name' => (string) ($definition['name'] ?? $templateKey),
                'description' => $this->nullableString($definition['description'] ?? null),
                'status' => (string) ($definition['status'] ?? 'draft'),
                'visibility' => (string) ($definition['visibility'] ?? 'internal'),
                'handler_key' => $this->nullableString($definition['handler_key'] ?? null),
                'schema' => is_array($definition['schema'] ?? null) ? $definition['schema'] : null,
                'settings' => is_array($definition['settings'] ?? null) ? $definition['settings'] : null,
            ]
        );
    }

    public function ensureTenantForm(int|Tenant $tenant, string $templateKey, array $overrides = []): TenantForm
    {
        $tenantModel = $tenant instanceof Tenant ? $tenant : Tenant::query()->findOrFail($tenant);
        $template = $this->ensureTemplate($templateKey);
        $definition = $this->templateDefinition($templateKey);
        $defaultForm = is_array($definition['default_form'] ?? null) ? $definition['default_form'] : [];
        $slug = $this->nullableString($overrides['slug'] ?? $defaultForm['slug'] ?? null);

        if ($slug === null) {
            throw new RuntimeException("Tenant form template '{$templateKey}' is missing a default slug.");
        }

        return TenantForm::query()->updateOrCreate(
            [
                'tenant_id' => (int) $tenantModel->id,
                'slug' => $slug,
            ],
            [
                'form_template_id' => (int) $template->id,
                'name' => (string) ($overrides['name'] ?? $defaultForm['name'] ?? $template->name),
                'description' => $this->nullableString($overrides['description'] ?? $defaultForm['description'] ?? $template->description),
                'status' => (string) ($overrides['status'] ?? $defaultForm['status'] ?? 'draft'),
                'channel' => (string) ($overrides['channel'] ?? $defaultForm['channel'] ?? 'backstage'),
                'schema' => is_array($overrides['schema'] ?? null)
                    ? $overrides['schema']
                    : (is_array($template->schema) ? $template->schema : null),
                'destination' => is_array($overrides['destination'] ?? null)
                    ? $overrides['destination']
                    : (is_array($defaultForm['destination'] ?? null) ? $defaultForm['destination'] : null),
                'settings' => is_array($overrides['settings'] ?? null)
                    ? $overrides['settings']
                    : (is_array($defaultForm['settings'] ?? null) ? $defaultForm['settings'] : null),
            ]
        );
    }

    public function ensureWholesaleApplicationFormForTenant(int|Tenant $tenant): TenantForm
    {
        return $this->ensureTenantForm($tenant, 'wholesale_application');
    }

    /**
     * @return array<string,mixed>
     */
    protected function templateDefinition(string $templateKey): array
    {
        $definition = config('forms.templates.' . $templateKey);
        if (! is_array($definition)) {
            throw new RuntimeException("Unknown form template '{$templateKey}'.");
        }

        return $definition;
    }

    protected function nullableString(mixed $value): ?string
    {
        $string = trim((string) $value);

        return $string !== '' ? $string : null;
    }
}

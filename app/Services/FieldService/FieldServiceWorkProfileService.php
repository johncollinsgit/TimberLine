<?php

namespace App\Services\FieldService;

use App\Models\Tenant;

class FieldServiceWorkProfileService
{
    /** @return array<string,mixed> */
    public function forTenant(Tenant $tenant): array
    {
        $tenant->loadMissing('accessProfile');
        $metadata = (array) ($tenant->accessProfile?->metadata ?? []);
        $template = strtolower(trim((string) (
            data_get($metadata, 'tenant_blueprint.business_template')
            ?? data_get($metadata, 'tenant_blueprint.template_key')
            ?? data_get($metadata, 'business_template')
            ?? 'generic'
        )));
        $key = match ($template) {
            'electrician', 'landscaping', 'field_service', 'contractor', 'trades' => 'trades',
            'law', 'professional_services', 'generic_project' => 'professional',
            'apparel', 'candle_maker', 'shopify_retail', 'retail' => 'retail_production',
            default => 'generic',
        };
        $definition = (array) config('tenant_blueprints.templates.'.$template, config('tenant_blueprints.templates.generic', []));

        return [
            'key' => $key,
            'template' => $template,
            'experience_version' => $this->experienceVersion($tenant),
            'default_view' => $key === 'trades' ? 'calendar' : 'list',
            'labels' => [
                'work' => (string) ($definition['work_label'] ?? 'Work'),
                'item' => (string) ($definition['project_label'] ?? 'Project'),
                'task' => (string) ($definition['task_label'] ?? 'Task'),
                'assignee' => (string) ($definition['assignee_label'] ?? 'Assignee'),
                'update' => (string) ($definition['communication_label'] ?? 'Update'),
                'asset' => (string) ($definition['upload_label'] ?? 'Files / Photos'),
                'material' => (string) ($definition['material_label'] ?? 'Materials / Resources'),
            ],
            'capabilities' => [
                'calendar' => $key === 'trades',
                'participants' => (bool) ($definition['wants_user_assignments'] ?? false),
                'team_updates' => (bool) ($definition['wants_team_communication'] ?? false),
                'files' => (bool) ($definition['wants_file_uploads'] ?? false),
                'photos' => (bool) ($definition['wants_photo_uploads'] ?? false),
                'field_capture' => (bool) ($definition['wants_mobile_field_capture'] ?? false),
            ],
        ];
    }

    private function experienceVersion(Tenant $tenant): int
    {
        $metadata = (array) ($tenant->moduleEntitlements()->where('module_key', 'field_service')->value('metadata') ?? []);

        return max(1, (int) data_get($metadata, 'experience_version', 1));
    }
}

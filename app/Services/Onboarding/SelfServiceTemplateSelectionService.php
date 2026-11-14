<?php

namespace App\Services\Onboarding;

use Illuminate\Validation\ValidationException;

/** Customer-facing business presets; these never grant access or start billing. */
class SelfServiceTemplateSelectionService
{
    public const SESSION_KEY = 'everbranch.business_template';

    public function __construct(private readonly BranchSelectionService $selection) {}

    /** @return array<int,array{key:string,label:string,description:string,image:string,branches:array,blueprint_template:string}> */
    public function templates(): array
    {
        $rows = [];
        foreach ((array) config('self_service.templates', []) as $key => $template) {
            if (! is_array($template) || ! is_array(config('tenant_blueprints.templates.'.($template['blueprint_template'] ?? '')))) {
                continue;
            }
            $rows[] = [
                'key' => (string) $key, 'label' => (string) $template['label'],
                'description' => (string) $template['description'], 'image' => (string) $template['image'],
                'branches' => $this->selection->normalize((array) ($template['branches'] ?? []), false),
                'blueprint_template' => (string) $template['blueprint_template'],
            ];
        }

        return $rows;
    }

    public function normalize(?string $key): ?string
    {
        $key = trim((string) $key);
        if ($key === '') {
            return null;
        }
        if (! in_array($key, array_column($this->templates(), 'key'), true)) {
            throw ValidationException::withMessages(['template' => 'Choose a business template from the current options.']);
        }

        return $key;
    }

    public function branches(string $key): array
    {
        $key = $this->normalize($key);
        if ($key === null) {
            return [];
        }

        return (array) (collect($this->templates())->firstWhere('key', $key)['branches'] ?? []);
    }
}

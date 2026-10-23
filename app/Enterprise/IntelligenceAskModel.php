<?php

namespace App\Enterprise;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Str;

/** Live-capable selection only; server facts and source links remain authoritative. */
class IntelligenceAskModel extends AskModel
{
    public function __construct(private Intelligence $intelligence) {}

    public function select(array $context, string $question): ?array
    {
        $tenant = Tenant::query()->findOrFail($context['tenant_id']);
        if (! $this->intelligence->status($tenant)['ready']) {
            return null;
        }
        $user = User::query()->findOrFail($context['identity']['id']);
        $requestId = request()->input('request_id');
        $result = $this->intelligence->generate($tenant, $user, $context['department']['id'], 'ask',
            ['identity' => $context['identity'], 'department' => $context['department'], 'sources' => $context['sources'], 'question' => $question],
            is_string($requestId) && Str::isUuid($requestId) ? $requestId : (string) Str::uuid());
        $value = $result['value'];
        abort_unless(array_keys($value) === ['refs'] && is_array($value['refs']) && count($value['refs']) <= 30, 503, 'The model did not return valid source references.');
        foreach ($value['refs'] as $ref) {
            abort_unless(is_string($ref) && in_array($ref, array_column($context['sources'], 'ref'), true), 503, 'The model returned an unsupported source.');
        }

        return array_values(array_unique($value['refs']));
    }
}

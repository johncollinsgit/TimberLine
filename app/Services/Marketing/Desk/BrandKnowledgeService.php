<?php

namespace App\Services\Marketing\Desk;

use App\Models\MarketingBrandKnowledgeVersion;
use App\Models\User;
use Illuminate\Support\Facades\Validator;

class BrandKnowledgeService
{
    public function create(int $tenantId, User $actor, array $input): MarketingBrandKnowledgeVersion
    {
        app(MarketingDeskAccess::class)->ensure($tenantId, $actor);
        $data = Validator::make($input, [
            'title' => 'required|string|max:160', 'audience' => 'required|in:general,retail,wholesale,services',
            'values' => 'required|string|max:3000', 'voice' => 'required|string|max:2000',
            'facts' => 'required|string|max:8000', 'expires_at' => 'required|date|after:now',
            'faqs' => 'nullable|array|max:30', 'faqs.*.question' => 'required|string|max:300', 'faqs.*.answer' => 'required|string|max:1500',
        ])->validate();

        return MarketingBrandKnowledgeVersion::create([
            'tenant_id' => $tenantId, 'title' => $data['title'], 'audience' => $data['audience'],
            'content' => array_intersect_key($data, array_flip(['values', 'voice', 'facts', 'faqs'])), 'expires_at' => $data['expires_at'],
        ]);
    }

    public function approved(int $tenantId, int $id, ?string $audience = null): MarketingBrandKnowledgeVersion
    {
        return MarketingBrandKnowledgeVersion::forTenantId($tenantId)->whereNotNull('approved_at')
            ->where('expires_at', '>', now())->when($audience, fn ($q) => $q->whereIn('audience', ['general', $audience]))->findOrFail($id);
    }

    public function approve(int $tenantId, int $id, User $actor): MarketingBrandKnowledgeVersion
    {
        app(MarketingDeskAccess::class)->ensure($tenantId, $actor);
        $knowledge = MarketingBrandKnowledgeVersion::forTenantId($tenantId)->where('expires_at', '>', now())->findOrFail($id);
        if (! $knowledge->approved_at) {
            $knowledge->forceFill(['approved_by' => $actor->id, 'approved_at' => now()])->save();
        }

        return $knowledge;
    }
}

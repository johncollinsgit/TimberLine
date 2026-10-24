<?php

namespace App\Proposals;

use App\Models\Tenant;
use Illuminate\Support\Facades\Validator;

class DraftIntake
{
    /** Provider/voice output is data only. It can reference existing scoped prices;
     * it cannot choose prices, recipients, publication, sending or payment. */
    public function create(Tenant $tenant, int $actor, string $source, array $suggestion): Draft
    {
        Validator::make(compact('source', 'suggestion'), [
            'source' => ['required', 'in:ai_draft,voice_draft'],
            'suggestion' => ['required', 'array:client_id,title,intro,catalog_ids,template_id,scope_notes,approach_key,reviewer_names,usage_plan'],
        ])->validate();

        return app(ProposalService::class)->saveDraft($tenant, $actor, [...$suggestion, 'source' => $source]);
    }
}

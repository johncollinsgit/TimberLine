<?php

namespace App\Services\Bud;

class BudCapabilityRegistry
{
    /** @return array<string,array{label:string,questions:array<int,string>,actions:array<int,string>}> */
    public function all(): array
    {
        return [
            'customer_loop' => ['label' => 'Customer Loop', 'questions' => ['what needs attention', 'what should we follow up on', 'what happened today'], 'actions' => ['prepare a follow-up draft', 'create a review request draft', 'create a social draft']],
            'workflows' => ['label' => 'Workflow Automations', 'questions' => ['how do I automate this', 'what template should I use'], 'actions' => ['open a matching template', 'explain trigger and if/then logic']],
            'marketing' => ['label' => 'Marketing', 'questions' => ['what should we send', 'how did a campaign do'], 'actions' => ['prepare an email or text draft', 'suggest a campaign']],
            'website' => ['label' => 'Website', 'questions' => ['where are website leads', 'what is on our site'], 'actions' => ['open the Website workspace', 'explain a safe publishing step']],
            'bud_ai' => ['label' => 'Bud AI', 'questions' => ['can you write this', 'can I talk to Bud'], 'actions' => ['prepare a metered AI draft when the owner enables Bud AI']],
        ];
    }
}

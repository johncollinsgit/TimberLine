<?php

namespace App\Services\Retail;

use App\Services\Billing\StandardUsagePricing;

class RetailUsagePricing
{
    /** New-customer reference only; selection never activates a service or rewrites legacy terms. */
    public function presentation(): array
    {
        return [...app(StandardUsagePricing::class)->presentation(),
            'team_usage_example' => app(StandardUsagePricing::class)->teamUsageExample(),
            'future_usage' => [
                ['key' => 'memory', 'label' => 'An assistant that gets to know you', 'status' => 'Planned release · long-term memory', 'note' => 'Return to previous plans, approved preferences and business decisions across conversations. Personal teammate memory stays separate from shared business knowledge, with review, correction and deletion controls. Memory is included as a Standard feature when released, with no extra access fee. Retrieval, summarization and model usage draw from the shared balance at 2× actual provider cost. No release date is promised.'],
                ['label' => 'Phone voice connection and calls', 'status' => 'Planned release · provider stack pending', 'note' => 'No additional Standard feature access fee. Voice usage will be 2× combined actual provider cost, including the selected model, voice and telephony components. Final all-in rates and any separately scoped fixed connection costs require review before activation.'],
                ['label' => 'Customer-facing AI conversations', 'status' => 'Buildout and scope quote required', 'note' => 'OpenAI usage is priced at 2× actual API cost. Approved catalog/customer access, provider setup, limits and any fixed connection fees must be agreed before launch.'],
                ['label' => 'Choose your voice model', 'status' => 'Planned for the eventual voice buildout', 'note' => 'Owners approve the available models and budgets. Team members can choose a lower-cost or more capable approved model, see an estimate first and itemized actual usage afterward. The current tablet demo retains its verified model.'],
                ['label' => 'Other future connections', 'status' => 'Pricing to be determined', 'note' => 'Connection, subscription or usage fees need a written quote and approval before activation. Unknown fees are not included at $0.'],
            ],
            'base_note' => 'Future Standard customers use one prepaid Everbranch service balance for email, text and AI, with future voice metered when available. There is no separate monthly email/text sending-access subscription and no text setup fee. Existing customer contracts and allowances stay unchanged.',
            'ai_note' => 'OpenAI usage costs 2× actual API cost. Estimates vary with the model, input/output, conversation history and tools. The future service will reserve estimated spend, enforce the available balance and owner-approved caps, and reconcile actual cost. Tablet demo voice is limited to five minutes per conversation and six starts per hour; it does not activate a paid service.',
        ];
    }
}

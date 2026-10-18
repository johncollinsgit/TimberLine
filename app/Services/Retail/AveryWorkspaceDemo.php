<?php

namespace App\Services\Retail;

/** Public fictional fixtures only. This service never queries a tenant/customer table. */
class AveryWorkspaceDemo
{
    public function snapshot(?string $customer = null, string $view = 'workspace'): array
    {
        $data = json_decode(file_get_contents(resource_path('data/avery-workspace-demo.json')), true, 512, JSON_THROW_ON_ERROR);
        $data['view'] = in_array($view, ['storefront', 'workspace', 'customers', 'likely_to_order', 'production', 'inventory', 'orders', 'shipping', 'agreement', 'products', 'invoices', 'new_customer', 'new_invoice', 'marketing'], true) ? $view : 'workspace';
        $data['focused_customer'] = collect($data['customers'])->firstWhere('id', $customer);
        $data['suggested_follow_ups'] = collect($data['customers'])->sortByDesc('score')->take(10)->values()->all();
        $data['ranking_explanation'] = 'Fictional follow-up suggestions from explicit restock interest, upcoming gift occasions, saved handle preferences and example purchase timing. Scores are heuristics, never calibrated probabilities. Permission to contact is separate and no message is sent.';

        return $data;
    }

    public function tools(): array
    {
        return [
            ['type' => 'function', 'name' => 'show_demo_view', 'description' => 'Display a fictional screen for the currently selected demo business. Use likely_to_order to show ten customers likely to order soon, with the recorded reasons and contact-permission status. Opening a screen does not save, send or charge anything.',
                'parameters' => ['type' => 'object', 'additionalProperties' => false, 'properties' => ['view' => ['type' => 'string', 'enum' => ['storefront', 'workspace', 'customers', 'likely_to_order', 'production', 'inventory', 'orders', 'shipping', 'agreement', 'products', 'invoices', 'new_customer', 'new_invoice', 'marketing']]], 'required' => ['view']]],
            ['type' => 'function', 'name' => 'open_demo_customer', 'description' => 'Open one fictional customer in the selected business by exact name or ID from the supplied records. Never open real customer data.',
                'parameters' => ['type' => 'object', 'additionalProperties' => false, 'properties' => ['customer' => ['type' => 'string']], 'required' => ['customer']]],
            ['type' => 'function', 'name' => 'get_demo_context', 'description' => 'Read the current fictional demo screen, focused customer, purchase history and suggested follow-ups. No provider, CRM or real customer access.',
                'parameters' => ['type' => 'object', 'additionalProperties' => false, 'properties' => new \stdClass, 'required' => []]],
            ['type' => 'function', 'name' => 'fill_demo_form', 'description' => 'Fill allowlisted fields in the currently open fictional customer or invoice form. Read get_demo_context first. Does not submit the form. Customer email must be fictional and end in .invalid. Never enter real recipient details here.',
                'parameters' => ['type' => 'object', 'additionalProperties' => false, 'properties' => ['form' => ['type' => 'string', 'enum' => ['customer', 'invoice']], 'fields' => ['type' => 'object', 'additionalProperties' => false, 'properties' => ['name' => ['type' => 'string'], 'email' => ['type' => 'string'], 'notes' => ['type' => 'string'], 'customer' => ['type' => 'string'], 'product' => ['type' => 'string'], 'variant' => ['type' => 'string'], 'quantity' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 20]]]], 'required' => ['form', 'fields']]],
            ['type' => 'function', 'name' => 'create_demo_customer', 'description' => 'Save an explicitly requested fictional customer on this tablet. Ask for the name and fictional .invalid email if missing. Never create a live customer or store real personal data.',
                'parameters' => ['type' => 'object', 'additionalProperties' => false, 'properties' => ['name' => ['type' => 'string', 'maxLength' => 80], 'email' => ['type' => 'string', 'maxLength' => 160], 'notes' => ['type' => 'string', 'maxLength' => 1000]], 'required' => ['name', 'email']]],
            ['type' => 'function', 'name' => 'create_demo_invoice', 'description' => 'Create an explicitly requested local fictional invoice draft. Read fresh context and use exact catalog product and variant IDs. Ask which customer, product, handle/variant or quantity when unspecified. Catalog price is fixed; never send or charge. Repeated identical drafts reuse the existing draft.',
                'parameters' => ['type' => 'object', 'additionalProperties' => false, 'properties' => ['customer' => ['type' => 'string'], 'product' => ['type' => 'string'], 'variant' => ['type' => 'string'], 'quantity' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 20]], 'required' => ['customer', 'product', 'variant', 'quantity']]],
            ['type' => 'function', 'name' => 'prepare_demo_marketing', 'description' => 'Prepare a personalized product-specific email or SMS draft for an exact fictional customer. Opens a review screen; never sends. After drafting ask whether the presenter would like to send a formatted email or text to their own confirmed address/mobile. Do not request, repeat or store the real recipient in the AI conversation; use the separate confirmation form.',
                'parameters' => ['type' => 'object', 'additionalProperties' => false, 'properties' => ['customer' => ['type' => 'string'], 'channel' => ['type' => 'string', 'enum' => ['email', 'sms']]], 'required' => ['customer', 'channel']]],
        ];
    }
}

<?php

namespace App\Services\AiBranch;

use App\Models\Tenant;
use App\Models\TenantAccessProfile;
use App\Models\TenantModuleEntitlement;

class AiBranchOffer
{
    public const INCLUDED_SOURCE = 'ai_existing_plan_20261020';

    public const ADDON = 'ai_branch_access';

    public function included(Tenant $tenant): bool
    {
        return config('ai_branch.standard_enabled') && (config('ai_branch.existing_tenants')[$tenant->id] ?? null) === $tenant->slug
            && TenantModuleEntitlement::where('tenant_id', $tenant->id)->where('module_key', 'ai_branch')
                ->where('entitlement_source', self::INCLUDED_SOURCE)->where('billing_status', 'included')
                ->where('enabled_status', 'enabled')->where('availability_status', 'available')->exists();
    }

    public function metered(Tenant $tenant): bool
    {
        return config('ai_branch.standard_enabled') && ! app(AiFictionalPilot::class)->reserved($tenant)
            && ($this->included($tenant) || \App\Models\TenantAccessAddon::where('tenant_id', $tenant->id)
                ->where('addon_key', self::ADDON)->where('enabled', true)->where('source', 'stripe_fulfillment')->exists());
    }

    public function tier(Tenant $tenant): array
    {
        $tier = config('module_catalog.addons.'.self::ADDON);
        if ($this->included($tenant)) {
            $tier['display_name'] = 'AI access included in your existing plan';
            $tier['pricing']['recurring_price_cents'] = 0;
        }

        return $tier;
    }

    public function model(Tenant $tenant): string
    {
        return (string) data_get(TenantAccessProfile::where('tenant_id', $tenant->id)->value('metadata'), 'ai_branch.model', 'gpt-6-luna');
    }

    public function presentation(Tenant $tenant): ?array
    {
        if (! $this->metered($tenant)) {
            return null;
        }
        $model = $this->model($tenant);
        $card = config('ai_branch.models')[$model] ?? null;
        abort_unless(is_array($card), 503, 'Selected AI model is unavailable.');
        $credit = \App\Models\TenantMessagingCreditAccount::forAllTenants()->where('tenant_id', $tenant->id)->first();

        return ['mode' => 'prepaid', 'model' => $model, 'label' => $card['label'],
            'input_per_million_micros' => $card['input_rate'] * 2, 'cached_input_per_million_micros' => $card['cached_input_rate'] * 2,
            'output_per_million_micros' => $card['output_rate'] * 2, 'rates_version' => config('ai_branch.rates_version'),
            'max_request_charge_micros' => (int) ceil((24000 * $card['input_rate'] + $card['max_output'] * $card['output_rate']) / 1000000) * 2,
            'credit_available_micros' => $credit?->availableMicros() ?? 0, 'credit_reserved_micros' => $credit?->reserved_micros ?? 0];
    }
}

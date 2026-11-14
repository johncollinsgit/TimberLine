<?php

namespace App\Services\Onboarding;

use App\Services\Tenancy\TenantModuleCatalogService;
use Illuminate\Validation\ValidationException;

/** A priced setup basket, never an entitlement or payment confirmation. */
class BranchSelectionService
{
    public const SESSION_KEY = 'everbranch.branch_selection';

    public function __construct(private readonly TenantModuleCatalogService $catalog) {}

    public function modules(): array
    {
        return (array) ($this->catalog->publicCatalogPayload()['modules'] ?? []);
    }

    public function accountsAvailable(): bool
    {
        return (bool) config('self_service.registration_enabled') || ((bool) config('services.google.enabled')
            && (bool) config('services.google.auto_provision', true)
            && filled(config('services.google.client_id'))
            && filled(config('services.google.client_secret'))
            && filled(config('services.google.redirect')));
    }

    public function normalize(array $keys, bool $strict = true): array
    {
        $allowed = array_column($this->modules(), 'key');
        $keys = array_values(array_unique(array_filter($keys, 'is_string')));
        if ($strict && array_diff($keys, $allowed) !== []) {
            throw ValidationException::withMessages(['branches' => 'Choose branches from the current catalog.']);
        }

        return array_values(array_intersect($keys, $allowed));
    }

    public function summary(array $keys): array
    {
        $selected = $this->normalize($keys, false);
        $offer = (array) config('module_catalog.offers.standard');
        $setup = (int) ($offer['setup_cents'] ?? 0);
        $monthly = (int) ($offer['monthly_cents'] ?? 0);

        return [
            'offer_key' => (string) ($offer['key'] ?? 'standard'),
            'offer_version' => (string) ($offer['version'] ?? ''),
            'name' => (string) ($offer['name'] ?? 'Everbranch Simple'),
            'currency' => (string) ($offer['currency'] ?? 'USD'),
            'setup_cents' => $setup,
            'monthly_cents' => $monthly,
            'due_before_tax_cents' => $setup + $monthly,
            'usage_note' => (string) ($offer['usage_fees_note'] ?? ''),
            'selected' => $selected,
            'branches' => array_values(array_filter($this->modules(), fn (array $row): bool => in_array($row['key'], $selected, true))),
        ];
    }
}

<?php

namespace App\Services\Tenancy;

use App\Models\Tenant;
use App\Models\Trajectory\Space;
use App\Models\User;
use App\Services\Marketplace\MarketplaceAccess;
use App\Services\Team\TeamWorkAccess;
use App\Services\Trajectory\FinanceAccess;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/** Read-only workspace presentation. Assignment never grants authorization or payment readiness. */
class TenantPremiumProductSummaryService
{
    public function __construct(
        protected TenantModuleAccessResolver $access,
        protected TeamWorkAccess $teamAccess,
        protected FinanceAccess $financeAccess,
        protected MarketplaceAccess $marketplaceAccess,
        protected TenantFinancialAccess $financialAccess,
    ) {}

    public function forTenant(int $tenantId, User $user): array
    {
        Gate::forUser($user)->authorize('view-tenant-module-store', $tenantId);
        $products = (array) config('module_catalog.offers.standard.premium_products', []);
        try {
            $tenant = Tenant::query()->findOrFail($tenantId);
            if (! $user->tenants()->whereKey($tenantId)->wherePivot('membership_active', true)->exists()) {
                return ['known' => false, 'products' => $this->unconfirmed($products, 'restricted')];
            }
            $keys = array_values(array_unique(array_merge(...array_map(fn (array $row): array => (array) ($row['module_keys'] ?? []), $products))));
            $states = $this->access->resolveForTenant($tenantId, $keys)['modules'];
            $rows = [];
            foreach ($products as $product) {
                $assigned = [];
                foreach ((array) ($product['module_keys'] ?? []) as $key) {
                    if (! array_key_exists($key, $states) || ! array_key_exists('has_recorded_access', $states[$key])) {
                        throw new \RuntimeException('Premium module state unavailable');
                    }
                    if ($states[$key]['has_recorded_access'] ?? false) {
                        $assigned[$key] = $states[$key];
                    }
                }
                $row = ['key' => $product['key'], 'name' => $product['name'], 'owned' => $assigned !== [], 'open_url' => null, 'components' => []];
                if ($assigned === []) {
                    $rows[] = $this->finish($row, 'unassigned');

                    continue;
                }
                $rollout = match ($product['key']) {
                    'teams' => (bool) config('team_work.enabled') && in_array((string) $tenantId, array_map('strval', (array) config('team_work.tenant_allowlist', [])), true),
                    'trajectory' => (bool) config('trajectory.enabled'),
                    'markets_makers' => (bool) config('marketplace.enabled'),
                    default => false,
                };
                foreach ($assigned as $key => $state) {
                    $componentState = ! $rollout ? 'gated' : (! ($state['enabled'] ?? false) ? 'unavailable' : (($state['setup_status'] ?? '') === 'configured' ? 'configured' : 'setup'));
                    $row['components'][] = ['name' => (string) ($state['label'] ?? config('module_catalog.modules.'.$key.'.display_name', $product['name'])), 'state' => $componentState, 'label' => $this->label($componentState)];
                }
                if (! $rollout) {
                    $rows[] = $this->finish($row, 'gated');

                    continue;
                }
                if (! collect($assigned)->contains(fn (array $state): bool => ($state['enabled'] ?? false) && ($state['setup_status'] ?? '') === 'configured')) {
                    $blocked = collect($assigned)->every(fn (array $state): bool => in_array($state['reason'] ?? '', ['module_unavailable', 'tenant_not_supported', 'channel_not_supported', 'disabled_by_entitlement', 'disabled_by_override'], true));
                    $rows[] = $this->finish($row, $blocked ? 'unavailable' : 'setup');

                    continue;
                }
                $workspace = $this->workspace($product['key'], $tenant, $user, $assigned);
                $row['open_url'] = $workspace['url'];
                $rows[] = $this->finish($row, $workspace['state']);
            }

            return ['known' => true, 'products' => $rows];
        } catch (Throwable $exception) {
            rescue(fn () => Log::warning('tenant.premium_summary.unavailable', ['exception_type' => class_basename($exception)]), null, false);

            return ['known' => false, 'products' => $this->unconfirmed($products, 'unknown')];
        }
    }

    protected function workspace(string $key, Tenant $tenant, User $user, array $assigned): array
    {
        if (! $user->is_active || ! $user->email_verified_at) {
            return ['state' => 'restricted', 'url' => null];
        }
        if ($key === 'teams') {
            // A configured optional Teams component cannot open an unfinished primary workspace.
            $primary = $assigned['team_work'] ?? [];
            if (! ($primary['enabled'] ?? false) || ($primary['setup_status'] ?? '') !== 'configured') {
                return ['state' => 'setup', 'url' => null];
            }
            try {
                $this->teamAccess->context($user, $tenant);
            } catch (HttpExceptionInterface $exception) {
                if (! in_array($exception->getStatusCode(), [403, 404], true)) {
                    throw $exception;
                }

                return ['state' => 'restricted', 'url' => null];
            }

            return ['state' => 'available', 'url' => route('team.app', ['tenant' => $tenant->slug])];
        }
        if ($key === 'trajectory') {
            // Current-tenant IDs/authorization columns only; never return space names, values or accounts.
            $spaces = Space::query()->where('tenant_id', $tenant->id)->get(['id', 'tenant_id', 'kind', 'owner_user_id', 'enabled']);
            foreach ($spaces as $space) {
                if ($this->financeAccess->allows($user, $space)) {
                    return ['state' => 'available', 'url' => route('trajectory.index')];
                }
            }

            return ['state' => $this->financialAccess->allows($user, $tenant) ? 'setup' : 'restricted', 'url' => null];
        }
        if ($key === 'markets_makers') {
            // Do not call settings(): that helper may create a settings row on a read.
            $store = $this->marketplaceAccess->store();
            if (! $store || (int) $store->tenant_id !== (int) $tenant->id) {
                return ['state' => 'setup', 'url' => null];
            }
            if (! $this->marketplaceAccess->operator($user)) {
                return ['state' => 'restricted', 'url' => null];
            }

            $appUrl = rtrim((string) config('app.url'), '/');
            $address = parse_url($appUrl);
            if (! is_array($address) || ! in_array($address['scheme'] ?? '', ['http', 'https'], true) || empty($address['host'])
                || isset($address['user']) || isset($address['pass']) || ! empty($address['path']) || isset($address['query']) || isset($address['fragment'])) {
                return ['state' => 'setup', 'url' => null];
            }

            return ['state' => 'available', 'url' => $appUrl.route('marketplace.operator.index', [], false)];
        }

        return ['state' => 'unknown', 'url' => null];
    }

    protected function finish(array $row, string $state): array
    {
        return $row + [
            'state' => $state, 'state_label' => $this->label($state),
            'needs_attention' => $row['owned'] !== false && ($state !== 'available' || collect($row['components'])->contains(fn (array $component): bool => $component['state'] !== 'configured')),
            'description' => match ($state) {
                'unassigned' => 'Separate pricing. We will review your needs and terms with you.',
                'gated' => 'Part of this workspace. Our team needs to finish releasing it here.',
                'setup' => 'Part of this workspace. Some setup still needs to be completed.',
                'unavailable' => 'Workspace access is currently unavailable. Ask your administrator to review it.',
                'restricted' => 'Ask your workspace owner about access and the next step.',
                'available' => 'Your workspace is available. Some included tools may have their own setup steps.',
                default => 'We could not confirm workspace access. Try again before making a change.',
            },
        ];
    }

    protected function label(string $state): string
    {
        return match ($state) {
            'unassigned' => 'Available to discuss', 'gated' => 'Waiting for rollout',
            'setup' => 'Setup needed', 'configured' => 'Setup recorded', 'available' => 'Workspace available',
            'restricted' => 'Access managed by owner', 'unavailable' => 'Access unavailable',
            default => 'Unable to confirm',
        };
    }

    protected function unconfirmed(array $products, string $state): array
    {
        return array_map(fn (array $product): array => $this->finish([
            'key' => $product['key'], 'name' => $product['name'], 'owned' => null, 'open_url' => null, 'components' => [],
        ], $state), $products);
    }
}

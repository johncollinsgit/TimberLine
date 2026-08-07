<?php

namespace App\Services\Bud;

use App\Models\CustomerLoopAction;
use App\Models\MarketingProfile;
use App\Models\Tenant;
use Illuminate\Support\Facades\Schema;

class BudWorkspaceContextService
{
    public function __construct(private BudCapabilityRegistry $capabilities) {}

    /** @return array<string,mixed> */
    public function forTenant(Tenant $tenant): array
    {
        $summary = ['open_customer_loop_actions' => 0, 'customers' => 0];
        if (Schema::hasTable('customer_loop_actions')) {
            $summary['open_customer_loop_actions'] = CustomerLoopAction::query()->forTenantId($tenant->id)
                ->whereNotIn('status', [CustomerLoopAction::STATUS_COMPLETED, CustomerLoopAction::STATUS_DISMISSED])->count();
        }
        if (Schema::hasTable('marketing_profiles')) {
            $summary['customers'] = MarketingProfile::query()->forTenantId($tenant->id)->count();
        }

        return ['capabilities' => $this->capabilities->all(), 'workspace_summary' => $summary];
    }
}

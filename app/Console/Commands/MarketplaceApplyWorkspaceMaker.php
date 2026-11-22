<?php

namespace App\Console\Commands;

use App\Models\Marketplace\Audit;
use App\Models\Marketplace\Identity;
use App\Models\Marketplace\Maker;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Marketplace\MarketplaceAccess;
use App\Services\Marketplace\MarketplaceCatalog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class MarketplaceApplyWorkspaceMaker extends Command
{
    protected $signature = 'marketplace:apply-workspace-maker
        {--operator= : Verified marketplace operator managing this seller workspace}
        {--tenant= : Seller workspace ID}
        {--city= : Verified local city to show on the maker profile, if supplied}
        {--story= : Public description supplied for this maker}';

    protected $description = 'Create a maker application for a workspace managed by the requesting marketplace operator';

    public function handle(MarketplaceAccess $access, MarketplaceCatalog $catalog): int
    {
        $data = validator($this->options(), [
            'operator' => 'required|integer|min:1', 'tenant' => 'required|integer|min:1',
            'city' => 'nullable|string|max:100', 'story' => 'required|string|max:5000',
        ])->validate();
        $maker = DB::transaction(function () use ($access, $catalog, $data) {
            $operator = User::lockForUpdate()->findOrFail($data['operator']);
            abort_unless(config('marketplace.checkout_provider') === 'everbranch'
                && $access->publicHost((string) config('marketplace.host')) && $access->operator($operator), 403);
            $tenant = Tenant::lockForUpdate()->findOrFail($data['tenant']);
            $access->assertWorkspaceManager($operator, $tenant->id);
            $matches = Maker::where('tenant_id', $tenant->id)->orWhere('name', $tenant->name)->lockForUpdate()->get();
            $identity = Identity::firstOrCreate(['email' => strtolower(trim($operator->email))], ['active' => true]);
            abort_unless($identity->active, 403);
            if ($matches->isNotEmpty()) {
                $existing = $matches->first();
                abort_unless($matches->count() === 1 && (int) $existing->tenant_id === $tenant->id
                    && $existing->memberships()->where('identity_id', $identity->id)->where('active', true)->where('role', 'owner')->exists(),
                    409, 'A maker profile already exists. Its owner can use the marketplace workspace connection flow.');

                return $existing;
            }

            $name = validator(['name' => $tenant->name], ['name' => 'required|string|max:160'])->validate()['name'];
            $maker = $catalog->apply($identity, ['name' => $name, 'city' => $data['city'] ?? '', 'story' => $data['story']]);
            $maker->update(['tenant_id' => $tenant->id, 'connected_by' => $operator->id]);
            Audit::create(['actor_user_id' => $operator->id, 'identity_id' => $identity->id,
                'subject_id' => $maker->id, 'action' => 'maker.workspace_connected', 'details' => ['tenant_id' => $tenant->id, 'source' => 'operator_managed_workspace_application']]);

            return $maker;
        }, 3);

        $this->info("Maker #{$maker->id} belongs to workspace #{$maker->tenant_id}. Application status: {$maker->status}.");

        return self::SUCCESS;
    }
}

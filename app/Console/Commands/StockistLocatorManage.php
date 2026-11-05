<?php

namespace App\Console\Commands;

use App\Models\ShopifyStore;
use App\Models\User;
use App\Services\Stockists\StockistLocatorService;
use Illuminate\Console\Command;

class StockistLocatorManage extends Command
{
    protected $signature = 'stockists:manage {action : inspect, import, publish, or rollback}
        {--shop= : Exact connected myshopify.com domain} {--file= : Private Omnium JSON export}
        {--maps-key-file= : Private JSON containing the existing restricted browser api_key}
        {--expected-count= : Reviewed source/visible count} {--revision= : Exact reviewed draft revision}
        {--actor= : Active tenant owner/admin email} {--apply : Apply the reviewed operation}';

    protected $description = 'Inspect, import or publish one tenant/store stockist directory; default is read-only.';

    public function handle(StockistLocatorService $service): int
    {
        $store = ShopifyStore::withoutGlobalScopes()->where('shop_domain', strtolower((string) $this->option('shop')))->firstOrFail();
        if (! $store->tenant_id || ! in_array($store->store_role, ['retail', 'mixed'], true)) {
            $this->error('A mapped retail store is required.');

            return self::FAILURE;
        }
        $action = $this->argument('action');
        if (! in_array($action, ['inspect', 'import', 'publish', 'rollback'], true)) {
            $this->error('Unknown action.');

            return self::FAILURE;
        }
        if ($action === 'inspect') {
            $payload = $service->payload($store);
            unset($payload['locations']);
            $this->line(json_encode($payload, JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }
        $source = null;
        if ($action === 'import') {
            $file = (string) $this->option('file');
            $source = json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
            $count = count($source['items'] ?? $source['locations'] ?? []);
        } else {
            $count = count(array_filter($service->draft($store), fn ($row) => $row['visible']));
        }
        if ($count < 1 || (string) $count !== (string) $this->option('expected-count')) {
            $this->error('The reviewed expected count does not match.');

            return self::FAILURE;
        }
        $this->line('shop='.$store->shop_domain.' count='.$count.' revision='.$service->revision($store));
        if (! $this->option('apply')) {
            $this->info('Read-only review. No records changed.');

            return self::SUCCESS;
        }
        $actor = User::query()->whereRaw('LOWER(email) = ?', [strtolower((string) $this->option('actor'))])->firstOrFail();
        $membership = $actor->tenants()->whereKey($store->tenant_id)->first()?->pivot;
        if (! $actor->is_active || ! $membership?->membership_active || ! in_array($membership->role, ['owner', 'tenant_owner', 'admin'], true)) {
            $this->error('An active tenant owner/admin is required.');

            return self::FAILURE;
        }
        if ($action === 'import') {
            $this->line(json_encode($service->import($store, $source, hash_file('sha256', $file), $actor->id), JSON_THROW_ON_ERROR));
            if ($this->option('maps-key-file')) {
                $key = json_decode(file_get_contents($this->option('maps-key-file')), true, 512, JSON_THROW_ON_ERROR)['api_key'] ?? '';
                $service->configureKey($store, $key, $service->revision($store), $actor->id);
            }
        } else {
            $service->publish($store, (string) $this->option('revision'), $actor->id, $action === 'rollback');
        }
        $this->info('Operation completed.');

        return self::SUCCESS;
    }
}

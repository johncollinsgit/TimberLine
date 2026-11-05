<?php

namespace App\Services\Stockists;

use App\Models\ShopifyStore;
use App\Models\StockistLocation;
use App\Models\StockistLocatorSetting;
use App\Services\Tenancy\LandlordOperatorActionAuditService;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class StockistLocatorService
{
    public function settings(ShopifyStore $store): StockistLocatorSetting
    {
        return StockistLocatorSetting::query()->forTenantId((int) $store->tenant_id)->firstOrNew(
            ['shopify_store_id' => $store->id], ['tenant_id' => $store->tenant_id, 'configuration' => []]
        );
    }

    public function locations(ShopifyStore $store)
    {
        return StockistLocation::query()->forTenantId((int) $store->tenant_id)->where('shopify_store_id', $store->id);
    }

    public function draft(ShopifyStore $store): array
    {
        return $this->locations($store)->orderBy('sort_order')->orderBy('id')->get()->map(fn ($row) => [
            'id' => $row->id, 'name' => $row->name, 'address' => $row->address,
            'latitude' => (float) $row->latitude, 'longitude' => (float) $row->longitude,
            'featured' => $row->featured, 'visible' => $row->source_visible, 'sort_order' => $row->sort_order,
        ])->all();
    }

    public function revision(ShopifyStore $store): string
    {
        return hash('sha256', json_encode([$this->draft($store), $this->settings($store)->configuration], JSON_THROW_ON_ERROR));
    }

    public function payload(ShopifyStore $store): array
    {
        $setting = $this->settings($store);
        $config = (array) $setting->configuration;

        return ['revision' => $this->revision($store), 'locations' => $this->draft($store),
            'status' => $setting->status, 'published_count' => count(data_get($config, 'published.locations', [])),
            'published_at' => data_get($config, 'published.at'), 'has_previous' => isset($config['previous']),
            'has_maps_key' => filled($config['maps_key_encrypted'] ?? null),
            'source_checksum' => $setting->source_checksum];
    }

    public function validateLocation(array $input): array
    {
        $data = Validator::make($input, [
            'name' => ['required', 'string', 'max:190'], 'address' => ['required', 'string', 'max:1500'],
            'latitude' => ['required', 'numeric', 'between:-90,90'], 'longitude' => ['required', 'numeric', 'between:-180,180'],
            'featured' => ['required', 'boolean'], 'visible' => ['required', 'boolean'],
            'sort_order' => ['required', 'integer', 'between:0,100000'],
        ])->validate();
        foreach (['name', 'address'] as $field) {
            $data[$field] = trim($data[$field]);
            if ($data[$field] === '' || strip_tags($data[$field]) !== $data[$field]) {
                throw ValidationException::withMessages([$field => 'Use plain text.']);
            }
        }

        return $data;
    }

    public function save(ShopifyStore $store, ?int $id, array $data, string $revision, ?int $actor): void
    {
        $data = $this->validateLocation($data);
        $this->change($store, $revision, $actor, 'location_saved', function () use ($store, $id, $data): void {
            $record = $id ? $this->locations($store)->findOrFail($id) : new StockistLocation([
                'tenant_id' => $store->tenant_id, 'shopify_store_id' => $store->id, 'source_key' => 'native:'.Str::uuid(),
            ]);
            $duplicate = $this->locations($store)->whereRaw('LOWER(TRIM(name)) = ?', [mb_strtolower($data['name'])])
                ->whereRaw('LOWER(TRIM(address)) = ?', [mb_strtolower($data['address'])]);
            if ($id) {
                $duplicate->whereKeyNot($id);
            }
            if ($duplicate->exists()) {
                throw ValidationException::withMessages(['name' => 'This retailer and address already exist.']);
            }
            $record->fill(collect($data)->except('visible')->all());
            $record->source_visible = $data['visible'];
            $record->save();
        });
    }

    public function mapsKey(StockistLocatorSetting $setting): ?string
    {
        $encrypted = data_get($setting->configuration, 'maps_key_encrypted');

        return $encrypted ? Crypt::decryptString($encrypted) : null;
    }

    public function previewToken(ShopifyStore $store): string
    {
        return Crypt::encryptString(json_encode(['store' => $store->id, 'tenant' => $store->tenant_id,
            'revision' => $this->revision($store), 'expires' => now()->addMinutes(15)->timestamp], JSON_THROW_ON_ERROR));
    }

    public function previewRows(ShopifyStore $store, string $token): array
    {
        $grant = rescue(fn () => json_decode(Crypt::decryptString($token), true, 16, JSON_THROW_ON_ERROR), null, false);
        abort_unless(is_array($grant) && ($grant['store'] ?? null) === $store->id && ($grant['tenant'] ?? null) === $store->tenant_id
            && ($grant['expires'] ?? 0) > now()->timestamp, 403, 'This private preview has expired.');
        abort_unless(hash_equals($this->revision($store), (string) ($grant['revision'] ?? '')), 409, 'The draft changed. Open a fresh preview.');

        return array_values(array_filter($this->draft($store), fn ($row) => $row['visible']));
    }

    public function configureKey(ShopifyStore $store, string $key, string $revision, ?int $actor): void
    {
        if (! preg_match('/^AIza[A-Za-z0-9_-]{30,100}$/', $key)) {
            throw ValidationException::withMessages(['maps_key' => 'Enter a Google Maps browser API key restricted to this storefront.']);
        }
        $this->change($store, $revision, $actor, 'maps_key_configured', function ($setting) use ($key): void {
            $config = (array) $setting->configuration;
            $config['maps_key_encrypted'] = Crypt::encryptString($key);
            $setting->forceFill(['configuration' => $config])->save();
        });
    }

    public function publish(ShopifyStore $store, string $revision, ?int $actor, bool $rollback = false): void
    {
        $this->change($store, $revision, $actor, $rollback ? 'publication_rolled_back' : 'directory_published', function ($setting) use ($store, $rollback): void {
            $config = (array) $setting->configuration;
            if ($rollback) {
                abort_unless(isset($config['previous']), 422, 'No previous publication is available.');
                [$config['published'], $config['previous']] = [$config['previous'], $config['published'] ?? null];
            } else {
                $rows = array_values(array_filter($this->draft($store), fn ($row) => $row['visible']));
                abort_unless(count($rows) > 0 && count($rows) <= 2000, 422, 'Publish between 1 and 2,000 visible locations.');
                abort_unless($this->mapsKey($setting), 422, 'Connect a restricted Google Maps browser key before publishing.');
                foreach ($rows as $row) {
                    $this->validateLocation($row);
                }
                if (isset($config['published'])) {
                    $config['previous'] = $config['published'];
                }
                $config['published'] = ['id' => (string) Str::uuid(), 'at' => now()->toIso8601String(), 'locations' => $rows];
            }
            $setting->forceFill(['configuration' => $config, 'status' => 'published'])->save();
            $this->locations($store)->update(['published' => false]);
            $this->locations($store)->whereIn('id', array_column($config['published']['locations'], 'id'))->update(['published' => true]);
        });
    }

    public function import(ShopifyStore $store, array $source, string $checksum, ?int $actor): array
    {
        $raw = $source['items'] ?? $source['locations'] ?? null;
        abort_unless(is_array($raw) && count($raw) > 0 && count($raw) <= 2000, 422, 'The export must contain 1–2,000 locations.');
        $rows = [];
        $seen = [];
        foreach (array_values($raw) as $index => $row) {
            $address = trim((string) ($row['address'] ?? $row['addr'] ?? '')) ?: trim(html_entity_decode(strip_tags((string) ($row['b'] ?? ''))));
            $data = $this->validateLocation([
                'name' => trim((string) ($row['name'] ?? $row['t'] ?? '')), 'address' => $address,
                'latitude' => $row['latitude'] ?? $row['lt'] ?? null, 'longitude' => $row['longitude'] ?? $row['lg'] ?? null,
                'featured' => (bool) ($row['featured'] ?? false), 'visible' => (bool) ($row['visible'] ?? $row['v'] ?? true), 'sort_order' => $index,
            ]);
            $key = mb_strtolower($data['name'].'|'.$data['address']);
            abort_if(isset($seen[$key]), 422, 'The source contains a duplicate retailer/address.');
            $seen[$key] = true;
            $rows[] = $data;
        }
        $this->change($store, $this->revision($store), $actor, 'source_imported', function ($setting) use ($store, $rows, $checksum): void {
            if ($setting->source_checksum === $checksum) {
                return;
            }
            $this->locations($store)->update(['source_visible' => false]);
            foreach ($rows as $row) {
                $key = 'omnium:'.hash('sha256', mb_strtolower($row['name'].'|'.$row['address']));
                $this->locations($store)->updateOrCreate(['source_key' => $key], [
                    'tenant_id' => $store->tenant_id, 'shopify_store_id' => $store->id,
                    ...collect($row)->except('visible')->all(), 'source_visible' => $row['visible'],
                ]);
            }
            $config = (array) $setting->configuration;
            $config['source'] = ['checksum' => $checksum, 'count' => count($rows), 'at' => now()->toIso8601String()];
            $setting->forceFill(['configuration' => $config, 'source_checksum' => $checksum, 'last_imported_at' => now()])->save();
        });

        return ['source_count' => count($rows), 'visible_count' => $this->locations($store)->where('source_visible', true)->count()];
    }

    private function change(ShopifyStore $store, string $revision, ?int $actor, string $action, callable $work): void
    {
        StockistLocatorSetting::query()->forTenantId((int) $store->tenant_id)->firstOrCreate(
            ['shopify_store_id' => $store->id], ['tenant_id' => $store->tenant_id, 'configuration' => []]
        );
        DB::transaction(function () use ($store, $revision, $actor, $action, $work): void {
            $setting = StockistLocatorSetting::query()->forTenantId((int) $store->tenant_id)->where('shopify_store_id', $store->id)->lockForUpdate()->firstOrFail();
            abort_unless(hash_equals($this->revision($store), $revision), 409, 'The directory changed. Reload before saving.');
            $before = $this->payload($store);
            $work($setting);
            app(LandlordOperatorActionAuditService::class)->record(
                (int) $store->tenant_id, $actor, 'stockist.'.$action, 'success', 'shopify_store', $store->id,
                context: ['shopify_store_id' => $store->id], beforeState: $before, afterState: $this->payload($store)
            );
        });
    }
}

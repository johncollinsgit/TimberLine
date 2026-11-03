<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\PaymentsCore\Contract;
use App\Services\Pos\PosException;
use App\Services\Pos\PosStore;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

/** Preview first. Provisioning never grants the POS entitlement or enables collection. */
final class PosProvisionCommand extends Command
{
    protected $signature = 'pos:provision {file} {--actor=} {--apply}';

    protected $description = 'Review/provision a merchant-bound POS register and named existing staff/devices';

    public function handle(PosStore $store): int
    {
        $path = $this->argument('file');
        if (! is_file($path) || is_link($path) || filesize($path) > 262144 || (fileperms($path) & 0077) !== 0) {
            $this->error('Use a private regular JSON file (mode 0600, maximum 256 KiB).');

            return self::FAILURE;
        }
        $value = json_decode(file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
        $rules = ['tenant_id' => 'required|integer|min:1', 'register_id' => 'required|regex:/^[a-f0-9]{32}$/D',
            'connection_id' => 'required|regex:/^[a-f0-9]{32}$/D', 'location_id' => 'required|regex:/^[A-Za-z0-9_-]{1,64}$/D',
            'name' => 'required|string|max:100', 'location_name' => 'required|string|max:100', 'timezone' => 'required|timezone',
            'catalog_source' => 'required|in:native,shopify', 'configuration' => 'required|array',
            'assignments' => 'required|array|min:1|max:100', 'assignments.*' => 'array:user_id,device_id,role',
            'assignments.*.user_id' => 'required|integer|min:1', 'assignments.*.device_id' => 'required|regex:/^[A-Za-z0-9_-]{16,64}$/D',
            'assignments.*.role' => 'required|in:cashier,manager,admin', 'items' => 'sometimes|array|max:200'];
        if (! is_array($value) || array_diff(array_keys($value), array_filter(array_keys($rules), fn ($k) => ! str_contains($k, '.')))
            || Validator::make($value, $rules)->fails()) {
            throw new PosException('invalid_provisioning_document', 422);
        }
        $tenant = $value['tenant_id'];
        $actorId = (int) $this->option('actor');
        $actor = User::query()->find($actorId);
        $member = $store->db->table('tenant_user')->where('tenant_id', $tenant)->where('user_id', $actorId)->first();
        if (! $actor?->is_active || ! $actor->hasVerifiedEmail() || ! $member?->membership_active
            || ! in_array($member->role, ['owner', 'tenant_owner', 'admin'], true)) {
            throw new PosException('existing_owner_required', 403);
        }
        $connection = $store->rows('connections', $tenant)->where('id', $value['connection_id'])->first();
        if (! $connection || ! $connection->verified_at) {
            throw new PosException('verified_owner_merchant_connection_required');
        }
        $old = $store->rows('registers', $tenant)->where('id', $value['register_id'])->first();
        if ($old && ($old->connection_id !== $value['connection_id'] || $old->location_id !== $value['location_id'] || $old->catalog_source !== $value['catalog_source'])) {
            throw new PosException('register_binding_immutable');
        }
        if (config('enterprise.runtime') === true && $value['catalog_source'] !== 'native') {
            throw new PosException('enterprise_native_catalog_required');
        }
        if ($value['catalog_source'] === 'shopify' && ! empty($value['items'])) {
            throw new PosException('shopify_native_stock_forbidden');
        }
        foreach ($value['assignments'] as $assignment) {
            $user = User::query()->find($assignment['user_id']);
            if (! $user?->is_active || ! $user->hasVerifiedEmail()
                || ! $store->db->table('tenant_user')->where('tenant_id', $tenant)->where('user_id', $user->id)->where('membership_active', true)->exists()) {
                throw new PosException('assignment_requires_existing_membership');
            }
        }
        foreach ($value['items'] ?? [] as $item) {
            if (array_diff(array_keys($item), ['sku', 'title', 'kind', 'unit_amount_minor', 'tax_bps', 'tax_basis', 'tracked', 'quantity'])
                || Validator::make($item, ['sku' => 'required|string|max:100', 'title' => 'required|string|max:200',
                    'kind' => 'required|in:merchandise,printing_service', 'unit_amount_minor' => 'required|integer|min:1|max:100000000',
                    'tax_bps' => 'required|integer|min:0|max:10000', 'tax_basis' => 'required|string|max:200',
                    'tracked' => 'required|boolean', 'quantity' => 'required|integer|min:0|max:1000000'])->fails()
                || ($item['kind'] === 'printing_service' && $item['tracked'])) {
                throw new PosException('invalid_reviewed_catalog');
            }
        }
        $this->line(json_encode(['mode' => $this->option('apply') ? 'apply' : 'preview', 'tenant_id' => $tenant,
            'register_id' => $value['register_id'], 'assignments' => count($value['assignments']), 'items' => count($value['items'] ?? []),
            'collection_enabled' => false, 'entitlement_changed' => false]));
        if (! $this->option('apply')) {
            return self::SUCCESS;
        }
        $store->db->transaction(function () use ($store, $value, $tenant, $actorId): void {
            $values = collect($value)->only(['name', 'location_id', 'location_name', 'timezone', 'connection_id', 'catalog_source'])->all();
            $values['configuration'] = $store->encrypt($value['configuration']);
            $values['active'] = true;
            $values['collection_enabled'] = false;
            $values['servicing_enabled'] = true;
            $store->rows('registers', $tenant)->updateOrInsert(['id' => $value['register_id']], $store->values($tenant, $values));
            $store->rows('assignments', $tenant)->where('register_id', $value['register_id'])->update(['active' => false]);
            foreach ($value['assignments'] as $assignment) {
                $store->rows('assignments', $tenant)->updateOrInsert(['register_id' => $value['register_id'], 'user_id' => $assignment['user_id'], 'device_id' => $assignment['device_id']],
                    $store->values($tenant, ['role' => $assignment['role'], 'active' => true]));
            }
            foreach ($value['items'] ?? [] as $item) {
                $id = substr(Contract::hash([$store->runtime(), $tenant, $value['location_id'], $item['sku']]), 0, 32);
                $old = $store->rows('items', $tenant)->where('id', $id)->lockForUpdate()->first();
                $held = (int) $store->rows('holds', $tenant)->where('item_id', $id)->where('state', 'held')->lockForUpdate()->get()->sum('quantity');
                if ($held || ($old && (int) $old->quantity !== $item['quantity'])) {
                    throw new PosException('use_audited_stock_adjustment');
                }
                $store->rows('items', $tenant)->updateOrInsert(['id' => $id], $store->values($tenant, [
                    ...$item, 'location_id' => $value['location_id'], 'revision' => Contract::hash($item),
                    'reviewed_by' => $actorId, 'tax_reviewed_at' => now(), 'active' => true,
                ]));
            }
            $store->event($tenant, $value['register_id'], 'register_provisioned', ['actor_id' => $actorId,
                'review_document_hash' => hash_file('sha256', $this->argument('file')), 'collection_enabled' => false]);
        }, 3);

        return self::SUCCESS;
    }
}

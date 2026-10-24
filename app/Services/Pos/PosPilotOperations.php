<?php

declare(strict_types=1);

namespace App\Services\Pos;

use App\Models\User;
use App\Services\PaymentsCore\Contract;
use Illuminate\Support\Facades\Hash;

final class PosPilotOperations
{
    public function __construct(private PosStore $store, private PosPilotAccess $access) {}

    public function operator(array $input): array
    {
        $ctx = $this->access->owner();
        $tenant = $ctx['tenant_id'];
        $id = $input['id'] ?? bin2hex(random_bytes(16));
        $old = $this->store->rows('operators', $tenant)->where('id', $id)->first();
        if (isset($input['id']) && ! $old) {
            throw new PosException('staff_not_found', 404);
        }
        $userId = $input['user_id'] ?? $old?->user_id;
        if ($userId) {
            $member = $this->store->db->table('tenant_user')->where('tenant_id', $tenant)->where('user_id', $userId)->where('membership_active', true)->first();
            $user = User::query()->find($userId);
            if (! $member || ! $user?->is_active || ! $user->hasVerifiedEmail()) {
                throw new PosException('existing_member_required');
            }
            if ($old?->user_id && (int) $old->user_id !== (int) $userId) {
                throw new PosException('staff_identity_immutable');
            }
            if ($this->store->rows('operators', $tenant)->where('user_id', $userId)->where('id', '!=', $id)->exists()) {
                throw new PosException('staff_identity_already_linked');
            }
        }
        $values = ['name' => $input['name'], 'role' => $input['role'], 'user_id' => $userId, 'active' => $input['active'], 'created_at' => $old?->created_at ?? now()];
        if (isset($input['pin'])) {
            $values['pin_hash'] = Hash::make($input['pin']);
            $this->store->rows('register_sessions', $tenant)->where('operator_id', $id)->update(['operator_id' => null]);
        }
        $this->store->rows('operators', $tenant)->updateOrInsert(['id' => $id], $this->store->values($tenant, $values));
        $this->store->event($tenant, str_repeat('0', 32), 'staff_updated', ['operator_id' => $id, 'owner_id' => $ctx['actor_id'], 'active' => $input['active']]);

        return ['operator_id' => $id];
    }

    public function company(string $timezone): array
    {
        $ctx = $this->access->owner();
        $this->store->rows('company_settings', $ctx['tenant_id'])->updateOrInsert(['tenant_id' => $ctx['tenant_id'], 'runtime_id' => $this->store->runtime()],
            $this->store->values($ctx['tenant_id'], ['id' => substr(hash('sha256', $this->store->runtime().':'.$ctx['tenant_id']), 0, 32), 'reporting_timezone' => $timezone, 'created_at' => now()]));

        return ['reporting_timezone' => $timezone];
    }

    public function createEvent(array $input): array
    {
        $ctx = $this->access->owner();
        $tenant = $ctx['tenant_id'];
        $id = bin2hex(random_bytes(16));
        \Illuminate\Support\Facades\Cache::lock('pos-event-location-'.hash('sha256', $this->store->runtime().':'.$tenant), 30)->block(5, function () use ($input, $tenant, $id): void {
            $this->store->db->transaction(function () use ($input, $tenant, $id): void {
                // Lock a stable existing row so overlapping event creation serializes.
                $registers = $this->store->rows('registers', $tenant)->whereIn('id', $input['register_ids'])->orderBy('id')->lockForUpdate()->get();
                if ($registers->count() !== count($input['register_ids']) || $registers->pluck('location_id')->unique()->count() !== 1
                    || $registers->contains(fn ($r) => ! $r->active || ($r->provider ?? '') !== 'stripe_terminal')) {
                    throw new PosException('event_registers_invalid');
                }
                if ($registers->pluck('catalog_source')->unique()->count() !== 1) {
                    throw new PosException('event_catalog_authority_mismatch');
                }
                $location = $registers->first()->location_id;
                if ($this->store->rows('trade_events', $tenant)->where('location_id', $location)->whereIn('state', ['planned', 'open', 'closing'])
                    ->where('starts_at', '<', $input['ends_at'])->where('ends_at', '>', $input['starts_at'])->exists()) {
                    throw new PosException('event_stock_location_in_use');
                }
                $staff = $this->store->rows('operators', $tenant)->whereIn('id', $input['operator_ids'])->where('active', true)->get();
                if ($staff->count() !== count($input['operator_ids'])) {
                    throw new PosException('event_staff_invalid');
                }
                $reviews = [];
                $shopifyLocations = [];
                foreach ($registers as $register) {
                    $config = $this->store->decrypt($register->configuration);
                    if (($config['tax_reviewed'] ?? false) !== true || empty($config['tax_valid_until']) || strtotime($config['tax_valid_until']) < strtotime($input['ends_at'])) {
                        throw new PosException('event_tax_review_required');
                    }
                    if ($register->catalog_source === 'shopify' && (($config['shopify_acceptance_verified'] ?? false) !== true
                        || ($input['shopify_routing_verified'] ?? false) !== true || empty($config['shopify']['location_id']))) {
                        throw new PosException('event_shopify_routing_review_required');
                    }
                    $address = $config['shopify']['tax_address'] ?? $config['tax_address'] ?? null;
                    if (! is_array($address)) {
                        throw new PosException('event_tax_address_review_required');
                    }
                    $normalized = ['address1' => $address['address1'] ?? '', 'city' => $address['city'] ?? '', 'region' => $address['provinceCode'] ?? $address['province'] ?? '', 'postal_code' => $address['zip'] ?? '', 'country' => $address['countryCode'] ?? $address['countryCodeV2'] ?? $address['country'] ?? ''];
                    foreach ($normalized as $key => $value) {
                        if (mb_strtolower(trim($value)) !== mb_strtolower(trim($input['address'][$key]))) {
                            throw new PosException('event_tax_address_mismatch');
                        }
                    }
                    if ($register->catalog_source === 'shopify') {
                        $shopifyLocations[] = ($config['shopify']['store_id'] ?? '').':'.$config['shopify']['location_id'];
                    }
                    $reviews[$register->id] = self::taxRevision($config);
                }
                if (count(array_unique($shopifyLocations)) > 1) {
                    throw new PosException('event_stock_authority_mismatch');
                }
                $stockAuthority = $shopifyLocations ? 'shopify:'.$shopifyLocations[0] : 'native:'.$location;
                foreach ($this->store->rows('trade_events', $tenant)->whereIn('state', ['planned', 'open', 'closing'])->where('starts_at', '<', $input['ends_at'])->where('ends_at', '>', $input['starts_at'])->get() as $other) {
                    $otherConfig = $this->store->decrypt($other->configuration);
                    $authority = $otherConfig['stock_authority'] ?? null;
                    if (! $authority) {
                        $otherRegister = $this->store->rows('registers', $tenant)->whereIn('id', $this->store->rows('event_registers', $tenant)->where('event_id', $other->id)->select('register_id'))->first();
                        $shop = $otherRegister ? ($this->store->decrypt($otherRegister->configuration)['shopify'] ?? null) : null;
                        $authority = $otherRegister?->catalog_source === 'shopify' && $shop ? 'shopify:'.$shop['store_id'].':'.$shop['location_id'] : 'native:'.$other->location_id;
                    }
                    if ($authority === $stockAuthority) {
                        throw new PosException('event_stock_location_in_use');
                    }
                }
                $configuration = ['stock_authority' => $stockAuthority, 'address' => $input['address'], 'tax_reviewed' => true, 'tax_valid_until' => $input['ends_at'],
                    'tax_revision' => Contract::hash($reviews), 'register_tax_revisions' => $reviews, 'shopify_routing_verified' => $input['shopify_routing_verified'] ?? false];
                $this->store->rows('trade_events', $tenant)->insert($this->store->values($tenant, ['id' => $id, 'name' => $input['name'], 'location_id' => $location,
                    'timezone' => $input['timezone'], 'starts_at' => $input['starts_at'], 'ends_at' => $input['ends_at'], 'configuration' => $this->store->encrypt($configuration), 'created_at' => now()]));
                foreach (['event_staff' => ['operator_id', $input['operator_ids']], 'event_registers' => ['register_id', $input['register_ids']]] as $table => [$column, $ids]) {
                    foreach ($ids as $value) {
                        $this->store->rows($table, $tenant)->insert($this->store->values($tenant, ['id' => bin2hex(random_bytes(16)), 'event_id' => $id, $column => $value, 'created_at' => now()]));
                    }
                }
            });
        });
        $this->store->event($tenant, str_repeat('0', 32), 'trade_event_created', ['event_id' => $id, 'actor_id' => $ctx['actor_id']]);

        return ['event_id' => $id];
    }

    public static function taxRevision(array $config): string
    {
        return Contract::hash(array_intersect_key($config, array_flip(['tax_reviewed', 'tax_valid_until', 'tax_policy', 'tax_basis', 'tax_address', 'shopify'])));
    }

    public function shift(string $eventId): array
    {
        $ctx = $this->access->context();
        $tenant = $ctx['tenant_id'];

        return $this->store->db->transaction(function () use ($ctx, $tenant, $eventId): array {
            $session = $this->store->rows('register_sessions', $tenant)->where('id', $ctx['session']->id)->lockForUpdate()->firstOrFail();
            $event = $this->store->rows('trade_events', $tenant)->where('id', $eventId)->where('state', 'open')->firstOrFail();
            $register = $ctx['device']->register_id;
            if (! $this->store->rows('event_staff', $tenant)->where('event_id', $eventId)->where('operator_id', $ctx['operator_id'])->where('active', true)->exists()
                || ! $this->store->rows('event_registers', $tenant)->where('event_id', $eventId)->where('register_id', $register)->where('active', true)->exists()) {
                throw new PosException('event_assignment_required', 403);
            }
            if ($session->shift_id) {
                $old = $this->store->rows('shifts', $tenant)->where('id', $session->shift_id)->where('state', 'open')->first();
                if ($old && $old->event_id === $eventId && $old->operator_id === $ctx['operator_id']) {
                    return ['shift_id' => $old->id];
                }
                if ($old) {
                    $this->store->rows('shifts', $tenant)->where('id', $old->id)->update(['state' => 'closed', 'closed_at' => now()]);
                }
            }
            $id = bin2hex(random_bytes(16));
            $this->store->rows('shifts', $tenant)->insert($this->store->values($tenant, ['id' => $id, 'event_id' => $event->id, 'operator_id' => $ctx['operator_id'],
                'device_id' => $ctx['device']->id, 'register_id' => $register, 'created_at' => now()]));
            $this->store->rows('register_sessions', $tenant)->where('id', $session->id)->update(['shift_id' => $id, 'last_used_at' => now()]);
            $this->store->event($tenant, str_repeat('0', 32), 'shift_opened', ['shift_id' => $id, 'operator_id' => $ctx['operator_id']]);

            return ['shift_id' => $id];
        });
    }

    public function touch(): array
    {
        $ctx = $this->access->context();
        $this->store->rows('register_sessions', $ctx['tenant_id'])->where('id', $ctx['session']->id)->update(['last_used_at' => now()]);

        return ['active' => true];
    }

    public function lock(bool $endShift = false): array
    {
        $ctx = $this->access->context(true);
        if ($endShift && $ctx['session']->shift_id) {
            $this->store->rows('shifts', $ctx['tenant_id'])->where('id', $ctx['session']->shift_id)->update(['state' => 'closed', 'closed_at' => now()]);
        }
        $updates = ['last_used_at' => now()];
        if ($ctx['shared']) {
            $updates['operator_id'] = null;
        }
        if ($endShift) {
            $updates['shift_id'] = null;
        }
        $this->store->rows('register_sessions', $ctx['tenant_id'])->where('id', $ctx['session']->id)->update($updates);

        return ['locked' => $ctx['shared'], 'shift_ended' => $endShift];
    }

    public function basket(array $input): array
    {
        $ctx = $this->access->register($input['register_id'], 'quote');
        $id = $input['id'] ?? bin2hex(random_bytes(16));

        return $this->store->db->transaction(function () use ($ctx, $input, $id): array {
            $old = $this->store->rows('baskets', $ctx['tenant_id'])->where('id', $id)->lockForUpdate()->first();
            if ($old && ($old->operator_id !== $ctx['operator_id'] || ! in_array($old->state, ['saved', 'reviewing'], true) || ($old->claimed_device_id && $old->claimed_device_id !== $ctx['device']->id))) {
                throw new PosException('basket_unavailable');
            }
            $payload = ['items' => $input['items'], 'customer' => $input['customer'] ?? null];
            $this->store->rows('baskets', $ctx['tenant_id'])->updateOrInsert(['id' => $id], $this->store->values($ctx['tenant_id'], ['operator_id' => $ctx['operator_id'],
                'event_id' => $ctx['trade_event']->id, 'register_id' => $input['register_id'], 'payload' => $this->store->encrypt($payload), 'payload_hash' => Contract::hash($payload),
                'sale_request_id' => $old?->sale_request_id ?? (string) \Illuminate\Support\Str::uuid(), 'created_at' => $old?->created_at ?? now(), 'updated_at' => now()]));

            return ['basket_id' => $id];
        });
    }

    public function resumeBasket(string $id): array
    {
        $ctx = $this->access->context();

        return $this->store->db->transaction(function () use ($ctx, $id): array {
            $basket = $this->store->rows('baskets', $ctx['tenant_id'])->where('id', $id)->where('operator_id', $ctx['operator_id'])->lockForUpdate()->firstOrFail();
            $scope = $this->access->register($basket->register_id, 'quote');
            if ($scope['trade_event']->id !== $basket->event_id || ($basket->claimed_device_id && $basket->claimed_device_id !== $ctx['device']->id)) {
                throw new PosException('basket_event_or_device_mismatch');
            }
            $payload = $this->store->decrypt($basket->payload);
            if (! hash_equals($basket->payload_hash, Contract::hash($payload))) {
                throw new PosException('basket_integrity');
            }
            if ($basket->state === 'saved') {
                $this->store->rows('baskets', $ctx['tenant_id'])->where('id', $id)->update(['state' => 'reviewing', 'claimed_device_id' => $ctx['device']->id]);
            }

            return ['basket_id' => $basket->id, 'state' => $basket->state, 'request_id' => $basket->sale_request_id, ...$payload];
        });
    }

    public function claimBasketQuote(array $ctx, array $input): void
    {
        $this->store->db->transaction(function () use ($ctx, $input): void {
            $basket = $this->store->rows('baskets', $ctx['tenant_id'])->where('id', $input['basket_id'])->where('operator_id', $ctx['operator_id'])->lockForUpdate()->firstOrFail();
            if ($basket->claimed_device_id !== $ctx['device']->id || $basket->register_id !== $ctx['register']->id || $basket->event_id !== $ctx['trade_event']->id || $basket->sale_request_id !== $input['request_id']) {
                throw new PosException('basket_binding_mismatch');
            }
            $saved = $this->store->decrypt($basket->payload);
            $quantities = fn ($items) => collect($items)->mapWithKeys(fn ($i) => [$i['item_id'] => $i['quantity']])->sortKeys()->all();
            if ($quantities($saved['items']) !== $quantities($input['items'] ?? [])) {
                throw new PosException('basket_selections_changed');
            }
            $this->store->rows('baskets', $ctx['tenant_id'])->where('id', $basket->id)->update(['state' => 'submitted']);
        });
    }

    public function favorite(string $register, string $item, bool $favorite): array
    {
        $ctx = $this->access->register($register, 'catalog');
        $row = $this->store->rows('favorites', $ctx['tenant_id'])->where('operator_id', $ctx['operator_id'])->where('register_id', $register)->where('item_id', $item);
        if ($favorite) {
            $row->insertOrIgnore($this->store->values($ctx['tenant_id'], ['id' => bin2hex(random_bytes(16)), 'operator_id' => $ctx['operator_id'], 'register_id' => $register, 'item_id' => $item, 'created_at' => now()]));
        } else {
            $row->delete();
        }

        return ['favorite' => $favorite];
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Pos;

use App\Models\MarketingProfile;
use App\Models\User;
use App\Services\Marketing\CustomerAccountFence;
use App\Services\PaymentsCore\Contract;
use Illuminate\Support\Str;

/** A register-scoped customer-present attestation; it never grants portal or wallet ownership. */
final class PosStaffRewardAuthorization
{
    public const DISCLOSURE = 'staff_confirmed_v1';

    public function __construct(private PosStore $store, private PosAccess $access) {}

    public function issue(string $register, array $input): array
    {
        $scope = $this->access->register($register, 'quote');
        if (! $this->enabled($scope)) {
            throw new PosException('staff_reward_confirmation_unavailable', 403);
        }

        return app(CustomerAccountFence::class)->forTenant($scope['tenant_id'], function () use ($scope, $input): array {
            $profile = app(PosCustomerDirectory::class)->profile($scope['tenant_id'], $input['customer_profile_id']);
            $contact = app(PosVerifiedCustomerAccount::class)->contact($profile, $input['channel']);
            $expected = $input['channel'] === 'email' ? strtolower(trim($input['expected_contact']))
                : app(\App\Support\Marketing\MarketingIdentityNormalizer::class)->toE164($input['expected_contact']);
            if (! $input['customer_confirmed'] || ! $contact || ! hash_equals($contact, (string) $expected)) {
                throw new PosException('customer_contact_confirmation_required', 422);
            }
            $policy = app(PosRewardRedemptionSettings::class)->forRegister($scope);
            $effective = app(PosRewardRedemptionSettings::class)->forRegisterProfile($scope, $profile);
            $key = substr(Contract::hash(['staff-reward', $scope['register']->id, $scope['actor_id'], $scope['device_id'], $input['request_id']]), 0, 32);
            $terms = ['register_id' => $scope['register']->id, 'actor_id' => $scope['actor_id'], 'device_id' => $scope['device_id'],
                'profile_id' => (int) $profile->id, 'channel' => $input['channel'], 'contact_hash' => hash('sha256', $contact),
                'policy_version' => $policy['version'], 'cap_minor' => $effective['max_minor']];
            $token = rtrim(strtr(base64_encode(hash_hmac('sha256', Contract::hash([$key, $terms]), (string) config('app.key'), true)), '+/', '-_'), '=');
            $prior = $this->store->rows('events', $scope['tenant_id'])->where('sale_id', $key)->where('code', 'staff_reward_authorized')->first();
            if ($prior) {
                $saved = $this->store->decrypt($prior->details);
                if (! hash_equals(Contract::hash($saved['terms']), Contract::hash($terms))) {
                    throw new PosException('reward_confirmation_request_conflict', 409);
                }
                $row = $this->store->db->table('pos_reward_authorizations')->where('runtime_id', $this->store->runtime())
                    ->where('tenant_id', $scope['tenant_id'])->where('id', $saved['authorization_id'])->firstOrFail();
                if ($row->sale_id !== null || now()->greaterThanOrEqualTo($row->expires_at)) {
                    throw new PosException('reward_authorization_invalid', 403);
                }
                $expires = \Carbon\CarbonImmutable::parse($row->expires_at)->toIso8601String();
            } else {
                $id = (string) Str::uuid();
                $expires = now()->addSeconds(120)->toIso8601String();
                $this->store->db->table('pos_reward_authorizations')->insert([
                    'id' => $id, 'runtime_id' => $this->store->runtime(), 'tenant_id' => $scope['tenant_id'],
                    'marketing_profile_id' => $profile->id, 'token_hash' => hash('sha256', $token),
                    'cap_minor' => $effective['max_minor'], 'policy_version' => $policy['version'],
                    'disclosure_version' => self::DISCLOSURE, 'account_link_id' => null,
                    'expires_at' => now()->addSeconds(120), 'created_at' => now(), 'updated_at' => now(),
                ]);
                $this->store->event($scope['tenant_id'], $key, 'staff_reward_authorized', ['authorization_id' => $id, 'terms' => $terms]);
                // Stable local lookup; only the opaque nonsecret authorization ID is retained.
                $this->store->event($scope['tenant_id'], substr(hash('sha256', $id), 0, 32), 'staff_reward_identity', $terms);
            }

            return ['qr_payload' => 'everbranch-reward:v1:'.$token, 'expires_at' => $expires,
                'max_minor' => $effective['max_minor'], 'policy_version' => $policy['version'], 'disclosure_version' => self::DISCLOSURE];
        });
    }

    public function enabled(array $scope): bool
    {
        return config('pos_live.staff_rewards_enabled') === true
            && ($this->store->decrypt($scope['register']->configuration)['staff_rewards_enabled'] ?? false) === true
            && app(StockPosCommerce::class)->enabled($scope['register']);
    }

    public function valid(MarketingProfile $profile, object $authorization, ?object $sale = null): bool
    {
        if ($authorization->disclosure_version !== self::DISCLOSURE || $authorization->account_link_id !== null
            || (int) $authorization->tenant_id !== (int) $profile->tenant_id || (int) $authorization->marketing_profile_id !== (int) $profile->id) {
            return false;
        }
        $event = $this->store->rows('events', (int) $profile->tenant_id)
            ->where('sale_id', substr(hash('sha256', $authorization->id), 0, 32))->where('code', 'staff_reward_identity')->first();
        if (! $event) {
            return false;
        }
        $terms = $this->store->decrypt($event->details);
        $register = $this->store->rows('registers', (int) $profile->tenant_id)->where('id', $terms['register_id'])->where('active', true)->first();
        $user = User::query()->where('is_active', true)->whereNotNull('email_verified_at')->find($terms['actor_id']);
        $contact = app(PosVerifiedCustomerAccount::class)->contact($profile, $terms['channel']);
        $assignment = $this->store->rows('assignments', (int) $profile->tenant_id)->where('register_id', $terms['register_id'])
            ->where('user_id', $terms['actor_id'])->where('device_id', $terms['device_id'])->where('active', true)->first();
        if (! $register || ! $user || ! $assignment || ! in_array($assignment->role, ['cashier', 'manager', 'admin'], true)
            || ! $user->tenants()->where('tenants.id', $profile->tenant_id)->wherePivot('membership_active', true)->exists()
            || ! $this->enabled(['register' => $register]) || ! $contact || ! hash_equals($terms['contact_hash'], hash('sha256', $contact))
            || $terms['policy_version'] !== $authorization->policy_version) {
            return false;
        }
        if ($sale) {
            return $sale->register_id === $terms['register_id'] && (int) $sale->actor_id === $terms['actor_id']
                && app(PosService::class)->snapshot($sale)['device_id'] === $terms['device_id'];
        }
        $scope = $this->access->register($terms['register_id'], 'quote');

        return $scope['actor_id'] === $terms['actor_id'] && $scope['device_id'] === $terms['device_id'];
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Pos;

/** Reviewed registered devices only; never a publishing or additional-device grant. */
final class PosDevelopmentAcceptance
{
    public function __construct(private PosStore $store) {}

    public function session(object $register, object $connection, ?array $scope = null): ?array
    {
        $test = $this->store->decrypt($register->configuration)['development_acceptance'] ?? [];
        $evidence = $test['evidence'] ?? [];
        $ownerRequested = $this->ownerRequested($test);
        if (! is_array($evidence) || config('pos_live.development_acceptance_enabled') !== true || $connection->environment !== 'production'
            || ! is_string($test['id'] ?? null) || ! preg_match('/^[a-f0-9]{32}$/D', $test['id'])
            || (! $ownerRequested && (! is_int($test['max_amount_minor'] ?? null) || $test['max_amount_minor'] < 1 || $test['max_amount_minor'] > 1000))
            || ($ownerRequested && ($test['max_amount_minor'] ?? null) !== null)
            || ! is_string($test['issued_at'] ?? null) || ! is_string($test['expires_at'] ?? null)
            || strtotime($test['issued_at']) > time() || strtotime($test['expires_at']) <= time()
            || (! $ownerRequested && strtotime($test['expires_at']) > strtotime($test['issued_at']) + 7200)
            || ! is_int($test['actor_id'] ?? null) || ! is_string($test['device_id'] ?? null)
            || ! is_string($test['build'] ?? null) || $test['build'] !== config('pos_live.onboarding_build')
            || ! $this->reviewMatches($evidence, $register, $connection, $test['build'], $test['device_id'])
            || strtotime($test['expires_at']) > strtotime($evidence['profile_expires_at'])
            || ! $this->store->rows('assignments', (int) $register->tenant_id)->where('register_id', $register->id)
                ->where('user_id', $test['actor_id'])->where('device_id', $test['device_id'])->where('active', true)
                ->where('role', 'admin')->exists()
            || ! $this->store->db->table('tenant_user')->where('tenant_id', $register->tenant_id)->where('user_id', $test['actor_id'])
                ->where('membership_active', true)->whereIn('role', ['owner', 'tenant_owner', 'admin'])->exists()) {
            return null;
        }
        if ($scope !== null && ($scope['actor_id'] !== $test['actor_id'] || $scope['device_id'] !== $test['device_id']
            || $scope['build'] !== $test['build'] || ! in_array($scope['role'], ['owner', 'tenant_owner', 'admin'], true))) {
            return null;
        }

        return $test;
    }

    /** The owner can remove our rehearsal budget without widening the reviewed device scope. */
    public function ownerRequested(array $session): bool
    {
        $review = $session['evidence'] ?? [];

        return is_array($review) && ($session['owner_requested'] ?? false) === true
            && ($review['owner_requested_unrestricted_collection'] ?? false) === true
            && ($review['owner_actor_id'] ?? null) === ($session['actor_id'] ?? null)
            && is_string($review['owner_request_sha256'] ?? null)
            && preg_match('/^[a-f0-9]{64}$/D', $review['owner_request_sha256']) === 1;
    }

    /** Evidence is operator-reviewed, private and audited; this does not request Apple permissions. */
    public function reviewMatches(array $review, object $register, object $connection, string $build, string $device): bool
    {
        foreach (['grant_sha256', 'signature_report_sha256', 'installed_build_report_sha256', 'ipa_sha256', 'registered_udid_sha256'] as $key) {
            if (! is_string($review[$key] ?? null) || ! preg_match('/^[a-f0-9]{64}$/D', $review[$key])) {
                return false;
            }
        }

        return ($review['tenant_id'] ?? null) === (int) $register->tenant_id
            && ($review['register_id'] ?? null) === $register->id
            && ($review['merchant_id'] ?? null) === $connection->merchant_id
            && ($review['location_id'] ?? null) === $connection->provider_location_id
            && ($review['build'] ?? null) === $build && ($review['device_id'] ?? null) === $device
            && ($review['application_id'] ?? null) === config('pos_live.square_application_id')
            && ($review['bundle_id'] ?? null) === config('pos_live.bundle_id')
            && ($review['apple_team_id'] ?? null) === config('pos_live.apple_team_id')
            && ($review['development_profile_verified'] ?? null) === true
            && ($review['registered_device_verified'] ?? null) === true
            && ($review['installed_build_verified'] ?? null) === true
            && is_string($review['apple_case_id'] ?? null) && $review['apple_case_id'] !== ''
            && is_string($review['profile_uuid'] ?? null) && $review['profile_uuid'] !== ''
            && is_string($review['profile_expires_at'] ?? null) && strtotime($review['profile_expires_at']) > time();
    }

    /** Called under the existing register collection lock, before stock/provider mutations. */
    public function claim(array $scope, object $sale, array $snapshot): void
    {
        $settings = $this->store->decrypt($scope['register']->configuration);
        if (! isset($settings['development_acceptance'])) {
            return;
        }
        $test = $this->session($scope['register'], $scope['connection'], $scope);
        if (! $test || ($snapshot['development_acceptance_id'] ?? null) !== $test['id']
            || $snapshot['build'] !== $test['build'] || $snapshot['device_id'] !== $test['device_id']
            || (int) $sale->actor_id !== $test['actor_id']
            || (! $this->ownerRequested($test) && (int) $sale->amount_minor > $test['max_amount_minor'])) {
            throw new PosException('development_acceptance_not_ready', 503);
        }
        if ($this->ownerRequested($test)) {
            $this->store->event($scope['tenant_id'], $sale->id, 'registered_owner_collection',
                ['session_id' => $test['id'], 'actor_id' => $test['actor_id'], 'build' => $test['build']]);

            return;
        }
        if (isset($test['sale_id']) && $test['sale_id'] !== $sale->id) {
            throw new PosException('development_acceptance_single_sale_only', 409);
        }
        if (! isset($test['sale_id'])) {
            $settings['development_acceptance']['sale_id'] = $sale->id;
            $this->store->rows('registers', $scope['tenant_id'])->where('id', $scope['register']->id)
                ->update(['configuration' => $this->store->encrypt($settings)]);
            $this->store->event($scope['tenant_id'], $sale->id, 'development_acceptance_sale_claimed',
                ['session_id' => $test['id'], 'actor_id' => $scope['actor_id'], 'build' => $test['build']]);
        }
    }
}

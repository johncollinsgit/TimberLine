<?php

declare(strict_types=1);

namespace App\Services\Pos;

use App\Models\MarketingProfileLink;
use App\Services\Marketing\CustomerAccountFence;
use App\Services\Marketing\Messaging\TenantMessagingGateway;
use App\Services\Marketing\MessagingContactChannelStateService;
use Illuminate\Support\Str;

/** Possession of the saved contact, rather than a contact/name match, authorizes one capped purchase. */
final class PosRewardContactAuthorization
{
    public function __construct(private PosStore $store, private PosAccess $access, private PosCustomerDirectory $customers,
        private PosRewardRedemptionSettings $policies, private TenantMessagingGateway $messages) {}

    public function request(string $register, int $profileId, string $channel, string $request, bool $customerRequested): array
    {
        if (! $customerRequested || ! Str::isUuid($request) || ! in_array($channel, ['phone', 'email'], true)) {
            throw new PosException('reward_contact_request_required', 422);
        }
        $scope = $this->access->register($register, 'quote');
        if (! $this->policies->availableForRegister($scope)) {
            throw new PosException('in_person_rewards_unavailable', 503);
        }
        $this->policies->forRegister($scope);
        $profile = $this->customers->profile($scope['tenant_id'], $profileId);
        $recipient = app(PosVerifiedCustomerAccount::class)->contact($profile, $channel);
        if (! $recipient) {
            throw new PosException('reward_saved_contact_required', 422);
        }
        if ($channel === 'phone' && app(MessagingContactChannelStateService::class)->resolveSmsStatus($scope['tenant_id'], $profile, $recipient) === 'unsubscribed') {
            throw new PosException('reward_contact_opted_out', 422);
        }
        $prefix = substr(hash('sha256', $this->store->runtime().'|'.$scope['tenant_id'].'|'.$profileId), 0, 8);
        $id = $prefix.substr(hash('sha256', $register.'|'.strtolower($request)), 0, 24);
        $code = (string) random_int(100000, 999999);
        $claimed = app(CustomerAccountFence::class)->forTenant($scope['tenant_id'], function () use ($scope, $profile, $channel, $recipient, $id, $code, $prefix) {
            $previous = $this->record($scope['tenant_id'], $id, 'reward_contact_requested');
            if ($previous) {
                if ($previous['profile_id'] !== (int) $profile->id || $previous['channel'] !== $channel || $previous['contact_hash'] !== hash('sha256', $recipient)) {
                    throw new PosException('reward_contact_request_conflict', 409);
                }

                return false;
            }
            // Staff cannot create an unbounded stream of messages for a customer.
            $recent = $this->store->rows('events', $scope['tenant_id'])->where('code', 'reward_contact_requested')->where('sale_id', 'like', $prefix.'%')->where('created_at', '>', now()->subHour())->get()
                ->filter(fn ($event) => ($this->store->decrypt($event->details)['profile_id'] ?? null) === (int) $profile->id);
            if ($recent->count() >= 5) {
                throw new PosException('reward_contact_rate_limited', 429);
            }
            foreach ($recent as $event) {
                if (! $this->record($scope['tenant_id'], $event->sale_id, 'reward_contact_verified')) {
                    $this->store->event($scope['tenant_id'], $event->sale_id, 'reward_contact_superseded');
                }
            }
            $policy = $this->policies->forProfile($profile);
            $this->store->event($scope['tenant_id'], $id, 'reward_contact_requested', ['profile_id' => (int) $profile->id,
                'register_id' => $scope['register']->id, 'actor_id' => $scope['actor_id'], 'channel' => $channel,
                'contact_hash' => hash('sha256', $recipient), 'code_hash' => $this->codeHash($id, $code), 'max_minor' => $policy['max_minor'],
                'policy_version' => $policy['version'], 'expires_at' => now()->addMinutes(5)->toIso8601String()]);

            return true;
        });
        $claim = $this->record($scope['tenant_id'], $id, 'reward_contact_requested');
        if ($claimed) {
            $amount = '$'.number_format($claim['max_minor'] / 100, 2, '.', '');
            $label = data_get(app(PosCustomerRewards::class)->policy($scope['tenant_id']), 'program.label', 'Rewards');
            $text = "Your {$label} code is {$code}. Share it only with your cashier to authorize up to {$amount} from your saved rewards for ONE in-person purchase. Expires in 5 minutes. No marketing signup.";
            try {
                $options = ['source_type' => 'pos_reward_authorization', 'idempotency_key' => 'pos-reward-contact:'.$id];
                $result = $channel === 'phone' ? $this->messages->sendSms($scope['tenant_id'], $recipient, $text, $options)
                    : $this->messages->sendEmail($scope['tenant_id'], $recipient, 'Authorize your in-person rewards', $text, $options);
                $accepted = ($result['success'] ?? false) === true;
            } catch (\Throwable) {
                $accepted = false;
            }
            $this->store->event($scope['tenant_id'], $id, 'reward_contact_send_result', ['state' => $accepted ? 'requested' : 'not_confirmed']);
        }

        return ['challenge_id' => $id, 'expires_at' => $claim['expires_at'], 'max_minor' => $claim['max_minor'],
            'state' => $this->record($scope['tenant_id'], $id, 'reward_contact_send_result')['state'] ?? 'not_confirmed'];
    }

    public function verify(string $register, string $id, string $code): array
    {
        $scope = $this->access->register($register, 'quote');
        if (! $this->policies->availableForRegister($scope)) {
            throw new PosException('in_person_rewards_unavailable', 503);
        }
        $this->policies->forRegister($scope);
        if (! preg_match('/^[a-f0-9]{32}$/D', $id) || ! preg_match('/^[0-9]{6}$/D', $code)) {
            throw new PosException('reward_contact_code_invalid', 422);
        }
        $result = app(CustomerAccountFence::class)->forTenant($scope['tenant_id'], function () use ($scope, $id, $code) {
            $claim = $this->record($scope['tenant_id'], $id, 'reward_contact_requested');
            if (! $claim || $claim['register_id'] !== $scope['register']->id || now()->greaterThanOrEqualTo($claim['expires_at'])
                || $this->record($scope['tenant_id'], $id, 'reward_contact_verified')
                || $this->record($scope['tenant_id'], $id, 'reward_contact_superseded')
                || $this->store->rows('events', $scope['tenant_id'])->where('sale_id', $id)->where('code', 'reward_contact_failed')->count() >= 6) {
                return ['error' => 'reward_contact_code_expired'];
            }
            if (! hash_equals($claim['code_hash'], $this->codeHash($id, $code))) {
                $this->store->event($scope['tenant_id'], $id, 'reward_contact_failed');

                return ['error' => 'reward_contact_code_invalid'];
            }
            $profile = $this->customers->profile($scope['tenant_id'], $claim['profile_id']);
            $accounts = app(PosVerifiedCustomerAccount::class);
            $recipient = $accounts->contact($profile, $claim['channel']);
            if (! $recipient || ! hash_equals($claim['contact_hash'], hash('sha256', $recipient))) {
                return ['error' => 'reward_contact_changed'];
            }
            $expiry = now()->addMinutes(15)->toIso8601String();
            $meta = ['verification_method' => 'pos_contact_otp_v1', 'verified_profile_id' => (int) $profile->id, 'channel' => $claim['channel'],
                'contact_hash' => $claim['contact_hash'], 'expires_at' => $expiry,
                'signature' => $accounts->contactSignature($scope['tenant_id'], (int) $profile->id, $id, $claim['channel'], $claim['contact_hash'], $expiry)];
            $link = MarketingProfileLink::create(['tenant_id' => $scope['tenant_id'], 'marketing_profile_id' => $profile->id,
                'source_type' => PosVerifiedCustomerAccount::CONTACT_SOURCE, 'source_id' => $id, 'source_meta' => $meta,
                'match_method' => 'customer_contact_possession', 'confidence' => 1]);
            $proof = app(PosRewardCustomerProof::class)->issueForAuthenticatedProfile($profile, ['max_minor' => $claim['max_minor'],
                'policy_version' => $claim['policy_version'], 'disclosure_version' => PosRewardCustomerProof::DISCLOSURE_VERSION], (int) $link->id);
            $this->store->event($scope['tenant_id'], $id, 'reward_contact_verified', ['account_link_id' => $link->id]);

            return $proof;
        });
        if (isset($result['error'])) {
            throw new PosException($result['error'], 422);
        }

        return $result;
    }

    private function codeHash(string $id, string $code): string
    {
        return hash_hmac('sha256', 'pos-reward-code|'.$id.'|'.$code, (string) config('app.key'));
    }

    private function record(int $tenant, string $id, string $code): ?array
    {
        $row = $this->store->rows('events', $tenant)->where('sale_id', $id)->where('code', $code)->first();

        return $row ? $this->store->decrypt($row->details) : null;
    }
}

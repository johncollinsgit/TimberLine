<?php

namespace App\Services\Marketing\Messaging;

use App\Models\TenantMessagingAccount;
use App\Models\TenantMessagingSenderProfile;
use App\Services\Marketing\MessagingEmailReplyAddressService;
use RuntimeException;

class TenantMessagingSenderProfileService
{
    public function __construct(
        protected MessagingEmailReplyAddressService $replyAddressService,
    ) {
    }

    /**
     * @return array{profile:TenantMessagingSenderProfile,from_email:string,from_name:string,reply_to_email:string,reply_mode:string}
     */
    public function resolveEmailSender(
        TenantMessagingAccount $account,
        ?int $profileId = null,
        ?string $storeKey = null,
        ?int $deliveryId = null,
    ): array {
        $query = TenantMessagingSenderProfile::query()
            ->forAllTenants()
            ->where('tenant_id', $account->tenant_id)
            ->where('tenant_messaging_account_id', $account->id)
            ->where('channel', 'email')
            ->where('verification_status', 'verified');

        if ($profileId !== null) {
            $profile = (clone $query)->whereKey($profileId)->first();
        } else {
            $profile = (clone $query)
                ->when($storeKey !== null, fn ($builder) => $builder->where(function ($nested) use ($storeKey): void {
                    $nested->where('store_key', $storeKey)->orWhereNull('store_key');
                }))
                ->orderByRaw('CASE WHEN store_key = ? THEN 0 WHEN store_key IS NULL THEN 1 ELSE 2 END', [$storeKey])
                ->orderByDesc('is_default')
                ->orderBy('id')
                ->first();
        }

        if (! $profile) {
            throw new RuntimeException('No verified sender profile is available for this tenant.');
        }

        $fromEmail = strtolower(trim((string) $profile->from_email));
        $fromDomain = strtolower((string) strrchr($fromEmail, '@'));
        $fromDomain = ltrim($fromDomain, '@');
        $authenticatedDomain = strtolower(trim((string) ($profile->authenticated_domain ?: $account->authenticated_domain)));

        if ($fromDomain === '' || $authenticatedDomain === '' || $fromDomain !== $authenticatedDomain) {
            throw new RuntimeException('The sender address must use the tenant authenticated domain.');
        }

        $replyMode = strtolower(trim((string) $profile->reply_mode));
        if (! in_array($replyMode, ['direct_inbox', 'everbranch_inbox'], true)) {
            throw new RuntimeException('The sender profile has an invalid reply mode.');
        }

        $replyTo = $replyMode === 'everbranch_inbox'
            ? $this->replyAddressService->replyAddressForDelivery((int) $account->tenant_id, (int) $deliveryId)
            : trim((string) $profile->reply_to_email);

        if ($replyTo === '') {
            throw new RuntimeException('The selected reply mode does not have a valid reply destination.');
        }

        return [
            'profile' => $profile,
            'from_email' => $fromEmail,
            'from_name' => trim((string) $profile->display_name),
            'reply_to_email' => $replyTo,
            'reply_mode' => $replyMode,
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Pos;

/**
 * Intentionally has no production binding. A future implementation must verify
 * customer-present authorization bound to the tenant, customer, original sale
 * and an unexpired authorization, and derive every value from the tenant's
 * server-side, frozen sale and published rewards policy, never from the client.
 * The authorization reference must be a non-secret identifier or hash; never
 * return the raw QR bearer token or another credential for storage.
 * Verification must be local and must not make network calls inside the ledger
 * transaction; any external evidence must be retrieved before invoking it.
 */
interface PosRewardReservationAuthority
{
    /**
     * @return array{profile_id:int, expected_amount_minor:int, limit_minor:int, eligible_minor:int, policy_version:string, authorization_reference:string}
     */
    public function authorize(int $tenant, object $sale, string $customerProof, int $amountMinor): array;
}

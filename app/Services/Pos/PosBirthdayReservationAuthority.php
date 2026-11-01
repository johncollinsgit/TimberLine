<?php

declare(strict_types=1);

namespace App\Services\Pos;

/**
 * Deliberately unbound: a future implementation must verify customer-present
 * authorization bound to tenant, profile, original sale, nonce and expiry;
 * obtain the terms from a published tenant policy and frozen quote, not client
 * input; and establish uncovered annual-cycle eligibility under the same lock
 * used by online issuance. Existing, legacy, or uncertain online coverage must
 * not be converted into a POS benefit. No provider calls may run under a DB lock.
 * Persist only a non-secret proof reference, never the QR bearer credential.
 */
interface PosBirthdayReservationAuthority
{
    /** @return array{profile_id:int, cycle_year:int, amount_minor:int, policy_version:string, authorization_reference:string} */
    public function authorize(int $tenant, object $sale, string $customerProof): array;
}

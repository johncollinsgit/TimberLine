<?php

declare(strict_types=1);

namespace App\Services\Pos;

/** Pure transition rules, not a reservation store or a payment evidence verifier. */
final class PosBirthdayLifecyclePolicy
{
    public function next(string $state, string $event): string
    {
        // The caller must verify the original provider operation and binding. In
        // particular, a local timeout, SDK dismissal or unknown callback is never
        // proof of an unsuccessful payment, and refund never restores a benefit.
        if (! in_array($state, ['held', 'consumed', 'released', 'review'], true)
            || ! in_array($event, ['verified_payment_succeeded', 'verified_terminal_unpaid', 'verified_unstarted_discard', 'payment_uncertain', 'verified_refund'], true)) {
            throw new PosException('birthday_lifecycle_invalid', 422);
        }
        if ($state === 'review') {
            return 'review';
        }
        if ($event === 'payment_uncertain') {
            return $state === 'released' ? 'review' : $state;
        }
        if ($event === 'verified_payment_succeeded') {
            return $state === 'released' ? 'review' : 'consumed';
        }
        if (in_array($event, ['verified_terminal_unpaid', 'verified_unstarted_discard'], true)) {
            return $state === 'consumed' ? 'review' : 'released';
        }

        return 'review'; // Refunds, including partial refunds, need an explicit policy decision.
    }
}

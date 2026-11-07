<?php

declare(strict_types=1);

namespace App\Services\Pos;

use App\Services\PaymentsCore\PaymentJournal;

/** Payment reads preserve the original source; a rewards-only receipt never fabricates provider evidence. */
final class PosSalePaymentEvidence
{
    public function __construct(private PaymentJournal $journal, private PosRewardOnlySale $rewardsOnly) {}

    public function read(object $sale, array $snapshot): ?array
    {
        return ($snapshot['reward_only'] ?? false) ? $this->rewardsOnly->evidence($sale, $snapshot)
            : ($sale->attempt_id ? $this->journal->get((int) $sale->tenant_id, $sale->attempt_id) : null);
    }
}

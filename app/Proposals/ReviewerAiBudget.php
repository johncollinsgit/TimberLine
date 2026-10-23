<?php

namespace App\Proposals;

use App\Enterprise\Trial\TrialBudget;
use App\Models\TeamOperationRecord;

/** Reuses the ONE campaign's locking/accounting; EVERY inherited path requires the explicit existing ledger. */
final class ReviewerAiBudget extends TrialBudget
{
    public function __construct(private ReviewerAiStore $reviewerStore)
    {
        parent::__construct($reviewerStore);
    }

    protected function baseline(): array
    {
        return $this->reviewerStore->baseline();
    }

    private function approved(?string $requested): string
    {
        $pinned = $this->reviewerStore->ledgerId();
        abort_unless($requested === null || hash_equals($pinned, $requested), 503, 'The lifetime ledger pin cannot change.');

        return $pinned;
    }

    protected function ledger(?string $approvedLedgerId = null): TeamOperationRecord
    {
        $pinned = $this->approved($approvedLedgerId);
        // Lock the existing row against deletion/replacement during this accounting transaction.
        $ledgers = TeamOperationRecord::query()->where('kind', 'ent_trial_budget')->lockForUpdate()->limit(2)->get();
        abort_unless($ledgers->count() === 1 && $ledgers->first()->id === $pinned, 503, 'The pinned lifetime ledger is unavailable. No replacement will be created.');

        return parent::ledger($pinned);
    }

    public function reserve(string $channel, string $key, string $inputHash, int $maximum, ?string $approvedLedgerId = null): array
    {
        abort_unless(in_array($channel, ['ai', 'acceptance'], true), 422, 'This adapter admits only the reviewed text AI path.');

        return parent::reserve($channel, $key, $inputHash, $maximum, $this->approved($approvedLedgerId));
    }

    public function settle(string $id, string $status, array $outcome, ?int $actual = null, ?string $approvedLedgerId = null): void
    {
        // The legacy nullable default must not skip the existing-ledger check here.
        parent::settle($id, $status, $outcome, $actual, $this->approved($approvedLedgerId));
    }
}

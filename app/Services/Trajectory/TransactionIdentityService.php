<?php

namespace App\Services\Trajectory;

use App\Models\Trajectory\Account;
use App\Models\Trajectory\Record;
use App\Models\Trajectory\Space;
use App\Models\Trajectory\Transaction;
use App\Models\User;

/** Read source identity only after current financial access to both sides of a cost. */
class TransactionIdentityService
{
    public function decorate(User $viewer, Space $target, array $entries): array
    {
        if ($entries === []) {
            return [];
        }
        $access = app(FinanceAccess::class);
        $viewer = $viewer->fresh();
        $freshTarget = $target->fresh();
        abort_unless($viewer && $freshTarget, 403);
        $access->authorize($viewer, $freshTarget);
        $sources = Transaction::whereIn('id', array_unique(array_column($entries, 'id')))->where('removed', false)->get(['id', 'space_id', 'account_id', 'posted_on', 'amount_cents', 'merchant'])->keyBy('id');
        $spaces = Space::whereIn('id', $sources->pluck('space_id')->push($target->id)->unique())->get()->keyBy('id');
        $allowed = $spaces->mapWithKeys(fn ($space) => [$space->id => $access->allows($viewer, $space)]);
        $allowedIds = $allowed->filter()->keys();
        $accounts = Account::whereIn('space_id', $allowedIds)->whereIn('id', $sources->pluck('account_id'))->get()->keyBy('id');
        $evidence = Record::whereIn('space_id', $allowedIds)->where('kind', 'transaction_source_identity')->where('active', true)->get()
            ->filter(fn ($record) => isset($record->data['transaction_id']) && $sources->has($record->data['transaction_id']))
            ->groupBy(fn ($record) => $record->data['transaction_id']);
        foreach ($entries as &$entry) {
            $source = $sources->get($entry['id']);
            $sourceSpace = $source ? $spaces->get($source->space_id) : null;
            $authorized = $sourceSpace && ($allowed[$sourceSpace->id] ?? false);
            $shared = $sourceSpace && $sourceSpace->id !== $target->id;
            $cost = $entry['amount_cents'] < 0 && in_array($entry['flow'] ?? null, ['expense', 'medical_payment', 'medical_membership'], true);
            $fallback = $entry['amount_cents'] > 0 ? 'Shared receipt' : ($cost
                ? ($target->kind === 'household' ? 'Household purchase paid from another space' : 'Business cost paid from another space')
                : 'Shared payment');
            if (! $authorized) {
                if ($shared || ! ($entry['editable'] ?? false)) {
                    $entry['merchant'] = $fallback;
                }
                $entry['source_identity'] = ['authorized_source' => false, 'paid_by_label' => $fallback,
                    'source_space_id' => null, 'source_space_name' => null, 'account_id' => null, 'account_name' => null,
                    'account_kind' => null, 'card_last4' => null, 'card_type' => null, 'card_label' => null, 'card_evidence_status' => 'not_recorded'];

                continue;
            }
            $merchant = $source->space_id === $target->id ? $entry['merchant'] : $source->merchant;
            $entry['merchant'] = $merchant;
            $account = $accounts->get($source->account_id);
            $records = $evidence->get($source->id, collect());
            $fingerprint = $records->isEmpty() ? null : app(SalesTaxService::class)->bankFingerprint($source->space_id, $source->account_id, $source->id,
                $source->posted_on->toDateString(), $source->amount_cents, $merchant);
            $valid = $records->filter(fn ($record) => $record->space_id === $source->space_id
                && ($record->data['reviewed'] ?? false) === true
                && is_string($record->data['bank_source_fingerprint'] ?? null)
                && $fingerprint !== null && hash_equals($fingerprint, $record->data['bank_source_fingerprint'])
                && preg_match('/^\d{4}$/', $record->data['card_last4'] ?? '')
                && ($record->data['observed_on'] ?? '2200-01-01') <= now($sourceSpace->timezone)->toDateString());
            // Duplicate or stale evidence never silently chooses a physical card.
            $card = $records->count() === 1 && $valid->count() === 1 ? $valid->first()->data : null;
            $entry['source_identity'] = ['authorized_source' => true, 'source_space_id' => $sourceSpace->id,
                'source_space_name' => $sourceSpace->name, 'account_id' => $account?->id, 'account_name' => $account?->name,
                'account_kind' => $account?->kind, 'card_last4' => $card['card_last4'] ?? null,
                'card_type' => $card['card_type'] ?? null, 'card_label' => $card['card_label'] ?? null,
                'card_evidence_status' => $card ? 'verified' : ($records->isEmpty() ? 'not_recorded' : 'needs_review'),
                'paid_by_label' => $shared ? ($cost ? ($target->kind === 'household' ? 'Household purchase paid by ' : 'Business cost paid by ') : ($entry['amount_cents'] > 0 ? 'Receipt from ' : 'Payment made by ')).$sourceSpace->name : null];
        }
        unset($entry);

        return $entries;
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Pos;

use App\Models\CandleCashBalance;
use App\Models\CandleCashTransaction;
use App\Services\Marketing\CanonicalMarketingProfileResolver;
use App\Services\PaymentsCore\Contract;

/** Read the original purchase terms and the existing wallet; never award from a receipt read. */
final class PosReceiptRewards
{
    public function __construct(private PosStore $store, private CanonicalMarketingProfileResolver $identities) {}

    public function forSale(object $sale, array $snapshot): ?array
    {
        $tenant = (int) $sale->tenant_id;
        $terms = $snapshot['customer_rewards'] ?? [];
        if (($terms['enabled'] ?? false) !== true) {
            return null;
        }
        $label = trim((string) data_get($terms, 'policy.program_identity.program_name', data_get($terms, 'program.label', 'Rewards')));
        $profile = $this->identities->byId($tenant, (int) ($snapshot['customer']['id'] ?? 0));
        $balance = $profile ? CandleCashBalance::query()->where('marketing_profile_id', $profile->id)->value('balance') : null;
        $prefix = substr(Contract::hash([$this->store->runtime(), $tenant, $sale->id]), 0, 40);
        $earned = $profile ? (int) round(CandleCashTransaction::query()->where('marketing_profile_id', $profile->id)
            ->where('source', 'everbranch_pay')->where('source_id', 'like', $prefix.':%')->sum('candle_cash_delta') * 100) : null;
        $redemption = $snapshot['terms']['pos_candle_cash'] ?? null;
        $rules = [];
        if (isset($terms['rules']['purchase_earn_bps'])) {
            $rules[] = number_format($terms['rules']['purchase_earn_bps'] / 100, 2, '.', '').'% back on eligible merchandise after discounts, before tax.';
        }
        if (($terms['member'] ?? false) && ($terms['rules']['candle_club_multiplier_enabled'] ?? false)) {
            $rules[] = 'Member earning multiplier: '.(string) ($terms['rules']['candle_club_multiplier_value'] ?? 1).'×.';
        }
        // Only disclose a cap frozen with this purchase, not another tenant's or today's policy.
        if (isset($terms['in_person_max_minor'])) {
            $rules[] = 'Use up to $'.number_format($terms['in_person_max_minor'] / 100, 2, '.', '').' per in-person purchase, limited to your balance and eligible merchandise.';
        }
        if (isset($terms['in_person_member_max_minor']) && $terms['in_person_member_max_minor'] !== ($terms['in_person_max_minor'] ?? null)) {
            $rules[] = 'Verified active members may use up to $'.number_format($terms['in_person_member_max_minor'] / 100, 2, '.', '').' per in-person purchase, limited to their balance and eligible merchandise.';
        }
        if (($terms['redemption'] ?? null) === 'online_only' && ! isset($terms['in_person_max_minor'])) {
            $rules[] = 'Rewards can be redeemed online under this program’s rules.';
        }

        return ['label' => $label ?: 'Rewards', 'balance_minor' => $balance === null ? null : (int) round($balance * 100),
            'earned_minor' => $earned, 'redeemed_minor' => is_array($redemption) ? (int) ($redemption['amount_minor'] ?? 0) : 0,
            'rules' => $rules, 'balance_as_of' => now()->toIso8601String()];
    }
}

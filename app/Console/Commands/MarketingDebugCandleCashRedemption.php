<?php

namespace App\Console\Commands;

use App\Models\CandleCashRedemption;
use App\Models\CustomerExternalProfile;
use App\Models\MarketingProfile;
use App\Models\MarketingProfileLink;
use App\Models\MarketingStorefrontEvent;
use App\Services\Marketing\CandleCashAccessGate;
use App\Services\Marketing\CandleCashService;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

class MarketingDebugCandleCashRedemption extends Command
{
    protected $signature = 'marketing:debug-candle-cash-redeem
        {--email= : Customer email}
        {--profile= : Marketing profile id}
        {--limit=5 : Number of recent rows to display}';

    protected $description = 'Inspect Candle Cash redemption readiness for a specific customer.';

    public function handle(CandleCashService $candleCashService, CandleCashAccessGate $accessGate): int
    {
        $email = trim((string) $this->option('email'));
        $profileId = (int) $this->option('profile');
        $limit = max(1, (int) $this->option('limit'));

        if ($profileId <= 0 && $email === '') {
            $this->error('Provide --email or --profile.');
            return self::FAILURE;
        }

        $profile = $profileId > 0
            ? MarketingProfile::query()->find($profileId)
            : MarketingProfile::query()->where('normalized_email', strtolower($email))->first();

        if (! $profile) {
            $this->warn('No marketing profile found.');
            return self::SUCCESS;
        }

        $balance = $candleCashService->currentBalance($profile);
        $access = $accessGate->storefrontRedeemAccessPayload($profile);
        $reward = $candleCashService->storefrontReward();
        $openIssuedCount = CandleCashRedemption::query()
            ->where('marketing_profile_id', $profile->id)
            ->where('status', 'issued')
            ->where(function ($query): void {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->count();

        $this->line('Profile: ' . $profile->id . ' ' . ($profile->email ?: ''));
        $this->line('Balance: ' . number_format($balance, 3, '.', ''));
        $this->line('Allowlist: ' . (($access['redeem_enabled'] ?? false) ? 'allowed' : 'blocked'));
        $this->line('CTA: ' . ($access['cta_label'] ?? ''));
        $this->line('Reward: ' . ($reward?->id ? ('#' . $reward->id . ' ' . $reward->name) : 'none'));
        $this->line('Open issued codes: ' . $openIssuedCount . ' / ' . $candleCashService->maxOpenStorefrontCodes());

        $links = MarketingProfileLink::query()
            ->where('marketing_profile_id', $profile->id)
            ->where('source_type', 'shopify_customer')
            ->pluck('source_id')
            ->values();
        $externalStores = CustomerExternalProfile::query()
            ->where('marketing_profile_id', $profile->id)
            ->pluck('store_key')
            ->values();

        $this->line('Shopify links: ' . ($links->isNotEmpty() ? $links->implode(', ') : 'none'));
        $this->line('External stores: ' . ($externalStores->isNotEmpty() ? $externalStores->implode(', ') : 'none'));

        $issued = CandleCashRedemption::query()
            ->where('marketing_profile_id', $profile->id)
            ->where('status', 'issued')
            ->orderByDesc('id')
            ->limit($limit)
            ->get(['id', 'platform', 'status', 'redemption_code', 'issued_at', 'expires_at', 'candle_cash_spent', 'reward_id', 'redemption_context']);

        $this->line('Issued redemptions: ' . $issued->count());
        $issuedRows = $issued->map(function (CandleCashRedemption $row) use ($reward, $candleCashService) {
            return [
                'id' => $row->id,
                'platform' => $row->platform,
                'status' => $row->status,
                'redemption_code' => $row->redemption_code,
                'issued_at' => $row->issued_at,
                'expires_at' => $row->expires_at,
                'candle_cash_spent' => $row->candle_cash_spent,
                'reward_id' => $row->reward_id,
                'matches_rules' => $reward ? $candleCashService->storefrontRedemptionMatchesCurrentRules($row, $reward) : null,
            ];
        });
        $this->outputRows($issuedRows, ['id', 'platform', 'status', 'redemption_code', 'issued_at', 'expires_at', 'candle_cash_spent', 'reward_id', 'matches_rules']);

        $recent = CandleCashRedemption::query()
            ->where('marketing_profile_id', $profile->id)
            ->orderByDesc('id')
            ->limit($limit)
            ->get(['id', 'platform', 'status', 'redemption_code', 'issued_at', 'redeemed_at', 'expires_at', 'candle_cash_spent', 'reward_id']);

        $this->line('Recent redemptions: ' . $recent->count());
        $this->outputRows($recent, ['id', 'platform', 'status', 'redemption_code', 'issued_at', 'redeemed_at', 'expires_at', 'candle_cash_spent', 'reward_id']);

        $events = MarketingStorefrontEvent::query()
            ->where('marketing_profile_id', $profile->id)
            ->whereIn('event_type', ['widget_redeem_request', 'public_reward_redeem'])
            ->orderByDesc('id')
            ->limit($limit)
            ->get(['id', 'event_type', 'status', 'issue_type', 'endpoint', 'occurred_at']);

        $this->line('Recent redeem events: ' . $events->count());
        $this->outputRows($events, ['id', 'event_type', 'status', 'issue_type', 'endpoint', 'occurred_at']);

        return self::SUCCESS;
    }

    /**
     * @param \Illuminate\Support\Collection<int, mixed> $rows
     * @param array<int, string> $keys
     */
    protected function outputRows(Collection $rows, array $keys): void
    {
        if ($rows->isEmpty()) {
            $this->line('  none');
            return;
        }

        $this->table($keys, $rows->map(function ($row) use ($keys) {
            $payload = [];
            foreach ($keys as $key) {
                $payload[$key] = data_get($row, $key);
            }
            return $payload;
        })->values());
    }
}

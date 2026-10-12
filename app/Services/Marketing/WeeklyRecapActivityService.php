<?php

namespace App\Services\Marketing;

use App\Models\BirthdayRewardIssuance;
use App\Models\CandleCashRedemption;
use App\Models\CandleCashTransaction;
use App\Models\MarketingEmailDelivery;
use App\Models\MarketingProfile;
use App\Models\MarketingStorefrontEvent;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class WeeklyRecapActivityService
{
    public const METRICS = [
        'customers_added' => 'Customers added',
        'unsubscribed' => 'Customers unsubscribed',
        'birthday_issued' => 'Birthday gifts issued',
        'birthday_redeemed' => 'Birthday gifts redeemed',
        'candle_cash_added' => 'Candle Cash added',
        'candle_cash_issued' => 'Candle Cash codes issued',
        'candle_cash_redeemed' => 'Candle Cash codes redeemed',
        'emails_sent' => 'Emails sent',
        'emails_failed' => 'Emails failed',
        'wishlist_added' => 'Wishlist items added',
        'storefront_health' => 'Storefront health',
    ];

    public function links(int $tenantId, CarbonInterface $start, CarbonInterface $end, bool $exclusiveEnd = true): array
    {
        $links = [];
        foreach (self::METRICS as $metric => $label) {
            $links[$metric] = rtrim(config('app.url'), '/').route('marketing.weekly-recap.activity', [
                'metric' => $metric, 'tenant' => $tenantId,
                'started_at' => $start->toIso8601String(), 'ended_at' => $end->toIso8601String(),
                'exclusive_end' => (int) (in_array($metric, ['customers_added', 'unsubscribed', 'birthday_issued', 'birthday_redeemed', 'emails_sent', 'emails_failed'], true) || $exclusiveEnd),
            ], false);
        }

        return $links;
    }

    public function query(string $metric, int $tenantId, CarbonInterface $start, CarbonInterface $end, bool $exclusiveEnd = true): Builder
    {
        $operator = $exclusiveEnd ? '<' : '<=';
        $query = match ($metric) {
            'customers_added' => $this->newCustomers($tenantId, $start, $end),
            'birthday_issued', 'birthday_redeemed' => BirthdayRewardIssuance::query()
                ->whereHas('marketingProfile', fn ($q) => $q->where('tenant_id', $tenantId))->with('marketingProfile'),
            'candle_cash_added' => CandleCashTransaction::query()
                ->whereHas('profile', fn ($q) => $q->where('tenant_id', $tenantId))->where('candle_cash_delta', '>', 0)->with('profile'),
            'candle_cash_issued', 'candle_cash_redeemed' => CandleCashRedemption::query()
                ->whereHas('profile', fn ($q) => $q->where('tenant_id', $tenantId))->with('profile', 'reward'),
            'emails_sent', 'emails_failed' => MarketingEmailDelivery::query()->forTenantId($tenantId)
                ->where('campaign_type', '!=', 'operator_recap')->with('profile'),
            'wishlist_added' => MarketingStorefrontEvent::query()->forTenantId($tenantId)
                ->where('event_type', 'widget_wishlist_add')->where('status', 'ok')->with('profile'),
            'storefront_health' => MarketingStorefrontEvent::query()->forTenantId($tenantId)->where('status', 'error')
                ->where(fn ($q) => $q->where('event_type', 'like', 'reward_%')->orWhereIn('event_type', ['widget_wishlist_add', 'widget_wishlist_remove']))->with('profile'),
            default => throw new \InvalidArgumentException('Unknown weekly recap activity.'),
        };
        if ($metric === 'customers_added') {
            return $query;
        }
        $column = match ($metric) {
            'birthday_issued', 'candle_cash_issued' => 'issued_at',
            'birthday_redeemed', 'candle_cash_redeemed' => 'redeemed_at',
            'candle_cash_added' => 'created_at',
            'emails_sent' => 'sent_at',
            'emails_failed' => 'failed_at',
            default => 'occurred_at',
        };

        return $query->where($column, '>=', $start)->where($column, $operator, $end)->orderByDesc($column)->orderByDesc('id');
    }

    public function newCustomers(int $tenantId, CarbonInterface $start, CarbonInterface $end): Builder
    {
        // Same address is one customer; a newly imported duplicate of an older address is not new.
        $email = "COALESCE(NULLIF(LOWER(TRIM(marketing_profiles.normalized_email)), ''), LOWER(TRIM(marketing_profiles.email)), '')";
        $priorEmail = "COALESCE(NULLIF(LOWER(TRIM(prior.normalized_email)), ''), LOWER(TRIM(prior.email)), '')";

        return MarketingProfile::query()->forTenantId($tenantId)
            ->where('created_at', '>=', $start)->where('created_at', '<', $end)
            ->where(function ($q) use ($tenantId, $start, $end, $email, $priorEmail): void {
                $q->whereRaw($email." = ''")->orWhereNotExists(function ($prior) use ($tenantId, $start, $end, $email, $priorEmail): void {
                    $prior->selectRaw('1')->from('marketing_profiles as prior')->where('prior.tenant_id', $tenantId)
                        ->whereRaw($priorEmail.' = '.$email)
                        ->where(fn ($p) => $p->where('prior.created_at', '<', $start)
                            ->orWhere(fn ($inside) => $inside->where('prior.created_at', '<', $end)->whereColumn('prior.id', '<', 'marketing_profiles.id')));
                });
            })->orderByDesc('created_at')->orderByDesc('id');
    }

    public function unsubscribes(int $tenantId, CarbonInterface $start, CarbonInterface $end): Collection
    {
        $unsubscribes = \App\Models\MarketingConsentEvent::query()->forTenantId($tenantId)
            ->where('channel', 'email')->whereIn('event_type', ['unsubscribed', 'revoked'])
            ->where('occurred_at', '>=', $start)->where('occurred_at', '<', $end)
            ->with(['profile' => fn ($query) => $query->forTenantId($tenantId)])->orderBy('occurred_at')->get()->toBase()
            ->map(fn ($event): array => [
                'email' => strtolower(trim((string) (data_get($event->details, 'metadata.email') ?: $event->profile?->email))),
                'profile_id' => $event->profile?->id,
                'name' => trim((string) $event->profile?->first_name.' '.(string) $event->profile?->last_name),
                'at' => $event->occurred_at->setTimezone('America/New_York')->format('M j, g:i a T'),
            ])->filter(fn (array $row): bool => $row['email'] !== '')->unique('email')->values();
        // Retain notifications even if the recipient's profile was deleted or its
        // email changed after the original message. Repeated POSTs do not reset this timestamp.
        $addressOptouts = \App\Models\MessagingContactChannelState::query()->forTenantId($tenantId)
            ->where('email_status', 'unsubscribed')->where('email_status_changed_at', '>=', $start)->where('email_status_changed_at', '<', $end)
            ->with(['profile' => fn ($query) => $query->forTenantId($tenantId)])->get()->toBase()->map(fn ($state): array => [
                'email' => (string) $state->email,
                'profile_id' => $state->profile?->id,
                'name' => trim((string) $state->profile?->first_name.' '.(string) $state->profile?->last_name),
                'at' => $state->email_status_changed_at->setTimezone('America/New_York')->format('M j, g:i a T'),
            ]);
        $unsubscribes = $unsubscribes->merge($addressOptouts)->unique('email')->values();

        return $unsubscribes;
    }
}

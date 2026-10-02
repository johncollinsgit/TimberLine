<?php

namespace App\Console\Commands;

use App\Models\BirthdayMessageEvent;
use App\Models\BirthdayRewardIssuance;
use App\Models\CustomerBirthdayProfile;
use App\Models\CustomerExternalProfile;
use App\Services\Marketing\BirthdayRewardEngineService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class MarketingBackfillBirthdayCoupons extends Command
{
    protected $signature = 'marketing:backfill-birthday-coupons
        {--tenant-id= : Tenant id}
        {--from= : First missed birthday date, YYYY-MM-DD}
        {--through= : Last missed birthday date, YYYY-MM-DD}
        {--limit=100 : Maximum outstanding rewards to process per run}
        {--retry-failed : Retry existing catchup rewards whose last email did not succeed}
        {--execute : Issue rewards and attempt consent-gated email; default is read-only}';

    protected $description = 'Preview or backfill missed birthday coupons without duplicating annual rewards.';

    public function handle(BirthdayRewardEngineService $engine): int
    {
        $tenantId = filter_var($this->option('tenant-id'), FILTER_VALIDATE_INT);
        $from = $this->validDate((string) $this->option('from'));
        $through = $this->validDate((string) $this->option('through'));
        $limit = max(1, (int) $this->option('limit'));
        $retryFailed = (bool) $this->option('retry-failed');
        $execute = (bool) $this->option('execute');

        if (! $tenantId || ! $from || ! $through || $from->year !== 2026 || $from->year !== $through->year
            || $from->greaterThan($through) || $through->greaterThan(now()->toImmutable()->startOfDay())) {
            $this->error('Provide a tenant id and a valid past birthday range within one calendar year.');

            return self::FAILURE;
        }

        $config = $engine->rewardConfig($tenantId);
        if (! (bool) ($config['enabled'] ?? false)
            || (string) ($config['reward_type'] ?? '') !== 'discount_code'
            || (float) ($config['reward_value'] ?? 0) !== 10.0) {
            $this->error('Backfill requires an enabled $10 birthday discount-code setting for this tenant.');

            return self::FAILURE;
        }

        $campaignKey = 'birthday-catchup-'.$from->year;
        $templateKey = 'birthday_email_catchup_2026';
        $summary = [
            'birthdays_in_range' => 0,
            'already_rewarded' => 0,
            'already_processed' => 0,
            'outstanding' => 0,
            'duplicate_profiles_skipped' => 0,
            'missing_email_skipped' => 0,
            'issued' => 0,
            'email_sent' => 0,
            'email_failed' => 0,
            'email_suppressed_no_consent' => 0,
            'errors' => 0,
        ];

        $profiles = CustomerBirthdayProfile::query()
            ->with('marketingProfile')
            ->whereHas('marketingProfile', fn ($query) => $query->where('tenant_id', $tenantId))
            ->whereNotNull('birth_month')
            ->whereNotNull('birth_day')
            ->lazyById(250);

        $candidates = [];
        $coveredEmails = [];
        foreach ($profiles as $profile) {
            $birthdayDate = $engine->cycleBirthdayDate($profile, $from->year);
            if (! $birthdayDate || $birthdayDate->lt($from) || $birthdayDate->gt($through)) {
                continue;
            }
            $summary['birthdays_in_range']++;

            $existing = BirthdayRewardIssuance::query()
                ->where('customer_birthday_profile_id', $profile->id)
                ->where('cycle_year', $from->year)
                ->orderByDesc('id')
                ->first();
            $isCatchup = $existing
                && data_get($existing->metadata, 'catchup_campaign_key') === $campaignKey;
            $emailConsented = (bool) $profile->marketingProfile?->accepts_email_marketing;
            $email = strtolower(trim((string) ($profile->marketingProfile?->normalized_email ?: $profile->marketingProfile?->email)));

            if (! $emailConsented) {
                $summary['email_suppressed_no_consent']++;

                continue;
            }
            if ($email === '') {
                $summary['missing_email_skipped']++;

                continue;
            }

            if ($existing && ! $isCatchup) {
                $summary['already_rewarded']++;
                $coveredEmails[$email] = true;

                continue;
            }
            if (! $existing && (int) $profile->reward_last_issued_year === $from->year) {
                $summary['already_rewarded']++;
                $coveredEmails[$email] = true;

                continue;
            }
            if ($existing && ($existing->isExpired() || $this->hasCatchupEmail($existing, $templateKey, $retryFailed))) {
                $summary['already_processed']++;
                $coveredEmails[$email] = true;

                continue;
            }

            $retailLinked = CustomerExternalProfile::query()
                ->forTenantId($tenantId)
                ->where('marketing_profile_id', $profile->marketing_profile_id)
                ->where('provider', 'shopify')
                ->where('store_key', 'retail')
                ->exists();
            if (isset($candidates[$email])) {
                $summary['duplicate_profiles_skipped']++;
                if ($retailLinked && ! $candidates[$email]['retail_linked']) {
                    $candidates[$email] = ['profile' => $profile, 'existing' => $existing, 'birthday_date' => $birthdayDate, 'retail_linked' => true];
                }
            } else {
                $candidates[$email] = ['profile' => $profile, 'existing' => $existing, 'birthday_date' => $birthdayDate, 'retail_linked' => $retailLinked];
            }
        }

        foreach ($coveredEmails as $email => $_) {
            unset($candidates[$email]);
        }

        $summary['outstanding'] = count($candidates);
        if (! $execute) {
            $this->printSummary($summary, $tenantId, $from, $through, false);

            return self::SUCCESS;
        }

        $processed = 0;
        foreach ($candidates as $candidate) {
            if ($processed >= $limit) {
                break;
            }
            $processed++;
            $profile = $candidate['profile'];
            $existing = $candidate['existing'];
            $birthdayDate = $candidate['birthday_date'];

            try {
                $now = now()->toImmutable();
                $result = $engine->issueAnnualReward($profile, [
                    'tenant_id' => $tenantId,
                    'cycle_year' => $from->year,
                    'claim_window_override' => [
                        'starts_at' => $now,
                        'ends_at' => $now->addDays(30)->endOfDay(),
                    ],
                    'issuance_metadata' => [
                        'catchup_campaign_key' => $campaignKey,
                        'birthday_date' => $birthdayDate->toDateString(),
                        'catchup_from' => $from->toDateString(),
                        'catchup_through' => $through->toDateString(),
                    ],
                    'send_email' => true,
                    'email_options' => [
                        'template_key' => $templateKey,
                        'force' => (bool) $existing,
                        'subject_template' => 'Happy belated birthday — a $10 gift from Modern Forestry',
                        'body_template' => 'Happy belated birthday {{first_name}}! We are sorry your birthday message arrived late. Our system had a hiccup and you deserved better. As a small business every person who chooses Modern Forestry means so much to us. We appreciate you and we are grateful to be a small part of the moments you make your own. Open your birthday gift at https://theforestrystudio.com/pages/birthday-gift. Sign in and we will add your $10 coupon then show you our candle bundles. Claim and use it by {{expiry_date}}. Your coupon can be used with eligible free shipping offers. It cannot be combined with Candle Cash or other discounts. Thank you for being here. With gratitude The Modern Forestry team.',
                    ],
                ]);
                if (! (bool) ($result['ok'] ?? false)) {
                    $summary['errors']++;
                    $this->warn('Issuance skipped for birthday profile '.$profile->id.': '.($result['error'] ?? 'unknown'));

                    continue;
                }

                if (! $existing) {
                    $summary['issued']++;
                }
                $delivery = (array) ($result['email_delivery'] ?? []);
                $summary[(bool) ($delivery['success'] ?? false) ? 'email_sent' : 'email_failed']++;
            } catch (\Throwable $exception) {
                $summary['errors']++;
                $this->warn('Issuance failed for birthday profile '.$profile->id.': '.$exception->getMessage());
            }

        }

        $this->printSummary($summary, $tenantId, $from, $through, true);

        return $summary['errors'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    protected function printSummary(array $summary, int $tenantId, CarbonImmutable $from, CarbonImmutable $through, bool $execute): void
    {
        $this->line('tenant_id='.$tenantId);
        $this->line('birthday_range='.$from->toDateString().'..'.$through->toDateString());
        $this->line('mode='.($execute ? 'execute' : 'dry-run'));
        foreach ($summary as $key => $value) {
            $this->line($key.'='.(int) $value);
        }
    }

    protected function validDate(string $value): ?CarbonImmutable
    {
        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $value);
        } catch (\Throwable) {
            return null;
        }

        return $date && $date->format('Y-m-d') === $value ? $date : null;
    }

    protected function hasCatchupEmail(BirthdayRewardIssuance $issuance, string $templateKey, bool $retryFailed): bool
    {
        $query = BirthdayMessageEvent::query()
            ->where('event_key', 'birthday_email:'.$issuance->id.':'.$templateKey);

        return $retryFailed
            ? (clone $query)->whereIn('status', ['sent', 'delivered', 'opened', 'clicked'])->exists()
            : $query->exists();
    }
}

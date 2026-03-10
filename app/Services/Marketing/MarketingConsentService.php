<?php

namespace App\Services\Marketing;

use App\Models\MarketingProfile;
use Carbon\CarbonImmutable;

class MarketingConsentService
{
    /**
     * @param array<string,mixed> $incoming
     */
    public function applyToProfile(MarketingProfile $profile, array $incoming): bool
    {
        $changed = false;

        $emailOptIn = $this->nullableBool($incoming['accepts_email_marketing'] ?? null);
        $smsOptIn = $this->nullableBool($incoming['accepts_sms_marketing'] ?? null);
        $emailOptOutAt = $this->asNullableDate($incoming['email_opted_out_at'] ?? null);
        $smsOptOutAt = $this->asNullableDate($incoming['sms_opted_out_at'] ?? null);

        // Explicit opt-out always wins.
        if ($emailOptIn === false || $emailOptOutAt !== null) {
            if ($profile->accepts_email_marketing !== false) {
                $profile->accepts_email_marketing = false;
                $changed = true;
            }
            if ($profile->email_opted_out_at === null || ($emailOptOutAt && $profile->email_opted_out_at != $emailOptOutAt)) {
                $profile->email_opted_out_at = $emailOptOutAt ?: CarbonImmutable::now();
                $changed = true;
            }
        } elseif ($emailOptIn === true) {
            // Do not override an explicit prior opt-out with weaker imported opt-in.
            if (! $profile->email_opted_out_at && $profile->accepts_email_marketing !== true) {
                $profile->accepts_email_marketing = true;
                $changed = true;
            }
        }

        if ($smsOptIn === false || $smsOptOutAt !== null) {
            if ($profile->accepts_sms_marketing !== false) {
                $profile->accepts_sms_marketing = false;
                $changed = true;
            }
            if ($profile->sms_opted_out_at === null || ($smsOptOutAt && $profile->sms_opted_out_at != $smsOptOutAt)) {
                $profile->sms_opted_out_at = $smsOptOutAt ?: CarbonImmutable::now();
                $changed = true;
            }
        } elseif ($smsOptIn === true) {
            if (! $profile->sms_opted_out_at && $profile->accepts_sms_marketing !== true) {
                $profile->accepts_sms_marketing = true;
                $changed = true;
            }
        }

        if ($changed) {
            $profile->save();
        }

        return $changed;
    }

    protected function nullableBool(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return ((int) $value) === 1;
        }

        $string = strtolower(trim((string) $value));
        if ($string === '') {
            return null;
        }

        if (in_array($string, ['true', 'yes', 'y', 'opt_in', 'subscribed', 'subscribed_true'], true)) {
            return true;
        }

        if (in_array($string, ['false', 'no', 'n', 'opt_out', 'unsubscribed', 'unsubscribed_true'], true)) {
            return false;
        }

        return null;
    }

    protected function asNullableDate(mixed $value): ?CarbonImmutable
    {
        $string = trim((string) $value);
        if ($string === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($string);
        } catch (\Throwable $e) {
            return null;
        }
    }
}

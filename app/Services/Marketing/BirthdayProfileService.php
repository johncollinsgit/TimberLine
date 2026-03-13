<?php

namespace App\Services\Marketing;

use App\Models\CustomerBirthdayAudit;
use App\Models\CustomerBirthdayProfile;
use App\Models\MarketingProfile;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class BirthdayProfileService
{
    /**
     * @param array<string,mixed> $payload
     * @param array<string,mixed> $options
     */
    public function captureForProfile(MarketingProfile $profile, array $payload, array $options = []): CustomerBirthdayProfile
    {
        $clear = (bool) ($payload['clear'] ?? false);
        $source = $this->nullableString($options['source'] ?? $payload['source'] ?? null) ?: 'unknown';
        $sourceCapturedAt = $this->asDate($options['source_captured_at'] ?? $payload['source_captured_at'] ?? null) ?: now()->toImmutable();
        $replaceSource = (bool) ($options['replace_source'] ?? true);
        $isUncertain = (bool) ($options['is_uncertain'] ?? false);

        $normalized = $clear
            ? [
                'birth_month' => null,
                'birth_day' => null,
                'birth_year' => null,
                'birthday_full_date' => null,
            ]
            : $this->normalizeBirthday($payload);

        /** @var CustomerBirthdayProfile|null $existing */
        $existing = CustomerBirthdayProfile::query()
            ->where('marketing_profile_id', $profile->id)
            ->first();

        $sourceToPersist = $source;
        $sourceCapturedAtToPersist = $sourceCapturedAt;

        if ($existing && ! $replaceSource && $existing->source) {
            $sourceToPersist = (string) $existing->source;
            $sourceCapturedAtToPersist = $existing->source_captured_at
                ? CarbonImmutable::instance($existing->source_captured_at)
                : $sourceCapturedAt;
        }

        $action = $clear
            ? 'birthday_cleared'
            : ($existing ? 'birthday_updated' : 'birthday_captured');

        return DB::transaction(function () use (
            $profile,
            $existing,
            $normalized,
            $sourceToPersist,
            $sourceCapturedAtToPersist,
            $action,
            $payload,
            $isUncertain
        ): CustomerBirthdayProfile {
            $record = CustomerBirthdayProfile::query()->updateOrCreate(
                [
                    'marketing_profile_id' => $profile->id,
                ],
                [
                    'birth_month' => $normalized['birth_month'],
                    'birth_day' => $normalized['birth_day'],
                    'birth_year' => $normalized['birth_year'],
                    'birthday_full_date' => $normalized['birthday_full_date'],
                    'source' => $sourceToPersist,
                    'source_captured_at' => $sourceCapturedAtToPersist,
                ]
            );

            $this->writeAudit(
                profile: $record,
                action: $action,
                source: $sourceToPersist,
                isUncertain: $isUncertain,
                payload: [
                    'input' => $payload,
                    'normalized' => $normalized,
                    'previous' => $existing ? [
                        'birth_month' => $existing->birth_month,
                        'birth_day' => $existing->birth_day,
                        'birth_year' => $existing->birth_year,
                        'birthday_full_date' => optional($existing->birthday_full_date)->toDateString(),
                        'source' => $existing->source,
                        'source_captured_at' => optional($existing->source_captured_at)->toIso8601String(),
                    ] : null,
                ]
            );

            return $record->fresh();
        });
    }

    public function birthdayForProfile(MarketingProfile $profile): ?CustomerBirthdayProfile
    {
        return CustomerBirthdayProfile::query()
            ->where('marketing_profile_id', $profile->id)
            ->first();
    }

    /**
     * @param array<string,mixed> $payload
     * @return array{birth_month:?int,birth_day:?int,birth_year:?int,birthday_full_date:?string}
     */
    public function normalizeBirthday(array $payload): array
    {
        $month = $this->nullableInt($payload['birth_month'] ?? null);
        $day = $this->nullableInt($payload['birth_day'] ?? null);
        $year = $this->nullableInt($payload['birth_year'] ?? null);
        $fullDate = $this->nullableString($payload['birthday_full_date'] ?? null);

        if ($fullDate !== null) {
            $parsed = $this->asDate($fullDate);
            if (! $parsed) {
                throw new RuntimeException('Birthday full date is not a valid date.');
            }

            $month = (int) $parsed->month;
            $day = (int) $parsed->day;
            $year = (int) $parsed->year;
        }

        if (($month === null && $day !== null) || ($month !== null && $day === null)) {
            throw new RuntimeException('Birthday month and day must both be supplied together.');
        }

        if ($month !== null && ($month < 1 || $month > 12)) {
            throw new RuntimeException('Birthday month must be between 1 and 12.');
        }

        if ($day !== null && ($day < 1 || $day > 31)) {
            throw new RuntimeException('Birthday day must be between 1 and 31.');
        }

        if ($year !== null && ($year < 1900 || $year > ((int) now()->year + 1))) {
            throw new RuntimeException('Birthday year is outside the allowed range.');
        }

        if ($month !== null && $day !== null) {
            if ($year !== null) {
                if (! checkdate($month, $day, $year)) {
                    throw new RuntimeException('Birthday date is invalid for the provided year.');
                }
            } else {
                // Validate month/day against a leap year so 02/29 is allowed in month/day-only mode.
                if (! checkdate($month, $day, 2000)) {
                    throw new RuntimeException('Birthday month/day combination is invalid.');
                }
            }
        }

        $birthdayFullDate = null;
        if ($month !== null && $day !== null && $year !== null) {
            $birthdayFullDate = sprintf('%04d-%02d-%02d', $year, $month, $day);
        }

        return [
            'birth_month' => $month,
            'birth_day' => $day,
            'birth_year' => $year,
            'birthday_full_date' => $birthdayFullDate,
        ];
    }

    /**
     * @param array<string,mixed> $payload
     */
    public function writeAudit(
        CustomerBirthdayProfile $profile,
        string $action,
        ?string $source,
        bool $isUncertain,
        array $payload = []
    ): CustomerBirthdayAudit {
        return CustomerBirthdayAudit::query()->create([
            'customer_birthday_profile_id' => $profile->id,
            'marketing_profile_id' => $profile->marketing_profile_id,
            'action' => trim($action) !== '' ? trim($action) : 'birthday_event',
            'source' => $this->nullableString($source),
            'is_uncertain' => $isUncertain,
            'payload' => $payload !== [] ? $payload : null,
        ]);
    }

    protected function nullableInt(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        if (! preg_match('/^-?\d+$/', $value)) {
            throw new RuntimeException('Birthday fields must be whole numbers.');
        }

        return (int) $value;
    }

    protected function nullableString(mixed $value): ?string
    {
        $string = trim((string) $value);

        return $string !== '' ? $string : null;
    }

    protected function asDate(mixed $value): ?CarbonImmutable
    {
        if ($value instanceof CarbonImmutable) {
            return $value;
        }

        if ($value instanceof \DateTimeInterface) {
            return CarbonImmutable::instance($value);
        }

        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }
}

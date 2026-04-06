<?php

namespace App\Services\Marketing;

class SmsMessageSafetyService
{
    /**
     * @var array<string,string>
     */
    protected const NORMALIZATION_MAP = [
        "\u{2018}" => "'",
        "\u{2019}" => "'",
        "\u{201A}" => "'",
        "\u{201B}" => "'",
        "\u{2032}" => "'",
        "\u{201C}" => '"',
        "\u{201D}" => '"',
        "\u{201E}" => '"',
        "\u{201F}" => '"',
        "\u{2033}" => '"',
        "\u{2013}" => '-',
        "\u{2014}" => '-',
        "\u{2015}" => '-',
        "\u{2212}" => '-',
        "\u{2026}" => '...',
        "\u{00A0}" => ' ',
        "\u{2002}" => ' ',
        "\u{2003}" => ' ',
        "\u{2009}" => ' ',
        "\u{200A}" => ' ',
        "\u{2022}" => '*',
    ];

    /**
     * @param  array<int,string>  $phoneNumbers
     * @return array<string,mixed>
     */
    public function analyze(string $message, array $phoneNumbers = [], bool $enforceSpendLimit = false): array
    {
        $normalization = $this->normalizeMessage($message);
        $normalizedBody = $normalization['body'];
        $characters = $this->messageCharacters($normalizedBody);
        $gsmAnalysis = $this->gsmAnalysis($characters);
        $unsupportedCharacters = $gsmAnalysis['unsupported_characters'];
        $encoding = $unsupportedCharacters === [] ? 'gsm7' : 'unicode';
        $smsSegments = $encoding === 'gsm7'
            ? $this->gsmSegments((int) $gsmAnalysis['units'])
            : $this->unicodeSegments(count($characters));

        $phoneNumbers = collect($phoneNumbers)
            ->map(fn ($value): string => trim((string) $value))
            ->filter(fn (string $value): bool => $value !== '')
            ->values();

        $deliveryPlans = $phoneNumbers->map(function (string $phone) use ($normalizedBody, $smsSegments): array {
            $mmsEligible = $this->mmsEligible($phone, $normalizedBody);
            $smsCost = $this->smsCost($smsSegments);
            $mmsCost = $mmsEligible ? $this->mmsCost() : null;
            $preferred = $mmsEligible && $mmsCost !== null && $mmsCost < $smsCost
                ? 'mms'
                : 'sms';

            return [
                'phone' => $phone,
                'mms_eligible' => $mmsEligible,
                'preferred_channel' => $preferred,
                'sms_cost' => $smsCost,
                'mms_cost' => $mmsCost,
                'selected_cost' => $preferred === 'mms' && $mmsCost !== null ? $mmsCost : $smsCost,
            ];
        });

        $recipientCount = $phoneNumbers->count();
        $mmsRecipientCount = $deliveryPlans->where('preferred_channel', 'mms')->count();
        $smsRecipientCount = max(0, $recipientCount - $mmsRecipientCount);
        $estimatedTotalCost = $recipientCount > 0
            ? (float) $deliveryPlans->sum('selected_cost')
            : 0.0;
        $estimatedCostPerRecipient = $recipientCount > 0
            ? $estimatedTotalCost / $recipientCount
            : min($this->smsCost($smsSegments), $this->mmsCost());

        $spendLimit = $this->bulkSpendLimit();
        $blockedBySpend = $enforceSpendLimit
            && $recipientCount > 0
            && $spendLimit !== null
            && $estimatedTotalCost > $spendLimit;

        $recommendedChannel = $recipientCount === 0
            ? ($this->mmsCost() < $this->smsCost($smsSegments) ? 'mms' : 'sms')
            : ($mmsRecipientCount === 0
                ? 'sms'
                : ($smsRecipientCount === 0 ? 'mms' : 'mixed'));

        $notes = [];
        if ((bool) $normalization['applied']) {
            $notes[] = 'Smart punctuation will be normalized before send.';
        }
        if ($encoding === 'unicode') {
            $notes[] = 'Unicode characters increase SMS segment cost.';
        }
        if ($mmsRecipientCount > 0) {
            $notes[] = $smsRecipientCount > 0
                ? 'Some recipients will be sent as MMS because it is cheaper than segmented SMS.'
                : 'This send will use MMS because it is cheaper than segmented SMS.';
        }

        $blockingReasons = [];
        if ($blockedBySpend && $spendLimit !== null) {
            $blockingReasons[] = sprintf(
                'Estimated send is about %s, above the %s safety limit.',
                $this->formatCurrency($estimatedTotalCost),
                $this->formatCurrency($spendLimit)
            );
        }

        return [
            'original_body' => trim($message),
            'normalized_body' => $normalizedBody,
            'normalization_applied' => (bool) $normalization['applied'],
            'normalization_replacements' => $normalization['replacements'],
            'encoding' => $encoding,
            'character_count' => count($characters),
            'sms_segments' => $smsSegments,
            'sms_units' => $encoding === 'gsm7' ? (int) $gsmAnalysis['units'] : count($characters),
            'unsupported_characters' => $unsupportedCharacters,
            'sms_recipient_count' => $smsRecipientCount,
            'mms_recipient_count' => $mmsRecipientCount,
            'recipient_count' => $recipientCount,
            'recommended_channel' => $recommendedChannel,
            'estimated_cost_per_recipient' => round($estimatedCostPerRecipient, 4),
            'estimated_cost_per_recipient_formatted' => $this->formatCurrency($estimatedCostPerRecipient),
            'estimated_total_cost' => round($estimatedTotalCost, 4),
            'estimated_total_cost_formatted' => $this->formatCurrency($estimatedTotalCost),
            'estimated_sms_cost_per_recipient' => round($this->smsCost($smsSegments), 4),
            'estimated_sms_cost_per_recipient_formatted' => $this->formatCurrency($this->smsCost($smsSegments)),
            'estimated_mms_cost_per_recipient' => round($this->mmsCost(), 4),
            'estimated_mms_cost_per_recipient_formatted' => $this->formatCurrency($this->mmsCost()),
            'bulk_spend_limit' => $spendLimit,
            'bulk_spend_limit_formatted' => $spendLimit !== null ? $this->formatCurrency($spendLimit) : null,
            'blocked' => $blockingReasons !== [],
            'blocking_reasons' => $blockingReasons,
            'notes' => $notes,
            'sms_only' => $mmsRecipientCount === 0,
            'mms_available' => $recipientCount === 0 ? true : $mmsRecipientCount > 0,
            'send_as_mms_recommended' => $recommendedChannel === 'mms',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function analyzeRecipient(string $message, ?string $phoneNumber): array
    {
        return $this->analyze($message, $phoneNumber ? [$phoneNumber] : [], false);
    }

    protected function smsCost(int $segments): float
    {
        $segments = max(1, $segments);

        return $segments * ((float) config('marketing.messaging.cost_guardrails.sms_outbound_per_segment', 0.0083)
            + (float) config('marketing.messaging.cost_guardrails.sms_carrier_fee_per_segment', 0.00395));
    }

    protected function mmsCost(): float
    {
        return (float) config('marketing.messaging.cost_guardrails.mms_outbound_per_message', 0.022)
            + (float) config('marketing.messaging.cost_guardrails.mms_carrier_fee_per_message', 0.009);
    }

    protected function mmsEligible(string $phoneNumber, string $body): bool
    {
        if (! (bool) config('marketing.messaging.cost_guardrails.prefer_mms_when_cheaper', true)) {
            return false;
        }

        $trimmed = trim($phoneNumber);
        if ($trimmed === '' || ! str_starts_with($trimmed, '+1')) {
            return false;
        }

        return mb_strlen($body) <= max(1, (int) config('marketing.messaging.cost_guardrails.mms_max_body_length', 1600));
    }

    /**
     * @param  array<int,string>  $characters
     * @return array{units:int,unsupported_characters:array<int,string>}
     */
    protected function gsmAnalysis(array $characters): array
    {
        $units = 0;
        $unsupported = [];
        $basic = $this->gsmBasicSet();
        $extended = $this->gsmExtendedSet();

        foreach ($characters as $character) {
            if (isset($basic[$character])) {
                $units++;
                continue;
            }

            if (isset($extended[$character])) {
                $units += 2;
                continue;
            }

            $unsupported[] = $character;
        }

        return [
            'units' => $units,
            'unsupported_characters' => array_values(array_slice(array_unique($unsupported), 0, 6)),
        ];
    }

    protected function gsmSegments(int $units): int
    {
        $units = max(1, $units);

        return $units <= 160
            ? 1
            : (int) ceil($units / 153);
    }

    protected function unicodeSegments(int $characters): int
    {
        $characters = max(1, $characters);

        return $characters <= 70
            ? 1
            : (int) ceil($characters / 67);
    }

    /**
     * @return array{body:string,applied:bool,replacements:array<int,string>}
     */
    protected function normalizeMessage(string $message): array
    {
        $trimmed = trim($message);
        $normalized = strtr($trimmed, self::NORMALIZATION_MAP);
        $replacements = [];

        foreach (self::NORMALIZATION_MAP as $search => $replacement) {
            if (str_contains($trimmed, $search)) {
                $replacements[] = sprintf('%s -> %s', $search, $replacement);
            }
        }

        return [
            'body' => $normalized,
            'applied' => $normalized !== $trimmed,
            'replacements' => array_values(array_slice($replacements, 0, 6)),
        ];
    }

    /**
     * @return array<int,string>
     */
    protected function messageCharacters(string $message): array
    {
        $characters = preg_split('//u', $message, -1, PREG_SPLIT_NO_EMPTY);

        return is_array($characters) ? $characters : [];
    }

    /**
     * @return array<string,bool>
     */
    protected function gsmBasicSet(): array
    {
        static $set;

        if (is_array($set)) {
            return $set;
        }

        $characters = [
            '@', '£', '$', '¥', 'è', 'é', 'ù', 'ì', 'ò', 'Ç',
            "\n",
            'Ø', 'ø',
            "\r",
            'Å', 'å', 'Δ', '_', 'Φ', 'Γ', 'Λ', 'Ω', 'Π', 'Ψ', 'Σ', 'Θ', 'Ξ',
            ' ', '!', '"', '#', '¤', '%', '&', "'", '(', ')', '*', '+', ',', '-', '.', '/',
            '0', '1', '2', '3', '4', '5', '6', '7', '8', '9',
            ':', ';', '<', '=', '>', '?',
            '¡',
            'A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J', 'K', 'L', 'M', 'N', 'O', 'P', 'Q', 'R', 'S', 'T', 'U', 'V', 'W', 'X', 'Y', 'Z',
            'Ä', 'Ö', 'Ñ', 'Ü', '§', '¿',
            'a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'i', 'j', 'k', 'l', 'm', 'n', 'o', 'p', 'q', 'r', 's', 't', 'u', 'v', 'w', 'x', 'y', 'z',
            'ä', 'ö', 'ñ', 'ü', 'à',
        ];

        $set = array_fill_keys($characters, true);

        return $set;
    }

    /**
     * @return array<string,bool>
     */
    protected function gsmExtendedSet(): array
    {
        static $set;

        if (is_array($set)) {
            return $set;
        }

        $set = array_fill_keys(['^', '{', '}', '\\', '[', '~', ']', '|', '€'], true);

        return $set;
    }

    protected function bulkSpendLimit(): ?float
    {
        $limit = (float) config('marketing.messaging.cost_guardrails.bulk_max_total_estimated_cost', 250);

        return $limit > 0 ? $limit : null;
    }

    protected function formatCurrency(float $value): string
    {
        return '$' . number_format(max(0, $value), 2);
    }
}

<?php

namespace App\Services\Wholesale;

use App\Models\TenantDiscoveryProfile;
use Illuminate\Support\Str;

class WholesaleProspectFitScorer
{
    /** @return array{score:int,confidence:int,positive_signals:array<int,string>,negative_signals:array<int,string>,missing_information:array<int,string>,evaluated_at:string} */
    public function score(int $tenantId, array $prospect): array
    {
        $types = collect((array) ($prospect['types'] ?? []))->map(fn ($value): string => Str::lower((string) $value));
        $positive = [];
        $negative = [];
        $missing = [];
        $score = 25;

        $strongTypes = ['gift_shop', 'home_goods_store', 'florist', 'book_store', 'furniture_store', 'store'];
        if ($types->intersect($strongTypes)->isNotEmpty()) {
            $score += 30;
            $positive[] = 'Business category is compatible with a specialty retail account.';
        } elseif ($types->contains('shopping_mall')) {
            $score += 10;
            $positive[] = 'The business is associated with a retail shopping category.';
        } else {
            $missing[] = 'Retail merchandise fit has not been verified.';
        }

        $profile = TenantDiscoveryProfile::query()
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->first();
        $configuredSignals = collect(array_merge(
            (array) $profile?->brand_keywords,
            (array) data_get($profile?->merchant_signals, 'product_categories', []),
            (array) data_get($profile?->merchant_signals, 'best_fit_descriptors', [])
        ))->map(fn ($value): string => Str::lower(trim((string) $value)))->filter()->unique();
        $prospectText = Str::lower(implode(' ', array_merge(
            [(string) ($prospect['business_name'] ?? ''), (string) ($prospect['primary_category'] ?? '')],
            $types->all()
        )));
        $matchedSignals = $configuredSignals
            ->filter(fn (string $signal): bool => str_contains($prospectText, $signal))
            ->values();
        if ($matchedSignals->isNotEmpty()) {
            $score += min(20, $matchedSignals->count() * 5);
            $positive[] = 'Business information matches configured tenant merchandising signals: '.$matchedSignals->implode(', ').'.';
        } elseif ($configuredSignals->isEmpty()) {
            $missing[] = 'Tenant merchandising signals are not configured; neutral retail-fit scoring was used.';
        }

        if (filled($prospect['website'] ?? null)) {
            $score += 10;
            $positive[] = 'A public business website is available for merchandise review.';
        } else {
            $missing[] = 'No public website was returned.';
        }

        $status = Str::upper((string) ($prospect['operational_status'] ?? ''));
        if ($status === 'OPERATIONAL') {
            $score += 10;
            $positive[] = 'Google reports the business as operational.';
        } elseif (in_array($status, ['CLOSED_PERMANENTLY', 'CLOSED_TEMPORARILY'], true)) {
            $score -= $status === 'CLOSED_PERMANENTLY' ? 70 : 30;
            $negative[] = $status === 'CLOSED_PERMANENTLY' ? 'The business is reported permanently closed.' : 'The business is reported temporarily closed.';
        } else {
            $missing[] = 'Current operating status is not confirmed.';
        }

        if (filled($prospect['phone'] ?? null)) {
            $score += 5;
            $positive[] = 'A public business phone number is available.';
        } else {
            $missing[] = 'No public phone number was returned.';
        }

        $confidenceInputs = 1 + (int) filled($prospect['website'] ?? null) + (int) filled($prospect['phone'] ?? null)
            + (int) ($types->isNotEmpty()) + (int) ($status !== '');

        return [
            'score' => max(0, min(100, $score)),
            'confidence' => min(100, 20 + ($confidenceInputs * 15)),
            'positive_signals' => $positive,
            'negative_signals' => $negative,
            'missing_information' => $missing,
            'evaluated_at' => now()->toIso8601String(),
        ];
    }
}

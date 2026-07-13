<?php

namespace App\Services\FieldService;

use App\Models\FieldServiceFinancialDocumentLine;
use App\Models\FieldServicePriceBookCandidate;
use App\Models\FieldServicePriceBookItem;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Str;

class PriceBookCandidateService
{
    /** @var array<int,string> */
    protected array $broadNames = ['electrical services', 'labor', 'material', 'labor material', 'labor and material', 'sales'];

    /** @return array{candidates:int,high_variance:int} */
    public function rebuild(Tenant $tenant): array
    {
        $lines = FieldServiceFinancialDocumentLine::query()
            ->forTenantId((int) $tenant->id)
            ->whereHas('document', fn ($query) => $query
                ->where('source', 'quickbooks')
                ->where('document_type', 'invoice')
                ->where('transaction_date', '>=', now()->subYear()->toDateString()))
            ->with('document:id,transaction_date')
            ->whereNotNull('description')
            ->get();
        $groups = [];

        foreach ($lines as $line) {
            $description = $this->candidateDescription((string) $line->description);
            $key = $this->normalize($description);
            $quantity = max(1.0, (float) ($line->quantity ?: 1));
            $unitPrice = is_numeric($line->unit_price)
                ? (float) $line->unit_price
                : (is_numeric($line->amount) ? (float) $line->amount / $quantity : 0.0);
            if ($key === '' || mb_strlen($description) < 8 || in_array($key, $this->broadNames, true) || $unitPrice <= 0) {
                continue;
            }

            $groups[$key][] = [
                'description' => $description,
                'price' => $unitPrice,
                'date' => $line->document?->transaction_date,
                'document_id' => (int) $line->field_service_financial_document_id,
            ];
        }

        $count = 0;
        $highVariance = 0;
        foreach ($groups as $key => $samples) {
            if (count($samples) < 2) {
                continue;
            }
            usort($samples, fn (array $a, array $b): int => $a['price'] <=> $b['price']);
            $prices = array_column($samples, 'price');
            $median = $this->median($prices);
            $minimum = min($prices);
            $maximum = max($prices);
            $recent = collect($samples)->sortByDesc('date')->first();
            $variance = $median > 0 && (($maximum - $minimum) / $median) > 0.25;
            $existing = FieldServicePriceBookCandidate::query()->forTenantId((int) $tenant->id)
                ->where('source', 'quickbooks')->where('normalized_key', $key)->first();
            $values = [
                'name' => Str::limit($samples[0]['description'], 255, ''),
                'description' => $samples[0]['description'],
                'sample_count' => count($samples),
                'median_unit_price' => round($median, 4),
                'minimum_unit_price' => round($minimum, 4),
                'maximum_unit_price' => round($maximum, 4),
                'recent_unit_price' => round((float) ($recent['price'] ?? $median), 4),
                'high_variance' => $variance,
                'last_invoiced_at' => $recent['date'] ?? null,
                'metadata' => ['document_ids' => collect($samples)->pluck('document_id')->unique()->take(20)->values()->all()],
            ];
            if ($existing) {
                $existing->forceFill($values)->save();
            } else {
                FieldServicePriceBookCandidate::query()->create($values + [
                    'tenant_id' => (int) $tenant->id,
                    'source' => 'quickbooks',
                    'normalized_key' => $key,
                    'status' => 'suggested',
                ]);
            }
            $count++;
            $highVariance += $variance ? 1 : 0;
        }

        return ['candidates' => $count, 'high_variance' => $highVariance];
    }

    public function approve(Tenant $tenant, FieldServicePriceBookCandidate $candidate, User $user, ?float $price = null): FieldServicePriceBookItem
    {
        abort_unless((int) $candidate->tenant_id === (int) $tenant->id, 404);
        $chosenPrice = $price ?? (float) $candidate->median_unit_price;
        $item = FieldServicePriceBookItem::query()->updateOrCreate(
            [
                'tenant_id' => (int) $tenant->id,
                'source' => 'curated',
                'external_id' => 'candidate:'.$candidate->id,
            ],
            [
                'name' => $candidate->name,
                'item_type' => 'service',
                'description' => $candidate->description,
                'unit_price' => $chosenPrice,
                'active' => true,
                'metadata' => [
                    'candidate_id' => (int) $candidate->id,
                    'sample_count' => (int) $candidate->sample_count,
                    'historical_median' => (float) $candidate->median_unit_price,
                    'historical_minimum' => (float) $candidate->minimum_unit_price,
                    'historical_maximum' => (float) $candidate->maximum_unit_price,
                    'last_invoiced_at' => optional($candidate->last_invoiced_at)->toDateString(),
                ],
            ]
        );
        $candidate->forceFill([
            'status' => 'approved',
            'approved_price_book_item_id' => (int) $item->id,
            'reviewed_by_user_id' => (int) $user->id,
            'reviewed_at' => now(),
        ])->save();

        return $item;
    }

    protected function candidateDescription(string $description): string
    {
        $first = preg_split('/[\r\n;]+/', trim($description), 2)[0] ?? '';

        return Str::of($first)->replaceMatches('/\s+/', ' ')->trim()->limit(500, '')->toString();
    }

    protected function normalize(string $description): string
    {
        return Str::of($description)
            ->lower()
            ->replace('&', ' and ')
            ->replaceMatches('/\$[0-9,.]+/', ' ')
            ->replaceMatches('/[^a-z0-9]+/', ' ')
            ->squish()
            ->toString();
    }

    /** @param array<int,float> $values */
    protected function median(array $values): float
    {
        sort($values, SORT_NUMERIC);
        $count = count($values);
        $middle = intdiv($count, 2);

        return $count % 2 === 0 ? ($values[$middle - 1] + $values[$middle]) / 2 : $values[$middle];
    }
}

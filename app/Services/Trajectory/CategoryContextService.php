<?php

namespace App\Services\Trajectory;

/** Review hints, never tax eligibility or a claim about private peer books. */
class CategoryContextService
{
    public function check(array $entry, bool $business): array
    {
        $title = ClassificationService::merchant($entry['merchant']);
        // Specific product phrases are safer than broad retailer/processor names.
        $software = preg_match('/\b(adobe creative cloud|adobe acrobat|adobe systems|github|digitalocean|quickbooks online|intuit quickbooks|microsoft 365|office 365|google workspace|dropbox|figma|canva pro|openai|chatgpt|software subscription)\b/u', $title);
        $context = $software ? [
            'category' => 'subscriptions',
            'compatible' => ['subscriptions', 'fees', 'education'],
            'description' => 'The title identifies a software or online-service product.',
            'practice' => 'For business software, review a software/subscriptions operating-expense category. A purchase of a lasting asset, prepaid contract, or mixed personal use can require different treatment. Confirm the receipt and business purpose.',
        ] : null;
        if (! $context) {
            foreach ([
                ['pattern' => '/\\b(car wash|auto repair|tire service|oil change|gas express)\\b/u', 'category' => 'transport', 'compatible' => ['transport', 'travel'], 'description' => 'The title identifies a vehicle service or fuel purchase.', 'practice' => 'Review a vehicle or travel expense category and substantiate business use. Separate commuting, personal use, and costs already covered by your mileage method.'],
                ['pattern' => '/\\b(internet service|internet bill|electric utility|water utility|duke energy)\\b/u', 'category' => 'utilities', 'compatible' => ['utilities'], 'description' => 'The title identifies a utility service.', 'practice' => 'Review utilities or communications operating expenses and document the business share. Home and mixed-use services need allocation evidence.'],
                ['pattern' => '/\\b(netflix|spotify|hulu|xbox game pass|prime video)\\b/u', 'category' => 'entertainment', 'compatible' => ['entertainment', 'subscriptions'], 'description' => 'The title identifies a media or gaming service.', 'practice' => 'Confirm a specific business purpose before treating entertainment subscriptions as operating costs. A business payment account alone does not establish deductibility.'],
            ] as $candidate) {
                if (preg_match($candidate['pattern'], $title)) {
                    $context = $candidate;
                    break;
                }
            }
        }
        if (! $context) {
            return ['fit' => ['status' => 'insufficient_context', 'reason' => 'The title alone does not identify the purchase reliably.'], 'practice' => $this->practice($business, null)];
        }
        $mismatch = ! in_array($entry['category'], [...$context['compatible'], 'uncategorized'], true);

        return ['fit' => ['status' => $mismatch ? 'review' : 'consistent', 'suggested_category' => $context['category'], 'reason' => $context['description'].($mismatch ? ' Its current category needs a purpose check.' : ' Its category can fit this context.')], 'practice' => $this->practice($business, $context['practice'])];
    }

    private function practice(bool $business, ?string $guidance): array
    {
        return [
            'status' => $business && $guidance ? 'general_guidance' : 'unavailable',
            'label' => 'Similar-business practice',
            'reason' => $business ? ($guidance ?? 'No reliable category-level comparison is available for this purchase.') : 'Business practice comparisons apply in a business space.',
            'basis' => 'General accounting review guidance; no measured peer categorization data is connected.',
            'peer_sample_size' => null,
            'source_url' => $business && $guidance ? 'https://www.irs.gov/forms-pubs/guide-to-business-expense-resources' : null,
        ];
    }
}

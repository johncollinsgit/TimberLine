<?php

namespace App\Services\Trajectory;

use Illuminate\Support\Collection;

class ReviewSuggestionService
{
    /**
     * Build review-only bulk suggestions from an exact prior decision or an
     * unambiguous provider phrase. Suggestions never create a merchant rule.
     */
    public function forEntries(Collection $entries): array
    {
        $candidates = $entries->filter(fn (array $entry): bool => $entry['editable'] && ! $entry['reviewed'] && $entry['amount_cents'] < 0 && $this->eligible($entry));
        $reviewed = $entries->filter(fn (array $entry): bool => $entry['editable'] && $entry['reviewed'] && $entry['amount_cents'] < 0 && $this->eligible($entry));
        $suggestions = [];

        foreach ($candidates->groupBy(fn (array $entry): string => ClassificationService::merchant($entry['merchant'])) as $merchant => $rows) {
            if ($rows->count() < 2) {
                continue;
            }
            $past = $reviewed->filter(fn (array $entry): bool => ClassificationService::merchant($entry['merchant']) === $merchant);
            $classifications = $past->map(fn (array $entry): string => implode(':', [$entry['category'], $entry['flow'], (int) $entry['face_punched'], (int) $entry['bullshit_spending']]))->unique()->values();
            if ($past->isNotEmpty() && $classifications->count() === 1) {
                $suggestions[] = $this->suggestion($rows, $past->first(), 'past_decision', 'Matches '.$past->count().' of your reviewed '.($past->count() === 1 ? 'purchase' : 'purchases').'.');

                continue;
            }
            if ($past->isEmpty() && ($context = $this->context($rows->first()['merchant']))) {
                $suggestions[] = $this->suggestion($rows, $context, 'title_context', 'The merchant title includes “'.$context['phrase'].'”. Review before applying.');
            }
        }

        usort($suggestions, fn (array $left, array $right): int => $right['count'] <=> $left['count'] ?: $right['amount_cents'] <=> $left['amount_cents']);

        return array_slice($suggestions, 0, 8);
    }

    private function eligible(array $entry): bool
    {
        return ! $entry['face_punched']
            && ! $entry['bullshit_spending']
            && $entry['flow'] === 'expense'
            && ! ClassificationService::isAmbiguousMerchant($entry['merchant']);
    }

    private function suggestion(Collection $rows, array $classification, string $kind, string $evidence): array
    {
        $rows = $rows->take(100)->values();
        $first = $rows->first();

        return [
            'kind' => $kind,
            'merchant' => $first['merchant'],
            'category' => $classification['category'],
            'flow' => $classification['flow'],
            'face_punched' => (bool) $classification['face_punched'],
            'bullshit_spending' => (bool) $classification['bullshit_spending'],
            'count' => $rows->count(),
            'amount_cents' => abs((int) $rows->sum('amount_cents')),
            'evidence' => $evidence,
            'transactions' => $rows->take(100)->map(fn (array $entry): array => [
                'id' => $entry['id'], 'version' => $entry['version'], 'date' => $entry['date'], 'merchant' => $entry['merchant'], 'amount_cents' => $entry['amount_cents'],
            ])->values()->all(),
        ];
    }

    private function context(string $merchant): ?array
    {
        $title = ClassificationService::merchant($merchant);
        foreach ([
            ['phrase' => 'publix', 'category' => 'groceries'], ['phrase' => 'food lion', 'category' => 'groceries'], ['phrase' => 'aldi', 'category' => 'groceries'], ['phrase' => 'ingles', 'category' => 'groceries'],
            ['phrase' => 'kindle', 'category' => 'entertainment'], ['phrase' => 'audible', 'category' => 'entertainment'], ['phrase' => 'netflix', 'category' => 'entertainment'], ['phrase' => 'spotify', 'category' => 'entertainment'],
            ['phrase' => 'freedom mortgage', 'category' => 'housing'], ['phrase' => 'electric', 'category' => 'utilities'], ['phrase' => 'power', 'category' => 'utilities'], ['phrase' => 'water', 'category' => 'utilities'],
            ['phrase' => 'shell', 'category' => 'transport'], ['phrase' => 'exxon', 'category' => 'transport'], ['phrase' => 'chevron', 'category' => 'transport'], ['phrase' => 'cvs', 'category' => 'health'], ['phrase' => 'walgreens', 'category' => 'health'],
        ] as $context) {
            if (preg_match('/\b'.preg_quote($context['phrase'], '/').'\b/u', $title)) {
                return [...$context, 'flow' => 'expense', 'face_punched' => false, 'bullshit_spending' => false];
            }
        }

        return null;
    }
}

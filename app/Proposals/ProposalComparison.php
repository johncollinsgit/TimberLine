<?php

namespace App\Proposals;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/** Explicit, read-only comparison tied to one reviewed commercial snapshot. */
class ProposalComparison
{
    public function snapshot(array $payload, int $status = 422): array
    {
        $definition = $this->definition();
        abort_unless($this->compatible($payload, $definition), $status,
            'This comparison is unavailable for the saved commercial terms. Review its source before adding it.');

        return ['schema_version' => 1, 'id' => $definition['id'],
            'commercial_sha256' => CommercialSnapshot::hash($payload), 'content' => $definition['content']];
    }

    public function from(array $payload): ?array
    {
        if (! array_key_exists('comparison', $payload)) {
            return null;
        }

        abort_unless($payload['comparison'] === $this->snapshot($payload, 503), 503,
            'This saved comparison is unavailable.');

        return $payload['comparison']['content'];
    }

    protected function definition(): array
    {
        return json_decode(file_get_contents(resource_path('data/proposals/lees-mcrae-comparison-v1.json')), true, flags: JSON_THROW_ON_ERROR);
    }

    private function compatible(array $payload, array $definition): bool
    {
        if (($payload['client']['reference'] ?? null) !== CampusReviewManifest::CLIENT_REFERENCE
            || ($definition['schema_version'] ?? null) !== 1
            || ($definition['id'] ?? null) !== 'lees-mcrae-comparison-v1'
            || ! is_array($payload['commercial_snapshot'] ?? null)
            || ! is_array($definition['commercial_source'] ?? null)
            || ! is_array($definition['content'] ?? null)) {
            return false;
        }
        // Null pins intentionally refuse until the current saved source is read and reviewed.
        foreach (['source_reference', 'source_copy_sha256', 'protected_sha256'] as $key) {
            $expected = $definition['commercial_source'][$key] ?? null;
            if (! is_string($expected) || $expected === ''
                || ($payload['commercial_snapshot'][$key] ?? null) !== $expected) {
                return false;
            }
        }
        try {
            CommercialSnapshot::verify($payload);
        } catch (ConflictHttpException) {
            return false;
        }
        if (($payload['usage_plan'] ?? null) !== 'unconfirmed'
            || ! is_array($payload['items'] ?? null) || count($payload['items']) !== 2) {
            return false;
        }
        $offers = [];
        foreach ($payload['items'] as $item) {
            if (! is_array($item) || ! is_string($item['name'] ?? null)
                || isset($offers[$item['name']]) || ($item['terms_months'] ?? null) !== [12]
                || ! is_array($item['charges'] ?? null) || count($item['charges']) !== 2) {
                return false;
            }
            $charges = [];
            foreach ($item['charges'] as $charge) {
                if (! is_array($charge) || ! is_string($charge['cadence'] ?? null)
                    || isset($charges[$charge['cadence']]) || ! is_int($charge['amount_cents'] ?? null)) {
                    return false;
                }
                $charges[$charge['cadence']] = $charge['amount_cents'];
            }
            ksort($charges);
            $offers[$item['name']] = $charges;
        }
        ksort($offers);

        return $offers === ['One workspace' => ['month' => 100000, 'one_time' => 300000],
            'Three-workspace bundle' => ['month' => 250000, 'one_time' => 700000]];
    }
}

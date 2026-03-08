<?php

namespace App\Services\ScentGovernance;

use App\Models\Scent;
use App\Models\ScentAlias;
use App\Models\WholesaleCustomScent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class ResolveScentMatchService
{
    /**
     * @param  array<string,mixed>  $context
     * @return Collection<int,array<string,mixed>>
     */
    public function resolveCandidates(string $search, array $context = []): Collection
    {
        $search = trim($search);
        if ($search === '') {
            return collect();
        }

        $isWholesale = (bool) ($context['is_wholesale'] ?? false);
        $needle = $this->normalizeSearchText($search);
        $tokenSource = $this->compactSearchText($needle);
        $tokens = collect(explode(' ', $tokenSource))
            ->map(fn (string $token): string => trim($token))
            ->filter(fn (string $token): bool => $token !== '')
            ->unique()
            ->values()
            ->all();

        if ($needle === '' || $tokens === []) {
            return collect();
        }

        /** @var array<int,array<string,mixed>> $candidates */
        $candidates = [];

        $scentSearchColumns = ['display_name', 'name'];
        if (Schema::hasColumn('scents', 'abbreviation')) {
            $scentSearchColumns[] = 'abbreviation';
        }
        if (Schema::hasColumn('scents', 'oil_reference_name')) {
            $scentSearchColumns[] = 'oil_reference_name';
        }

        $canonicalRows = Scent::query()
            ->with(['oilBlend:id,name'])
            ->where(function (Builder $query) use ($tokens, $scentSearchColumns): void {
                $this->applyLooseTextSearch($query, $tokens, $scentSearchColumns);
            })
            ->orderByRaw('COALESCE(display_name, name)')
            ->limit(180)
            ->get([
                'id',
                'name',
                'display_name',
                'abbreviation',
                'oil_reference_name',
                'is_wholesale_custom',
                'is_blend',
                'is_candle_club',
                'oil_blend_id',
            ]);

        foreach ($canonicalRows as $scent) {
            $name = (string) ($scent->display_name ?: $scent->name ?: '');
            if ($name === '') {
                continue;
            }

            $score = $this->candidateScoreFromFields($needle, [
                $name,
                (string) ($scent->name ?? ''),
                (string) ($scent->abbreviation ?? ''),
                (string) ($scent->oil_reference_name ?? ''),
                (string) ($scent->oilBlend?->name ?? ''),
            ]);

            if ($score < 0.08) {
                continue;
            }

            $type = $this->candidateType($scent, false);
            if ($isWholesale && str_contains($type, 'Wholesale')) {
                $score += 0.08;
            }

            $this->upsertCandidate($candidates, [
                'id' => (int) $scent->id,
                'name' => $name,
                'mapping_type' => $type,
                'score' => $score,
                'why' => 'matched canonical scent fields',
            ]);
        }

        if (Schema::hasTable('wholesale_custom_scents')) {
            $customSearchColumns = ['custom_scent_name'];
            if (Schema::hasColumn('wholesale_custom_scents', 'account_name')) {
                $customSearchColumns[] = 'account_name';
            }
            if (Schema::hasColumn('wholesale_custom_scents', 'notes')) {
                $customSearchColumns[] = 'notes';
            }

            $customQuery = WholesaleCustomScent::query()
                ->with(['canonicalScent:id,name,display_name,is_wholesale_custom,is_blend,is_candle_club,oil_blend_id'])
                ->where('active', true)
                ->whereNotNull('canonical_scent_id')
                ->where(function (Builder $query) use ($tokens, $customSearchColumns, $scentSearchColumns): void {
                    $this->applyLooseTextSearch($query, $tokens, $customSearchColumns);
                    $query->orWhereHas('canonicalScent', function (Builder $canonicalQuery) use ($tokens, $scentSearchColumns): void {
                        $this->applyLooseTextSearch($canonicalQuery, $tokens, $scentSearchColumns);
                    });
                })
                ->limit(300)
                ->get();

            $accountNeedle = WholesaleCustomScent::normalizeAccountName((string) ($context['account_name'] ?? ''));

            foreach ($customQuery as $row) {
                $scent = $row->canonicalScent;
                if (! $scent) {
                    continue;
                }

                $name = (string) ($scent->display_name ?: $scent->name ?: '');
                if ($name === '') {
                    continue;
                }

                $customName = trim((string) $row->custom_scent_name);
                $score = $this->candidateScoreFromFields($needle, [
                    $customName,
                    $name,
                    (string) ($scent->name ?? ''),
                    (string) ($row->notes ?? ''),
                    (string) ($row->account_name ?? ''),
                ]) + 0.14;

                if ($score < 0.1) {
                    continue;
                }

                $why = 'matched wholesale custom scent name';
                if ($accountNeedle !== '' && WholesaleCustomScent::normalizeAccountName((string) $row->account_name) === $accountNeedle) {
                    $score += 0.2;
                    $why = 'matched wholesale custom scent name + account history';
                }

                $this->upsertCandidate($candidates, [
                    'id' => (int) $scent->id,
                    'name' => $name,
                    'mapping_type' => $this->candidateType($scent, true),
                    'score' => $score,
                    'why' => $why,
                ]);
            }
        }

        if (Schema::hasTable('scent_aliases')) {
            $aliasScopes = ['markets', 'retail', 'global'];
            if ($isWholesale) {
                $aliasScopes[] = 'wholesale';
                $aliasScopes[] = 'order_type:wholesale';
                if (! empty($context['account_name'])) {
                    $aliasScopes[] = 'account:'.WholesaleCustomScent::normalizeAccountName((string) $context['account_name']);
                }
            }

            $aliases = ScentAlias::query()
                ->with(['scent:id,name,display_name,is_wholesale_custom,is_blend,is_candle_club,oil_blend_id'])
                ->whereIn('scope', array_values(array_unique($aliasScopes)))
                ->where(function (Builder $query) use ($tokens): void {
                    $this->applyLooseTextSearch($query, $tokens, ['alias']);
                })
                ->limit(220)
                ->get();

            foreach ($aliases as $alias) {
                $scent = $alias->scent;
                if (! $scent) {
                    continue;
                }

                $name = (string) ($scent->display_name ?: $scent->name ?: '');
                if ($name === '') {
                    continue;
                }

                $score = max(
                    $this->candidateScoreFromFields($needle, [
                        (string) $alias->alias,
                        $name,
                        (string) ($scent->name ?? ''),
                    ]),
                    0.0
                ) + 0.12;

                if ($score < 0.1) {
                    continue;
                }

                $this->upsertCandidate($candidates, [
                    'id' => (int) $scent->id,
                    'name' => $name,
                    'mapping_type' => $this->candidateType(
                        $scent,
                        str_contains((string) $alias->scope, 'wholesale') || str_starts_with((string) $alias->scope, 'account:')
                    ),
                    'score' => $score,
                    'why' => 'matched alias rule',
                ]);
            }
        }

        $rows = array_values($candidates);
        usort($rows, function (array $a, array $b) use ($isWholesale): int {
            $aType = (string) ($a['mapping_type'] ?? '');
            $bType = (string) ($b['mapping_type'] ?? '');

            $aPref = $isWholesale && str_contains($aType, 'Wholesale') ? 1 : 0;
            $bPref = $isWholesale && str_contains($bType, 'Wholesale') ? 1 : 0;

            return [$bPref, (float) ($b['score'] ?? 0), (string) ($a['name'] ?? '')]
                <=> [$aPref, (float) ($a['score'] ?? 0), (string) ($b['name'] ?? '')];
        });

        return collect(array_slice($rows, 0, 8))
            ->map(function (array $row): array {
                $row['score'] = (int) max(1, min(99, round(((float) ($row['score'] ?? 0)) * 100)));
                return $row;
            })
            ->values();
    }

    /**
     * @param  array<string,mixed>  $context
     */
    public function findExistingScent(string $search, array $context = []): ?Scent
    {
        $needle = $this->normalizeSearchText($search);
        if ($needle === '') {
            return null;
        }

        $exact = Scent::query()
            ->where(function (Builder $query) use ($needle): void {
                $query->whereRaw('lower(name) = ?', [$needle])
                    ->orWhereRaw('lower(coalesce(display_name, \'\')) = ?', [$needle])
                    ->orWhereRaw('lower(coalesce(abbreviation, \'\')) = ?', [$needle]);
            })
            ->first();

        if ($exact) {
            return $exact;
        }

        if (Schema::hasTable('scent_aliases')) {
            $scopes = ['markets', 'retail', 'global'];
            $isWholesale = (bool) ($context['is_wholesale'] ?? false);
            if ($isWholesale) {
                $scopes[] = 'wholesale';
                $scopes[] = 'order_type:wholesale';
                if (! empty($context['account_name'])) {
                    $scopes[] = 'account:'.WholesaleCustomScent::normalizeAccountName((string) $context['account_name']);
                }
            }

            $alias = ScentAlias::query()
                ->whereRaw('lower(alias) = ?', [$needle])
                ->whereIn('scope', array_values(array_unique($scopes)))
                ->first();

            if ($alias?->scent_id) {
                return Scent::query()->find((int) $alias->scent_id);
            }
        }

        $best = $this->resolveCandidates($search, $context)->first();
        if (! $best) {
            return null;
        }

        $score = (int) ($best['score'] ?? 0);
        if ($score < 86) {
            return null;
        }

        return Scent::query()->find((int) ($best['id'] ?? 0));
    }

    /**
     * @param  array<string,mixed>  $context
     */
    public function resolveSingleCandidateId(string $search, array $context = [], int $minimumScore = 90): ?int
    {
        $best = $this->resolveCandidates($search, $context)->first();
        if (! $best) {
            return null;
        }

        $score = (int) ($best['score'] ?? 0);
        if ($score < $minimumScore) {
            return null;
        }

        $id = (int) ($best['id'] ?? 0);

        return $id > 0 ? $id : null;
    }

    /**
     * @param  array<int,string>  $tokens
     * @param  array<int,string>  $columns
     */
    protected function applyLooseTextSearch(Builder $query, array $tokens, array $columns): void
    {
        if ($tokens === [] || $columns === []) {
            return;
        }

        foreach ($tokens as $token) {
            $like = '%'.$token.'%';
            $query->where(function (Builder $tokenQuery) use ($columns, $like): void {
                foreach ($columns as $index => $column) {
                    if ($index === 0) {
                        $tokenQuery->whereRaw("lower(coalesce({$column}, '')) like ?", [$like]);
                    } else {
                        $tokenQuery->orWhereRaw("lower(coalesce({$column}, '')) like ?", [$like]);
                    }
                }
            });
        }
    }

    /**
     * @param  array<int,string>  $fields
     */
    protected function candidateScoreFromFields(string $needle, array $fields): float
    {
        $best = 0.0;
        foreach ($fields as $value) {
            $normalized = $this->normalizeSearchText($value);
            if ($normalized === '') {
                continue;
            }

            $score = $this->textSimilarity($needle, $normalized);
            if ($score > $best) {
                $best = $score;
            }
        }

        return $best;
    }

    protected function candidateType(Scent $scent, bool $viaWholesaleCustom): string
    {
        if ((bool) ($scent->is_candle_club ?? false)) {
            return 'Subscription Drop';
        }

        if ($viaWholesaleCustom || (bool) ($scent->is_wholesale_custom ?? false)) {
            return (bool) ($scent->is_blend ?? false)
                ? 'Wholesale Custom Blend'
                : 'Wholesale Custom Scent';
        }

        return (bool) ($scent->is_blend ?? false)
            ? 'Canonical Blend'
            : 'Canonical Scent';
    }

    /**
     * @param  array<int,array<string,mixed>>  $candidates
     * @param  array<string,mixed>  $candidate
     */
    protected function upsertCandidate(array &$candidates, array $candidate): void
    {
        $id = (int) ($candidate['id'] ?? 0);
        if ($id <= 0) {
            return;
        }

        if (! isset($candidates[$id])) {
            $candidates[$id] = $candidate;
            return;
        }

        if ((float) ($candidate['score'] ?? 0) > (float) ($candidates[$id]['score'] ?? 0)) {
            $candidates[$id] = $candidate;
            return;
        }

        if ((string) ($candidates[$id]['why'] ?? '') === '' && (string) ($candidate['why'] ?? '') !== '') {
            $candidates[$id]['why'] = $candidate['why'];
        }
    }

    protected function compactSearchText(string $value): string
    {
        $compact = $this->normalizeSearchText($value);
        $compact = preg_replace('/[^a-z0-9]+/i', ' ', $compact) ?? $compact;
        $compact = preg_replace('/\s+/', ' ', $compact) ?? $compact;
        return trim($compact);
    }

    protected function normalizeSearchText(?string $value): string
    {
        $clean = strtolower(trim((string) $value));
        $clean = preg_replace('/\b(wholesale|retail|market|event)\b/i', '', $clean) ?? $clean;
        $clean = str_replace('&', 'and', $clean);
        $clean = preg_replace('/\s+/', ' ', $clean) ?? $clean;
        return trim($clean);
    }

    protected function textSimilarity(string $left, string $right): float
    {
        $left = trim($left);
        $right = trim($right);

        if ($left === '' || $right === '') {
            return 0.0;
        }

        if ($left === $right) {
            return 1.0;
        }

        if (str_contains($left, $right) || str_contains($right, $left)) {
            return 0.92;
        }

        $similar = 0.0;
        similar_text($left, $right, $similar);
        $similarScore = $similar / 100.0;

        $maxLen = max(strlen($left), strlen($right));
        $distanceScore = $maxLen > 0
            ? 1.0 - (levenshtein($left, $right) / $maxLen)
            : 0.0;

        return max(0.0, min(1.0, max($similarScore, $distanceScore)));
    }
}


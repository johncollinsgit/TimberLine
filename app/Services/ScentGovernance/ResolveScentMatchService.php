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
    /** @var array<string,true>|null */
    protected ?array $scentNameTokens = null;

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

        // Strip candle-domain noise (container/size/quantity words) so verbose titles
        // like "Appalachian maple bourbon mason candle 4oz" still reach the real scent.
        // Falls back to the raw tokens if cleaning would leave nothing to search on.
        $searchTokens = $this->significantTokens($tokens);
        if ($searchTokens === []) {
            $searchTokens = $tokens;
        }
        $scoreNeedle = implode(' ', $searchTokens);
        if ($scoreNeedle === '') {
            $scoreNeedle = $needle;
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
            ->where(function (Builder $query) use ($searchTokens, $scentSearchColumns): void {
                $this->applyLooseTextSearch($query, $searchTokens, $scentSearchColumns);
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

            $score = $this->candidateScoreFromFields($scoreNeedle, [
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
                ->where(function (Builder $query) use ($searchTokens, $customSearchColumns, $scentSearchColumns): void {
                    $this->applyLooseTextSearch($query, $searchTokens, $customSearchColumns);
                    $query->orWhereHas('canonicalScent', function (Builder $canonicalQuery) use ($searchTokens, $scentSearchColumns): void {
                        $this->applyLooseTextSearch($canonicalQuery, $searchTokens, $scentSearchColumns);
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
                $score = $this->candidateScoreFromFields($scoreNeedle, [
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
                ->where(function (Builder $query) use ($searchTokens): void {
                    $this->applyLooseTextSearch($query, $searchTokens, ['alias']);
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
                    $this->candidateScoreFromFields($scoreNeedle, [
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

        // Acronym / initialism pass — resolves short codes like "AMB" to
        // "Appalachian Maple Bourbon" even when no abbreviation is stored.
        $acronym = $this->acronymNeedle($searchTokens);
        if ($acronym !== '') {
            $acronymRows = Scent::query()->get([
                'id', 'name', 'display_name', 'abbreviation',
                'is_wholesale_custom', 'is_blend', 'is_candle_club', 'oil_blend_id',
            ]);

            foreach ($acronymRows as $scent) {
                $name = (string) ($scent->display_name ?: $scent->name ?: '');
                if ($name === '') {
                    continue;
                }

                $abbr = strtolower(trim((string) ($scent->abbreviation ?? '')));
                $initials = $this->scentInitials($name);

                if ($abbr !== '' && $abbr === $acronym) {
                    $this->upsertCandidate($candidates, [
                        'id' => (int) $scent->id,
                        'name' => $name,
                        'mapping_type' => $this->candidateType($scent, false),
                        'score' => 0.97,
                        'why' => 'matched scent abbreviation',
                    ]);
                } elseif ($initials !== '' && $initials === $acronym) {
                    $this->upsertCandidate($candidates, [
                        'id' => (int) $scent->id,
                        'name' => $name,
                        'mapping_type' => $this->candidateType($scent, false),
                        'score' => 0.95,
                        'why' => 'matched scent initials',
                    ]);
                }
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

    /**
     * Drop candle-domain noise tokens (containers, sizes, quantities) while never
     * removing a token that is itself a real scent-name word.
     *
     * @param  array<int,string>  $tokens
     * @return array<int,string>
     */
    protected function significantTokens(array $tokens): array
    {
        $stop = $this->matchStopwords();
        $scentWords = $this->scentNameTokenSet();

        $kept = [];
        foreach ($tokens as $token) {
            if ($token === '') {
                continue;
            }
            // Keep anything that is a genuine scent-name word (e.g. a scent named "Cotton").
            if (isset($scentWords[$token])) {
                $kept[] = $token;

                continue;
            }
            // Drop pure sizes/quantities like "4oz", "8", "16oz", "250ml".
            if (preg_match('/^\d+(\.\d+)?(oz|ounce|ounces|ml|g|gram|grams|lb|lbs)?$/', $token) === 1) {
                continue;
            }
            if (isset($stop[$token])) {
                continue;
            }
            $kept[] = $token;
        }

        return array_values(array_unique($kept));
    }

    /**
     * @return array<string,true>
     */
    protected function matchStopwords(): array
    {
        static $set = null;
        if ($set !== null) {
            return $set;
        }

        $words = [
            'candle', 'candles', 'jar', 'jars', 'mason', 'tin', 'tins', 'glass', 'vessel', 'vessels',
            'votive', 'votives', 'tumbler', 'tumblers', 'travel', 'refill', 'refills', 'melt', 'melts',
            'wax', 'tart', 'tarts', 'soy', 'coconut', 'beeswax', 'cotton', 'wooden', 'wick', 'wicks', 'blend',
            'one', 'two', 'three', 'four', 'five', 'six', 'seven', 'eight', 'nine', 'ten', 'single',
            'pack', 'packs', 'set', 'sets', 'qty', 'pcs', 'pc', 'count', 'ct', 'x',
            'the', 'a', 'an', 'of', 'with', 'and', 'for', 'oz', 'ounce', 'ounces', 'ml', 'g', 'gram',
            'grams', 'lb', 'lbs', 'size', 'sizes', 'scent', 'scented', 'fragrance',
        ];

        return $set = array_fill_keys($words, true);
    }

    /**
     * Set of every word that appears in a real scent name/display name, so we never
     * strip a legitimate scent word as if it were noise.
     *
     * @return array<string,true>
     */
    protected function scentNameTokenSet(): array
    {
        if ($this->scentNameTokens !== null) {
            return $this->scentNameTokens;
        }

        $set = [];
        Scent::query()->select(['name', 'display_name'])->get()->each(function (Scent $scent) use (&$set): void {
            foreach ([$scent->name, $scent->display_name] as $value) {
                $compact = $this->compactSearchText($this->normalizeSearchText((string) $value));
                foreach (explode(' ', $compact) as $word) {
                    $word = trim($word);
                    if ($word !== '') {
                        $set[$word] = true;
                    }
                }
            }
        });

        return $this->scentNameTokens = $set;
    }

    /**
     * If the significant tokens are a single short alpha code (e.g. "amb"), return it
     * so it can be matched against scent abbreviations / initials.
     *
     * @param  array<int,string>  $tokens
     */
    protected function acronymNeedle(array $tokens): string
    {
        if (count($tokens) !== 1) {
            return '';
        }

        $token = $tokens[0];
        if (strlen($token) < 2 || strlen($token) > 6 || ! ctype_alpha($token)) {
            return '';
        }

        return $token;
    }

    protected function scentInitials(string $name): string
    {
        $compact = $this->compactSearchText($this->normalizeSearchText($name));
        $stop = $this->matchStopwords();
        $initials = '';

        foreach (explode(' ', $compact) as $word) {
            $word = trim($word);
            if ($word === '' || isset($stop[$word])) {
                continue;
            }
            $initials .= $word[0];
        }

        return $initials;
    }
}

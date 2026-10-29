<?php

namespace App\Services\Team\Search;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SearchIndex
{
    private ?bool $schemaReady = null;

    public function __construct(private SearchMatcher $matcher) {}

    public function available(): bool
    {
        return (config('team_work.search_enabled') || config('team_work.search_index_enabled'))
            && ($this->schemaReady ??= Schema::hasTable('team_search_documents') && Schema::hasTable('team_search_terms'));
    }

    private function hash(int $tenantId, string $token): string
    {
        return hash_hmac('sha256', $tenantId.'|v'.SearchMatcher::VERSION.'|'.$token, hash_hmac('sha256', 'everbranch-team-search', config('app.key')));
    }

    private function grams(string $word): array
    {
        $result = [];
        $characters = mb_str_split($word);
        $length = count($characters);
        for ($i = 0; $i < $length - 1; $i++) {
            $result[] = 'g:'.$characters[$i].$characters[$i + 1];
        }

        return array_unique($result);
    }

    public function put(int $tenantId, string $kind, string $entityId, array $document): void
    {
        if (! $this->available()) {
            return;
        }
        $identity = ['tenant_id' => $tenantId, 'kind' => $kind, 'entity_id' => $entityId];
        $encoded = json_encode($document, JSON_THROW_ON_ERROR);
        $fingerprint = $this->hash($tenantId, $encoded);
        DB::transaction(function () use ($tenantId, $identity, $encoded, $fingerprint, $document) {
            // Serialize with the caller's canonical workspace write / rebuild lock.
            DB::table('team_search_documents')->insertOrIgnore($identity + ['schema_version' => SearchMatcher::VERSION, 'fingerprint' => '', 'payload' => Crypt::encryptString('{}'), 'indexed_at' => now()]);
            $row = DB::table('team_search_documents')->where($identity)->lockForUpdate()->first();
            if ($row->fingerprint === $fingerprint && (int) $row->schema_version === SearchMatcher::VERSION) {
                return;
            }
            $tokens = [];
            foreach (array_unique($this->matcher->words(($document['identifier'] ?? $document['id']).' '.$document['title'].' '.($document['body'] ?? ''))) as $word) {
                $tokens[] = 'w:'.$word;
                $prefixLength = min(500, mb_strlen($word));
                for ($i = 1; $i <= $prefixLength; $i++) {
                    $tokens[] = 'p:'.mb_substr($word, 0, $i);
                }
                foreach ($this->grams($word) as $gram) {
                    $tokens[] = $gram;
                }
            }
            $tokens[] = 'i:'.str_replace(' ', '', $this->matcher->normalize($document['identifier'] ?? $document['id']));
            $title = $this->matcher->normalize($document['title']);
            $tokens[] = 't:'.$title;
            for ($i = 1; $i <= min(500, mb_strlen($title)); $i++) {
                $tokens[] = 'tp:'.mb_substr($title, 0, $i);
            }
            DB::table('team_search_terms')->where(['tenant_id' => $tenantId, 'document_id' => $row->id])->delete();
            foreach (array_chunk(array_values(array_unique($tokens)), 200) as $chunk) {
                DB::table('team_search_terms')->insert(array_map(fn ($token) => ['tenant_id' => $tenantId, 'document_id' => $row->id, 'token' => $this->hash($tenantId, $token)], $chunk));
            }
            DB::table('team_search_documents')->where('id', $row->id)->update(['payload' => Crypt::encryptString($encoded), 'fingerprint' => $fingerprint, 'schema_version' => SearchMatcher::VERSION, 'indexed_at' => now(),
                'sort_closed' => ! empty($document['completed']) || ! empty($document['archived']), 'sort_updated' => (strtotime($document['updated_at'] ?? '') ?: 0) * 1000]);
        });
    }

    public function remove(int $tenantId, string $kind, string $entityId): void
    {
        if (! $this->available()) {
            return;
        }
        DB::transaction(function () use ($tenantId, $kind, $entityId) {
            $ids = DB::table('team_search_documents')->where(['tenant_id' => $tenantId, 'kind' => $kind, 'entity_id' => $entityId])->pluck('id');
            DB::table('team_search_terms')->where('tenant_id', $tenantId)->whereIn('document_id', $ids)->delete();
            DB::table('team_search_documents')->where('tenant_id', $tenantId)->whereIn('id', $ids)->delete();
        });
    }

    /** No provider calls or writes. False positives are rehydrated, authorized and ranked. */
    public function candidates(int $tenantId, string $input): \Illuminate\Database\Query\Builder
    {
        $query = $this->matcher->parse($input);
        $documents = DB::table('team_search_documents')->where('tenant_id', $tenantId)->where('schema_version', SearchMatcher::VERSION);
        foreach ($query['terms'] as $i => $term) {
            $tokens = ['w:'.$term];
            if ($i === count($query['terms']) - 1) {
                $tokens[] = 'p:'.$term;
            }
            if (mb_strlen($term) >= 5) {
                $tokens = [...$tokens, ...$this->grams($term)];
            }
            $hashes = array_map(fn ($token) => $this->hash($tenantId, $token), $tokens);
            $sub = DB::table('team_search_terms')->select('document_id')->where('tenant_id', $tenantId)->whereIn('token', $hashes);
            $documents->whereIn('id', $sub);
        }
        // Identifier normalization admits both J-05 and J05.
        if ($query['text'] !== '') {
            $identifier = $this->hash($tenantId, 'i:'.str_replace(' ', '', $query['text']));
            $documents = DB::table('team_search_documents')->where('tenant_id', $tenantId)->where('schema_version', SearchMatcher::VERSION)
                ->where(fn ($q) => $q->whereIn('id', $documents->select('id'))->orWhereIn('id', DB::table('team_search_terms')->select('document_id')->where('tenant_id', $tenantId)->where('token', $identifier)));
        }

        return $documents;
    }

    /** Exact IDs / titles / title prefixes outrank all fuzzy or body-only matches. */
    public function fastCandidates(int $tenantId, string $input): \Illuminate\Database\Query\Builder
    {
        $query = $this->matcher->parse($input);
        $tokens = [$this->hash($tenantId, 'i:'.str_replace(' ', '', $query['text'])), $this->hash($tenantId, 't:'.$query['text']), $this->hash($tenantId, 'tp:'.$query['text'])];
        $matches = DB::table('team_search_terms')->select('document_id')->where('tenant_id', $tenantId)->whereIn('token', $tokens);

        return DB::table('team_search_documents as d')->whereIn('d.id', $matches)
            ->where('d.tenant_id', $tenantId)->where('d.schema_version', SearchMatcher::VERSION)
            ->select(['d.id', 'd.kind', 'd.entity_id', 'd.sort_closed', 'd.sort_updated'])
            ->selectRaw('CASE WHEN EXISTS (SELECT 1 FROM team_search_terms WHERE tenant_id = ? AND token = ? AND document_id = d.id) THEN 0 WHEN EXISTS (SELECT 1 FROM team_search_terms WHERE tenant_id = ? AND token = ? AND document_id = d.id) THEN 1 ELSE 2 END AS bucket', [$tenantId, $tokens[0], $tenantId, $tokens[1]])
            ->orderBy('bucket')->orderBy('d.sort_closed')->orderByDesc('d.sort_updated')->orderBy('d.kind')->orderBy('d.entity_id');
    }
}

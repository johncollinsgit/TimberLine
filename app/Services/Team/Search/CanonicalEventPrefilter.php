<?php

namespace App\Services\Team\Search;

use App\Models\TeamOperationRecord;
use App\Services\Team\Operations\OperationsSync;

/** References only. A possible hit must still pass current policy, projection and final ranking. */
class CanonicalEventPrefilter
{
    public function mightMatch(object $candidate, SearchMatcher $matcher, array $query): bool
    {
        $record = $candidate->record ?? null;
        if (! $record instanceof TeamOperationRecord || $record->kind !== 'event') {
            return true;
        }
        $candidate->decoded_data = $record->data ?? [];
        $data = $candidate->decoded_data;
        $searchable = array_intersect_key($data, array_flip(SearchDocuments::OPERATION_SEARCH_FIELDS));
        // Source fields are re-extracted from these exact notes by the final
        // projection. Their values/passages are already present; names are below.
        if ($record->connection_id && isset($data['source_id'], $data['source_workspace']) && is_string($data['original_notes'] ?? null)) {
            unset($searchable['fields']);
        }
        // Include date evidence that may supplement the recorded occurrence.
        $searchable['calendar'] = [$data['_teams_enrichment']['calendar'] ?? [], $data['sessions'] ?? []];
        // The final projection may restore reviewed-by/time/source-version values
        // from an exact audited field approval. They are candidates only here.
        $searchable['field_reviews'] = $data['_teams_enrichment']['field_reviews'] ?? [];
        // Raw keys deliberately overmatch the disclosed body. This text never
        // leaves the request or becomes a displayed search document.
        $text = $this->text($searchable).' '.$record->starts_on?->toDateString().' '.$record->ends_on?->toDateString();
        $text .= ' '.implode(' ', array_unique(OperationsSync::NOTE_FIELDS)).' explicit_label needs_review approved';
        // A disclosed phrase can cross two permitted fields separated by a hidden
        // field in raw data. Test its terms here; enforce the exact phrase only on
        // the authorized final projection.
        $query['phrases'] = [];

        return $matcher->score(['id' => 'operation:'.$record->id, 'type' => 'event', 'identifier' => $record->id, 'title' => $record->title, 'body' => $text], $query) !== null;
    }

    private function text(mixed $value): string
    {
        if (is_scalar($value)) {
            return (string) $value;
        }
        if (! is_array($value)) {
            return '';
        }
        $parts = [];
        foreach ($value as $key => $child) {
            $parts[] = (string) $key.' '.$this->text($child);
        }

        return implode(' ', $parts);
    }
}

<?php

namespace App\Services\Trajectory;

use App\Models\Trajectory\MedicalDocument;
use App\Models\Trajectory\Record;
use App\Models\Trajectory\Space;
use Illuminate\Support\Str;

class MedicalDocumentLookup
{
    /** Called inside an authorized household assistant request. Only document metadata is read. */
    public function answer(Space $space, string $question, array $context): ?array
    {
        if ($space->kind !== 'household' || preg_match('/\b(categorize|classify|move this transaction|split|undo)\b/i', $question)) {
            return null;
        }
        $medical = ($context['level'] ?? '') === 'medical' || preg_match('/\b(medical|hospital|provider|surgery|physician)\b/i', $question);
        $asksForOriginal = preg_match('/\b(doc(?:ument)?s?|originals?|statements?|invoices?|pdfs?)\b/i', $question);
        $asksForTerms = preg_match('/\b(balance|due|owe|payment plan|autopay|servicer|transfer(?:red)?)\b/i', $question);
        if (! $asksForOriginal && ! $asksForTerms) {
            return null;
        }
        $ignore = explode(' ', 'a an and any are as at attached bill bills billing by can case cases confirm confirms did do doc docs document documents does download file files find for from get give has have how i in invoice invoices is it its list me medical my of on open original originals our pdf pdfs please read said saved say says see share show statement statements tell the there these this to uploaded us was balance balances amount much owe owed due paying payment payments plan autopay when scheduled outstanding now servicer transferred transfer what whats where which with you');
        $terms = array_diff(preg_split('/[^\pL\pN]+/u', Str::lower($question), -1, PREG_SPLIT_NO_EMPTY), $ignore);
        $terms = array_unique(array_filter($terms, fn ($term) => mb_strlen($term) >= 3));
        if (! $terms && ! $medical) {
            return null;
        }
        $cases = Record::where('space_id', $space->id)->where('kind', 'medical_need')->where('active', true)->pluck('name', 'id');
        $rows = MedicalDocument::where('space_id', $space->id)->where(fn ($q) => $q->whereNull('need_id')->orWhereIn('need_id', $cases->keys()))->latest('id')->get(MedicalDocument::LIST_COLUMNS);
        $ranked = [];
        foreach ($rows as $row) {
            $case = $row->need_id ? $cases[$row->need_id] : 'Household medical documents';
            $metadata = $row->metadata;
            $text = Str::lower($case.' '.$metadata['filename'].' '.($metadata['note'] ?? ''));
            $words = preg_split('/[^\pL\pN]+/u', $text, -1, PREG_SPLIT_NO_EMPTY);
            $score = count(array_intersect($terms, $words));
            if ($score || ($medical && ! $terms)) {
                $ranked[] = ['score' => $score, 'row' => $row, 'case' => $case];
            }
        }
        if (! $ranked) {
            return $medical && $asksForOriginal ? ['reply' => 'I couldn’t find a saved original matching that request. Open Medical sharing and choose Original documents under the case.'] : null;
        }
        $matches = collect($ranked)->sortByDesc('score');
        $matches = $matches->where('score', $matches->first()['score']);
        $sources = $matches->take(3)->map(fn ($match) => [
            'need_id' => $match['row']->need_id, 'id' => $match['row']->id, 'case' => $match['case'],
            'filename' => $match['row']->metadata['filename'], 'note' => $match['row']->metadata['note'] ?? null,
        ])->values();

        return ['reply' => $matches->count() > $sources->count() ? 'These are the three most recent matching originals. More are available under the case in Medical sharing.' : 'Here are the matching originals saved in Medical sharing.', 'medical_documents' => $sources->all()];
    }
}

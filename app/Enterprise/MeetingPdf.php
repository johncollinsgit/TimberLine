<?php

namespace App\Enterprise;

/** Fixed-layout meeting snapshot, using the same quoted-text/URI-only PDF contract as BriefingPdf. */
class MeetingPdf
{
    public function render(array $document): string
    {
        $meeting = $document['meeting'];
        $roll = $document['roll_call'];
        $owners = $document['owners'];
        $content = "0.12 0.23 0.18 rg 0 712 612 80 re f\n";
        $links = [];
        $text = function (string $value, int $y, int $size = 9, bool $bold = false, string $color = '0.12 0.23 0.18', int $x = 36, int $width = 540) use (&$content): void {
            $value = $this->fit($value, $width, $size);
            $content .= "$color rg BT /".($bold ? 'F2' : 'F1')." $size Tf 1 0 0 1 $x $y Tm ".$this->literal($value)." Tj ET\n";
        };
        $link = function (string $url, int $y, string $label = 'Open source', int $x = 482) use (&$links, $text): void {
            if (strlen($url) <= 2048 && preg_match('~^https?://[^\s]+$~D', $url)) {
                $links[] = compact('url', 'y', 'x');
                $text($label, $y, 8, false, '0.16 0.39 0.28', $x, 576 - $x);
            }
        };
        $text('EVERBRANCH ENTERPRISE / MEETING ROLL CALL', 763, 10, true, '1 1 1');
        $text($meeting['title'], 733, 20, true, '1 1 1');
        $text($roll['scope']['label'], 689, 12, true);
        $text('AS OF '.$roll['as_of'].' | CURRENT RECORD-DERIVED AGENDA', 670, 8);
        $text($roll['label'], 651, 9, true, $roll['sample_data'] ? '0.58 0.28 0.08' : '0.16 0.39 0.28');
        $text('PRIORITIES & AGENDA', 620, 11, true);
        $y = 598;
        foreach (array_slice($roll['items'], 0, 4) as $item) {
            $text(strtoupper(str_replace('_', ' ', $item['kind'])).' | '.$item['title'], $y, 10, true);
            $text($item['reason'], $y - 13, 9, false, '0.12 0.23 0.18', 36, 375);
            $updated = $item['source_updated_at'] ? \Carbon\CarbonImmutable::parse($item['source_updated_at'])->utc()->format('Y-m-d H:i').' UTC' : 'Unknown';
            $text('Updated: '.$updated, $y - 13, 8, false, '0.12 0.23 0.18', 420, 156);
            $text('Owner: '.($item['owner']['name'] ?? 'Unassigned').' | Due: '.($item['due_on'] ?? 'Unknown'), $y - 25, 8);
            $text('Next: '.$item['next_action'], $y - 37, 8, false, '0.12 0.23 0.18', 36, 435);
            $link($item['source_url'], $y - 37);
            $y -= 50;
        }
        if ($roll['items'] === []) {
            $text('No priorities in the checked sample. This does not establish no campus work.', $y);
        }
        $text('DECISIONS', 388, 10, true);
        $y = 369;
        foreach (array_slice($meeting['decisions'], 0, 3) as $entry) {
            $text('- '.$entry['text'], $y);
            $y -= 22;
        }
        if ($meeting['decisions'] === []) {
            $text('No decisions recorded.', $y);
        }
        $text('BLOCKERS', 297, 10, true);
        $y = 278;
        foreach (array_slice($meeting['blockers'], 0, 2) as $entry) {
            $text($entry['text'], $y, 9, true);
            $text('Owner: '.($owners[(string) ($entry['owner_id'] ?? '')] ?? 'Unassigned').' | Due: '.($entry['due_on'] ?? 'Unknown'), $y - 12, 8);
            $y -= 27;
        }
        if ($meeting['blockers'] === []) {
            $text('No meeting blockers recorded.', $y);
        }
        $text('COMMITMENTS / EXACT PREVIEW REQUIRED BEFORE TASK CHANGES', 219, 9, true);
        $y = 200;
        foreach (array_slice($meeting['commitments'], 0, 3) as $entry) {
            $applied = isset($meeting['applied'][$entry['id']]);
            $text(($applied ? 'CONFIRMED | ' : 'PROPOSED | ').$entry['text'], $y, 9, true);
            $text('Owner: '.($owners[(string) $entry['owner_id']] ?? 'Unavailable owner').' | Due: '.$entry['due_on'], $y - 13, 8);
            $y -= 30;
        }
        if ($meeting['commitments'] === []) {
            $text('No commitments recorded.', $y);
        }
        $overflow = ['priorities' => max(0, $roll['total_visible'] - 4), 'decisions' => max(0, count($meeting['decisions']) - 3),
            'blockers' => max(0, count($meeting['blockers']) - 2), 'commitments' => max(0, count($meeting['commitments']) - 3)];
        $text('OVERFLOW: '.$overflow['priorities'].' priorities / '.$overflow['decisions'].' decisions / '.$overflow['blockers'].' blockers / '.$overflow['commitments'].' commitments. See complete view.', 107, 8, true);
        $text('Authorized bounded records; no live college feeds or private ratings. Long text is abridged; see complete view.', 93, 8);
        $text('Dated snapshot; download again to regenerate. Due dates: Eastern. Updated times: UTC. Latin transliteration.', 80, 8);
        $link($meeting['url'], 60, 'Open the complete authorized meeting view', 36);
        $text('Leader-private notes. Source access is rechecked for every download.', 39, 8);
        $text('1 / 1', 21, 8, false, '0.12 0.23 0.18', 546, 30);

        return $this->pdf($content, $links);
    }

    private function literal(string $text): string
    {
        $text = preg_replace('/[\x00-\x1F\x7F]/u', ' ', $text) ?? '';
        $text = iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $text) ?: '';

        return '('.str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text).')';
    }

    private function fit(string $text, int $width, int $size): string
    {
        $text = preg_replace('/\s+/u', ' ', $text) ?? '';
        $measure = function (string $value) use ($size): float {
            $latin = iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $value) ?: '';
            $units = 0;
            foreach (str_split($latin) as $char) {
                $units += str_contains('ilI.,:;!| ', $char) ? 300 : (str_contains('MW@mw', $char) ? 1000 : (ctype_upper($char) ? 850 : (ord($char) > 127 ? 1000 : 650)));
            }

            return $units * $size / 1000;
        };
        if ($measure($text) <= $width) {
            return $text;
        }
        do {
            $text = mb_substr($text, 0, -1);
        } while ($text !== '' && $measure($text.'...') > $width);

        return $text.'...';
    }

    private function pdf(string $content, array $links): string
    {
        $objects = ['<< /Type /Catalog /Pages 2 0 R >>', '<< /Type /Pages /Kids [3 0 R] /Count 1 >>', '',
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>',
            '<< /Length '.strlen($content)." >>\nstream\n".$content.'endstream'];
        $annots = [];
        foreach ($links as $entry) {
            $objects[] = '<< /Type /Annot /Subtype /Link /Rect ['.$entry['x'].' '.($entry['y'] - 2).' 576 '.($entry['y'] + 10).'] /Border [0 0 0] /A << /S /URI /URI '.$this->literal($entry['url']).' >> >>';
            $annots[] = count($objects).' 0 R';
        }
        $objects[2] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 4 0 R /F2 5 0 R >> >> /Contents 6 0 R /Annots ['.implode(' ', $annots).'] >>';
        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        foreach ($objects as $i => $object) {
            $offsets[] = strlen($pdf);
            $pdf .= ($i + 1)." 0 obj\n".$object."\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 ".(count($objects) + 1)."\n0000000000 65535 f \n";
        foreach ($offsets as $offset) {
            $pdf .= sprintf('%010d 00000 n ', $offset)."\n";
        }

        return $pdf."trailer\n<< /Size ".(count($objects) + 1).' /Root 1 0 R >>'."\nstartxref\n".$xref."\n%%EOF\n";
    }
}

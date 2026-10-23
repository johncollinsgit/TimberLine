<?php

namespace App\Enterprise;

/** Fixed one-page Letter document. No HTML parser, file import, remote asset or executable PDF action. */
class BriefingPdf
{
    public function render(array $briefing): string
    {
        $content = "0.12 0.23 0.18 rg\n0 704 612 88 re f\n";
        $text = function (string $value, float $x, float $y, int $size = 10, bool $bold = false, string $color = '0.12 0.23 0.18') use (&$content): void {
            $value = $this->fitWidth($value, 576 - $x, $size);
            $content .= $color.' rg BT /'.($bold ? 'F2' : 'F1')." $size Tf 1 0 0 1 $x $y Tm ".$this->literal($value)." Tj ET\n";
        };
        $links = [];
        $link = function (string $url, float $y, int $number) use (&$links, $text): void {
            // All URLs originate in Briefing::build, never record/model text. Still reject PDF action schemes.
            if (strlen($url) <= 2048 && preg_match('~^https?://[^\s]+$~D', $url)) {
                $links[] = ['url' => $url, 'y' => $y];
                $text('['.$number.'] Open record', 478, $y, 8, false, '0.16 0.39 0.28');
            }
        };
        $text('EVERBRANCH ENTERPRISE  /  CAMPUS BRIEFING', 36, 764, 10, true, '1 1 1');
        $text($this->fit($briefing['scope']['department'], 38), 36, 733, 22, true, '1 1 1');
        $text($this->fit($briefing['scope']['workspace'], 75), 36, 683, 11, true);
        $text('AS OF '.$briefing['as_of'].'  |  YOUR AUTHORIZED WORKSPACE', 36, 665, 8);
        $text($this->fit($briefing['label'], 105), 36, 647, 9, true, $briefing['sample_data'] ? '0.58 0.28 0.08' : '0.16 0.39 0.28');
        $text($briefing['summary']['headline'], 36, 616, 16, true);
        foreach ($this->wrap($briefing['summary']['text'], 101, 2) as $i => $line) {
            $text($line, 36, 596 - $i * 13, 9);
        }
        $text('Current record summary - no generated narrative. Refreshed for this download.', 36, 563, 8);
        $content .= "0.82 0.87 0.81 RG 36 550 m 576 550 l S\n";
        $text('PROJECTS IN VIEW', 36, 529, 10, true);
        $text('Completed / checked tasks  |  recorded status', 285, 529, 8);
        $y = 508;
        $number = 1;
        foreach (array_slice($briefing['projects'], 0, 8) as $project) {
            $text($this->fit($project['title'], 67), 36, $y, 10, true);
            $line = $project['completed_tasks'].' / '.$project['tasks'].' tasks completed | '.$project['status'];
            if ($project['overdue_tasks']) {
                $line .= ' | '.$project['overdue_tasks'].' overdue';
            }
            $text($line, 36, $y - 14, 9);
            $link($project['url'], $y - 14, $number++);
            $y -= 35;
        }
        if ($briefing['projects'] === []) {
            $text('No readable projects in the checked sample. This is not evidence of no campus work.', 36, $y, 9);
        }
        $content .= "0.82 0.87 0.81 RG 36 219 m 576 219 l S\n";
        $text('RECENT REQUESTS IN VIEW', 36, 200, 10, true);
        $y = 181;
        foreach (array_slice($briefing['requests'], 0, 4) as $request) {
            $text($this->fit($request['title'], 60), 36, $y, 9, true);
            $text(str_replace('_', ' ', $request['status']).($request['due_on'] ? ' | due '.$request['due_on'] : ''), 36, $y - 11, 8);
            $link($request['url'], $y - 11, $number++);
            $y -= 24;
        }
        if ($briefing['requests'] === []) {
            $text('No readable requests in the checked sample.', 36, $y, 9);
        }
        $text('COVERAGE & FRESHNESS', 36, 74, 8, true);
        $text('Selected workspace; checked max 100 projects / 1,000 linked tasks / 100 requests from 1,000 scanned.', 36, 61, 8);
        $text('Shown max 8 projects / 4 requests. Unauthorized records, other departments and live feeds are excluded.', 36, 49, 8);
        $text('Snapshot, not all campus activity. Refresh to regenerate. Record titles use Latin-script transliteration.', 36, 37, 8);
        $text('1 / 1', 546, 20, 8);

        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '',
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>',
            '<< /Length '.strlen($content)." >>\nstream\n".$content.'endstream',
        ];
        $annots = [];
        foreach ($links as $entry) {
            $objects[] = '<< /Type /Annot /Subtype /Link /Rect [474 '.($entry['y'] - 2).' 576 '.($entry['y'] + 10).'] /Border [0 0 0] /A << /S /URI /URI '.$this->literal($entry['url']).' >> >>';
            $annots[] = count($objects).' 0 R';
        }
        $objects[2] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 4 0 R /F2 5 0 R >> >> /Contents 6 0 R /Annots ['.implode(' ', $annots).'] >>';
        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [0];
        foreach ($objects as $i => $object) {
            $offsets[] = strlen($pdf);
            $pdf .= ($i + 1)." 0 obj\n".$object."\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= 'xref'."\n0 ".(count($objects) + 1)."\n0000000000 65535 f \n";
        foreach (array_slice($offsets, 1) as $offset) {
            $pdf .= sprintf('%010d 00000 n ', $offset)."\n";
        }

        return $pdf.'trailer'."\n<< /Size ".(count($objects) + 1).' /Root 1 0 R >>'."\nstartxref\n".$xref."\n%%EOF\n";
    }

    private function fit(string $text, int $length): string
    {
        $text = preg_replace('/\s+/u', ' ', $text) ?? '';

        return mb_strlen($text) > $length ? mb_substr($text, 0, $length - 3).'...' : $text;
    }

    private function wrap(string $text, int $length, int $lines): array
    {
        return array_slice(explode("\n", wordwrap($this->fit($text, $length * $lines - 5), $length, "\n", true)), 0, $lines);
    }

    private function literal(string $text): string
    {
        $text = preg_replace('/[\x00-\x1F\x7F]/u', ' ', $text) ?? '';
        $text = iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $text) ?: '';

        return '('.str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text).')';
    }

    private function fitWidth(string $text, float $width, int $size): string
    {
        // Conservative upper bounds for the built-in Helvetica faces, including wide glyphs.
        $measure = function (string $value) use ($size): float {
            $latin = iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $value) ?: '';
            $units = 0;
            foreach (str_split($latin) as $char) {
                $units += str_contains('ilI.,:;!| ', $char) ? 300
                    : (str_contains('MW@mw', $char) ? 1000 : (ctype_upper($char) ? 850 : (ord($char) > 127 ? 1000 : 650)));
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
}

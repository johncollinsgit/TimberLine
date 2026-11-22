<?php

namespace App\Services\Marketplace;

use Illuminate\Validation\ValidationException;

class MakerVideoCaptions
{
    /** Keep a narrow WebVTT subset so timestamps and plain-text cues survive review unchanged. */
    public function normalize(?string $input, ?float $duration = null): ?string
    {
        $text = trim(str_replace(["\r\n", "\r"], "\n", (string) $input));
        if ($text === '' || $text === 'WEBVTT') {
            return null;
        }
        $blocks = preg_split('/\n[ \t]*\n+/', $text);
        if (array_shift($blocks) !== 'WEBVTT' || ! $blocks) {
            throw ValidationException::withMessages(['captions' => 'Start captions with WEBVTT, a blank line, then timestamped cues.']);
        }
        $normalized = ['WEBVTT'];
        foreach ($blocks as $block) {
            $lines = explode("\n", $block);
            if (! str_contains($lines[0], ' --> ')) {
                array_shift($lines); // Optional cue identifier.
            }
            $times = explode(' --> ', (string) array_shift($lines));
            $start = $this->seconds($times[0] ?? '');
            $end = $this->seconds($times[1] ?? '');
            if (count($times) !== 2 || $start === null || $end === null || $end <= $start || ! $lines || ($duration !== null && $end > $duration + 0.1)) {
                throw ValidationException::withMessages(['captions' => 'Each cue needs text and increasing start and end times inside the edited video.']);
            }
            $normalized[] = implode("\n", [implode(' --> ', $times), ...array_map(fn ($line) => htmlspecialchars(html_entity_decode($line, ENT_QUOTES | ENT_HTML5, 'UTF-8'), ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8'), $lines)]);
        }

        return implode("\n\n", $normalized)."\n";
    }

    private function seconds(string $stamp): ?float
    {
        if (! preg_match('/^(?:(\d{2,}):)?([0-5]\d):([0-5]\d)\.(\d{3})$/D', $stamp, $match)) {
            return null;
        }

        return (int) $match[1] * 3600 + (int) $match[2] * 60 + (int) $match[3] + (int) $match[4] / 1000;
    }
}

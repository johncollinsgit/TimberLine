<?php

namespace App\Services\Marketing;

use App\Models\MarketingShortLink;
use Illuminate\Support\Str;

class MarketingLinkShortenerService
{
    /**
     * @return array{
     *   message:string,
     *   links:array<int,array{
     *     original:string,
     *     shortened:string,
     *     code:string
     *   }>
     * }
     */
    public function shortenMessage(string $message, ?int $createdBy = null): array
    {
        $message = trim($message);
        if ($message === '' || !(bool) config('marketing.links.enabled', true)) {
            return [
                'message' => $message,
                'links' => [],
            ];
        }

        if (!preg_match_all('/https?:\/\/[^\s<>"\']+/i', $message, $matches, PREG_OFFSET_CAPTURE)) {
            return [
                'message' => $message,
                'links' => [],
            ];
        }

        $updated = $message;
        $links = [];
        $shortenedByOriginal = [];
        $occurrences = $matches[0];

        // Replace from right to left so offsets remain stable.
        for ($i = count($occurrences) - 1; $i >= 0; $i--) {
            $raw = (string) ($occurrences[$i][0] ?? '');
            $offset = (int) ($occurrences[$i][1] ?? 0);
            if ($raw === '') {
                continue;
            }

            [$cleanUrl, $suffix] = $this->splitTrailingPunctuation($raw);
            if ($cleanUrl === '' || !$this->isValidUrl($cleanUrl)) {
                continue;
            }

            $shortened = $shortenedByOriginal[$cleanUrl] ?? null;
            if ($shortened === null) {
                $record = $this->shortenUrl($cleanUrl, $createdBy);
                $shortened = [
                    'original' => $cleanUrl,
                    'shortened' => $record['short_url'],
                    'code' => $record['code'],
                ];
                $shortenedByOriginal[$cleanUrl] = $shortened;
                $links[] = $shortened;
            }

            $replacement = $shortened['shortened'] . $suffix;
            $updated = substr_replace($updated, $replacement, $offset, strlen($raw));
        }

        return [
            'message' => $updated,
            'links' => array_values(array_reverse($links)),
        ];
    }

    /**
     * @return array{code:string,short_url:string,destination_url:string}
     */
    public function shortenUrl(string $url, ?int $createdBy = null): array
    {
        $url = trim($url);
        if (!$this->isValidUrl($url)) {
            throw new \InvalidArgumentException('A valid http(s) URL is required.');
        }

        $hash = hash('sha256', strtolower($url));
        $existing = MarketingShortLink::query()
            ->where('url_hash', $hash)
            ->first();

        if ($existing) {
            return [
                'code' => $existing->code,
                'short_url' => $this->shortUrl($existing->code),
                'destination_url' => $existing->destination_url,
            ];
        }

        $code = $this->nextUniqueCode();
        $link = MarketingShortLink::query()->create([
            'code' => $code,
            'destination_url' => $url,
            'url_hash' => $hash,
            'created_by' => $createdBy,
        ]);

        return [
            'code' => $link->code,
            'short_url' => $this->shortUrl($link->code),
            'destination_url' => $link->destination_url,
        ];
    }

    public function shortUrl(string $code): string
    {
        $baseUrl = trim((string) config('marketing.links.base_url', ''));
        $prefix = trim((string) config('marketing.links.path_prefix', 'go'), '/');

        if ($baseUrl !== '') {
            $base = rtrim($baseUrl, '/');
            if ($prefix === '') {
                return "{$base}/{$code}";
            }

            return "{$base}/{$prefix}/{$code}";
        }

        return route('marketing.short-links.redirect', ['code' => $code]);
    }

    protected function isValidUrl(string $url): bool
    {
        $valid = filter_var($url, FILTER_VALIDATE_URL);
        if ($valid === false) {
            return false;
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        return in_array($scheme, ['http', 'https'], true);
    }

    /**
     * @return array{0:string,1:string}
     */
    protected function splitTrailingPunctuation(string $raw): array
    {
        $suffix = '';
        $trimChars = '.,!?;:)]}';
        while ($raw !== '') {
            $last = substr($raw, -1);
            if ($last === false || !str_contains($trimChars, $last)) {
                break;
            }

            $suffix = $last . $suffix;
            $raw = substr($raw, 0, -1);
        }

        return [$raw, $suffix];
    }

    protected function nextUniqueCode(): string
    {
        do {
            $code = Str::lower(Str::random(7));
        } while (MarketingShortLink::query()->where('code', $code)->exists());

        return $code;
    }
}

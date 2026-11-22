<?php

namespace App\Services\ManagedWebsite;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use SimpleXMLElement;

class StudioEpisodeFeed
{
    private const NS = 'https://theeverbranch.com/ns/studio/1';

    public function url(int $tenant): string
    {
        $url = config('studio_distribution.feeds')[$tenant] ?? null;
        abort_unless(is_string($url) && parse_url($url, PHP_URL_SCHEME) === 'https' && parse_url($url, PHP_URL_HOST)
            && ! parse_url($url, PHP_URL_USER) && ! parse_url($url, PHP_URL_PASS) && ! parse_url($url, PHP_URL_PORT), 404);

        return $url;
    }

    public function episodes(int $tenant): array
    {
        $url = $this->url($tenant);
        try {
            $response = Http::withoutRedirecting()->withOptions(['stream' => true])->connectTimeout(3)->timeout(10)->get($url);
        } catch (\Illuminate\Http\Client\ConnectionException) {
            abort(503, 'The episode feed is unavailable.');
        }
        abort_unless($response->successful(), 503, 'The episode feed is unavailable.');
        $stream = $response->toPsrResponse()->getBody();
        $body = '';
        try {
            while (! $stream->eof()) {
                $chunk = $stream->read(65536);
                abort_if(($chunk === '' && ! $stream->eof()) || strlen($body) + strlen($chunk) > 8 * 1024 * 1024, 503, 'The episode feed could not be read.');
                $body .= $chunk;
            }
        } finally {
            $stream->close();
        }
        abort_if(preg_match('/<!DOCTYPE/i', $body), 503, 'The episode feed could not be read.');
        $rss = simplexml_load_string($body, SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        abort_unless($rss && isset($rss->channel), 503, 'The episode feed could not be read.');
        $episodes = [];
        foreach ($rss->channel->item as $item) {
            $guid = (string) $item->guid;
            $revision = (string) $item->children(self::NS)->revision;
            if (! str_starts_with($guid, 'urn:uuid:') || ! Str::isUuid(substr($guid, 9)) || ! Str::isUuid($revision)) {
                continue;
            }
            $assets = [];
            foreach ($item->children(self::NS)->asset as $asset) {
                $attributes = $asset->attributes();
                $kind = (string) $attributes['kind'];
                $href = (string) $attributes['url'];
                if (! in_array($kind, ['episode_video', 'short', 'trailer', 'captions'], true) || ! $this->validAsset($url, $href, $kind === 'captions' ? 'vtt' : 'mp4')) {
                    continue;
                }
                $digest = pathinfo(parse_url($href, PHP_URL_PATH), PATHINFO_FILENAME);
                $assets[$digest] = ['kind' => $kind, 'url' => $href, 'digest' => $digest];
            }
            $audio = (string) $item->enclosure['url'];
            $id = substr($guid, 9);
            if ($this->validAsset($url, $audio, 'mp3') && collect($assets)->contains('kind', 'episode_video')) {
                $episodes[$id] = ['id' => $id, 'revision' => $revision, 'title' => (string) $item->title,
                    'description' => (string) $item->description, 'audio' => $audio, 'assets' => $assets];
            }
        }

        return $episodes;
    }

    private function validAsset(string $feed, string $url, string $extension): bool
    {
        $base = parse_url($feed);
        $path = rtrim(dirname($base['path'] ?? '/'), '/').'/media/';
        $prefix = 'https://'.$base['host'].$path;

        return str_starts_with($url, $prefix) && preg_match('/^[a-f0-9]{64}\.'.$extension.'$/D', substr($url, strlen($prefix)));
    }

    public function asset(int $tenant, string $id, string $revision, string $digest): array
    {
        $episode = $this->episodes($tenant)[$id] ?? null;
        abort_unless($episode && $episode['revision'] === $revision, 409, 'The episode revision changed. Refresh to review it.');
        $asset = $episode['assets'][$digest] ?? null;
        abort_unless($asset && $asset['kind'] !== 'captions', 422, 'Choose a published video from this episode.');

        return [$episode, $asset];
    }
}

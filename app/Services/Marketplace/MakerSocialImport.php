<?php

namespace App\Services\Marketplace;

use App\Services\Marketing\Desk\MetaMarketingClient;
use Illuminate\Support\Facades\Http;

class MakerSocialImport
{
    public function __construct(private MetaMarketingClient $meta) {}

    public function clips(int $tenant, int $id, string $platform): array
    {
        $connection = $this->meta->connection($tenant, $id, $platform, 'media');
        $endpoint = $this->meta->account($connection).($platform === 'instagram' ? '/media' : '/videos');
        $fields = $platform === 'instagram' ? 'id,caption,media_type,media_url,permalink' : 'id,title,description,permalink_url,source';
        $response = $this->meta->request($connection, 'GET', $endpoint, ['fields' => $fields, 'limit' => 50]);
        $clips = [];
        foreach ($response['data'] ?? [] as $item) {
            if ($platform === 'instagram' && ($item['media_type'] ?? '') !== 'VIDEO') {
                continue;
            }
            $source = $platform === 'instagram' ? ($item['media_url'] ?? '') : ($item['source'] ?? '');
            $id = (string) ($item['id'] ?? '');
            if (! preg_match('/^[0-9]{1,40}$/D', $id) || ! $this->validCdn($source)) {
                continue;
            }
            $description = trim((string) ($item['description'] ?? $item['caption'] ?? ''));
            $url = (string) ($item['permalink'] ?? $item['permalink_url'] ?? '');
            if ($platform === 'facebook' && str_starts_with($url, '/') && ! str_starts_with($url, '//')) {
                $url = 'https://www.facebook.com'.$url;
            }
            $clips[$id] = ['id' => $id, 'title' => mb_substr((string) ($item['title'] ?? strtok($description, "\n") ?: ucfirst($platform).' video'), 0, 160),
                'description' => mb_substr($description, 0, 5000), 'url' => self::postUrl($url) ?? '', 'source' => $source];
        }

        return $clips;
    }

    public static function postUrl(string $input): ?string
    {
        $parts = parse_url(trim($input));
        if (! is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])) {
            return null;
        }
        $host = strtolower($parts['host'] ?? '');
        $path = $parts['path'] ?? '';
        $allowed = match ($host) {
            'instagram.com', 'www.instagram.com' => '#^/(reels?|p)/[A-Za-z0-9_-]+/?$#D',
            'www.facebook.com', 'facebook.com', 'm.facebook.com' => '#^/(reel/[0-9]+|[A-Za-z0-9_.-]+/videos/(?:[A-Za-z0-9_-]+/)?[0-9]+|share/(?:r|v)/[A-Za-z0-9_-]+)/?$#D',
            'www.youtube.com', 'youtube.com', 'm.youtube.com' => '#^/shorts/[A-Za-z0-9_-]{11}/?$#D',
            'youtu.be' => '#^/[A-Za-z0-9_-]{11}/?$#D',
            default => null,
        };
        if (in_array($host, ['www.facebook.com', 'facebook.com', 'm.facebook.com'], true) && $path === '/watch/' && preg_match('/(?:^|&)v=(\d+)(?:&|$)/D', $parts['query'] ?? '', $match)) {
            return 'https://www.facebook.com/watch/?v='.$match[1];
        }

        return $allowed && preg_match($allowed, $path) ? 'https://'.$host.$path : null;
    }

    private function validCdn(string $url): bool
    {
        $value = parse_url($url);
        $host = strtolower($value['host'] ?? '');

        return ($value['scheme'] ?? null) === 'https' && ! isset($value['port']) && ! isset($value['user']) && ! isset($value['pass'])
            && (str_ends_with($host, '.cdninstagram.com') || str_ends_with($host, '.fbcdn.net'));
    }

    public function download(string $url)
    {
        abort_unless($this->validCdn($url), 422);
        $max = min((int) config('marketplace.video_upload_mb'), 256) * 1048576;
        $tmp = tempnam(sys_get_temp_dir(), 'maker-social-');
        abort_unless($tmp !== false, 503);
        try {
            $response = Http::withoutRedirecting()->connectTimeout(8)->timeout(120)->withOptions([
                'sink' => $tmp, 'progress' => static function ($total, $received) use ($max) {
                    abort_if($total > $max || $received > $max, 413, 'Use the original video upload for a larger file.');
                },
            ])->get($url);
            abort_unless($response->ok(), 422, 'The platform could not release this video. Use your original file.');
            clearstatcache(true, $tmp);
            $size = filesize($tmp);
            abort_unless($size > 0 && $size <= $max, 422);
            $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($tmp);
            abort_unless(in_array($mime, ['video/mp4', 'video/quicktime', 'video/webm'], true), 422, 'Use an MP4, MOV or WebM original.');

            return response()->download($tmp, 'maker-video', ['Content-Type' => $mime, 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff'])->deleteFileAfterSend(true);
        } catch (\Throwable $error) {
            if (is_file($tmp)) {
                unlink($tmp);
            }
            throw $error;
        }
    }
}

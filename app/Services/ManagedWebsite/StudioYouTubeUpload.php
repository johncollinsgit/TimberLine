<?php

namespace App\Services\ManagedWebsite;

use App\Models\IntegrationConnection;
use App\Models\StudioEpisodeDelivery;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class StudioYouTubeUpload
{
    // A bounded chunk per queue job. Google determines the confirmed offset on every retry.
    public function transfer(StudioEpisodeDelivery $delivery, IntegrationConnection $connection, array $asset): bool
    {
        $details = $delivery->details;
        $oauth = app(StudioYouTubeAccount::class)->access($connection);
        $api = fn () => Http::withoutRedirecting()->withToken($oauth)->timeout(40)->connectTimeout(5);
        if (! isset($details['session'])) {
            $head = Http::withoutRedirecting()->timeout(10)->head($asset['url']);
            $size = (int) $head->header('Content-Length');
            abort_unless($head->successful() && $size > 0, 422, 'A public video with a known length is required.');
            $metadata = ['snippet' => ['title' => $details['title'], 'description' => $details['description'], 'categoryId' => '22'],
                'status' => ['privacyStatus' => $details['privacy'], 'selfDeclaredMadeForKids' => $details['made_for_kids']]];
            if (! empty($details['publish_at'])) {
                $metadata['status']['publishAt'] = $details['publish_at'];
            }
            $start = $api()->withHeaders(['X-Upload-Content-Length' => (string) $size, 'X-Upload-Content-Type' => 'video/mp4'])
                ->post('https://www.googleapis.com/upload/youtube/v3/videos?uploadType=resumable&part=snippet,status&notifySubscribers=false', $metadata);
            $session = (string) $start->header('Location');
            abort_unless($start->successful() && parse_url($session, PHP_URL_SCHEME) === 'https'
                && in_array(parse_url($session, PHP_URL_HOST), ['www.googleapis.com', 'youtube.googleapis.com'], true)
                && parse_url($session, PHP_URL_PATH) === '/upload/youtube/v3/videos' && ! parse_url($session, PHP_URL_USER)
                && ! parse_url($session, PHP_URL_PASS) && ! parse_url($session, PHP_URL_PORT), 422, 'YouTube could not start an upload.');
            $details = [...$details, 'session' => $session, 'bytes' => $size];
            $delivery->update(['details' => $details, 'status' => 'uploading']);
        }
        $size = $details['bytes'];
        $url = $details['session'];
        $probe = $api()->withHeaders(['Content-Range' => 'bytes */'.$size])->withBody('', 'video/mp4')->put($url);
        if ($probe->successful()) {
            return $this->finish($delivery, $probe);
        }
        if (in_array($probe->status(), [404, 410], true)) {
            $delivery->update(['status' => 'review']);

            return true;
        }
        abort_unless($probe->status() === 308, 502, 'YouTube could not verify the upload offset.');
        $offset = 0;
        if ($range = $probe->header('Range')) {
            abort_unless(preg_match('/^bytes=0-([0-9]+)$/D', $range, $match), 502);
            $offset = (int) $match[1] + 1;
        }
        abort_unless($offset < $size, 502, 'YouTube has not confirmed the completed video.');
        $end = min($size - 1, $offset + 8 * 1024 * 1024 - 1);
        $expected = "bytes $offset-$end/$size";
        $checkRange = static function (int $status, string $range) use ($offset, $end, $size, $expected): void {
            abort_unless(($status === 206 && $range === $expected) || ($status === 200 && $offset === 0 && $end === $size - 1), 502, 'The media server must support byte ranges.');
        };
        $chunk = Http::withoutRedirecting()->timeout(35)->withOptions(['on_headers' => static function ($response) use ($checkRange, $offset, $end) {
            $checkRange($response->getStatusCode(), $response->getHeaderLine('Content-Range'));
            abort_unless((int) $response->getHeaderLine('Content-Length') === $end - $offset + 1, 502, 'Unexpected media length.');
        }])->withHeaders(['Range' => "bytes=$offset-$end"])->get($asset['url']);
        $checkRange($chunk->status(), (string) $chunk->header('Content-Range'));
        abort_unless(strlen($chunk->body()) === $end - $offset + 1, 502);
        $part = $api()->withHeaders(['Content-Range' => $expected])->withBody($chunk->body(), 'video/mp4')->put($url);

        return $part->status() === 308 ? false : $this->finish($delivery, $part);
    }

    private function finish(StudioEpisodeDelivery $delivery, Response $response): bool
    {
        $id = $response->json('id');
        if (! $response->successful() || ! is_string($id) || ! preg_match('/^[A-Za-z0-9_-]{11}$/D', $id)) {
            throw new RuntimeException('YouTube has not confirmed a video ID.');
        }
        $details = $delivery->details;
        unset($details['session']);
        $delivery->update(['status' => 'uploaded', 'details' => [...$details, 'video_id' => $id]]);

        return true;
    }
}

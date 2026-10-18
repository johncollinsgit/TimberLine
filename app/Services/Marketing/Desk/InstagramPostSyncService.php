<?php

namespace App\Services\Marketing\Desk;

use App\Models\IntegrationConnection;
use App\Models\MarketingSocialPost;
use App\Models\MarketingSocialPublication;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class InstagramPostSyncService
{
    /** Import an explicitly requested, bounded account snapshot. Never publish. */
    public function sync(int $tenantId, int $connectionId, User $actor): int
    {
        app(MarketingDeskAccess::class)->ensure($tenantId, $actor);
        $client = app(MetaMarketingClient::class);
        $connection = $client->connection($tenantId, $connectionId, 'instagram', 'media');
        $credentialHash = hash('sha256', $connection->access_token);
        $account = $client->account($connection);
        $response = $client->request($connection, 'GET', $account.'/media', [
            'fields' => 'id,caption,media_type,media_url,thumbnail_url,permalink,timestamp,like_count,comments_count,children{id,media_type,media_url,thumbnail_url}',
            'limit' => 50,
        ]);
        abort_unless(is_array($response['data'] ?? null) && count($response['data']) <= 50, 502, 'Instagram did not return a valid post list.');
        $records = [];
        foreach ($response['data'] as $record) {
            abort_unless(is_array($record) && preg_match('/^[0-9]+$/', (string) ($record['id'] ?? '')), 502, 'Instagram returned an invalid media identity.');
            $records[(string) $record['id']] = $this->normalize($record);
        }

        return DB::transaction(function () use ($tenantId, $connectionId, $actor, $client, $credentialHash, $account, $records): int {
            // The owning connection serializes repeat imports without a second scheduler.
            IntegrationConnection::forTenantId($tenantId)->whereKey($connectionId)->lockForUpdate()->firstOrFail();
            app(MarketingDeskAccess::class)->ensure($tenantId, $actor->fresh());
            $current = $client->connection($tenantId, $connectionId, 'instagram', 'media');
            abort_unless(hash_equals($credentialHash, hash('sha256', $current->access_token)) && $client->account($current) === $account, 409, 'This account changed during sync. Refresh and try again.');
            foreach ($records as $externalId => $record) {
                $publication = MarketingSocialPublication::forTenantId($tenantId)->where('platform', 'instagram')->where('external_id', (string) $externalId)->lockForUpdate()->first();
                if ($publication) {
                    abort_unless((int) $publication->connection_id === $connectionId && $publication->status === 'published', 409, 'An existing publication needs ownership or outcome review.');
                    $post = MarketingSocialPost::forTenantId($tenantId)->findOrFail($publication->post_id);
                    if (($post->content['source'] ?? '') === 'instagram_import') {
                        $post->update(['content' => $record['content']]);
                    }
                } else {
                    $post = MarketingSocialPost::create(['tenant_id' => $tenantId, 'created_by' => $actor->id, 'revision' => 1, 'status' => 'published', 'content' => $record['content']]);
                    $publication = MarketingSocialPublication::create(['tenant_id' => $tenantId, 'post_id' => $post->id, 'revision' => $post->revision, 'connection_id' => $connectionId, 'platform' => 'instagram', 'status' => 'published', 'external_id' => (string) $externalId]);
                }
                $publication->update(['insights' => array_replace((array) $publication->insights, $record['insights'])]);
            }

            return count($records);
        }, 3);
    }

    private function normalize(array $record): array
    {
        $type = match ($record['media_type'] ?? '') {
            'IMAGE' => 'image', 'VIDEO' => 'video', 'CAROUSEL_ALBUM' => 'carousel', default => null,
        };
        abort_unless($type !== null && is_string($record['timestamp'] ?? null) && preg_match('/^\d{4}-\d{2}-\d{2}T/', $record['timestamp']), 502, 'Instagram returned an unsupported post.');
        try {
            $publishedAt = CarbonImmutable::parse($record['timestamp'])->utc()->toIso8601String();
        } catch (\Throwable) {
            abort(502, 'Instagram returned an invalid post date.');
        }
        $media = $type === 'carousel' ? array_slice((array) data_get($record, 'children.data', []), 0, 10) : [$record];
        $urls = array_values(array_filter(array_map(fn ($item) => $this->photoUrl(is_array($item) ? ($item['media_url'] ?? null) : null), $media)));
        $thumbnail = $this->photoUrl($type === 'video' ? ($record['thumbnail_url'] ?? null) : ($record['media_url'] ?? ($urls[0] ?? null)));
        $link = $record['permalink'] ?? null;
        $link = is_string($link) && parse_url($link, PHP_URL_SCHEME) === 'https' && in_array(strtolower((string) parse_url($link, PHP_URL_HOST)), ['www.instagram.com', 'instagram.com'], true) && ! parse_url($link, PHP_URL_USER) && ! parse_url($link, PHP_URL_PASS) ? $link : null;
        $caption = is_string($record['caption'] ?? null) ? mb_substr($record['caption'], 0, 2200) : '';

        return ['content' => ['caption' => $caption, 'media_type' => $type, 'media_urls' => $urls, 'thumbnail_url' => $thumbnail, 'source' => 'instagram_import', 'published_at' => $publishedAt],
            'insights' => ['_permalink' => $link, '_thumbnail_url' => $thumbnail, '_published_at' => $publishedAt, '_media_observed_at' => now()->toIso8601String(),
                'likes' => $this->count($record['like_count'] ?? null), 'comments' => $this->count($record['comments_count'] ?? null)]];
    }

    private function count(mixed $value): ?int
    {
        return is_int($value) && $value >= 0 ? $value : null;
    }

    private function photoUrl(mixed $url): ?string
    {
        if (! is_string($url) || strlen($url) > 4000 || parse_url($url, PHP_URL_SCHEME) !== 'https' || parse_url($url, PHP_URL_USER) || parse_url($url, PHP_URL_PASS)) {
            return null;
        }
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        return preg_match('/(^|\.)(cdninstagram\.com|fbcdn\.net)$/', $host) ? $url : null;
    }
}

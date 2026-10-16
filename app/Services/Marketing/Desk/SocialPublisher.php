<?php

namespace App\Services\Marketing\Desk;

use App\Models\MarketingSocialPost;
use App\Models\MarketingSocialPublication;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SocialPublisher
{
    public function run(int $tenantId, int $publicationId): void
    {
        $publication = MarketingSocialPublication::forTenantId($tenantId)->findOrFail($publicationId);
        if (! in_array($publication->status, ['pending', 'prepared', 'processing'], true)) {
            return;
        }
        $post = MarketingSocialPost::forTenantId($tenantId)->findOrFail($publication->post_id);
        $actor = User::findOrFail($post->approved_by);
        app(MarketingDeskAccess::class)->ensureDelivery($tenantId, $actor);
        abort_unless($post->status === 'scheduled' && $post->scheduled_at?->lte(now())
            && hash_equals((string) $post->approved_hash, app(SocialPostService::class)->hash($post))
            && (int) $publication->revision === (int) $post->revision, 409);
        $client = app(MetaMarketingClient::class);
        $connection = $client->connection($tenantId, $publication->connection_id, $publication->platform, 'publish');
        if ($publication->status === 'processing') {
            $state = $client->request($connection, 'GET', $publication->external_id, ['fields' => 'status']);
            if (data_get($state, 'status.video_status') === 'ready') {
                $publication->update(['status' => 'published']);
                $this->finishPost($post);
            }

            return;
        }
        if (! in_array($publication->status, ['pending', 'prepared'], true)) {
            return;
        }
        if ($publication->platform === 'instagram' && $publication->status === 'prepared') {
            $state = $client->request($connection, 'GET', $publication->container_id, ['fields' => 'status_code']);
            if (($state['status_code'] ?? '') !== 'FINISHED') {
                if (in_array($state['status_code'] ?? '', ['ERROR', 'EXPIRED'], true)) {
                    $publication->update(['status' => 'failed', 'failure_code' => 'media_processing_failed']);
                }

                return;
            }
        }
        $claimed = DB::transaction(function () use ($publication, $post): bool {
            $currentPost = MarketingSocialPost::forTenantId($post->tenant_id)->whereKey($post->id)->lockForUpdate()->firstOrFail();
            if ((int) $currentPost->revision !== (int) $post->revision || $currentPost->status !== 'scheduled' || ! $currentPost->scheduled_at?->lte(now()) || ! hash_equals((string) $currentPost->approved_hash, app(SocialPostService::class)->hash($currentPost))) {
                return false;
            }
            $current = MarketingSocialPublication::whereKey($publication->id)->lockForUpdate()->firstOrFail();
            if ($current->status !== $publication->status) {
                return false;
            }
            $publication->fill(['status' => $publication->platform === 'instagram' && $publication->status === 'pending' ? 'preparing' : 'publishing', 'attempt_uuid' => (string) Str::uuid()])->save();

            return true;
        }, 3);
        if (! $claimed) {
            return;
        }
        try {
            $content = $post->content;
            $caption = $content[$publication->platform.'_caption'] ?? $content['caption'];
            $account = $client->account($connection);
            if ($publication->platform === 'instagram' && $publication->status === 'preparing') {
                $media = $content['media_urls'];
                $payload = ['caption' => $caption];
                if ($content['media_type'] === 'carousel') {
                    $children = [];
                    foreach ($media as $url) {
                        $child = $client->request($connection, 'POST', $account.'/media', ['image_url' => $url, 'is_carousel_item' => true]);
                        abort_if(empty($child['id']), 502);
                        $children[] = $child['id'];
                    }
                    $payload += ['media_type' => 'CAROUSEL', 'children' => implode(',', $children)];
                } elseif ($content['media_type'] === 'video') {
                    $payload += ['media_type' => 'REELS', 'video_url' => $media[0]];
                } else {
                    $payload['image_url'] = $media[0];
                }
                $result = $client->request($connection, 'POST', $account.'/media', $payload);
                abort_if(empty($result['id']), 502);
                $publication->update(['status' => 'prepared', 'container_id' => (string) $result['id']]);

                return;
            }
            if ($publication->platform === 'instagram') {
                $result = $client->request($connection, 'POST', $account.'/media_publish', ['creation_id' => $publication->container_id]);
            } else {
                if ($content['media_type'] === 'carousel') {
                    $attached = [];
                    foreach ($content['media_urls'] as $url) {
                        $photo = $client->request($connection, 'POST', $account.'/photos', ['url' => $url, 'published' => false]);
                        abort_if(empty($photo['id']), 502);
                        $attached[] = ['media_fbid' => (string) $photo['id']];
                    }
                    $result = $client->request($connection, 'POST', $account.'/feed', ['message' => $caption, 'attached_media' => $attached]);
                } else {
                    [$path, $payload] = match ($content['media_type']) {
                        'image' => ['photos', ['url' => $content['media_urls'][0], 'caption' => $caption]],
                        'video' => ['videos', ['file_url' => $content['media_urls'][0], 'description' => $caption]],
                        default => ['feed', ['message' => $caption]],
                    };
                    $result = $client->request($connection, 'POST', $account.'/'.$path, $payload);
                }
            }
            abort_if(empty($result['id']), 502);
            $publication->update(['status' => $publication->platform === 'facebook' && $content['media_type'] === 'video' ? 'processing' : 'published', 'external_id' => (string) ($result['post_id'] ?? $result['id']), 'failure_code' => null]);
            $this->finishPost($post);
        } catch (\Throwable $exception) {
            $publication->update(['status' => 'unknown', 'failure_code' => 'provider_outcome_unverified']);
            throw $exception;
        }
    }

    private function finishPost(MarketingSocialPost $post): void
    {
        if (! MarketingSocialPublication::forTenantId($post->tenant_id)->where('post_id', $post->id)->where('revision', $post->revision)->where('status', '!=', 'published')->exists()) {
            $post->update(['status' => 'published']);
        }
    }

    public function refreshInsights(int $tenantId, int $id, User $actor): void
    {
        app(MarketingDeskAccess::class)->ensure($tenantId, $actor);
        $publication = MarketingSocialPublication::forTenantId($tenantId)->where('status', 'published')->findOrFail($id);
        $client = app(MetaMarketingClient::class);
        $connection = $client->connection($tenantId, $publication->connection_id, $publication->platform, 'insights');
        $metricNames = $publication->platform === 'instagram' ? ['reach', 'likes', 'comments', 'saved', 'shares'] : ['post_impressions_unique', 'post_clicks', 'post_reactions_by_type_total'];
        $metrics = array_intersect_key((array) $publication->insights, array_flip(['_reconciliation']));
        foreach ($metricNames as $metric) {
            try {
                $data = $client->request($connection, 'GET', $publication->external_id.'/insights', ['metric' => $metric]);
                foreach ($data['data'] ?? [] as $item) {
                    $metrics[$item['name']] = $item['total_value']['value'] ?? $item['values'][0]['value'] ?? null;
                }
            } catch (\Illuminate\Http\Client\RequestException $e) {
                if ($e->response->status() !== 400) {
                    throw $e;
                }
                $metrics[$metric] = null;
            }
        }
        $fields = $publication->platform === 'instagram' ? 'id,permalink,media_type,timestamp' : 'id,permalink_url,created_time';
        $info = $client->request($connection, 'GET', $publication->external_id, ['fields' => $fields]);
        $link = $info['permalink'] ?? $info['permalink_url'] ?? null;
        if (is_string($link) && parse_url($link, PHP_URL_SCHEME) === 'https' && in_array(parse_url($link, PHP_URL_HOST), ['www.instagram.com', 'www.facebook.com', 'instagram.com', 'facebook.com'], true)) {
            $metrics['_permalink'] = $link;
        }
        $publication->update(['insights' => $metrics, 'observed_at' => now()]);
    }
}

<?php

namespace App\Services\Marketing\Desk;

use App\Models\MarketingSocialPost;
use App\Models\MarketingSocialPublication;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class SocialPostService
{
    public function save(int $tenantId, User $actor, array $input, ?int $id = null): MarketingSocialPost
    {
        app(MarketingDeskAccess::class)->ensure($tenantId, $actor);
        $data = Validator::make($input, [
            'caption' => 'required|string|max:2200', 'facebook_caption' => 'nullable|string|max:5000',
            'instagram_caption' => 'nullable|string|max:2200', 'media_type' => 'required|in:text,image,carousel,video',
            'media_urls' => 'present|array|max:10', 'media_urls.*' => 'required|url:https|max:2000',
            'revision' => 'nullable|integer|min:1',
        ])->validate();
        foreach ($data['media_urls'] as $url) {
            $host = strtolower((string) parse_url($url, PHP_URL_HOST));
            abort_unless((in_array($host, (array) config('marketing_desk.media_hosts'), true) || app(MarketingMediaService::class)->ownedUrl($tenantId, $url))
                && ! parse_url($url, PHP_URL_USER) && ! parse_url($url, PHP_URL_PASS), 422, 'Use an approved media host.');
        }
        $count = count($data['media_urls']);
        abort_unless(match ($data['media_type']) {
            'text' => $count === 0, 'image', 'video' => $count === 1, 'carousel' => $count >= 2, default => false
        }, 422, 'Choose media matching this post format.');

        return DB::transaction(function () use ($tenantId, $actor, $data, $id) {
            $post = $id ? MarketingSocialPost::forTenantId($tenantId)->lockForUpdate()->findOrFail($id) : new MarketingSocialPost(['tenant_id' => $tenantId, 'created_by' => $actor->id, 'revision' => 0]);
            if ($id) {
                abort_unless((int) $post->revision === (int) ($data['revision'] ?? 0), 409, 'This post changed. Refresh before editing.');
                abort_if(MarketingSocialPublication::forTenantId($tenantId)->where('post_id', $id)->whereIn('status', ['publishing', 'preparing', 'processing', 'unknown', 'published'])->exists(), 409, 'Published or uncertain posts must be copied to a new draft.');
                MarketingSocialPublication::forTenantId($tenantId)->where('post_id', $id)->whereIn('status', ['pending', 'prepared'])->update(['status' => 'cancelled']);
            }
            unset($data['revision']);
            $data = array_filter($data, fn ($value, $key) => ! in_array($key, ['facebook_caption', 'instagram_caption'], true) || trim((string) $value) !== '', ARRAY_FILTER_USE_BOTH);
            $post->fill(['content' => $data, 'revision' => $post->revision + 1, 'status' => 'draft', 'approved_hash' => null, 'approved_by' => null, 'approved_at' => null, 'scheduled_at' => null])->save();

            return $post;
        }, 3);
    }

    public function approve(int $tenantId, int $id, User $actor, int $revision, array $connections, string $scheduledAt): MarketingSocialPost
    {
        app(MarketingDeskAccess::class)->ensure($tenantId, $actor);
        abort_unless(count($connections) >= 1 && count($connections) <= 2, 422);

        return DB::transaction(function () use ($tenantId, $id, $actor, $revision, $connections, $scheduledAt) {
            $post = MarketingSocialPost::forTenantId($tenantId)->lockForUpdate()->findOrFail($id);
            abort_unless((int) $post->revision === $revision && $post->status === 'draft', 409);
            foreach ($connections as $platform => $connectionId) {
                abort_unless(in_array($platform, ['facebook', 'instagram'], true), 422);
                abort_if($platform === 'instagram' && $post->content['media_type'] === 'text', 422, 'Instagram requires an image or video.');
                app(MetaMarketingClient::class)->connection($tenantId, (int) $connectionId, $platform, 'publish');
                MarketingSocialPublication::firstOrCreate(['post_id' => $post->id, 'revision' => $revision, 'platform' => $platform], ['tenant_id' => $tenantId, 'connection_id' => (int) $connectionId]);
            }
            $post->fill(['approved_by' => $actor->id, 'approved_at' => now(), 'approved_hash' => $this->hash($post), 'status' => 'scheduled', 'scheduled_at' => \Carbon\CarbonImmutable::parse($scheduledAt, app(MarketingWorkspaceClock::class)->timezone($tenantId))->utc()])->save();

            return $post;
        }, 3);
    }

    public function hash(MarketingSocialPost $post): string
    {
        return hash('sha256', json_encode(['revision' => (int) $post->revision, 'content' => $post->content], JSON_THROW_ON_ERROR));
    }
}

<?php

namespace App\Services\Marketing\Desk;

use Illuminate\Support\Collection;

class SocialResultsService
{
    public const METRICS = ['reach' => 'Reach', 'likes' => 'Likes', 'comments' => 'Comments', 'saved' => 'Saves', 'shares' => 'Shares'];

    public function cards(Collection $posts, Collection $publications, string $metric = 'reach', string $platform = 'all', string $format = 'all'): Collection
    {
        $posts = $posts->keyBy('id');

        return $publications->where('status', 'published')->filter(fn ($p) => $posts->has($p->post_id) && ($platform === 'all' || $p->platform === $platform))
            ->map(function ($publication) use ($posts, $metric) {
                $post = $posts[$publication->post_id];
                $content = $post->content;
                $insights = (array) $publication->insights;
                $key = $publication->platform === 'facebook' && $metric === 'reach' ? 'post_impressions_unique' : $metric;
                $score = $insights[$key] ?? null;
                $photo = $insights['_thumbnail_url'] ?? $content['thumbnail_url'] ?? (($content['media_type'] ?? '') !== 'video' ? ($content['media_urls'][0] ?? null) : null);

                return ['post' => $post, 'publication' => $publication, 'caption' => $content[$publication->platform.'_caption'] ?? $content['caption'], 'format' => $content['media_type'], 'photo' => $photo,
                    'score' => is_numeric($score) && $score >= 0 ? (int) $score : null, 'metric' => $metric, 'metrics' => $insights, 'permalink' => $insights['_permalink'] ?? null,
                    'published_at' => $content['published_at'] ?? $insights['_published_at'] ?? $post->scheduled_at?->toIso8601String(), 'synced_at' => $insights['_media_observed_at'] ?? null];
            })->filter(fn ($card) => $format === 'all' || $card['format'] === $format)
            ->sort(fn ($a, $b) => ($b['score'] ?? -1) <=> ($a['score'] ?? -1) ?: $b['post']->id <=> $a['post']->id)->values();
    }
}

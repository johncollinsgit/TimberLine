<?php

namespace App\Services\Media;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

class FreeStockPhotoService
{
    /**
     * @return array{
     *   url:string,
     *   source:string,
     *   author:?string,
     *   query:string,
     *   metadata:array<string,mixed>
     * }|null
     */
    public function firstMatch(string $query): ?array
    {
        $query = trim(Str::limit($query, 120, ''));
        if ($query === '') {
            $query = 'candle fragrance lifestyle';
        }

        foreach ($this->providerOrder() as $provider) {
            $result = match ($provider) {
                'pexels' => $this->searchPexels($query),
                'unsplash' => $this->searchUnsplash($query),
                'pixabay' => $this->searchPixabay($query),
                default => null,
            };

            if ($result !== null) {
                return $result;
            }
        }

        return null;
    }

    /**
     * @return array<int,string>
     */
    protected function providerOrder(): array
    {
        $configured = trim((string) config('services.stock_photos.provider_order', ''));
        $providers = $configured !== ''
            ? array_map('trim', explode(',', strtolower($configured)))
            : ['pexels', 'unsplash', 'pixabay'];

        return array_values(array_filter(
            array_unique($providers),
            fn (string $provider): bool => in_array($provider, ['pexels', 'unsplash', 'pixabay'], true)
        ));
    }

    protected function searchPexels(string $query): ?array
    {
        $apiKey = trim((string) config('services.pexels.key'));
        if ($apiKey === '') {
            return null;
        }

        try {
            $response = Http::withToken($apiKey)
                ->acceptJson()
                ->timeout(8)
                ->get('https://api.pexels.com/v1/search', [
                    'query' => $query,
                    'per_page' => 1,
                    'orientation' => 'landscape',
                ]);
        } catch (Throwable) {
            return null;
        }

        if (! $response->ok()) {
            return null;
        }

        $photo = data_get($response->json(), 'photos.0');
        $url = data_get($photo, 'src.large2x') ?: data_get($photo, 'src.large') ?: data_get($photo, 'src.medium');
        if (! is_string($url) || trim($url) === '') {
            return null;
        }

        return [
            'url' => $url,
            'source' => 'pexels',
            'author' => data_get($photo, 'photographer'),
            'query' => $query,
            'metadata' => [
                'pexels_id' => data_get($photo, 'id'),
                'photographer_url' => data_get($photo, 'photographer_url'),
                'source_url' => data_get($photo, 'url'),
                'auto_first_match' => true,
            ],
        ];
    }

    protected function searchUnsplash(string $query): ?array
    {
        $accessKey = trim((string) config('services.unsplash.access_key'));
        if ($accessKey === '') {
            return null;
        }

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Client-ID '.$accessKey,
                'Accept-Version' => 'v1',
            ])
                ->acceptJson()
                ->timeout(8)
                ->get('https://api.unsplash.com/search/photos', [
                    'query' => $query,
                    'per_page' => 1,
                    'orientation' => 'landscape',
                    'content_filter' => 'high',
                ]);
        } catch (Throwable) {
            return null;
        }

        if (! $response->ok()) {
            return null;
        }

        $photo = data_get($response->json(), 'results.0');
        $url = data_get($photo, 'urls.regular') ?: data_get($photo, 'urls.full');
        if (! is_string($url) || trim($url) === '') {
            return null;
        }

        return [
            'url' => $url,
            'source' => 'unsplash',
            'author' => data_get($photo, 'user.name'),
            'query' => $query,
            'metadata' => [
                'unsplash_id' => data_get($photo, 'id'),
                'photographer_url' => data_get($photo, 'user.links.html'),
                'source_url' => data_get($photo, 'links.html'),
                'download_location' => data_get($photo, 'links.download_location'),
                'auto_first_match' => true,
            ],
        ];
    }

    protected function searchPixabay(string $query): ?array
    {
        $apiKey = trim((string) config('services.pixabay.key'));
        if ($apiKey === '') {
            return null;
        }

        try {
            $response = Http::acceptJson()
                ->timeout(8)
                ->get('https://pixabay.com/api/', [
                    'key' => $apiKey,
                    'q' => $query,
                    'image_type' => 'photo',
                    'orientation' => 'horizontal',
                    'safesearch' => 'true',
                    'per_page' => 3,
                ]);
        } catch (Throwable) {
            return null;
        }

        if (! $response->ok()) {
            return null;
        }

        $photo = data_get($response->json(), 'hits.0');
        $url = data_get($photo, 'largeImageURL') ?: data_get($photo, 'webformatURL');
        if (! is_string($url) || trim($url) === '') {
            return null;
        }

        return [
            'url' => $url,
            'source' => 'pixabay',
            'author' => data_get($photo, 'user'),
            'query' => $query,
            'metadata' => [
                'pixabay_id' => data_get($photo, 'id'),
                'photographer_url' => data_get($photo, 'userImageURL'),
                'source_url' => data_get($photo, 'pageURL'),
                'auto_first_match' => true,
            ],
        ];
    }
}

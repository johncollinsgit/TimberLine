<?php

namespace App\Support\Wiki;

use Illuminate\Filesystem\Filesystem;

class WikiStore
{
    public function __construct(private readonly Filesystem $files) {}

    public function load(?int $tenantId = null): array
    {
        $path = $this->path($tenantId);
        if (! $this->files->exists($path)) {
            return $this->emptyPayload();
        }

        $raw = $this->files->get($path);
        $decoded = json_decode($raw, true);

        if (! is_array($decoded)) {
            return $this->emptyPayload();
        }

        return array_merge($this->emptyPayload(), $decoded);
    }

    public function save(array $payload, ?int $tenantId = null): void
    {
        $path = $this->path($tenantId);
        $dir = dirname($path);

        if (! $this->files->isDirectory($dir)) {
            $this->files->makeDirectory($dir, 0755, true);
        }

        $this->files->put($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    public function upsertArticle(string $slug, array $data, ?int $tenantId = null): void
    {
        $payload = $this->load($tenantId);
        $payload['articles'][$slug] = $data;
        $payload['deleted_articles'] = array_values(array_filter(
            $payload['deleted_articles'],
            fn (string $deleted): bool => $deleted !== $slug
        ));

        $this->save($payload, $tenantId);
    }

    public function deleteArticle(string $slug, ?int $tenantId = null): void
    {
        $payload = $this->load($tenantId);
        unset($payload['articles'][$slug]);

        if (! in_array($slug, $payload['deleted_articles'], true)) {
            $payload['deleted_articles'][] = $slug;
        }

        $this->save($payload, $tenantId);
    }

    public function upsertCategory(string $slug, array $data, ?int $tenantId = null): void
    {
        $payload = $this->load($tenantId);
        $payload['categories'][$slug] = $data;
        $payload['deleted_categories'] = array_values(array_filter(
            $payload['deleted_categories'],
            fn (string $deleted): bool => $deleted !== $slug
        ));

        $this->save($payload, $tenantId);
    }

    public function deleteCategory(string $slug, ?int $tenantId = null): void
    {
        $payload = $this->load($tenantId);
        unset($payload['categories'][$slug]);

        if (! in_array($slug, $payload['deleted_categories'], true)) {
            $payload['deleted_categories'][] = $slug;
        }

        $this->save($payload, $tenantId);
    }

    private function path(?int $tenantId = null): string
    {
        if ($tenantId !== null && $tenantId > 0) {
            return storage_path('app/wiki/tenants/'.$tenantId.'/content.json');
        }

        return storage_path('app/wiki/content.json');
    }

    private function emptyPayload(): array
    {
        return [
            'categories' => [],
            'articles' => [],
            'deleted_categories' => [],
            'deleted_articles' => [],
        ];
    }
}

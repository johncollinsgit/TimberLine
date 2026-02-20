<?php

namespace App\Support\Wiki;

use Illuminate\Filesystem\Filesystem;

class WikiStore
{
    public function __construct(private readonly Filesystem $files)
    {
    }

    public function load(): array
    {
        $path = $this->path();
        if (!$this->files->exists($path)) {
            return $this->emptyPayload();
        }

        $raw = $this->files->get($path);
        $decoded = json_decode($raw, true);

        if (!is_array($decoded)) {
            return $this->emptyPayload();
        }

        return array_merge($this->emptyPayload(), $decoded);
    }

    public function save(array $payload): void
    {
        $path = $this->path();
        $dir = dirname($path);

        if (!$this->files->isDirectory($dir)) {
            $this->files->makeDirectory($dir, 0755, true);
        }

        $this->files->put($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    public function upsertArticle(string $slug, array $data): void
    {
        $payload = $this->load();
        $payload['articles'][$slug] = $data;
        $payload['deleted_articles'] = array_values(array_filter(
            $payload['deleted_articles'],
            fn (string $deleted): bool => $deleted !== $slug
        ));

        $this->save($payload);
    }

    public function deleteArticle(string $slug): void
    {
        $payload = $this->load();
        unset($payload['articles'][$slug]);

        if (!in_array($slug, $payload['deleted_articles'], true)) {
            $payload['deleted_articles'][] = $slug;
        }

        $this->save($payload);
    }

    public function upsertCategory(string $slug, array $data): void
    {
        $payload = $this->load();
        $payload['categories'][$slug] = $data;
        $payload['deleted_categories'] = array_values(array_filter(
            $payload['deleted_categories'],
            fn (string $deleted): bool => $deleted !== $slug
        ));

        $this->save($payload);
    }

    public function deleteCategory(string $slug): void
    {
        $payload = $this->load();
        unset($payload['categories'][$slug]);

        if (!in_array($slug, $payload['deleted_categories'], true)) {
            $payload['deleted_categories'][] = $slug;
        }

        $this->save($payload);
    }

    private function path(): string
    {
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

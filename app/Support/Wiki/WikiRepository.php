<?php

namespace App\Support\Wiki;

use App\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

class WikiRepository
{
    private ?array $resolvedContext = null;

    public function __construct(
        private readonly WikiStore $store,
        private readonly TenantContext $tenantContext
    ) {}

    public function categories(): Collection
    {
        $base = collect($this->context()['tenant_guide'] ? config('workspace_guide.categories', []) : config('wiki.categories', []))
            ->map(fn (array $category): array => $this->interpolate($category))
            ->keyBy('slug');
        $stored = $this->store->load($this->storageTenantId());
        $overrides = collect($stored['categories'] ?? [])->mapWithKeys(
            fn (array $category, string $slug): array => [$slug => array_merge(['slug' => $slug], $category)]
        );
        $deleted = collect($stored['deleted_categories'] ?? []);

        return $base
            ->merge($overrides)
            ->reject(fn (array $category): bool => $deleted->contains($category['slug']))
            ->map(function (array $category): array {
                $category['url'] = '/wiki/category/'.$category['slug'];
                $category['subcategories'] = $category['subcategories'] ?? [];

                return $category;
            })
            ->keyBy('slug');
    }

    public function category(string $slug): ?array
    {
        return $this->categories()->get($slug);
    }

    public function articles(): Collection
    {
        $categories = $this->categories();
        $base = collect($this->context()['tenant_guide'] ? config('workspace_guide.articles', []) : config('wiki.articles', []))
            ->map(fn (array $article): array => $this->interpolate($article))
            ->keyBy('slug');
        $stored = $this->store->load($this->storageTenantId());
        $overrides = collect($stored['articles'] ?? [])->mapWithKeys(
            fn (array $article, string $slug): array => [$slug => array_merge(['slug' => $slug], $article)]
        );
        $deleted = collect($stored['deleted_articles'] ?? []);

        return $base
            ->merge($overrides)
            ->reject(fn (array $article): bool => $deleted->contains($article['slug']))
            ->map(function (array $article) use ($categories): array {
                $article['published'] = $article['published'] ?? true;
                $article['featured'] = $article['featured'] ?? false;
                $article['pinned'] = $article['pinned'] ?? false;
                $article['views'] = $article['views'] ?? null;
                $article['sections'] = $article['sections'] ?? [];
                $article['related'] = $article['related'] ?? [];
                $article['url'] = $article['path'] ?? '/wiki/article/'.$article['slug'];
                $article['updated_at'] = CarbonImmutable::parse($article['updated_at'] ?? now()->toDateString());
                $article['category_meta'] = $categories->get($article['category']);

                return $article;
            })->values();
    }

    public function publishedArticles(): Collection
    {
        return $this->articles()->filter(fn (array $article): bool => (bool) $article['published'])->values();
    }

    public function article(string $slug): ?array
    {
        return $this->publishedArticles()->firstWhere('slug', $slug);
    }

    public function featuredArticle(): ?array
    {
        $articles = $this->publishedArticles();

        return $articles->firstWhere('featured', true)
            ?? $articles->firstWhere('pinned', true)
            ?? $articles->sortByDesc('updated_at')->first();
    }

    public function recentlyUpdated(int $limit = 6): Collection
    {
        return $this->publishedArticles()->sortByDesc('updated_at')->take($limit)->values();
    }

    public function popular(int $limit = 6): Collection
    {
        $articles = $this->publishedArticles();
        $hasViews = $articles->contains(fn (array $article): bool => ! is_null($article['views']));

        if ($hasViews) {
            return $articles->sortByDesc(fn (array $article): int => (int) $article['views'])->take($limit)->values();
        }

        return $this->recentlyUpdated($limit);
    }

    public function fromWiki(int $limit = 8): Collection
    {
        $articles = $this->publishedArticles();
        $grouped = $articles->groupBy('category');

        $sampled = collect();
        foreach ($grouped as $bucket) {
            $pick = $bucket->sortByDesc('updated_at')->first();
            if ($pick) {
                $sampled->push($pick);
            }
        }

        if ($sampled->count() < $limit) {
            $additional = $articles
                ->reject(fn (array $article): bool => $sampled->contains('slug', $article['slug']))
                ->sortByDesc('updated_at')
                ->take($limit - $sampled->count());
            $sampled = $sampled->concat($additional);
        }

        return $sampled->take($limit)->values();
    }

    public function randomArticle(): ?array
    {
        $articles = $this->publishedArticles();

        if ($articles->isEmpty()) {
            return null;
        }

        return $articles->random();
    }

    public function byCategory(string $categorySlug): Collection
    {
        return $this->publishedArticles()
            ->where('category', $categorySlug)
            ->sortByDesc('updated_at')
            ->values();
    }

    public function search(string $query): Collection
    {
        $needle = Str::lower(trim($query));
        if ($needle === '') {
            return $this->publishedArticles();
        }

        return $this->publishedArticles()->filter(function (array $article) use ($needle): bool {
            $haystack = Str::lower(implode(' ', [
                $article['title'] ?? '',
                $article['excerpt'] ?? '',
                $article['category_meta']['title'] ?? '',
            ]));

            return Str::contains($haystack, $needle);
        })->values();
    }

    public function relatedArticles(array $article): Collection
    {
        $relatedSlugs = collect($article['related'] ?? [])->filter()->values();

        return $this->publishedArticles()
            ->filter(fn (array $candidate): bool => $relatedSlugs->contains($candidate['slug']))
            ->sortBy(fn (array $candidate): int => $relatedSlugs->search($candidate['slug']))
            ->values();
    }

    public function linkify(string $text): HtmlString
    {
        $escaped = e($text);

        $html = preg_replace_callback('/\[\[([^\]|]+)(?:\|([^\]]+))?\]\]/', function (array $matches): string {
            $slug = trim($matches[1]);
            $label = trim($matches[2] ?? '');
            $article = $this->article($slug);

            if (! $article) {
                return e($label !== '' ? $label : $slug);
            }

            $display = $label !== '' ? $label : ($article['title'] ?? $slug);

            return '<a href="'.e($article['url']).'" class="text-sky-300 hover:text-sky-200 underline underline-offset-2">'.e($display).'</a>';
        }, $escaped);

        return new HtmlString((string) $html);
    }

    public function upsertArticle(string $slug, array $data): void
    {
        $this->store->upsertArticle($slug, $data, $this->storageTenantId());
    }

    public function deleteArticle(string $slug): void
    {
        $this->store->deleteArticle($slug, $this->storageTenantId());
    }

    public function upsertCategory(string $slug, array $data): void
    {
        $this->store->upsertCategory($slug, $data, $this->storageTenantId());
    }

    public function deleteCategory(string $slug): void
    {
        $this->store->deleteCategory($slug, $this->storageTenantId());
    }

    public function isTenantGuide(): bool
    {
        return (bool) $this->context()['tenant_guide'];
    }

    public function workspaceName(): string
    {
        return (string) $this->context()['tenant_name'];
    }

    /** @return array{tenant_id:?int,tenant_name:string,tenant_guide:bool} */
    private function context(): array
    {
        $tenantId = $this->tenantContext->id();
        if ($this->resolvedContext !== null && $this->resolvedContext['tenant_id'] === $tenantId) {
            return $this->resolvedContext;
        }

        $tenant = $tenantId !== null ? Tenant::query()->find($tenantId) : null;
        $flagshipName = strtolower(trim((string) config('everbranch.flagship_tenant_name', 'Modern Forestry')));
        $tenantName = trim((string) ($tenant?->name ?? 'Everbranch'));
        $tenantSlug = strtolower(trim((string) ($tenant?->slug ?? '')));
        $isFlagship = $tenant === null
            || strtolower($tenantName) === $flagshipName
            || in_array($tenantSlug, ['modern-forestry', 'modern-forestry-wholesale'], true);

        return $this->resolvedContext = [
            'tenant_id' => $tenantId,
            'tenant_name' => $tenantName !== '' ? $tenantName : 'Everbranch',
            'tenant_guide' => ! $isFlagship,
        ];
    }

    private function storageTenantId(): ?int
    {
        return $this->isTenantGuide() ? $this->context()['tenant_id'] : null;
    }

    private function interpolate(mixed $value): mixed
    {
        if (is_array($value)) {
            return collect($value)
                ->map(fn (mixed $item): mixed => $this->interpolate($item))
                ->all();
        }

        if (is_string($value)) {
            return str_replace('{{tenant_name}}', $this->workspaceName(), $value);
        }

        return $value;
    }
}

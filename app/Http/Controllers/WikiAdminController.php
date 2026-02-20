<?php

namespace App\Http\Controllers;

use App\Support\Wiki\WikiRepository;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class WikiAdminController extends Controller
{
    public function __construct(private readonly WikiRepository $wiki)
    {
    }

    public function createArticle(Request $request): View
    {
        return view('wiki.admin.create-article', [
            'categories' => $this->wiki->categories()->values(),
            'defaultCategory' => (string) $request->query('category', 'wholesale-processes'),
            'sectionsJson' => json_encode([], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        ]);
    }

    public function storeArticle(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'slug' => ['required', 'string', 'max:180', 'regex:/^[a-z0-9-]+$/'],
            'title' => ['required', 'string', 'max:180'],
            'excerpt' => ['required', 'string', 'max:500'],
            'category' => ['required', 'string', Rule::in($this->wiki->categories()->keys()->all())],
            'updated_at' => ['required', 'date'],
            'path' => ['nullable', 'string', 'max:255'],
            'views' => ['nullable', 'integer', 'min:0'],
            'featured' => ['nullable', 'boolean'],
            'pinned' => ['nullable', 'boolean'],
            'published' => ['nullable', 'boolean'],
            'needs_details' => ['nullable', 'boolean'],
            'related_csv' => ['nullable', 'string', 'max:1000'],
            'sections_json' => ['nullable', 'string'],
        ]);

        $slug = trim($validated['slug']);
        if ($this->wiki->articles()->contains(fn (array $article): bool => $article['slug'] === $slug)) {
            return back()->withErrors(['slug' => 'This slug already exists.'])->withInput();
        }

        $sections = [];
        $rawSections = trim((string) ($validated['sections_json'] ?? ''));
        if ($rawSections !== '') {
            $decoded = json_decode($rawSections, true);
            if (!is_array($decoded)) {
                return back()->withErrors(['sections_json' => 'Sections JSON must be a valid array.'])->withInput();
            }
            $sections = $decoded;
        }

        $related = collect(explode(',', (string) ($validated['related_csv'] ?? '')))
            ->map(fn (string $slugValue): string => trim($slugValue))
            ->filter()
            ->values()
            ->all();

        $payload = [
            'slug' => $slug,
            'title' => $validated['title'],
            'excerpt' => $validated['excerpt'],
            'category' => $validated['category'],
            'updated_at' => $validated['updated_at'],
            'sections' => $sections,
            'related' => $related,
            'featured' => (bool) ($validated['featured'] ?? false),
            'pinned' => (bool) ($validated['pinned'] ?? false),
            'published' => (bool) ($validated['published'] ?? false),
            'needs_details' => (bool) ($validated['needs_details'] ?? false),
            'views' => is_null($validated['views'] ?? null) ? null : (int) $validated['views'],
        ];

        $path = trim((string) ($validated['path'] ?? ''));
        if ($path !== '') {
            $payload['path'] = Str::startsWith($path, '/') ? $path : '/'.$path;
        }

        $this->wiki->upsertArticle($slug, $payload);

        return redirect($payload['path'] ?? route('wiki.article', ['slug' => $slug]))
            ->with('status', 'Wiki article created.');
    }

    public function editArticle(string $slug): View
    {
        $article = $this->wiki->articles()->firstWhere('slug', $slug);
        abort_if(!$article, 404);

        return view('wiki.admin.edit-article', [
            'article' => $article,
            'categories' => $this->wiki->categories()->values(),
            'sectionsJson' => json_encode($article['sections'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            'relatedCsv' => implode(', ', $article['related'] ?? []),
        ]);
    }

    public function updateArticle(Request $request, string $slug): RedirectResponse
    {
        $existing = $this->wiki->articles()->firstWhere('slug', $slug);
        abort_if(!$existing, 404);

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:180'],
            'excerpt' => ['required', 'string', 'max:500'],
            'category' => ['required', 'string', Rule::in($this->wiki->categories()->keys()->all())],
            'updated_at' => ['required', 'date'],
            'path' => ['nullable', 'string', 'max:255'],
            'views' => ['nullable', 'integer', 'min:0'],
            'featured' => ['nullable', 'boolean'],
            'pinned' => ['nullable', 'boolean'],
            'published' => ['nullable', 'boolean'],
            'needs_details' => ['nullable', 'boolean'],
            'related_csv' => ['nullable', 'string', 'max:1000'],
            'sections_json' => ['nullable', 'string'],
        ]);

        $sections = [];
        $rawSections = trim((string) ($validated['sections_json'] ?? ''));
        if ($rawSections !== '') {
            $decoded = json_decode($rawSections, true);
            if (!is_array($decoded)) {
                return back()->withErrors(['sections_json' => 'Sections JSON must be a valid array.'])->withInput();
            }
            $sections = $decoded;
        }

        $related = collect(explode(',', (string) ($validated['related_csv'] ?? '')))
            ->map(fn (string $slugValue): string => trim($slugValue))
            ->filter()
            ->values()
            ->all();

        $payload = [
            'slug' => $existing['slug'],
            'title' => $validated['title'],
            'excerpt' => $validated['excerpt'],
            'category' => $validated['category'],
            'updated_at' => $validated['updated_at'],
            'sections' => $sections,
            'related' => $related,
            'featured' => (bool) ($validated['featured'] ?? false),
            'pinned' => (bool) ($validated['pinned'] ?? false),
            'published' => (bool) ($validated['published'] ?? false),
            'needs_details' => (bool) ($validated['needs_details'] ?? false),
            'views' => is_null($validated['views'] ?? null) ? null : (int) $validated['views'],
        ];

        $path = trim((string) ($validated['path'] ?? ''));
        if ($path !== '') {
            $payload['path'] = Str::startsWith($path, '/') ? $path : '/'.$path;
        }

        $this->wiki->upsertArticle($existing['slug'], $payload);

        $target = $payload['path'] ?? route('wiki.article', ['slug' => $existing['slug']]);

        return redirect($target)->with('status', 'Wiki article updated.');
    }

    public function deleteArticle(string $slug): RedirectResponse
    {
        $existing = $this->wiki->articles()->firstWhere('slug', $slug);
        abort_if(!$existing, 404);

        $this->wiki->deleteArticle($slug);

        return redirect()->route('wiki.index')->with('status', 'Wiki article deleted.');
    }

    public function createCategory(): View
    {
        return view('wiki.admin.create-category');
    }

    public function storeCategory(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'slug' => ['required', 'string', 'max:120', 'regex:/^[a-z0-9-]+$/'],
            'title' => ['required', 'string', 'max:120'],
            'description' => ['required', 'string', 'max:500'],
            'subcategories_csv' => ['nullable', 'string', 'max:1000'],
        ]);

        $slug = trim($validated['slug']);
        if ($this->wiki->categories()->has($slug)) {
            return back()->withErrors(['slug' => 'This category slug already exists.'])->withInput();
        }

        $subcategories = collect(explode(',', (string) ($validated['subcategories_csv'] ?? '')))
            ->map(fn (string $value): string => trim($value))
            ->filter()
            ->unique()
            ->values()
            ->all();

        $this->wiki->upsertCategory($slug, [
            'slug' => $slug,
            'title' => $validated['title'],
            'description' => $validated['description'],
            'subcategories' => $subcategories,
        ]);

        return redirect()->route('wiki.category', ['slug' => $slug])->with('status', 'Wiki category created.');
    }

    public function editCategory(string $slug): View
    {
        $category = $this->wiki->category($slug);
        abort_if(!$category, 404);

        return view('wiki.admin.edit-category', [
            'category' => $category,
            'subcategoriesCsv' => implode(', ', $category['subcategories'] ?? []),
        ]);
    }

    public function updateCategory(Request $request, string $slug): RedirectResponse
    {
        $existing = $this->wiki->category($slug);
        abort_if(!$existing, 404);

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:120'],
            'description' => ['required', 'string', 'max:500'],
            'subcategories_csv' => ['nullable', 'string', 'max:1000'],
        ]);

        $subcategories = collect(explode(',', (string) ($validated['subcategories_csv'] ?? '')))
            ->map(fn (string $value): string => trim($value))
            ->filter()
            ->unique()
            ->values()
            ->all();

        $this->wiki->upsertCategory($slug, [
            'slug' => $slug,
            'title' => $validated['title'],
            'description' => $validated['description'],
            'subcategories' => $subcategories,
        ]);

        return redirect()->route('wiki.category', ['slug' => $slug])->with('status', 'Wiki category updated.');
    }

    public function deleteCategory(string $slug): RedirectResponse
    {
        abort_if($slug === 'wholesale-processes', 403);

        $existing = $this->wiki->category($slug);
        abort_if(!$existing, 404);

        $this->wiki->deleteCategory($slug);

        return redirect()->route('wiki.categories')->with('status', 'Wiki category deleted.');
    }
}

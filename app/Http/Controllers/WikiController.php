<?php

namespace App\Http\Controllers;

use App\Support\Wiki\WikiRepository;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class WikiController extends Controller
{
    public function __construct(private readonly WikiRepository $wiki)
    {
    }

    public function index(Request $request): View
    {
        $query = trim((string) $request->query('q', ''));
        $results = $query !== '' ? $this->wiki->search($query) : $this->wiki->publishedArticles();

        return view('wiki.home', [
            'query' => $query,
            'categories' => $this->wiki->categories()->values(),
            'featured' => $this->wiki->featuredArticle(),
            'fromWiki' => $this->wiki->fromWiki(8),
            'recentlyUpdated' => $this->wiki->recentlyUpdated(6),
            'popular' => $this->wiki->popular(6),
            'randomArticle' => $this->wiki->randomArticle(),
            'searchResults' => $results,
        ]);
    }

    public function categories(): View
    {
        return view('wiki.categories', [
            'categories' => $this->wiki->categories()->values(),
            'wiki' => $this->wiki,
        ]);
    }

    public function category(string $slug): View
    {
        $category = $this->wiki->category($slug);
        abort_if(!$category, 404);

        $subcategories = collect($category['subcategories'] ?? [])
            ->map(fn (string $childSlug): ?array => $this->wiki->category($childSlug))
            ->filter()
            ->values();

        return view('wiki.category', [
            'category' => $category,
            'articles' => $this->wiki->byCategory($slug),
            'subcategories' => $subcategories,
        ]);
    }

    public function article(string $slug): View
    {
        $article = $this->wiki->article($slug);
        abort_if(!$article, 404);
        $isWholesaleArticle = str_starts_with($article['slug'], 'wholesale-');
        $breadcrumbCategory = $isWholesaleArticle
            ? $this->wiki->category('wholesale-processes')
            : $article['category_meta'];

        return view('wiki.article', [
            'article' => $article,
            'category' => $breadcrumbCategory,
            'related' => $this->wiki->relatedArticles($article),
            'wiki' => $this->wiki,
        ]);
    }

    public function wholesaleProcesses(): View
    {
        return $this->article('wholesale-processes');
    }

    public function random(): RedirectResponse
    {
        $article = $this->wiki->randomArticle();

        return $article
            ? redirect($article['url'])
            : redirect()->route('wiki.index');
    }
}

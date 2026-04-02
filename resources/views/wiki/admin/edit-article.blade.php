<x-layouts::app :title="'Edit Wiki Article'">
    <div class="mx-auto w-full max-w-[1000px] px-4 py-6 md:px-6 space-y-6">
        <section class="rounded-3xl border border-zinc-200 bg-white p-6">
            <nav class="text-xs text-zinc-500">
                <a href="{{ route('wiki.index') }}" class="hover:text-zinc-600">Wiki</a>
                <span class="mx-1">/</span>
                <span>Edit Article</span>
            </nav>
            <h1 class="mt-2 text-2xl font-semibold text-zinc-950">Edit: {{ $article['title'] }}</h1>
        </section>

        @if($errors->any())
            <section class="rounded-2xl border border-red-300/35 bg-red-500/10 px-4 py-3 text-sm text-red-100">
                <ul class="space-y-1">
                    @foreach($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </section>
        @endif

        <form method="POST" action="{{ route('wiki.admin.article.update', ['slug' => $article['slug']]) }}" class="space-y-4 rounded-3xl border border-zinc-200 bg-white p-6">
            @csrf
            @method('PUT')

            <div class="grid gap-4 md:grid-cols-2">
                <label class="text-sm text-zinc-700">Title
                    <input name="title" value="{{ old('title', $article['title']) }}" class="mt-1 w-full rounded-xl border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900" />
                </label>
                <label class="text-sm text-zinc-700">Category
                    <select name="category" class="mt-1 w-full rounded-xl border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900">
                        @foreach($categories as $category)
                            <option value="{{ $category['slug'] }}" @selected(old('category', $article['category']) === $category['slug'])>{{ $category['title'] }}</option>
                        @endforeach
                    </select>
                </label>
            </div>

            <label class="text-sm text-zinc-700 block">Excerpt
                <textarea name="excerpt" rows="3" class="mt-1 w-full rounded-xl border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900">{{ old('excerpt', $article['excerpt']) }}</textarea>
            </label>

            <div class="grid gap-4 md:grid-cols-3">
                <label class="text-sm text-zinc-700">Updated at
                    <input type="date" name="updated_at" value="{{ old('updated_at', $article['updated_at']->toDateString()) }}" class="mt-1 w-full rounded-xl border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900" />
                </label>
                <label class="text-sm text-zinc-700">Path (optional)
                    <input name="path" value="{{ old('path', $article['path'] ?? '') }}" class="mt-1 w-full rounded-xl border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900" />
                </label>
                <label class="text-sm text-zinc-700">Views (optional)
                    <input type="number" name="views" value="{{ old('views', $article['views']) }}" class="mt-1 w-full rounded-xl border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900" />
                </label>
            </div>

            <label class="text-sm text-zinc-700 block">Related slugs (comma-separated)
                <input name="related_csv" value="{{ old('related_csv', $relatedCsv) }}" class="mt-1 w-full rounded-xl border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900" />
            </label>

            <label class="text-sm text-zinc-700 block">Sections JSON
                <textarea name="sections_json" rows="18" class="mt-1 w-full rounded-xl border border-zinc-300 bg-zinc-50 px-3 py-2 font-mono text-xs text-zinc-950">{{ old('sections_json', $sectionsJson) }}</textarea>
            </label>

            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-5 text-sm text-zinc-700">
                <label class="inline-flex items-center gap-2"><input type="checkbox" name="featured" value="1" @checked(old('featured', $article['featured'])) /> Featured</label>
                <label class="inline-flex items-center gap-2"><input type="checkbox" name="pinned" value="1" @checked(old('pinned', $article['pinned'])) /> Pinned</label>
                <label class="inline-flex items-center gap-2"><input type="checkbox" name="published" value="1" @checked(old('published', $article['published'])) /> Published</label>
                <label class="inline-flex items-center gap-2"><input type="checkbox" name="needs_details" value="1" @checked(old('needs_details', $article['needs_details'] ?? false)) /> Needs details</label>
            </div>

            <div class="flex items-center gap-2">
                <button type="submit" class="rounded-xl border border-sky-300/40 bg-sky-100 px-4 py-2 text-sm font-medium text-sky-900 hover:bg-sky-100">Save article</button>
                <a href="{{ $article['url'] }}" class="rounded-xl border border-zinc-300 px-4 py-2 text-sm text-zinc-700 hover:border-zinc-400">Cancel</a>
            </div>
        </form>
    </div>
</x-layouts::app>

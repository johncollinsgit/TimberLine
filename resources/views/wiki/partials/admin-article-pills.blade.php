@php
    $isAdmin = auth()->user()?->isAdmin() ?? false;
@endphp
@if($isAdmin && !empty($article['slug'] ?? null))
    <div class="flex items-center gap-2">
        <a href="{{ route('wiki.admin.article.edit', ['slug' => $article['slug']]) }}" class="rounded-full border border-sky-300/40 bg-sky-100 px-3 py-1 text-xs font-medium text-sky-900 hover:bg-sky-100">Edit</a>
        <form method="POST" action="{{ route('wiki.admin.article.delete', ['slug' => $article['slug']]) }}" onsubmit="return confirm('Delete this wiki article?');">
            @csrf
            @method('DELETE')
            <button type="submit" class="rounded-full border border-red-300/40 bg-red-500/15 px-3 py-1 text-xs font-medium text-red-100 hover:bg-red-500/25">Delete</button>
        </form>
    </div>
@endif

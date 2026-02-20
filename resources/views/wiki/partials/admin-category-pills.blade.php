@php
    $isAdmin = auth()->user()?->isAdmin() ?? false;
@endphp
@if($isAdmin && !empty($category['slug'] ?? null))
    <div class="flex items-center gap-2">
        <a href="{{ route('wiki.admin.category.edit', ['slug' => $category['slug']]) }}" class="rounded-full border border-sky-300/40 bg-sky-500/15 px-3 py-1 text-xs font-medium text-sky-100 hover:bg-sky-500/25">Edit</a>
        @if($category['slug'] !== 'wholesale-processes')
            <form method="POST" action="{{ route('wiki.admin.category.delete', ['slug' => $category['slug']]) }}" onsubmit="return confirm('Delete this wiki category?');">
                @csrf
                @method('DELETE')
                <button type="submit" class="rounded-full border border-red-300/40 bg-red-500/15 px-3 py-1 text-xs font-medium text-red-100 hover:bg-red-500/25">Delete</button>
            </form>
        @endif
    </div>
@endif

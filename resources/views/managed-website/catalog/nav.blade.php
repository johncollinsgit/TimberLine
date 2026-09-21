<nav aria-label="Store management" class="flex flex-wrap gap-2 border-b border-zinc-200 pb-3">
    @foreach(['products'=>'Products','collections'=>'Collections','customers'=>'Customers','orders'=>'Orders'] as $entity=>$label)
    <a href="{{ route('managed-website.'.$entity.'.index') }}" class="rounded-lg px-4 py-2 text-sm font-semibold {{ request()->routeIs('managed-website.'.$entity.'.*') ? 'bg-zinc-900 text-white' : 'text-zinc-600 hover:bg-zinc-100' }}" @if(request()->routeIs('managed-website.'.$entity.'.*')) aria-current="page" @endif>{{ $label }}</a>
    @endforeach
</nav>

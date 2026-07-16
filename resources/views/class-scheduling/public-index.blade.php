<!DOCTYPE html><html lang="en"><head>@include('partials.head')<meta name="robots" content="index,follow"></head>
<body class="min-h-screen bg-[#f7f4ec] text-zinc-950">
    <header class="relative overflow-hidden bg-zinc-900 text-white">
        @if($settings->hero_image_url)<img src="{{ $settings->hero_image_url }}" alt="" class="absolute inset-0 h-full w-full object-cover opacity-50">@endif
        <div class="absolute inset-0 bg-gradient-to-r from-black/80 via-black/45 to-transparent"></div>
        <div class="relative mx-auto flex min-h-[360px] max-w-6xl flex-col justify-end px-5 py-12 sm:px-8">
            @if($settings->logo_url)<img src="{{ $settings->logo_url }}" alt="{{ $tenant->name }}" class="mb-8 max-h-28 max-w-64 object-contain object-left brightness-0 invert">@endif
            <div class="text-xs font-semibold uppercase tracking-[0.28em]">Grow your own. We show you how.</div>
            <h1 class="mt-3 max-w-3xl text-4xl font-semibold sm:text-6xl">{{ $settings->public_heading }}</h1>
            <p class="mt-4 max-w-2xl text-base leading-7 text-white/85">{{ $settings->public_intro }}</p>
        </div>
    </header>
    <main class="mx-auto max-w-6xl px-5 py-10 sm:px-8 sm:py-16">
        @if(session('status'))<div class="mb-8 rounded-2xl border border-emerald-300 bg-emerald-50 px-5 py-4 font-medium text-emerald-950">{{ session('status') }}</div>@endif
        <div class="grid gap-6 md:grid-cols-2 lg:grid-cols-3">
            @forelse($classes as $class)
                <a href="{{ route('public.classes.show', ['tenant' => $tenant->slug, 'class' => $class->slug]) }}" class="group overflow-hidden rounded-[2rem] border border-black/10 bg-white shadow-sm transition hover:-translate-y-1 hover:shadow-xl">
                    @if($class->image_url)<img src="{{ $class->image_url }}" alt="{{ $class->title }}" class="h-56 w-full object-cover transition duration-500 group-hover:scale-[1.03]">@endif
                    <div class="p-6"><div class="text-[11px] font-semibold uppercase tracking-[0.22em]" style="color: {{ $settings->brand_color }}">{{ $class->category ?: 'Class' }}</div><h2 class="mt-2 text-2xl font-semibold">{{ $class->title }}</h2><p class="mt-3 text-sm leading-6 text-zinc-600">{{ Str::limit($class->description, 150) }}</p><div class="mt-5 border-t border-zinc-200 pt-4 text-sm"><div class="font-semibold">{{ $class->starts_at->format('D, M j · g:i A') }}</div><div class="mt-1 text-zinc-600">{{ $class->location }} · {{ $class->seats_remaining }} seats left</div></div></div>
                </a>
            @empty
                <div class="md:col-span-2 lg:col-span-3 rounded-[2rem] border border-dashed border-zinc-300 bg-white p-10 text-center"><h2 class="text-xl font-semibold">New class dates are coming soon.</h2><p class="mt-2 text-zinc-600">Contact {{ $settings->contact_email ?: $tenant->name }} for the next available session.</p></div>
            @endforelse
        </div>
    </main>
    <footer class="border-t border-black/10 px-5 py-8 text-center text-sm text-zinc-600">{{ $tenant->name }} · Greenville, South Carolina @if($settings->contact_email) · <a href="mailto:{{ $settings->contact_email }}" class="underline">{{ $settings->contact_email }}</a> @endif</footer>
</body></html>

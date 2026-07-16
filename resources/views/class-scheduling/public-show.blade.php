<!DOCTYPE html><html lang="en"><head>@include('partials.head')<meta name="robots" content="index,follow"></head>
<body class="min-h-screen bg-[#f7f4ec] text-zinc-950">
    <main class="mx-auto max-w-6xl px-5 py-8 sm:px-8 sm:py-12">
        <a href="{{ route('public.classes.index', ['tenant' => $tenant->slug]) }}" class="text-sm font-semibold" style="color: {{ $settings->brand_color }}">← All classes</a>
        @if(session('status'))<div class="mt-6 rounded-2xl border border-emerald-300 bg-emerald-50 px-5 py-4 font-medium text-emerald-950">{{ session('status') }}</div>@endif
        <div class="mt-6 overflow-hidden rounded-[2rem] border border-black/10 bg-white shadow-sm">
            <div class="grid lg:grid-cols-2">
                <div class="min-h-80 bg-zinc-200">@if($scheduledClass->image_url)<img src="{{ $scheduledClass->image_url }}" alt="{{ $scheduledClass->title }}" class="h-full min-h-80 w-full object-cover">@endif</div>
                <div class="p-6 sm:p-10"><div class="text-[11px] font-semibold uppercase tracking-[0.22em]" style="color: {{ $settings->brand_color }}">{{ $scheduledClass->category ?: 'Class' }}</div><h1 class="mt-3 text-4xl font-semibold">{{ $scheduledClass->title }}</h1><p class="mt-5 leading-7 text-zinc-600">{{ $scheduledClass->description }}</p><div class="mt-6 rounded-2xl bg-[#f7f4ec] p-5"><div class="font-semibold">{{ $scheduledClass->starts_at->format('l, F j, Y') }}</div><div class="mt-1 text-zinc-700">{{ $scheduledClass->starts_at->format('g:i A') }} @if($scheduledClass->ends_at)–{{ $scheduledClass->ends_at->format('g:i A') }} @endif · {{ $scheduledClass->location }}</div><div class="mt-2 text-sm text-zinc-600">{{ $scheduledClass->seats_remaining }} of {{ $scheduledClass->capacity }} seats available @if($scheduledClass->price !== null) · ${{ number_format((float) $scheduledClass->price, 2) }} @endif</div></div></div>
            </div>
        </div>

        <section class="mx-auto mt-8 max-w-2xl rounded-[2rem] border border-black/10 bg-white p-6 shadow-sm sm:p-10">
            <h2 class="text-2xl font-semibold">Reserve your seat</h2><p class="mt-2 text-sm text-zinc-600">Your signup creates a customer record for this business so class details and reminders stay together.</p>
            <form method="POST" action="{{ route('public.classes.store', ['tenant' => $tenant->slug, 'class' => $scheduledClass->slug]) }}" class="mt-6 grid gap-4 sm:grid-cols-2">
                @csrf
                <label class="sm:col-span-2 text-sm font-medium">Name<input name="name" required value="{{ old('name') }}" class="mt-1.5 w-full rounded-xl border-zinc-300"></label>
                <label class="text-sm font-medium">Email<input type="email" name="email" required value="{{ old('email') }}" class="mt-1.5 w-full rounded-xl border-zinc-300"></label>
                <label class="text-sm font-medium">Phone<input name="phone" value="{{ old('phone') }}" class="mt-1.5 w-full rounded-xl border-zinc-300"></label>
                <label class="text-sm font-medium">Seats<input type="number" name="seats" min="1" max="{{ min(10, $scheduledClass->seats_remaining) }}" value="{{ old('seats', 1) }}" class="mt-1.5 w-full rounded-xl border-zinc-300"></label>
                <label class="sm:col-span-2 text-sm font-medium">Anything Laura should know?<textarea name="notes" rows="3" class="mt-1.5 w-full rounded-xl border-zinc-300">{{ old('notes') }}</textarea></label>
                <label class="sm:col-span-2 flex gap-3 rounded-xl bg-zinc-50 p-3 text-sm"><input type="checkbox" name="email_reminders_enabled" value="1" checked class="mt-0.5 rounded border-zinc-300"><span>Email me a reminder for this class.</span></label>
                <label class="sm:col-span-2 flex gap-3 rounded-xl bg-zinc-50 p-3 text-sm"><input type="checkbox" name="sms_reminders_enabled" value="1" class="mt-0.5 rounded border-zinc-300"><span>Text me an operational class reminder at the phone number above. Message/data rates may apply; reply STOP to opt out.</span></label>
                @if($errors->any())<div class="sm:col-span-2 rounded-xl bg-red-50 p-3 text-sm text-red-800">{{ $errors->first() }}</div>@endif
                <button class="sm:col-span-2 rounded-full px-6 py-3.5 font-semibold text-white" style="background: {{ $settings->brand_color }}">Sign up for class</button>
            </form>
        </section>
    </main>
</body></html>

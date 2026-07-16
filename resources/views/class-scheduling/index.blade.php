@php
    $calendarStart = $month->copy()->startOfMonth()->startOfWeek();
    $calendarEnd = $month->copy()->endOfMonth()->endOfWeek();
    $classesByDate = $classes->groupBy(fn ($class) => $class->starts_at->format('Y-m-d'));
@endphp

<x-layouts::app.sidebar title="Classes">
    <div class="mx-auto w-full max-w-[1600px] space-y-6 px-4 py-5 sm:px-6">
        @if(session('status'))<div class="rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-900">{{ session('status') }}</div>@endif

        <header class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <div class="text-[11px] font-semibold uppercase tracking-[0.24em] text-emerald-800">Class Scheduling Branch</div>
                <h1 class="mt-2 text-3xl font-semibold text-zinc-950">Classes & appointments</h1>
                <p class="mt-2 max-w-2xl text-sm text-zinc-600">Publish available classes, open a session to see its customers, and schedule reminders before class.</p>
            </div>
            <a href="{{ $publicUrl }}" target="_blank" rel="noopener" class="fb-btn fb-btn-secondary">Preview customer signup</a>
        </header>

        <section class="mf-app-card overflow-hidden rounded-3xl">
            <div class="flex items-center justify-between gap-4 border-b border-zinc-200 px-5 py-4">
                <a href="{{ route('class-scheduling.index', ['month' => $previousMonth]) }}" class="rounded-full border border-zinc-200 px-3 py-1.5 text-sm font-semibold text-zinc-700">← Previous</a>
                <h2 class="text-xl font-semibold text-zinc-950">{{ $month->format('F Y') }}</h2>
                <a href="{{ route('class-scheduling.index', ['month' => $nextMonth]) }}" class="rounded-full border border-zinc-200 px-3 py-1.5 text-sm font-semibold text-zinc-700">Next →</a>
            </div>
            <div class="grid grid-cols-7 border-b border-zinc-200 bg-zinc-50 text-center text-[10px] font-semibold uppercase tracking-[0.16em] text-zinc-500">
                @foreach(['Sun','Mon','Tue','Wed','Thu','Fri','Sat'] as $day)<div class="px-2 py-2">{{ $day }}</div>@endforeach
            </div>
            <div class="grid grid-cols-7">
                @for($day = $calendarStart->copy(); $day->lte($calendarEnd); $day->addDay())
                    @php
                        $dayClasses = $classesByDate->get($day->format('Y-m-d'), collect());
                    @endphp
                    <div class="min-h-32 border-b border-r border-zinc-200 p-2 {{ $day->month !== $month->month ? 'bg-zinc-50 text-zinc-400' : 'bg-white' }}">
                        <div class="text-xs font-semibold">{{ $day->day }}</div>
                        <div class="mt-2 space-y-1.5">
                            @foreach($dayClasses as $class)
                                <a href="{{ route('class-scheduling.show', $class) }}" class="block rounded-xl border border-emerald-100 bg-emerald-50 p-2 text-emerald-950 transition hover:border-emerald-300">
                                    <div class="text-[10px] font-semibold uppercase tracking-wide">{{ $class->starts_at->format('g:i A') }}</div>
                                    <div class="mt-0.5 text-xs font-semibold leading-tight">{{ $class->title }}</div>
                                    <div class="mt-1 text-[10px] text-emerald-800">{{ $class->seats_taken }}/{{ $class->capacity }} enrolled</div>
                                </a>
                            @endforeach
                        </div>
                    </div>
                @endfor
            </div>
        </section>

        <div class="grid gap-6 xl:grid-cols-[1.05fr_0.95fr]">
            <section class="mf-app-card rounded-3xl p-5 sm:p-6">
                <h2 class="text-xl font-semibold text-zinc-950">Add a class</h2>
                <form method="POST" action="{{ route('class-scheduling.store') }}" class="mt-5 grid gap-4 sm:grid-cols-2">
                    @csrf
                    <label class="sm:col-span-2 text-sm font-medium text-zinc-700">Class name<input name="title" required value="{{ old('title') }}" class="mt-1.5 w-full rounded-xl border-zinc-200" placeholder="Sourdough Basics"></label>
                    <label class="text-sm font-medium text-zinc-700">Category<input name="category" value="{{ old('category') }}" class="mt-1.5 w-full rounded-xl border-zinc-200" placeholder="Cooking"></label>
                    <label class="text-sm font-medium text-zinc-700">Location<input name="location" value="{{ old('location') }}" class="mt-1.5 w-full rounded-xl border-zinc-200" placeholder="Greenville, SC"></label>
                    <label class="text-sm font-medium text-zinc-700">Starts<input type="datetime-local" name="starts_at" required value="{{ old('starts_at') }}" class="mt-1.5 w-full rounded-xl border-zinc-200"></label>
                    <label class="text-sm font-medium text-zinc-700">Ends<input type="datetime-local" name="ends_at" value="{{ old('ends_at') }}" class="mt-1.5 w-full rounded-xl border-zinc-200"></label>
                    <label class="text-sm font-medium text-zinc-700">Capacity<input type="number" min="1" name="capacity" required value="{{ old('capacity', 12) }}" class="mt-1.5 w-full rounded-xl border-zinc-200"></label>
                    <label class="text-sm font-medium text-zinc-700">Price<input type="number" min="0" step="0.01" name="price" value="{{ old('price') }}" class="mt-1.5 w-full rounded-xl border-zinc-200"></label>
                    <label class="text-sm font-medium text-zinc-700">Reminder lead time<input type="number" min="1" name="reminder_hours" value="{{ old('reminder_hours', 24) }}" class="mt-1.5 w-full rounded-xl border-zinc-200"><span class="mt-1 block text-xs text-zinc-500">Hours before class</span></label>
                    <label class="text-sm font-medium text-zinc-700">Status<select name="status" class="mt-1.5 w-full rounded-xl border-zinc-200"><option value="published">Published</option><option value="draft">Draft</option></select></label>
                    <label class="sm:col-span-2 text-sm font-medium text-zinc-700">Photo URL<input type="url" name="image_url" value="{{ old('image_url') }}" class="mt-1.5 w-full rounded-xl border-zinc-200" placeholder="https://..."></label>
                    <label class="sm:col-span-2 text-sm font-medium text-zinc-700">Description<textarea name="description" rows="4" class="mt-1.5 w-full rounded-xl border-zinc-200">{{ old('description') }}</textarea></label>
                    <label class="sm:col-span-2 flex items-center gap-2 text-sm text-zinc-700"><input type="checkbox" name="registration_open" value="1" checked class="rounded border-zinc-300 text-emerald-700">Open customer registration</label>
                    <div class="sm:col-span-2"><button class="fb-btn fb-btn-primary">Add class</button></div>
                </form>
            </section>

            <section id="class-settings" class="mf-app-card rounded-3xl p-5 sm:p-6">
                <h2 class="text-xl font-semibold text-zinc-950">Signup settings</h2>
                <p class="mt-2 text-sm text-zinc-600">These settings control the customer-facing class page.</p>
                <form method="POST" action="{{ route('class-scheduling.settings.update') }}" class="mt-5 space-y-4">
                    @csrf @method('PUT')
                    <label class="flex items-center gap-2 text-sm font-medium text-zinc-800"><input type="checkbox" name="public_signup_enabled" value="1" @checked($settings->public_signup_enabled) class="rounded border-zinc-300 text-emerald-700">Publish customer signup page</label>
                    <label class="block text-sm font-medium text-zinc-700">Heading<input name="public_heading" required value="{{ old('public_heading', $settings->public_heading) }}" class="mt-1.5 w-full rounded-xl border-zinc-200"></label>
                    <label class="block text-sm font-medium text-zinc-700">Introduction<textarea name="public_intro" rows="3" class="mt-1.5 w-full rounded-xl border-zinc-200">{{ old('public_intro', $settings->public_intro) }}</textarea></label>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <label class="text-sm font-medium text-zinc-700">Timezone<input name="timezone" required value="{{ old('timezone', $settings->timezone) }}" class="mt-1.5 w-full rounded-xl border-zinc-200"></label>
                        <label class="text-sm font-medium text-zinc-700">Reminder hours<input type="number" min="1" name="default_reminder_hours" required value="{{ old('default_reminder_hours', collect($settings->default_reminder_offsets)->first() ?: 24) }}" class="mt-1.5 w-full rounded-xl border-zinc-200"></label>
                    </div>
                    <label class="block text-sm font-medium text-zinc-700">Contact email<input type="email" name="contact_email" value="{{ old('contact_email', $settings->contact_email) }}" class="mt-1.5 w-full rounded-xl border-zinc-200"></label>
                    <label class="block text-sm font-medium text-zinc-700">Logo URL<input type="url" name="logo_url" value="{{ old('logo_url', $settings->logo_url) }}" class="mt-1.5 w-full rounded-xl border-zinc-200"></label>
                    <label class="block text-sm font-medium text-zinc-700">Hero photo URL<input type="url" name="hero_image_url" value="{{ old('hero_image_url', $settings->hero_image_url) }}" class="mt-1.5 w-full rounded-xl border-zinc-200"></label>
                    <label class="block text-sm font-medium text-zinc-700">Brand color<input type="color" name="brand_color" value="{{ old('brand_color', $settings->brand_color) }}" class="mt-1.5 h-11 w-full rounded-xl border border-zinc-200 bg-white p-1"></label>
                    <button class="fb-btn fb-btn-primary">Save signup settings</button>
                </form>
            </section>
        </div>
    </div>
</x-layouts::app.sidebar>

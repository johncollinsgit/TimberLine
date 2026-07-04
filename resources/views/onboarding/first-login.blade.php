@php
    $authTenantPresentation = $authTenantPresentation ?? [];
    $workspaceName = $workspaceName ?? '';
    $templateOptions = is_array($templateOptions ?? null) ? $templateOptions : [];
    $defaultTemplateKey = $defaultTemplateKey ?? 'trades_electrical';
    $guideQuestions = is_array($guideQuestions ?? null) ? $guideQuestions : [];
    $hardestParts = is_array($guideQuestions['hardest_parts'] ?? null) ? $guideQuestions['hardest_parts'] : [];
    $teamSizes = is_array($guideQuestions['team_sizes'] ?? null) ? $guideQuestions['team_sizes'] : [];
    $ownerNeeds = is_array($guideQuestions['owner_needs'] ?? null) ? $guideQuestions['owner_needs'] : [];
    $guideSlides = is_array($guideSlides ?? null) ? $guideSlides : [];
    $moduleOptions = is_array($moduleOptions ?? null) ? $moduleOptions : [];
    $appointmentSlots = is_array($appointmentSlots ?? null) ? $appointmentSlots : [];
    $bookedAppointmentSlots = is_array($bookedAppointmentSlots ?? null) ? $bookedAppointmentSlots : [];
    $oldNeeds = (array) old('owner_need', []);
    $oldModules = (array) old('module_choices', []);
    $oldStartPath = old('start_path', 'self');
@endphp

<x-layouts::auth.simple :title="__('Create workspace')">
    <div
        data-first-login-guide
        data-slide-count="{{ count($guideSlides) }}"
        class="-mx-4 -my-8 min-h-screen overflow-hidden bg-white text-zinc-950 sm:-mx-8 lg:-mx-16"
    >
        <form method="POST" action="{{ route('workspace.first-login.store', absolute: false) }}" class="grid min-h-screen lg:grid-cols-[1.02fr_0.98fr]">
            @csrf

            <input type="hidden" name="start_path" value="{{ $oldStartPath }}" data-start-path-input>

            <section class="flex min-h-screen flex-col px-6 py-6 sm:px-10 lg:px-14">
                <div class="flex items-center justify-between gap-4">
                    <x-app-logo />
                    <div class="rounded-full border border-zinc-200 bg-zinc-50 px-3 py-1 text-xs font-semibold text-zinc-600">
                        First login
                    </div>
                </div>

                <div class="mt-10 flex-1">
                    <div class="mb-8 flex items-center gap-2">
                        @foreach (['Basics', 'Need', 'Path', 'Finish'] as $index => $label)
                            <span class="h-2 w-12 rounded-full bg-zinc-200 transition data-[active=true]:bg-emerald-600" data-progress-dot data-progress-index="{{ $index }}"></span>
                        @endforeach
                    </div>

                    <div class="space-y-10" data-guide-step="0">
                        <div class="max-w-3xl">
                            <p class="text-xs font-semibold uppercase tracking-[0.2em] text-emerald-700">{{ $authTenantPresentation['hero_tagline'] ?? 'Small business setup' }}</p>
                            <h1 class="mt-3 text-4xl font-semibold tracking-tight text-zinc-950 sm:text-5xl">Hey there, what is hardest right now?</h1>
                            <p class="mt-4 max-w-2xl text-base text-zinc-600">{{ $authTenantPresentation['hero_subtitle'] ?? 'A guided setup for owners who have enough tabs open already.' }}</p>
                        </div>

                        <div class="space-y-3">
                            <h2 class="text-xl font-semibold text-zinc-950">What is the hardest part of running a small business?</h2>
                            <div class="grid gap-3 sm:grid-cols-2">
                                @foreach ($hardestParts as $key => $option)
                                    @php $selected = old('hardest_part') === $key; @endphp
                                    <label class="group cursor-pointer rounded-2xl border px-4 py-4 transition has-[:checked]:border-emerald-500 has-[:checked]:bg-emerald-50 {{ $selected ? 'border-emerald-500 bg-emerald-50' : 'border-zinc-200 bg-white hover:border-zinc-300' }}">
                                        <input type="radio" name="hardest_part" value="{{ $key }}" class="sr-only" @checked($selected)>
                                        <span class="block text-sm font-semibold text-zinc-950">{{ $option['label'] ?? $key }}</span>
                                        <span class="mt-1 block text-sm text-zinc-600">{{ $option['description'] ?? '' }}</span>
                                    </label>
                                @endforeach
                            </div>
                            @error('hardest_part') <p class="text-xs text-red-600">{{ $message }}</p> @enderror
                        </div>

                        <div class="space-y-3">
                            <h2 class="text-xl font-semibold text-zinc-950">How many people are on your team?</h2>
                            <div class="flex flex-wrap gap-3">
                                @foreach ($teamSizes as $key => $label)
                                    @php $selected = old('team_size') === $key; @endphp
                                    <label class="cursor-pointer rounded-full border px-4 py-2 text-sm font-semibold transition has-[:checked]:border-emerald-500 has-[:checked]:bg-emerald-50 has-[:checked]:text-emerald-800 {{ $selected ? 'border-emerald-500 bg-emerald-50 text-emerald-800' : 'border-zinc-200 bg-white text-zinc-700 hover:border-zinc-300' }}">
                                        <input type="radio" name="team_size" value="{{ $key }}" class="sr-only" @checked($selected)>
                                        {{ $label }}
                                    </label>
                                @endforeach
                            </div>
                            @error('team_size') <p class="text-xs text-red-600">{{ $message }}</p> @enderror
                        </div>
                    </div>

                    <div class="hidden space-y-8" data-guide-step="1">
                        <div class="max-w-3xl">
                            <p class="text-xs font-semibold uppercase tracking-[0.2em] text-emerald-700">Priorities</p>
                            <h1 class="mt-3 text-4xl font-semibold tracking-tight text-zinc-950 sm:text-5xl">What do you need as a small business owner right now?</h1>
                            <p class="mt-4 max-w-2xl text-base text-zinc-600">Pick a few. We will use this to shape your workspace and the landlord intake view.</p>
                        </div>

                        <div class="grid gap-3 sm:grid-cols-2">
                            @foreach ($ownerNeeds as $key => $option)
                                @php $selected = in_array($key, $oldNeeds, true); @endphp
                                <label class="group cursor-pointer rounded-2xl border px-4 py-4 transition has-[:checked]:border-emerald-500 has-[:checked]:bg-emerald-50 {{ $selected ? 'border-emerald-500 bg-emerald-50' : 'border-zinc-200 bg-white hover:border-zinc-300' }}">
                                    <input type="checkbox" name="owner_need[]" value="{{ $key }}" class="sr-only" @checked($selected)>
                                    <span class="block text-sm font-semibold text-zinc-950">{{ $option['label'] ?? $key }}</span>
                                    <span class="mt-1 block text-sm text-zinc-600">{{ $option['description'] ?? '' }}</span>
                                </label>
                            @endforeach
                        </div>
                        @error('owner_need') <p class="text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>

                    <div class="hidden space-y-8" data-guide-step="2">
                        <div class="max-w-3xl">
                            <p class="text-xs font-semibold uppercase tracking-[0.2em] text-emerald-700">Let's get started</p>
                            <h1 class="mt-3 text-4xl font-semibold tracking-tight text-zinc-950 sm:text-5xl">Do you want a person or the tour?</h1>
                            <p class="mt-4 max-w-2xl text-base text-zinc-600">Either way, we will save the answers under your user so the Everbranch team can see the context.</p>
                        </div>

                        <div class="grid gap-4 sm:grid-cols-2">
                            <button type="button" class="rounded-3xl border border-zinc-200 bg-white p-5 text-left shadow-sm transition hover:border-emerald-400 hover:bg-emerald-50" data-path-choice="guided">
                                <span class="text-3xl">🤝</span>
                                <span class="mt-4 block text-lg font-semibold text-zinc-950">I want a person to help me with this</span>
                                <span class="mt-2 block text-sm text-zinc-600">Pick a slot and send your contact info. No calendar pileup, no double booking.</span>
                            </button>
                            <button type="button" class="rounded-3xl border border-zinc-200 bg-white p-5 text-left shadow-sm transition hover:border-emerald-400 hover:bg-emerald-50" data-path-choice="self">
                                <span class="text-3xl">🧭</span>
                                <span class="mt-4 block text-lg font-semibold text-zinc-950">I want to explore the features on my own</span>
                                <span class="mt-2 block text-sm text-zinc-600">Take the quick Everbranch walkthrough, then choose the apps that fit.</span>
                            </button>
                        </div>
                    </div>

                    <div class="hidden space-y-8" data-guide-step="3" data-guided-panel>
                        <div class="max-w-3xl">
                            <p class="text-xs font-semibold uppercase tracking-[0.2em] text-emerald-700">Guided setup</p>
                            <h1 class="mt-3 text-4xl font-semibold tracking-tight text-zinc-950 sm:text-5xl">Choose a time that is still open.</h1>
                            <p class="mt-4 max-w-2xl text-base text-zinc-600">We will save this with your onboarding answers and bring it into the landlord dashboard.</p>
                        </div>

                        <div class="grid gap-4 sm:grid-cols-2">
                            <div class="space-y-2">
                                <label for="appointment_name" class="block text-sm font-semibold text-zinc-900">Name</label>
                                <input id="appointment_name" name="appointment_name" value="{{ old('appointment_name', auth()->user()?->name) }}" class="block w-full rounded-2xl border border-zinc-200 px-4 py-3 text-sm outline-none focus:border-emerald-500 focus:ring-2 focus:ring-emerald-500/20">
                                @error('appointment_name') <p class="text-xs text-red-600">{{ $message }}</p> @enderror
                            </div>
                            <div class="space-y-2">
                                <label for="appointment_email" class="block text-sm font-semibold text-zinc-900">Email</label>
                                <input id="appointment_email" name="appointment_email" type="email" value="{{ old('appointment_email', auth()->user()?->email) }}" class="block w-full rounded-2xl border border-zinc-200 px-4 py-3 text-sm outline-none focus:border-emerald-500 focus:ring-2 focus:ring-emerald-500/20">
                                @error('appointment_email') <p class="text-xs text-red-600">{{ $message }}</p> @enderror
                            </div>
                            <div class="space-y-2 sm:col-span-2">
                                <label for="appointment_phone" class="block text-sm font-semibold text-zinc-900">Phone</label>
                                <input id="appointment_phone" name="appointment_phone" value="{{ old('appointment_phone') }}" class="block w-full rounded-2xl border border-zinc-200 px-4 py-3 text-sm outline-none focus:border-emerald-500 focus:ring-2 focus:ring-emerald-500/20">
                                @error('appointment_phone') <p class="text-xs text-red-600">{{ $message }}</p> @enderror
                            </div>
                        </div>

                        <div class="grid gap-3 sm:grid-cols-3">
                            @foreach ($appointmentSlots as $slotKey => $slotLabel)
                                @php
                                    $booked = in_array($slotKey, $bookedAppointmentSlots, true);
                                    $selected = old('appointment_slot') === $slotKey;
                                @endphp
                                <label class="rounded-2xl border px-4 py-3 text-sm font-semibold transition has-[:checked]:border-emerald-500 has-[:checked]:bg-emerald-50 {{ $booked ? 'cursor-not-allowed border-zinc-100 bg-zinc-50 text-zinc-400' : 'cursor-pointer border-zinc-200 bg-white text-zinc-800 hover:border-zinc-300' }}">
                                    <input type="radio" name="appointment_slot" value="{{ $slotKey }}" class="sr-only" @checked($selected) @disabled($booked)>
                                    {{ $slotLabel }}
                                    @if ($booked)
                                        <span class="mt-1 block text-xs font-medium text-zinc-400">Taken</span>
                                    @endif
                                </label>
                            @endforeach
                        </div>
                        @error('appointment_slot') <p class="text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>

                    <div class="hidden space-y-8" data-guide-step="3" data-self-panel>
                        <div class="max-w-3xl">
                            <p class="text-xs font-semibold uppercase tracking-[0.2em] text-emerald-700">Everbranch walkthrough</p>
                            <h1 class="mt-3 text-4xl font-semibold tracking-tight text-zinc-950 sm:text-5xl" data-slide-title>{{ $guideSlides[0]['headline'] ?? 'What Everbranch does' }}</h1>
                            <p class="mt-4 max-w-2xl text-base text-zinc-600" data-slide-body>{{ $guideSlides[0]['body'] ?? '' }}</p>
                        </div>

                        <div class="rounded-[2rem] border border-zinc-200 bg-zinc-50 p-8">
                            <div class="flex min-h-[15rem] items-center justify-center rounded-[1.5rem] bg-white shadow-inner">
                                <div class="text-center">
                                    <div class="text-7xl" data-slide-visual>{{ $guideSlides[0]['visual'] ?? '🌿' }}</div>
                                    <div class="mt-6 flex justify-center gap-2">
                                        @foreach ($guideSlides as $index => $slide)
                                            <span class="h-2 w-8 rounded-full bg-zinc-200 data-[active=true]:bg-emerald-600" data-slide-dot data-slide-index="{{ $index }}"></span>
                                        @endforeach
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="flex flex-wrap gap-3">
                            <button type="button" class="rounded-full border border-zinc-300 px-4 py-2 text-sm font-semibold text-zinc-700 hover:bg-zinc-100" data-slide-prev>Back</button>
                            <button type="button" class="rounded-full bg-zinc-950 px-5 py-2 text-sm font-semibold text-white hover:bg-zinc-800" data-slide-next>Next</button>
                        </div>
                    </div>

                    <div class="hidden space-y-8" data-guide-step="4">
                        <div class="max-w-3xl">
                            <p class="text-xs font-semibold uppercase tracking-[0.2em] text-emerald-700">Choose your apps</p>
                            <h1 class="mt-3 text-4xl font-semibold tracking-tight text-zinc-950 sm:text-5xl">Click the apps that most pertain to you.</h1>
                            <p class="mt-4 max-w-2xl text-base text-zinc-600">These become your first setup map. You can change them later.</p>
                        </div>

                        <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                            @foreach ($moduleOptions as $moduleKey => $module)
                                @php $selected = in_array($moduleKey, $oldModules, true); @endphp
                                <label class="cursor-pointer rounded-2xl border p-4 transition has-[:checked]:border-emerald-500 has-[:checked]:bg-emerald-50 {{ $selected ? 'border-emerald-500 bg-emerald-50' : 'border-zinc-200 bg-white hover:border-zinc-300' }}">
                                    <input type="checkbox" name="module_choices[]" value="{{ $moduleKey }}" class="sr-only" @checked($selected)>
                                    <span class="flex items-start gap-3">
                                        <span class="flex size-10 shrink-0 items-center justify-center rounded-2xl bg-zinc-100 text-xl">{{ $module['icon'] ?? '•' }}</span>
                                        <span>
                                            <span class="block text-sm font-semibold text-zinc-950">{{ $module['label'] ?? $moduleKey }}</span>
                                            <span class="mt-1 block text-xs leading-5 text-zinc-600">{{ $module['description'] ?? '' }}</span>
                                        </span>
                                    </span>
                                </label>
                            @endforeach
                        </div>
                        @error('module_choices') <p class="text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>

                    <div class="mt-10 space-y-4 rounded-3xl border border-zinc-200 bg-zinc-50 p-5">
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div class="space-y-2">
                                <label for="workspace_name" class="block text-sm font-semibold text-zinc-900">Workspace name</label>
                                <input id="workspace_name" name="workspace_name" value="{{ old('workspace_name', $workspaceName) }}" class="block w-full rounded-2xl border border-zinc-200 bg-white px-4 py-3 text-sm text-zinc-950 outline-none focus:border-emerald-500 focus:ring-2 focus:ring-emerald-500/20" autocomplete="organization">
                                @error('workspace_name') <p class="text-xs text-red-600">{{ $message }}</p> @enderror
                            </div>
                            <div class="space-y-2">
                                <label for="template_key" class="block text-sm font-semibold text-zinc-900">Starter workspace</label>
                                <select id="template_key" name="template_key" class="block w-full rounded-2xl border border-zinc-200 bg-white px-4 py-3 text-sm text-zinc-950 outline-none focus:border-emerald-500 focus:ring-2 focus:ring-emerald-500/20">
                                    @foreach ($templateOptions as $template)
                                        @php $templateKey = (string) ($template['key'] ?? ''); @endphp
                                        <option value="{{ $templateKey }}" @selected(old('template_key', $defaultTemplateKey) === $templateKey)>
                                            {{ $template['label'] ?? $templateKey }}
                                        </option>
                                    @endforeach
                                </select>
                                @error('template_key') <p class="text-xs text-red-600">{{ $message }}</p> @enderror
                            </div>
                        </div>
                    </div>
                </div>

                <footer class="mt-8 flex flex-wrap items-center justify-between gap-3 border-t border-zinc-200 pt-5">
                    <button type="button" class="rounded-full border border-zinc-300 px-5 py-2.5 text-sm font-semibold text-zinc-700 hover:bg-zinc-100" data-guide-back>Back</button>
                    <div class="flex items-center gap-3">
                        <button type="button" class="rounded-full border border-zinc-300 px-5 py-2.5 text-sm font-semibold text-zinc-700 hover:bg-zinc-100" data-guide-skip>Skip tour</button>
                        <button type="button" class="rounded-full bg-emerald-700 px-6 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-emerald-600" data-guide-next>Continue</button>
                        <button type="submit" class="hidden rounded-full bg-zinc-950 px-6 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-zinc-800" data-guide-submit>Create workspace</button>
                    </div>
                </footer>
            </section>

            <aside class="hidden min-h-screen items-center justify-center bg-[#111827] px-12 py-10 text-white lg:flex">
                <div class="relative w-full max-w-xl">
                    <div class="absolute -left-6 top-16 size-28 rounded-3xl bg-emerald-400/20 blur-2xl"></div>
                    <div class="absolute -right-4 bottom-10 size-36 rounded-3xl bg-cyan-400/20 blur-2xl"></div>
                    <div class="relative rounded-[2rem] border border-white/10 bg-white/10 p-8 shadow-2xl backdrop-blur">
                        <div class="flex items-center gap-3">
                            <img src="{{ asset('brand/everbranch-mark.svg') }}" alt="Everbranch" class="size-12 rounded-2xl bg-white p-2">
                            <div>
                                <p class="text-sm font-semibold text-emerald-200">Everbranch</p>
                                <p class="text-xs text-white/60">One operating home for the branches of your business.</p>
                            </div>
                        </div>

                        <div class="mt-10 rounded-3xl bg-white p-5 text-zinc-950 shadow-xl">
                            <div class="flex items-center gap-3">
                                <span class="flex size-12 items-center justify-center rounded-2xl bg-emerald-100 text-2xl">🌿</span>
                                <div>
                                    <p class="text-sm font-semibold">Invoicing</p>
                                    <p class="text-xs text-zinc-500">Connected to customers, work, and messages.</p>
                                </div>
                            </div>
                            <div class="mt-4 grid grid-cols-3 gap-2 text-center text-xs font-semibold text-zinc-600">
                                <div class="rounded-2xl bg-zinc-100 px-3 py-3">Supplies</div>
                                <div class="rounded-2xl bg-zinc-100 px-3 py-3">Team</div>
                                <div class="rounded-2xl bg-zinc-100 px-3 py-3">Customers</div>
                            </div>
                        </div>

                        <div class="mt-6 rounded-3xl border border-white/10 bg-[#0f172a] p-5">
                            <p class="text-sm font-semibold text-white">Custom tools without the software circus.</p>
                            <p class="mt-2 text-sm leading-6 text-white/65">Your answers become the first map for what to simplify, what to build, and what the Everbranch team should help with first.</p>
                        </div>
                    </div>
                </div>
            </aside>
        </form>
    </div>

    <script>
        (() => {
            const root = document.querySelector('[data-first-login-guide]');
            if (!root) return;

            const steps = Array.from(root.querySelectorAll('[data-guide-step]'));
            const next = root.querySelector('[data-guide-next]');
            const back = root.querySelector('[data-guide-back]');
            const skip = root.querySelector('[data-guide-skip]');
            const submit = root.querySelector('[data-guide-submit]');
            const pathInput = root.querySelector('[data-start-path-input]');
            const progressDots = Array.from(root.querySelectorAll('[data-progress-dot]'));
            const slides = @json($guideSlides);
            let step = 0;
            let path = pathInput?.value === 'guided' ? 'guided' : 'self';
            let slide = 0;

            function visibleStep() {
                if (path === 'guided' && step === 4) return 3;
                return step;
            }

            function renderSlides() {
                const current = slides[slide] || {};
                const title = root.querySelector('[data-slide-title]');
                const body = root.querySelector('[data-slide-body]');
                const visual = root.querySelector('[data-slide-visual]');
                if (title) title.textContent = current.headline || '';
                if (body) body.textContent = current.body || '';
                if (visual) visual.textContent = current.visual || '🌿';
                root.querySelectorAll('[data-slide-dot]').forEach((dot) => {
                    dot.dataset.active = String(Number(dot.dataset.slideIndex) === slide);
                });
            }

            function render() {
                const actual = visibleStep();
                steps.forEach((panel) => {
                    const panelStep = Number(panel.dataset.guideStep);
                    const guidedPanel = panel.hasAttribute('data-guided-panel');
                    const selfPanel = panel.hasAttribute('data-self-panel');
                    const branchMatches = (!guidedPanel && !selfPanel) || (guidedPanel && path === 'guided') || (selfPanel && path === 'self');
                    panel.classList.toggle('hidden', panelStep !== actual || !branchMatches);
                });

                if (pathInput) pathInput.value = path;
                back?.classList.toggle('invisible', step === 0);
                skip?.classList.toggle('hidden', !(path === 'self' && step === 3));
                next?.classList.toggle('hidden', step >= 4 || (path === 'guided' && step >= 3));
                submit?.classList.toggle('hidden', !(step >= 4 || (path === 'guided' && step >= 3)));

                progressDots.forEach((dot) => {
                    const index = Number(dot.dataset.progressIndex);
                    dot.dataset.active = String(index <= Math.min(step, 3));
                });

                renderSlides();
            }

            root.querySelectorAll('[data-path-choice]').forEach((button) => {
                button.addEventListener('click', () => {
                    path = button.dataset.pathChoice === 'guided' ? 'guided' : 'self';
                    step = path === 'guided' ? 3 : 3;
                    render();
                });
            });

            root.querySelector('[data-slide-next]')?.addEventListener('click', () => {
                if (slide < slides.length - 1) {
                    slide += 1;
                    renderSlides();
                    return;
                }
                step = 4;
                render();
            });

            root.querySelector('[data-slide-prev]')?.addEventListener('click', () => {
                if (slide > 0) {
                    slide -= 1;
                    renderSlides();
                    return;
                }
                step = 2;
                render();
            });

            next?.addEventListener('click', () => {
                step = Math.min(step + 1, 4);
                render();
            });

            back?.addEventListener('click', () => {
                if (path === 'self' && step === 4) {
                    step = 3;
                } else if (step === 3) {
                    step = 2;
                } else {
                    step = Math.max(step - 1, 0);
                }
                render();
            });

            skip?.addEventListener('click', () => {
                step = 4;
                render();
            });

            render();
        })();
    </script>
</x-layouts::auth.simple>

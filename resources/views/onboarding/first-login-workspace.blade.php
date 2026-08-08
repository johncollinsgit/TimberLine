@php
    $workspaceName = $workspaceName ?? '';
    $businessTypes = is_array($businessTypes ?? null) ? $businessTypes : [];
    $teamSizes = is_array($teamSizes ?? null) ? $teamSizes : [];
    $hardestParts = is_array($hardestParts ?? null) ? $hardestParts : [];
    $toolOptions = is_array($toolOptions ?? null) ? $toolOptions : [];
    $recommendedTools = is_array($recommendedTools ?? null) ? $recommendedTools : [];
    $steps = ['Name', 'Business', 'Priorities', 'Tools', 'Ready'];
@endphp

<x-layouts::auth.simple :title="__('Create your workspace')" :auth-tenant-presentation="$authTenantPresentation ?? []">
    <style>
        body:has([data-flw-shell]) {
            background: #f8fbfa;
        }

        body:has([data-flw-shell]) .fb-auth-shell {
            display: block;
            isolation: isolate;
            max-width: none;
            min-height: 100vh;
            overflow: hidden;
            padding: 0;
            position: relative;
        }

        body:has([data-flw-shell]) .fb-auth-brand-panel {
            display: none;
        }

        body:has([data-flw-shell]) .fb-auth-card-wrap {
            align-items: stretch;
            display: flex;
            justify-content: stretch;
            min-height: 100vh;
            position: relative;
        }

        body:has([data-flw-shell]) .fb-auth-card {
            background: transparent;
            border: 0;
            box-shadow: none;
            max-width: none;
            padding: 0;
            width: 100%;
        }

        body:has([data-flw-shell]) .fb-auth-shell::before,
        body:has([data-flw-shell]) .fb-auth-shell::after,
        body:has([data-flw-shell]) .fb-auth-card-wrap::before {
            border-radius: 999px;
            content: '';
            filter: blur(58px);
            inset: auto;
            opacity: 0.58;
            pointer-events: none;
            position: absolute;
            z-index: -1;
        }

        body:has([data-flw-shell]) .fb-auth-shell::before {
            background: rgba(103, 210, 166, 0.48);
            height: min(42vw, 38rem);
            left: -12rem;
            top: -13rem;
            width: min(42vw, 38rem);
        }

        body:has([data-flw-shell]) .fb-auth-shell::after {
            background: rgba(126, 176, 237, 0.42);
            bottom: -15rem;
            height: min(45vw, 42rem);
            right: -14rem;
            width: min(45vw, 42rem);
        }

        body:has([data-flw-shell]) .fb-auth-card-wrap::before {
            background: rgba(215, 184, 245, 0.28);
            height: min(32vw, 30rem);
            right: 18%;
            top: -14rem;
            width: min(32vw, 30rem);
        }

        [data-flw-shell] {
            align-items: center;
            background:
                radial-gradient(48rem 36rem at 100% 0%, rgba(226, 250, 240, 0.74), transparent 68%),
                radial-gradient(44rem 34rem at 0% 100%, rgba(227, 239, 255, 0.74), transparent 70%),
                linear-gradient(145deg, rgba(255, 255, 255, 0.84), rgba(247, 251, 249, 0.64));
            display: flex;
            min-height: 100vh;
            overflow: hidden;
            padding: 2rem;
            position: relative;
        }

        [data-flw-shell]::before,
        [data-flw-shell]::after {
            content: '';
            inset: -18%;
            pointer-events: none;
            position: absolute;
        }

        [data-flw-shell]::before {
            background:
                conic-gradient(from 185deg at 50% 50%, transparent 0deg, rgba(135, 227, 183, 0.22) 58deg, transparent 132deg, rgba(155, 192, 248, 0.2) 204deg, transparent 286deg, rgba(223, 183, 247, 0.17) 338deg, transparent 360deg);
            filter: blur(74px);
            opacity: 0.72;
            z-index: 0;
        }

        [data-flw-shell]::after {
            background-image:
                radial-gradient(circle, rgba(58, 139, 106, 0.22) 0 1px, transparent 1.75px),
                radial-gradient(circle, rgba(100, 150, 217, 0.18) 0 1px, transparent 1.5px);
            background-position: 0 0, 42px 72px;
            background-size: 142px 142px, 191px 191px;
            mask-image: radial-gradient(ellipse 94% 80% at 50% 50%, transparent 36%, #000 100%);
            opacity: 0.5;
            z-index: 0;
        }

        [data-flw] {
            animation: flw-shell-in 520ms cubic-bezier(0.22, 1, 0.36, 1) both;
            backdrop-filter: blur(16px);
            background: rgba(255, 255, 255, 0.86);
            border: 1px solid rgba(255, 255, 255, 0.88);
            border-radius: 2.25rem;
            box-shadow: 0 30px 100px rgba(21, 55, 48, 0.11), 0 2px 12px rgba(21, 55, 48, 0.04), inset 0 1px 0 rgba(255, 255, 255, 0.84);
            position: relative;
            z-index: 1;
        }

        [data-step]:not([hidden]) {
            animation: flw-step-in 420ms cubic-bezier(0.22, 1, 0.36, 1) both;
        }

        .flw-card:hover,
        .flw-team:hover,
        .flw-focus:hover,
        .flw-tool:hover,
        .flw-help:hover {
            transform: translateY(-1px);
        }

        @keyframes flw-shell-in {
            from {
                opacity: 0;
                transform: translateY(10px) scale(0.985);
                filter: blur(14px);
            }
            to {
                opacity: 1;
                transform: translateY(0) scale(1);
                filter: blur(0);
            }
        }

        @keyframes flw-step-in {
            from {
                opacity: 0;
                transform: translateY(8px);
                filter: blur(10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
                filter: blur(0);
            }
        }

        @keyframes flw-edge-green {
            0%, 100% { transform: translate3d(0, 0, 0) scale(1); }
            50% { transform: translate3d(9vw, 7vh, 0) scale(1.12); }
        }

        @keyframes flw-edge-blue {
            0%, 100% { transform: translate3d(0, 0, 0) scale(1); }
            50% { transform: translate3d(-8vw, -6vh, 0) scale(1.1); }
        }

        @keyframes flw-edge-lilac {
            0%, 100% { transform: translate3d(0, 0, 0) scale(1); }
            50% { transform: translate3d(4vw, 10vh, 0) scale(1.08); }
        }

        @keyframes flw-aurora {
            0%, 100% { transform: rotate(0deg) scale(1); }
            50% { transform: rotate(18deg) scale(1.08); }
        }

        @keyframes flw-glints {
            0%, 100% { background-position: 0 0, 42px 72px; opacity: 0.27; }
            50% { background-position: 32px -26px, 10px 98px; opacity: 0.48; }
        }

        @media (prefers-reduced-motion: no-preference) {
            body:has([data-flw-shell]) .fb-auth-shell::before {
                animation: flw-edge-green 22s ease-in-out infinite;
            }

            body:has([data-flw-shell]) .fb-auth-shell::after {
                animation: flw-edge-blue 26s ease-in-out infinite;
            }

            body:has([data-flw-shell]) .fb-auth-card-wrap::before {
                animation: flw-edge-lilac 30s ease-in-out infinite;
            }

            [data-flw-shell]::before {
                animation: flw-aurora 34s ease-in-out infinite;
            }

            [data-flw-shell]::after {
                animation: flw-glints 18s ease-in-out infinite;
            }
        }

        @media (prefers-reduced-motion: reduce) {
            [data-flw],
            [data-step]:not([hidden]),
            [data-flw-shell]::before,
            [data-flw-shell]::after {
                animation: none;
            }

            .flw-card:hover,
            .flw-team:hover,
            .flw-focus:hover,
            .flw-tool:hover,
            .flw-help:hover {
                transform: none;
            }
        }

        @media (max-width: 640px) {
            [data-flw-shell] {
                align-items: flex-start;
                padding: 1rem;
            }

            [data-flw] {
                border-radius: 1.5rem;
            }
        }
    </style>

    <div data-flw-shell class="w-full">
        <div
            data-flw
            data-workspace-name="{{ $workspaceName }}"
            data-recommended='@json($recommendedTools)'
            class="mx-auto w-full max-w-5xl p-8 sm:p-14 lg:p-16"
        >
            <div class="flex items-start justify-between gap-6">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.18em] text-emerald-700">Workspace setup</p>
                    <h1 class="mt-2 text-3xl font-semibold tracking-tight text-zinc-950 sm:text-4xl">Start with the right foundation.</h1>
                    <p class="mt-3 max-w-2xl text-base leading-7 text-zinc-600">Five quick choices help us show the work that belongs in your workspace—and leave out the work that does not.</p>
                </div>
                <span class="shrink-0 text-sm font-medium text-zinc-500">
                    <span data-step-num>1</span>/<span>{{ count($steps) }}</span>
                </span>
            </div>

            <div class="mt-5 flex flex-wrap gap-x-5 gap-y-2 text-xs font-medium text-zinc-500">
                <span>About two minutes</span>
                <span>No billing or invitations yet</span>
                <span>Change it later</span>
            </div>

            {{-- Progress dots --}}
            <div class="mt-5 flex items-center gap-1.5" aria-hidden="true">
                @foreach ($steps as $i => $label)
                    <span data-dot="{{ $i + 1 }}" class="h-1.5 flex-1 rounded-full bg-zinc-200 transition-colors"></span>
                @endforeach
            </div>

            @if ($errors->any())
                <div class="mt-5 rounded-xl border border-rose-200 bg-rose-50 p-3 text-sm text-rose-800">
                    {{ $errors->first() }}
                </div>
            @endif

            <form method="POST" action="{{ route('workspace.first-login.store') }}" class="mt-6">
                @csrf
                <input type="hidden" name="workspace_name" value="{{ $workspaceName }}">
                <input type="hidden" name="template_key" value="">
                <input type="hidden" name="team_size" value="">
                <input type="hidden" name="hardest_part" value="">
                <input type="hidden" name="start_path" value="guided">
                <input type="hidden" name="appointment_name" value="">
                <input type="hidden" name="appointment_email" value="">
                <input type="hidden" name="appointment_phone" value="">
                <input type="hidden" name="custom_business_type" value="">
                <input type="hidden" name="business_description" value="">
                <input type="hidden" name="customer_label" value="">
                <input type="hidden" name="work_label" value="">
                <span data-modules-holder></span>

                {{-- Step 1: name --}}
                <section data-step="1" class="space-y-4">
                    <div>
                        <h2 class="text-lg font-semibold text-zinc-900">Name your workspace</h2>
                        <p class="mt-1 text-sm text-zinc-500">You can change this later.</p>
                    </div>
                    <input
                        data-name-input type="text" value="{{ $workspaceName }}" maxlength="120"
                        placeholder="e.g. Collins Electric"
                        class="w-full rounded-xl border border-zinc-300 px-4 py-3 text-base text-zinc-900 shadow-sm focus:border-emerald-500 focus:ring-2 focus:ring-emerald-200"
                    >
                </section>

                {{-- Step 2: business type --}}
                <section data-step="2" class="space-y-4" hidden>
                    <div class="max-w-xl">
                        <h2 class="text-2xl font-semibold tracking-tight text-zinc-900">What kind of business are you building?</h2>
                        <p class="mt-1.5 text-sm leading-6 text-zinc-600">Choose the closest fit. Apps and connections will not change this choice for you.</p>
                    </div>
                    <div class="grid gap-3 sm:grid-cols-2">
                        @foreach ($businessTypes as $type)
                            <button
                                type="button" data-pick-type="{{ $type['key'] }}"
                                class="flw-card rounded-2xl border border-zinc-200 bg-white px-4 py-4 text-left transition hover:border-zinc-400 hover:bg-zinc-50"
                            >
                                <span class="text-sm font-semibold text-zinc-900">{{ $type['label'] }}</span>
                                <span class="mt-1 block text-sm leading-5 text-zinc-500">{{ $type['blurb'] }}</span>
                            </button>
                        @endforeach
                    </div>
                    <div data-custom-context hidden class="border-l-2 border-emerald-500 pl-5">
                        <p class="text-base font-semibold text-zinc-900">Tell us about your business.</p>
                        <p class="mt-1 text-sm leading-6 text-zinc-600">We will start with a simple workspace for contacts and updates. The right tools get added after we have confirmed the fit together.</p>
                        <div class="mt-5 grid gap-4 sm:grid-cols-2">
                            <label class="block text-sm font-medium text-zinc-800">What type of business is it? <span class="text-rose-600">*</span>
                                <input data-custom-business-type type="text" maxlength="120" placeholder="Nonprofit, studio, property manager…" class="mt-2 w-full rounded-xl border border-zinc-300 bg-white px-3.5 py-3 text-sm text-zinc-900 outline-none focus:border-emerald-500 focus:ring-2 focus:ring-emerald-200">
                            </label>
                            <label class="block text-sm font-medium text-zinc-800">Anything we should know? <span class="font-normal text-zinc-400">Optional</span>
                                <textarea data-business-description rows="2" maxlength="500" placeholder="For example: memberships, inspections, client projects, or classes." class="mt-2 w-full resize-none rounded-xl border border-zinc-300 bg-white px-3.5 py-3 text-sm text-zinc-900 outline-none focus:border-emerald-500 focus:ring-2 focus:ring-emerald-200"></textarea>
                            </label>
                        </div>
                    </div>
                </section>

                {{-- Step 3: team size --}}
                <section data-step="3" class="space-y-7" hidden>
                    <div>
                        <h2 class="text-lg font-semibold text-zinc-900">How big is your team right now?</h2>
                        <p class="mt-1 text-sm text-zinc-500">This helps us keep the first setup practical instead of overbuilt.</p>
                    </div>
                    <div class="grid gap-2.5 sm:grid-cols-2">
                        @foreach ($teamSizes as $key => $label)
                            <button
                                type="button" data-pick-team="{{ $key }}"
                                class="flw-team rounded-xl border border-zinc-200 bg-white px-4 py-3 text-left text-sm font-medium text-zinc-800 transition hover:border-emerald-300 hover:shadow-sm"
                            >{{ $label }}</button>
                        @endforeach
                    </div>
                    <div class="border-t border-zinc-200 pt-7">
                        <h2 class="text-lg font-semibold text-zinc-900">What would you most like help with?</h2>
                        <p class="mt-1 text-sm text-zinc-500">We will make this easier to find first.</p>
                        <div class="mt-4 space-y-2">
                            @foreach ($hardestParts as $key => $opt)
                                <button
                                    type="button" data-pick-focus="{{ $key }}"
                                    class="flw-focus block w-full rounded-xl border border-zinc-200 bg-white p-3.5 text-left transition hover:border-zinc-400 hover:bg-zinc-50"
                                >
                                    <span class="block text-sm font-semibold text-zinc-900">{{ $opt['label'] }}</span>
                                    <span class="mt-0.5 block text-xs text-zinc-500">{{ $opt['description'] }}</span>
                                </button>
                            @endforeach
                        </div>
                    </div>
                </section>

                {{-- Step 4: tools --}}
                <section data-step="4" class="space-y-4" hidden>
                    <div>
                        <h2 class="text-lg font-semibold text-zinc-900">Pick the tools that sound useful</h2>
                        <p class="mt-1 text-sm text-zinc-500">Recommended tools are a launch plan, not a checkout screen. We will activate what is ready.</p>
                        <p data-neutral-tools-note class="mt-2 hidden text-sm leading-6 text-emerald-800">Your first workspace includes customers, messages, and reporting. We will add other tools after we have reviewed the fit.</p>
                    </div>
                    <div class="grid max-h-[42vh] gap-2.5 overflow-y-auto pr-1 sm:grid-cols-2">
                        @foreach ($toolOptions as $key => $tool)
                            <button
                                type="button" data-tool="{{ $key }}"
                                class="flw-tool flex items-start gap-3 rounded-xl border border-zinc-200 bg-white p-3 text-left transition hover:border-emerald-300"
                            >
                                <span class="text-lg leading-none">{{ $tool['icon'] ?? '•' }}</span>
                                <span class="min-w-0">
                                    <span class="flex items-center gap-1.5">
                                        <span class="text-sm font-semibold text-zinc-900">{{ $tool['label'] }}</span>
                                        <span data-rec-badge class="hidden rounded-full bg-emerald-100 px-1.5 py-0.5 text-[0.6rem] font-semibold text-emerald-700">Recommended</span>
                                    </span>
                                    <span class="mt-0.5 block text-xs text-zinc-500">{{ $tool['description'] }}</span>
                                </span>
                                <span data-tool-check class="ml-auto hidden text-emerald-600">✓</span>
                            </button>
                        @endforeach
                    </div>
                </section>

                {{-- Step 5: concierge and review --}}
                <section data-step="5" class="space-y-5" hidden>
                    <div>
                        <h2 class="text-lg font-semibold text-zinc-900">Would you like a hand?</h2>
                        <p class="mt-1 text-sm text-zinc-500">Choose the kind of start you prefer. You can change your mind later.</p>
                    </div>
                    <div class="grid gap-2.5 sm:grid-cols-2">
                        <button type="button" data-pick-help="guided" class="flw-help rounded-xl border-2 border-emerald-500 bg-emerald-50 p-4 text-left transition">
                            <span class="block text-sm font-semibold text-zinc-900">Set it up with me</span>
                            <span class="mt-1 block text-xs text-zinc-500">We will help bring in contacts and choose your first tools.</span>
                        </button>
                        <button type="button" data-pick-help="self" class="flw-help rounded-xl border-2 border-zinc-200 bg-white p-4 text-left transition">
                            <span class="block text-sm font-semibold text-zinc-900">Let me explore first</span>
                            <span class="mt-1 block text-xs text-zinc-500">Open your workspace, then reach out when you want help.</span>
                        </button>
                    </div>
                    <div data-help-contact class="space-y-2.5 rounded-xl border border-zinc-200 bg-zinc-50 p-4">
                        <p class="text-xs font-medium text-zinc-600">Where can we reach you? (optional)</p>
                        <input data-help-name type="text" placeholder="Your name" maxlength="120" class="w-full rounded-lg border border-zinc-300 px-3 py-2 text-sm">
                        <input data-help-email type="email" placeholder="Email" maxlength="255" class="w-full rounded-lg border border-zinc-300 px-3 py-2 text-sm">
                        <input data-help-phone type="text" placeholder="Phone" maxlength="40" class="w-full rounded-lg border border-zinc-300 px-3 py-2 text-sm">
                    </div>
                    <div class="border-t border-zinc-200 pt-5">
                        <h3 class="text-lg font-semibold text-zinc-900">Ready when you are</h3>
                        <p class="mt-1 text-sm text-zinc-500">We will open your first workspace and check in before adding extra tools.</p>
                    </div>
                    <dl class="space-y-2 rounded-xl border border-zinc-200 bg-zinc-50 p-4 text-sm">
                        <div class="flex justify-between gap-4"><dt class="text-zinc-500">Workspace</dt><dd data-review-name class="font-medium text-zinc-900"></dd></div>
                        <div class="flex justify-between gap-4"><dt class="text-zinc-500">Business</dt><dd data-review-type class="font-medium text-zinc-900"></dd></div>
                        <div class="flex justify-between gap-4"><dt class="text-zinc-500">Team</dt><dd data-review-team class="font-medium text-zinc-900"></dd></div>
                        <div class="flex justify-between gap-4"><dt class="text-zinc-500">Tools</dt><dd data-review-tools class="text-right font-medium text-zinc-900"></dd></div>
                    </dl>
                    <p class="text-xs text-zinc-400">Your choices do not start billing, send messages, or invite anyone.</p>
                </section>

                {{-- Nav --}}
                <div class="mt-7 flex items-center justify-between gap-3">
                    <button type="button" data-back class="rounded-full px-4 py-2 text-sm font-medium text-zinc-500 hover:text-zinc-800" hidden>Back</button>
                    <span class="flex-1"></span>
                    <button type="button" data-next class="rounded-full bg-zinc-950 px-6 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-zinc-800 disabled:cursor-not-allowed disabled:opacity-40">Continue</button>
                    <button type="submit" data-submit class="rounded-full bg-zinc-950 px-6 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-zinc-800" hidden>Create workspace</button>
                </div>
            </form>
        </div>
    </div>

    @push('scripts')
    @endpush

    <script>
        (function () {
            var root = document.querySelector('[data-flw]');
            if (!root) return;
            var recommended = {};
            try { recommended = JSON.parse(root.getAttribute('data-recommended') || '{}'); } catch (e) {}
            var TOTAL = 5;
            var step = 1;
            var state = {
                workspace_name: root.getAttribute('data-workspace-name') || '',
                template_key: '', template_label: '',
                custom_business_type: '', business_description: '', customer_label: '', work_label: '',
                team_size: '', team_label: '',
                hardest_part: '',
                module_choices: [],
                start_path: 'guided',
            };

            var $ = function (sel) { return root.querySelector(sel); };
            var $$ = function (sel) { return Array.prototype.slice.call(root.querySelectorAll(sel)); };
            var hidden = {
                workspace_name: $('input[name="workspace_name"]'),
                template_key: $('input[name="template_key"]'),
                team_size: $('input[name="team_size"]'),
                hardest_part: $('input[name="hardest_part"]'),
                start_path: $('input[name="start_path"]'),
                appointment_name: $('input[name="appointment_name"]'),
                appointment_email: $('input[name="appointment_email"]'),
                appointment_phone: $('input[name="appointment_phone"]'),
                custom_business_type: $('input[name="custom_business_type"]'),
                business_description: $('input[name="business_description"]'),
                customer_label: $('input[name="customer_label"]'),
                work_label: $('input[name="work_label"]'),
            };
            var modulesHolder = $('[data-modules-holder]');

            function syncHidden() {
                hidden.workspace_name.value = state.workspace_name;
                hidden.template_key.value = state.template_key;
                hidden.team_size.value = state.team_size;
                hidden.hardest_part.value = state.hardest_part;
                hidden.start_path.value = state.start_path;
                hidden.custom_business_type.value = state.custom_business_type;
                hidden.business_description.value = state.business_description;
                hidden.customer_label.value = state.customer_label;
                hidden.work_label.value = state.work_label;
                modulesHolder.innerHTML = '';
                state.module_choices.forEach(function (k) {
                    var i = document.createElement('input');
                    i.type = 'hidden'; i.name = 'module_choices[]'; i.value = k;
                    modulesHolder.appendChild(i);
                });
            }

            function canNext() {
                if (step === 1) return state.workspace_name.trim().length > 0;
                if (step === 2) return state.template_key !== '' && (state.template_key !== 'custom' || state.custom_business_type.trim().length > 0);
                if (step === 3) return state.team_size !== '' && state.hardest_part !== '';
                return true;
            }

            function selectOne(nodes, node, activeClasses) {
                nodes.forEach(function (n) {
                    n.classList.remove('border-emerald-500', 'border-2', 'bg-emerald-50');
                    n.classList.add('border-zinc-200');
                });
                node.classList.remove('border-zinc-200');
                node.classList.add('border-emerald-500', 'bg-emerald-50');
            }

            function refreshTools() {
                var rec = recommended[state.template_key] || [];
                var neutralBase = ['customers', 'messaging', 'reporting'];
                var isNeutralWorkspace = state.template_key === 'generic' || state.template_key === 'custom';
                $$('[data-tool]').forEach(function (el) {
                    var k = el.getAttribute('data-tool');
                    el.hidden = isNeutralWorkspace && neutralBase.indexOf(k) === -1;
                    var picked = state.module_choices.indexOf(k) !== -1;
                    el.classList.toggle('border-emerald-500', picked);
                    el.classList.toggle('bg-emerald-50', picked);
                    el.classList.toggle('border-zinc-200', !picked);
                    var check = el.querySelector('[data-tool-check]');
                    if (check) check.classList.toggle('hidden', !picked);
                    var badge = el.querySelector('[data-rec-badge]');
                    if (badge) badge.classList.toggle('hidden', rec.indexOf(k) === -1);
                });
                var neutralNote = $('[data-neutral-tools-note]');
                if (neutralNote) neutralNote.classList.toggle('hidden', !isNeutralWorkspace);
            }

            function updateReview() {
                $('[data-review-name]').textContent = state.workspace_name || '—';
                $('[data-review-type]').textContent = state.template_label || '—';
                $('[data-review-team]').textContent = state.team_label || '—';
                $('[data-review-tools]').textContent = state.module_choices.length ? (state.module_choices.length + ' selected') : 'None yet';
            }

            function render() {
                $$('[data-step]').forEach(function (p) { p.hidden = parseInt(p.getAttribute('data-step'), 10) !== step; });
                $$('[data-dot]').forEach(function (d) {
                    var on = parseInt(d.getAttribute('data-dot'), 10) <= step;
                    d.classList.toggle('bg-emerald-500', on);
                    d.classList.toggle('bg-zinc-200', !on);
                });
                $('[data-step-num]').textContent = step;
                $('[data-back]').hidden = step === 1;
                $('[data-next]').hidden = step === TOTAL;
                $('[data-submit]').hidden = step !== TOTAL;
                $('[data-next]').disabled = !canNext();
                if (step === TOTAL) updateReview();
            }

            // Name
            var nameInput = $('[data-name-input]');
            nameInput.addEventListener('input', function () {
                state.workspace_name = nameInput.value;
                syncHidden();
                $('[data-next]').disabled = !canNext();
            });

            // Business type
            $$('[data-pick-type]').forEach(function (el) {
                el.addEventListener('click', function () {
                    state.template_key = el.getAttribute('data-pick-type');
                    state.template_label = el.querySelector('.text-sm') ? el.querySelector('.text-sm').textContent : state.template_key;
                    state.module_choices = (recommended[state.template_key] || []).slice();
                    $('[data-custom-context]').hidden = state.template_key !== 'custom';
                    selectOne($$('[data-pick-type]'), el);
                    refreshTools();
                    syncHidden();
                    $('[data-next]').disabled = !canNext();
                });
            });

            [['[data-custom-business-type]', 'custom_business_type'], ['[data-business-description]', 'business_description'], ['[data-customer-label]', 'customer_label'], ['[data-work-label]', 'work_label']].forEach(function (pair) {
                var input = $(pair[0]);
                if (!input) return;
                input.addEventListener('input', function () {
                    state[pair[1]] = input.value;
                    syncHidden();
                    $('[data-next]').disabled = !canNext();
                });
            });

            // Team size
            $$('[data-pick-team]').forEach(function (el) {
                el.addEventListener('click', function () {
                    state.team_size = el.getAttribute('data-pick-team');
                    state.team_label = el.textContent.trim();
                    selectOne($$('[data-pick-team]'), el);
                    syncHidden();
                    $('[data-next]').disabled = !canNext();
                });
            });

            // Focus
            $$('[data-pick-focus]').forEach(function (el) {
                el.addEventListener('click', function () {
                    state.hardest_part = el.getAttribute('data-pick-focus');
                    selectOne($$('[data-pick-focus]'), el);
                    syncHidden();
                    $('[data-next]').disabled = !canNext();
                });
            });

            // Tools
            $$('[data-tool]').forEach(function (el) {
                el.addEventListener('click', function () {
                    var k = el.getAttribute('data-tool');
                    var idx = state.module_choices.indexOf(k);
                    if (idx === -1) state.module_choices.push(k); else state.module_choices.splice(idx, 1);
                    refreshTools();
                    syncHidden();
                });
            });

            // Concierge
            $$('[data-pick-help]').forEach(function (el) {
                el.addEventListener('click', function () {
                    state.start_path = el.getAttribute('data-pick-help');
                    $$('[data-pick-help]').forEach(function (n) {
                        n.classList.remove('border-emerald-500', 'bg-emerald-50');
                        n.classList.add('border-zinc-200');
                    });
                    el.classList.remove('border-zinc-200');
                    el.classList.add('border-emerald-500', 'bg-emerald-50');
                    $('[data-help-contact]').hidden = state.start_path !== 'guided';
                    syncHidden();
                });
            });
            var hn = $('[data-help-name]'), he = $('[data-help-email]'), hp = $('[data-help-phone]');
            if (hn) hn.addEventListener('input', function () { hidden.appointment_name.value = hn.value; });
            if (he) he.addEventListener('input', function () { hidden.appointment_email.value = he.value; });
            if (hp) hp.addEventListener('input', function () { hidden.appointment_phone.value = hp.value; });

            $('[data-next]').addEventListener('click', function () { if (canNext() && step < TOTAL) { step++; render(); } });
            $('[data-back]').addEventListener('click', function () { if (step > 1) { step--; render(); } });

            syncHidden();
            render();
        })();
    </script>
</x-layouts::auth.simple>

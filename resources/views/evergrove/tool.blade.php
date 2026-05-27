@php
    $content = is_array($content ?? null) ? $content : [];
    $tool = is_array($tool ?? null) ? $tool : [];
    $toolKey = (string) ($toolKey ?? 'project_estimate');
    $businessSizes = is_array($content['business_sizes'] ?? null) ? $content['business_sizes'] : [];
    $timelines = is_array($content['timeline_options'] ?? null) ? $content['timeline_options'] : [];
    $budgetRanges = is_array($content['budget_ranges'] ?? null) ? $content['budget_ranges'] : [];
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    @include('partials.head', [
        'app_name' => 'Evergrove',
        'title' => $tool['title'] ?? 'Evergrove Calculator',
        'description' => $tool['summary'] ?? 'Evergrove planning calculator.',
    ])
</head>
<body class="fb-public-body" data-premium-motion="public">
    @include('platform.partials.premium-motion')

    <main class="fb-public-shell fb-contact-shell">
        <a href="/#tools" class="fb-btn fb-btn-secondary fb-contact-back">Back to Evergrove</a>

        <section class="fb-card fb-contact-overview" aria-label="Calculator overview" data-reveal data-premium-surface>
            <p class="fb-section-kicker">Example Tool</p>
            <h1 class="fb-contact-title">{{ $tool['title'] ?? 'Calculator' }}</h1>
            <p class="fb-contact-summary">{{ $tool['summary'] ?? '' }}</p>
        </section>

        <section class="fb-section" aria-label="Calculator" data-reveal>
            <div class="grid gap-6 lg:grid-cols-[1fr_0.9fr]">
                <article class="fb-card p-6" data-premium-surface>
                    <div data-evergrove-calculator data-tool-key="{{ $toolKey }}" class="space-y-5">
                        @if($toolKey === 'ai_roi')
                            <div class="grid gap-4 md:grid-cols-2">
                                <label class="block text-sm font-semibold text-[var(--fb-text-primary)]">
                                    Hours repeated weekly
                                    <input data-calc-input="hours" type="number" min="0" step="1" value="8" class="fb-input mt-2" />
                                </label>
                                <label class="block text-sm font-semibold text-[var(--fb-text-primary)]">
                                    Loaded hourly cost
                                    <input data-calc-input="hourly" type="number" min="0" step="5" value="55" class="fb-input mt-2" />
                                </label>
                            </div>
                            <div class="grid gap-4 md:grid-cols-2">
                                <label class="block text-sm font-semibold text-[var(--fb-text-primary)]">
                                    Automatable percent
                                    <input data-calc-input="percent" type="number" min="0" max="100" step="5" value="45" class="fb-input mt-2" />
                                </label>
                                <label class="block text-sm font-semibold text-[var(--fb-text-primary)]">
                                    Monthly tool cost estimate
                                    <input data-calc-input="cost" type="number" min="0" step="25" value="250" class="fb-input mt-2" />
                                </label>
                            </div>
                        @elseif($toolKey === 'automation_savings')
                            <div class="grid gap-4 md:grid-cols-2">
                                <label class="block text-sm font-semibold text-[var(--fb-text-primary)]">
                                    Manual handoffs per week
                                    <input data-calc-input="handoffs" type="number" min="0" step="1" value="40" class="fb-input mt-2" />
                                </label>
                                <label class="block text-sm font-semibold text-[var(--fb-text-primary)]">
                                    Minutes per handoff
                                    <input data-calc-input="minutes" type="number" min="0" step="1" value="12" class="fb-input mt-2" />
                                </label>
                            </div>
                            <div class="grid gap-4 md:grid-cols-2">
                                <label class="block text-sm font-semibold text-[var(--fb-text-primary)]">
                                    Loaded hourly cost
                                    <input data-calc-input="hourly" type="number" min="0" step="5" value="45" class="fb-input mt-2" />
                                </label>
                                <label class="block text-sm font-semibold text-[var(--fb-text-primary)]">
                                    Automation coverage percent
                                    <input data-calc-input="percent" type="number" min="0" max="100" step="5" value="55" class="fb-input mt-2" />
                                </label>
                            </div>
                        @else
                            <div class="grid gap-4 md:grid-cols-2">
                                <label class="block text-sm font-semibold text-[var(--fb-text-primary)]">
                                    Pages or primary screens
                                    <input data-calc-input="screens" type="number" min="1" step="1" value="8" class="fb-input mt-2" />
                                </label>
                                <label class="block text-sm font-semibold text-[var(--fb-text-primary)]">
                                    Custom workflows
                                    <input data-calc-input="workflows" type="number" min="0" step="1" value="3" class="fb-input mt-2" />
                                </label>
                            </div>
                            <div class="grid gap-4 md:grid-cols-2">
                                <label class="block text-sm font-semibold text-[var(--fb-text-primary)]">
                                    Integrations
                                    <input data-calc-input="integrations" type="number" min="0" step="1" value="2" class="fb-input mt-2" />
                                </label>
                                <label class="block text-sm font-semibold text-[var(--fb-text-primary)]">
                                    Data migration complexity
                                    <select data-calc-input="complexity" class="fb-input mt-2">
                                        <option value="1">Low</option>
                                        <option value="1.25" selected>Medium</option>
                                        <option value="1.6">High</option>
                                    </select>
                                </label>
                            </div>
                        @endif

                        <div class="border border-[var(--fb-border)] bg-[var(--fb-surface-subtle)] p-5">
                            <p class="text-xs font-semibold uppercase tracking-[0.16em] text-[var(--fb-text-muted)]">{{ $tool['result_label'] ?? 'Estimated result' }}</p>
                            <p data-calc-result class="mt-2 text-3xl font-semibold text-[var(--fb-text-primary)]">$0</p>
                            <p data-calc-note class="mt-2 text-sm text-[var(--fb-text-secondary)]">Adjust the inputs to frame the discussion.</p>
                        </div>
                    </div>
                </article>

                <form method="POST" action="{{ route('evergrove.inquiries.store') }}" class="fb-card p-6 space-y-4" data-premium-surface>
                    @csrf
                    <input type="hidden" name="source_page" value="{{ $toolKey }}" />
                    <input type="hidden" name="calculator_payload" data-calc-payload value="" />

                    @if (session('status'))
                        <div class="fb-state fb-state--success text-sm">{{ session('status') }}</div>
                    @endif

                    <div>
                        <h2 class="text-lg font-semibold text-[var(--fb-text-primary)]">Send this estimate to Evergrove</h2>
                        <p class="mt-1 text-sm text-[var(--fb-text-secondary)]">The calculator output will be attached to your project notes.</p>
                    </div>

                    <div class="grid gap-4 md:grid-cols-2">
                        <label class="block text-sm font-semibold text-[var(--fb-text-primary)]">
                            Name
                            <input name="name" type="text" value="{{ old('name') }}" required class="fb-input mt-2" />
                            @error('name') <span class="mt-1 block text-xs text-red-600">{{ $message }}</span> @enderror
                        </label>
                        <label class="block text-sm font-semibold text-[var(--fb-text-primary)]">
                            Email
                            <input name="email" type="email" value="{{ old('email') }}" required class="fb-input mt-2" />
                            @error('email') <span class="mt-1 block text-xs text-red-600">{{ $message }}</span> @enderror
                        </label>
                    </div>

                    <div class="grid gap-4 md:grid-cols-2">
                        <label class="block text-sm font-semibold text-[var(--fb-text-primary)]">
                            Company
                            <input name="company" type="text" value="{{ old('company') }}" class="fb-input mt-2" />
                        </label>
                        <label class="block text-sm font-semibold text-[var(--fb-text-primary)]">
                            Website
                            <input name="website" type="url" value="{{ old('website') }}" class="fb-input mt-2" placeholder="https://example.com" />
                        </label>
                    </div>

                    <div class="grid gap-4 md:grid-cols-3">
                        <label class="block text-sm font-semibold text-[var(--fb-text-primary)]">
                            Size
                            <select name="business_size" class="fb-input mt-2">
                                <option value="">Select one</option>
                                @foreach($businessSizes as $key => $label)
                                    <option value="{{ $key }}" @selected(old('business_size') === $key)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label class="block text-sm font-semibold text-[var(--fb-text-primary)]">
                            Timeline
                            <select name="timeline" class="fb-input mt-2">
                                <option value="">Select one</option>
                                @foreach($timelines as $key => $label)
                                    <option value="{{ $key }}" @selected(old('timeline') === $key)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label class="block text-sm font-semibold text-[var(--fb-text-primary)]">
                            Budget
                            <select name="budget_range" class="fb-input mt-2">
                                <option value="">Select one</option>
                                @foreach($budgetRanges as $key => $label)
                                    <option value="{{ $key }}" @selected(old('budget_range') === $key)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </label>
                    </div>

                    <label class="block text-sm font-semibold text-[var(--fb-text-primary)]">
                        What should be easier?
                        <textarea name="pain_point" rows="5" class="fb-input mt-2">{{ old('pain_point') }}</textarea>
                    </label>

                    <button type="submit" class="fb-btn fb-btn-primary">Send estimate</button>
                </form>
            </div>
        </section>
    </main>

    <script>
        (() => {
            const root = document.querySelector('[data-evergrove-calculator]');
            if (!root) return;

            const formPayload = document.querySelector('[data-calc-payload]');
            const resultEl = root.querySelector('[data-calc-result]');
            const noteEl = root.querySelector('[data-calc-note]');
            const toolKey = root.dataset.toolKey || 'project_estimate';

            const money = (value) => new Intl.NumberFormat('en-US', {
                style: 'currency',
                currency: 'USD',
                maximumFractionDigits: 0,
            }).format(Math.max(0, value || 0));

            const value = (key) => {
                const input = root.querySelector(`[data-calc-input="${key}"]`);
                return Number.parseFloat(input?.value || '0') || 0;
            };

            const calculate = () => {
                let low = 0;
                let high = 0;
                let note = '';
                const inputs = {};

                root.querySelectorAll('[data-calc-input]').forEach((input) => {
                    inputs[input.dataset.calcInput] = input.value;
                });

                if (toolKey === 'ai_roi') {
                    const gross = value('hours') * value('hourly') * 4.33 * (value('percent') / 100);
                    low = gross * 0.7 - value('cost');
                    high = gross * 1.15 - value('cost');
                    note = 'Monthly planning value after estimated tool cost.';
                } else if (toolKey === 'automation_savings') {
                    const hours = value('handoffs') * value('minutes') / 60;
                    const annual = hours * value('hourly') * 52 * (value('percent') / 100);
                    low = annual * 0.75;
                    high = annual * 1.2;
                    note = 'Annual labor value before implementation cost.';
                } else {
                    const base = 1800 + value('screens') * 350 + value('workflows') * 950 + value('integrations') * 1250;
                    low = base * value('complexity');
                    high = low * 1.65;
                    note = 'Build range for planning. Discovery narrows scope.';
                }

                resultEl.textContent = `${money(low)} - ${money(high)}`;
                noteEl.textContent = note;

                if (formPayload) {
                    formPayload.value = JSON.stringify({
                        tool: toolKey,
                        inputs,
                        low: Math.round(low),
                        high: Math.round(high),
                        note,
                    });
                }
            };

            root.querySelectorAll('[data-calc-input]').forEach((input) => {
                input.addEventListener('input', calculate);
                input.addEventListener('change', calculate);
            });
            calculate();
        })();
    </script>
</body>
</html>

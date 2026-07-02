@php
    $content = is_array($promo ?? null) ? $promo : [];
    $cta = is_array($content['ctas'] ?? null) ? $content['ctas'] : [];
    $brandAssets = (array) config('everbranch.brand_assets', []);
    $brandAssetVersion = (string) ($brandAssets['cache_tag'] ?? 'eb1');
    $brandLockupPath = (string) ($brandAssets['lockup'] ?? 'brand/everbranch-lockup.svg');
    $brandMarkPath = (string) ($brandAssets['mark'] ?? 'brand/everbranch-mark.svg');
    $productName = (string) config('everbranch.product_name', 'Everbranch');
    $headline = 'Less anxiety. Find peace. The one place to run your business.';
    $summary = 'Everbranch helps small businesses organize customers, tasks, messages, files, and workflows in one simple system, so nothing important gets lost in the noise so you can spend more time with your family.';
    $industryCards = [
        [
            'title' => 'Retail & product brands',
            'summary' => 'Wholesale requests, inventory questions, event prep, reorders, and follow-ups.',
            'outcome' => 'Keep buyer requests, reorders, and customer context attached.',
            'chips' => ['Wholesale', 'Reorders', 'Events'],
        ],
        [
            'title' => 'Electrical & plumbing',
            'summary' => 'Job notes, estimates, parts questions, scheduling notes, and crew next steps.',
            'outcome' => 'Give every service call one record the office and field can trust.',
            'chips' => ['Jobs', 'Parts', 'Crew'],
        ],
        [
            'title' => 'Construction & project work',
            'summary' => 'Approvals, materials, change requests, documents, subcontractor updates, and punch lists.',
            'outcome' => 'Put decisions and open items where everyone can find them.',
            'chips' => ['Approvals', 'Materials', 'Punch list'],
        ],
        [
            'title' => 'Service businesses',
            'summary' => 'Client records, appointments, recurring work, task handoffs, and follow-up reminders.',
            'outcome' => 'Make the next appointment, reminder, and handoff easy to see.',
            'chips' => ['Clients', 'Appointments', 'Reminders'],
        ],
    ];
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    @include('partials.head', [
        'title' => $productName.' | One Place to Run Your Business',
        'description' => $summary,
    ])
</head>
<body class="fb-public-body fb-public-body--splash" data-premium-motion="public">
    @include('platform.partials.premium-motion')

    <div class="fb-public-shell fb-public-shell--wide">
        <div class="fb-site-nav-wrap">
            <nav class="fb-site-nav fb-site-nav--premium" aria-label="Primary navigation">
                <a href="#splash" class="fb-site-brand fb-site-brand--lockup">
                    <img src="{{ asset($brandLockupPath) }}?v={{ $brandAssetVersion }}" alt="{{ $productName }}" />
                </a>
                <div class="fb-site-links" role="tablist" aria-label="Public sections">
                    <a id="tab-product" href="#everbranch-public" class="is-active" role="tab" aria-selected="true" aria-controls="panel-product" data-public-tab-trigger="product">Home</a>
                    <a id="tab-workflows" href="#everbranch-public" role="tab" aria-selected="false" aria-controls="panel-workflows" data-public-tab-trigger="workflows">See it work</a>
                    <a id="tab-customers" href="#everbranch-public" role="tab" aria-selected="false" aria-controls="panel-customers" data-public-tab-trigger="customers">Who it helps</a>
                    <a id="tab-contact" href="#everbranch-public" role="tab" aria-selected="false" aria-controls="panel-contact" data-public-tab-trigger="contact">Contact</a>
                </div>
                <div class="fb-hero-cta fb-hero-cta--nav">
                    <a href="{{ route('login') }}" class="fb-btn fb-btn-secondary">Login</a>
                    @if(is_array($cta['start_client'] ?? null) && filled($cta['start_client']['href'] ?? null))
                        <a href="{{ $cta['start_client']['href'] }}" class="fb-btn fb-btn-primary">{{ $cta['start_client']['label'] ?? 'Start as a client' }}</a>
                    @else
                        <a href="{{ route('platform.start') }}" class="fb-btn fb-btn-primary">Request access</a>
                    @endif
                </div>
            </nav>
        </div>

        <main id="everbranch-public" class="fb-public-main" tabindex="-1">
            <section class="fb-public-tabs" aria-label="Everbranch overview tabs" data-public-tabs data-reveal>
                <div class="fb-public-tabs__panels">
                    <article id="panel-product" class="fb-public-tab-panel is-active" role="tabpanel" aria-labelledby="tab-product" data-public-tab-panel="product">
                        <header id="splash" class="fb-splash fb-splash--intro-only" aria-label="Everbranch entry">
                            <div class="fb-splash__field" data-problem-garden aria-label="Scattered work examples">
                                <button type="button" class="fb-problem-chip fb-problem-chip--one">Invoice draft in email</button>
                                <button type="button" class="fb-problem-chip fb-problem-chip--two">Notebook: call back Friday</button>
                                <button type="button" class="fb-problem-chip fb-problem-chip--three">Spreadsheet row missing owner</button>
                                <button type="button" class="fb-problem-chip fb-problem-chip--four">Photo note from the field</button>
                                <button type="button" class="fb-problem-chip fb-problem-chip--five">Text thread: pricing?</button>
                                <button type="button" class="fb-problem-chip fb-problem-chip--six">Missed follow-up</button>
                                <button type="button" class="fb-problem-chip fb-problem-chip--seven">File in the wrong folder</button>
                                <button type="button" class="fb-problem-chip fb-problem-chip--eight">Customer asked twice</button>
                                <button type="button" class="fb-problem-chip fb-problem-chip--nine">Task has no owner</button>
                                <button type="button" class="fb-problem-chip fb-problem-chip--ten">Crew note in memory</button>
                                <button type="button" class="fb-problem-chip fb-problem-chip--eleven">Employees need address</button>
                                <button type="button" class="fb-problem-chip fb-problem-chip--twelve">Customer changed order</button>
                                <button type="button" class="fb-problem-chip fb-problem-chip--thirteen">How much should I order?</button>
                                <button type="button" class="fb-problem-chip fb-problem-chip--fourteen">Crew needs latest notes</button>
                                <button type="button" class="fb-problem-chip fb-problem-chip--fifteen">Which invoice got paid?</button>
                                <button type="button" class="fb-problem-chip fb-problem-chip--sixteen">Appointment moved again</button>
                            </div>

                            <div class="fb-splash__content" data-reveal>
                                <p class="fb-section-kicker">Small-business work, finally in one place</p>
                                <h1>{{ $headline }}</h1>
                                <p>{{ $summary }}</p>
                                <div class="fb-hero-cta fb-hero-cta--centered">
                                    <a href="{{ route('platform.start') }}" class="fb-btn fb-btn-primary">Request access</a>
                                    <a href="#everbranch-public" class="fb-btn fb-btn-secondary" data-public-tab-jump="workflows">See Everbranch in action</a>
                                    <a href="{{ route('login') }}" class="fb-btn fb-btn-secondary">Login</a>
                                </div>
                            </div>
                        </header>
                    </article>

                    <article id="panel-workflows" class="fb-public-tab-panel" role="tabpanel" aria-labelledby="tab-workflows" data-public-tab-panel="workflows" hidden>
                        <div class="fb-section fb-section--public fb-section--theater" aria-label="See Everbranch work">
                            <div class="fb-section-header fb-section-header--centered">
                                <p class="fb-section-kicker">See it work</p>
                                <h2>Watch scattered work become one clear next step.</h2>
                                <p>Choose a business moment. The problem stays visible first, then the workspace pulls the details together.</p>
                            </div>

                            <aside class="fb-product-demo fb-product-demo--wide" data-demo-mode="problem" data-premium-surface data-public-product-demo aria-label="Everbranch workflow example">
                                <div class="fb-product-demo__tabs" role="tablist" aria-label="Small business examples">
                                    <button type="button" class="is-active" role="tab" aria-selected="true" data-product-demo-scenario="retail" data-demo-customer="Pine &amp; Porch" data-demo-type="Retail buyer" data-demo-primary="Wholesale request" data-demo-note="Asked for event bestsellers, case pricing, and a fall reorder reminder." data-demo-task="Send line sheet and approve wholesale access" data-demo-owner="Sarah" data-demo-followup="Reorder follow-up ready for next Friday" data-demo-feed-one="Buyer details captured" data-demo-feed-two="Task assigned to Sarah" data-demo-feed-three="Follow-up reminder queued">
                                        <span>Retail</span>
                                        <strong>Wholesale request -> task -> reorder follow-up</strong>
                                    </button>
                                    <button type="button" role="tab" aria-selected="false" data-product-demo-scenario="trades" data-demo-customer="Monroe Ave Service Call" data-demo-type="Electrical &amp; plumbing" data-demo-primary="Job note" data-demo-note="Breaker panel photo came in with a parts question and a customer timing note." data-demo-task="Confirm parts and assign crew next step" data-demo-owner="Eli" data-demo-followup="Customer update due before 3 PM" data-demo-feed-one="Job note saved" data-demo-feed-two="Parts question added" data-demo-feed-three="Crew next step assigned">
                                        <span>Trades</span>
                                        <strong>Job note -> parts question -> crew next step</strong>
                                    </button>
                                    <button type="button" role="tab" aria-selected="false" data-product-demo-scenario="construction" data-demo-customer="Maple Street Remodel" data-demo-type="Construction project" data-demo-primary="Approval needed" data-demo-note="Client approved the fixture change, but material timing and punch-list items need one place." data-demo-task="Update materials and owner punch-list" data-demo-owner="Maya" data-demo-followup="Punch-list item ready for Friday review" data-demo-feed-one="Approval captured" data-demo-feed-two="Material note organized" data-demo-feed-three="Punch-list item assigned">
                                        <span>Projects</span>
                                        <strong>Approval -> material note -> punch-list item</strong>
                                    </button>
                                    <button type="button" role="tab" aria-selected="false" data-product-demo-scenario="service" data-demo-customer="Northline Maintenance" data-demo-type="Service business" data-demo-primary="Client record" data-demo-note="Recurring appointment, open question, and handoff note are tied to the same customer." data-demo-task="Schedule visit and send reminder" data-demo-owner="Jordan" data-demo-followup="Reminder ready for Monday morning" data-demo-feed-one="Client record updated" data-demo-feed-two="Appointment added" data-demo-feed-three="Reminder prepared">
                                        <span>Service</span>
                                        <strong>Client record -> appointment -> reminder</strong>
                                    </button>
                                </div>

                                <div class="fb-product-demo__frame" aria-live="polite">
                                    <div class="fb-product-demo__topbar">
                                        <img src="{{ asset($brandMarkPath) }}?v={{ $brandAssetVersion }}" alt="" />
                                        <strong>{{ $productName }} workspace</strong>
                                        <label><span>Search or ask what you want to do...</span><kbd>Cmd K</kbd></label>
                                        <em data-product-demo-field="type">Retail buyer</em>
                                    </div>
                                    <div class="fb-product-demo__mode" role="tablist" aria-label="Problem and solution view">
                                        <button type="button" class="is-active fb-product-demo__mode-problem" role="tab" aria-selected="true" data-product-demo-mode="problem">
                                            <span>Problem</span>
                                            The details are scattered.
                                        </button>
                                        <button type="button" class="fb-product-demo__mode-solution" role="tab" aria-selected="false" data-product-demo-mode="solution">
                                            <span>Solution</span>
                                            Everbranch gives them one home.
                                        </button>
                                    </div>
                                    <div class="fb-product-demo__mess" data-product-demo-problem aria-label="Scattered work example">
                                        <span class="fb-product-demo__mess-item fb-product-demo__mess-item--text">Text: “Can you resend pricing?”</span>
                                        <span class="fb-product-demo__mess-item fb-product-demo__mess-item--sheet">Spreadsheet row missing follow-up</span>
                                        <span class="fb-product-demo__mess-item fb-product-demo__mess-item--note">Notebook: call back Friday</span>
                                        <span class="fb-product-demo__mess-item fb-product-demo__mess-item--photo">Photo + parts question</span>
                                        <span class="fb-product-demo__mess-item fb-product-demo__mess-item--memory">Someone remembers the next step</span>
                                        <span class="fb-product-demo__mess-item fb-product-demo__mess-item--email">Email with invoice draft</span>
                                        <span class="fb-product-demo__mess-item fb-product-demo__mess-item--file">File named final-final.pdf</span>
                                        <span class="fb-product-demo__mess-item fb-product-demo__mess-item--owner">Task with no owner</span>
                                        <span class="fb-product-demo__mess-item fb-product-demo__mess-item--calendar">Appointment moved twice</span>
                                        <span class="fb-product-demo__mess-item fb-product-demo__mess-item--handoff">Crew handoff in memory</span>
                                    </div>
                                    <div class="fb-product-demo__workspace">
                                        <nav class="fb-product-demo__sidebar" aria-label="Example workspace sections">
                                            <span class="is-active">Home</span>
                                            <span>Customers</span>
                                            <span>Work</span>
                                            <span>Tasks</span>
                                            <span>Files</span>
                                            <span>Reports</span>
                                        </nav>
                                        <section class="fb-product-demo__record" aria-label="Example record">
                                            <div class="fb-product-demo__record-head">
                                                <div>
                                                    <p>Open record</p>
                                                    <h3 data-product-demo-field="customer">Pine &amp; Porch</h3>
                                                </div>
                                                <span data-product-demo-field="primary">Wholesale request</span>
                                            </div>
                                            <p data-product-demo-field="note">Asked for event bestsellers, case pricing, and a fall reorder reminder.</p>
                                            <div class="fb-product-demo__task-card">
                                                <span>Next step</span>
                                                <strong data-product-demo-field="task">Send line sheet and approve wholesale access</strong>
                                                <small>Assigned to <b data-product-demo-field="owner">Sarah</b></small>
                                            </div>
                                        </section>
                                        <section class="fb-product-demo__activity" aria-label="Example activity">
                                            <p>Activity</p>
                                            <ul>
                                                <li data-product-demo-feed="one">Buyer details captured</li>
                                                <li data-product-demo-feed="two">Task assigned to Sarah</li>
                                                <li data-product-demo-feed="three">Follow-up reminder queued</li>
                                            </ul>
                                        </section>
                                    </div>
                                    <div class="fb-product-demo__workflow" aria-label="Example workflow progress">
                                        <ol>
                                            <li class="is-active" data-product-demo-step="0">Detail captured</li>
                                            <li data-product-demo-step="1">Work organized</li>
                                            <li data-product-demo-step="2">Next step assigned</li>
                                            <li data-product-demo-step="3">Follow-up ready</li>
                                        </ol>
                                        <p data-product-demo-field="followup">Reorder follow-up ready for next Friday</p>
                                    </div>
                                </div>
                                <p class="fb-product-demo__motion-note">Motion-safe version: detail captured, work organized, next step assigned, follow-up ready.</p>
                            </aside>
                        </div>
                    </article>

                    <article id="panel-customers" class="fb-public-tab-panel" role="tabpanel" aria-labelledby="tab-customers" data-public-tab-panel="customers" hidden>
                        <div class="fb-section fb-section--public" aria-label="Who Everbranch helps">
                            <div class="fb-section-header fb-section-header--centered">
                                <p class="fb-section-kicker">Who it helps</p>
                                <h2>Built for the messy middle of small business.</h2>
                                <p>Everbranch does not replace the way your business works. It gives that work a home.</p>
                            </div>
                            <div class="fb-industry-switcher fb-industry-switcher--clean" aria-label="Everbranch industry examples">
                                @foreach($industryCards as $industry)
                                    <article class="fb-industry-card fb-industry-card--clean" data-premium-surface>
                                        <div>
                                            <p>{{ $industry['title'] }}</p>
                                            <h3>{{ $industry['summary'] }}</h3>
                                        </div>
                                        <p>{{ $industry['outcome'] }}</p>
                                        <ul>
                                            @foreach($industry['chips'] as $chip)
                                                <li>{{ $chip }}</li>
                                            @endforeach
                                        </ul>
                                    </article>
                                @endforeach
                            </div>
                        </div>
                    </article>

                    <article id="panel-contact" class="fb-public-tab-panel" role="tabpanel" aria-labelledby="tab-contact" data-public-tab-panel="contact" hidden>
                        <div class="fb-section fb-section--public" aria-label="Contact Everbranch">
                            <div class="fb-contact-panel" data-premium-surface>
                                <div>
                                    <p class="fb-section-kicker">Contact</p>
                                    <h2>Tell us what keeps getting lost.</h2>
                                    <p>Send the messy version. Everbranch will help you find the clean starting point.</p>
                                </div>
                                @include('platform.partials.contact-form', ['sourcePage' => 'everbranch_home_contact'])
                            </div>
                        </div>
                    </article>
                </div>
            </section>
        </main>
    </div>
</body>
</html>

@php
    $content = is_array($promo ?? null) ? $promo : [];
    $cta = is_array($content['ctas'] ?? null) ? $content['ctas'] : [];
    $brandAssets = (array) config('everbranch.brand_assets', []);
    $brandAssetVersion = (string) ($brandAssets['cache_tag'] ?? 'eb1');
    $brandLockupPath = (string) ($brandAssets['lockup'] ?? 'brand/everbranch-lockup.svg');
    $brandMarkPath = (string) ($brandAssets['mark'] ?? 'brand/everbranch-mark.svg');
    $productName = (string) config('everbranch.product_name', 'Everbranch');
    $headline = 'Less Problems. More peace. The one place to run your business.';
    $summary = 'Everbranch helps small businesses organize customers, tasks, messages, files, and workflows in one simple system, so nothing important gets lost in the noise so you can spend more time with your family.';
    $startClientCta = is_array($cta['start_client'] ?? null) && filled($cta['start_client']['href'] ?? null)
        ? [
            'href' => (string) $cta['start_client']['href'],
            'label' => (string) ($cta['start_client']['label'] ?? 'Start as a client'),
        ]
        : [
            'href' => route('platform.start'),
            'label' => 'Request access',
        ];
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
        <div class="fb-site-nav-wrap" data-public-mobile-nav>
            <nav class="fb-site-nav fb-site-nav--premium" aria-label="Primary navigation">
                <div class="fb-site-nav__bar">
                    <a href="#splash" class="fb-site-brand fb-site-brand--lockup">
                        <img src="{{ asset($brandLockupPath) }}?v={{ $brandAssetVersion }}" alt="{{ $productName }}" />
                    </a>
                    <button
                        type="button"
                        class="fb-site-nav__toggle"
                        data-public-mobile-nav-toggle
                        aria-expanded="false"
                        aria-controls="public-mobile-drawer"
                        aria-label="Open navigation menu"
                    >
                        <span></span>
                        <span></span>
                        <span></span>
                    </button>
                </div>
                <div class="fb-site-links" role="tablist" aria-label="Public sections">
                    <a id="tab-product" href="#everbranch-public" class="is-active" role="tab" aria-selected="true" aria-controls="panel-product" data-public-tab-trigger="product">Home</a>
                    <a id="tab-workflows" href="#everbranch-public" role="tab" aria-selected="false" aria-controls="panel-workflows" data-public-tab-trigger="workflows">See it work</a>
                    <a id="tab-customers" href="#everbranch-public" role="tab" aria-selected="false" aria-controls="panel-customers" data-public-tab-trigger="customers">Who it helps</a>
                    <a id="tab-contact" href="#everbranch-public" role="tab" aria-selected="false" aria-controls="panel-contact" data-public-tab-trigger="contact">Contact</a>
                </div>
                <div class="fb-hero-cta fb-hero-cta--nav">
                    <a href="{{ route('login') }}" class="fb-btn fb-btn-secondary">Login</a>
                    <a href="{{ $startClientCta['href'] }}" class="fb-btn fb-btn-primary">{{ $startClientCta['label'] }}</a>
                </div>
            </nav>
            <button type="button" class="fb-site-nav__backdrop" data-public-mobile-nav-backdrop hidden aria-hidden="true" tabindex="-1"></button>
            <div id="public-mobile-drawer" class="fb-site-nav__drawer" data-public-mobile-nav-drawer hidden>
                <div class="fb-site-nav__drawer-links" aria-label="Public sections">
                    <a href="#everbranch-public" class="is-active" data-public-tab-trigger="product" data-public-mobile-nav-link="product">Home</a>
                    <a href="#everbranch-public" data-public-tab-trigger="workflows" data-public-mobile-nav-link="workflows">See it work</a>
                    <a href="#everbranch-public" data-public-tab-trigger="customers" data-public-mobile-nav-link="customers">Who it helps</a>
                    <a href="#everbranch-public" data-public-tab-trigger="contact" data-public-mobile-nav-link="contact">Contact</a>
                </div>
                <div class="fb-site-nav__drawer-cta">
                    <a href="{{ route('login') }}" class="fb-btn fb-btn-secondary" data-public-mobile-nav-link="login">Login</a>
                    <a href="{{ $startClientCta['href'] }}" class="fb-btn fb-btn-primary" data-public-mobile-nav-link="start">{{ $startClientCta['label'] }}</a>
                </div>
            </div>
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
                                <h2>Too many apps for my small business.</h2>
                                <p>Everbranch gives you one place for your brain to focus.</p>
                            </div>

                            <aside class="fb-product-demo fb-product-demo--wide fb-product-demo--slideshow" data-demo-stage="choose" data-demo-mode="problem" data-premium-surface data-public-product-demo aria-label="Everbranch workflow example">
                                <div class="fb-product-demo__stage fb-product-demo__stage--choose" data-demo-stage-panel="choose">
                                    <div class="fb-product-demo__question">
                                        <p class="fb-section-kicker">Step 1</p>
                                        <h3>What is your business?</h3>
                                    </div>
                                    <div class="fb-product-demo__category-grid" aria-label="Choose a business type">
                                        <button type="button" class="is-active" data-product-demo-scenario="retail" data-demo-customer="Pine &amp; Porch" data-demo-type="Retail" data-demo-primary="Wholesale request" data-demo-note="Buyer, order, inventory, and follow-up in one view." data-demo-task="Send line sheet and approve wholesale access" data-demo-owner="Sarah" data-demo-stats="Customers::18::4 ready to reorder|Orders::$6.8k::this week|Follow-ups::6::2 due today" data-demo-links="Pine &amp; Porch buyer::Customer|Fall Harvest reorder::Task|Case pricing sheet::File" data-demo-jobs="Approve wholesale login::Due today|Prep market reorder set::Assigned to Omar|Friday reorder reminder::Scheduled" data-demo-mobile="Retail dashboard|6 follow-ups due|Wholesale request ready|Inventory question flagged">
                                            <span>Retail</span>
                                            <strong>Products, orders, customers, reorders.</strong>
                                        </button>
                                        <button type="button" data-product-demo-scenario="trades" data-demo-customer="Monroe Ave Service Call" data-demo-type="Trades" data-demo-primary="Job note" data-demo-note="Customer, crew, parts, and schedule together." data-demo-task="Confirm parts and assign crew next step" data-demo-owner="Eli" data-demo-stats="Jobs::14::3 parts questions|Visits::9::2 arrivals pending|On time::96%::last 7 days" data-demo-links="Monroe Ave customer::Service|40A breaker quote::Estimate|Panel photo set::Files" data-demo-jobs="Confirm breaker size::Due 11 AM|Dispatch crew next step::Assigned to Eli|Customer update::Before 3 PM" data-demo-mobile="Trades dashboard|9 visits today|Parts question active|Crew note saved">
                                            <span>Trades</span>
                                            <strong>Jobs, crews, estimates, parts.</strong>
                                        </button>
                                        <button type="button" data-product-demo-scenario="construction" data-demo-customer="Maple Street Remodel" data-demo-type="Projects" data-demo-primary="Approval needed" data-demo-note="Approvals, materials, and punch-list items connected." data-demo-task="Update materials and owner punch-list" data-demo-owner="Maya" data-demo-stats="Punch items::27::5 ownerless|Materials::5::2 vendor replies|Booked::$42k::3 changes" data-demo-links="Maple Street client::Project|Fixture approval::Change order|Tile schedule::Material" data-demo-jobs="Update fixture takeoff::Assigned to Maya|Confirm supplier lead time::Due Friday|Owner walkthrough recap::Queued" data-demo-mobile="Project dashboard|5 material holds|Approval saved|Punch list ready">
                                            <span>Projects</span>
                                            <strong>Approvals, materials, subs, files.</strong>
                                        </button>
                                        <button type="button" data-product-demo-scenario="service" data-demo-customer="Northline Maintenance" data-demo-type="Service" data-demo-primary="Client record" data-demo-note="Appointments, reminders, questions, and handoffs aligned." data-demo-task="Schedule visit and send reminder" data-demo-owner="Jordan" data-demo-stats="Accounts::32::9 visits today|Reminders::91%::hit rate|Questions::4::1 overdue" data-demo-links="Northline maintenance::Customer|Monday visit block::Schedule|Open question::Note" data-demo-jobs="Confirm gate access::Before 8 AM|Send visit reminder::Assigned to Jordan|Route recurring call::Queued" data-demo-mobile="Service dashboard|9 visits today|Reminder ready|Question routed">
                                            <span>Service</span>
                                            <strong>Clients, visits, reminders, handoffs.</strong>
                                        </button>
                                    </div>
                                </div>

                                <div class="fb-product-demo__stage fb-product-demo__stage--problem" data-demo-stage-panel="problem" hidden>
                                    <div class="fb-product-demo__problem-copy">
                                        <p data-product-demo-field="type">Retail</p>
                                        <h3>If you're like us this is what you are working in and paying for</h3>
                                    </div>
                                    <div class="fb-product-demo__app-screen" aria-label="Apps scattered across a small business">
                                        <div class="fb-product-demo__app-cloud" data-product-demo-app-cloud>
                                            <span class="fb-product-demo__app-bubble fb-product-demo__app-bubble--shopify"><b>S</b>Shopify</span>
                                            <span class="fb-product-demo__app-bubble fb-product-demo__app-bubble--instagram"><b>IG</b>Instagram</span>
                                            <span class="fb-product-demo__app-bubble fb-product-demo__app-bubble--gmail"><b>G</b>Gmail</span>
                                            <span class="fb-product-demo__app-bubble fb-product-demo__app-bubble--phone"><b>P</b>Phone</span>
                                            <span class="fb-product-demo__app-bubble fb-product-demo__app-bubble--quickbooks"><b>QB</b>QuickBooks</span>
                                            <span class="fb-product-demo__app-bubble fb-product-demo__app-bubble--square"><b>SQ</b>Square</span>
                                            <span class="fb-product-demo__app-bubble fb-product-demo__app-bubble--stripe"><b>ST</b>Stripe</span>
                                            <span class="fb-product-demo__app-bubble fb-product-demo__app-bubble--slack"><b>#</b>Slack</span>
                                            <span class="fb-product-demo__app-bubble fb-product-demo__app-bubble--calendar"><b>31</b>Calendar</span>
                                            <span class="fb-product-demo__app-bubble fb-product-demo__app-bubble--drive"><b>D</b>Drive</span>
                                            <span class="fb-product-demo__app-bubble fb-product-demo__app-bubble--excel"><b>X</b>Excel</span>
                                            <span class="fb-product-demo__app-bubble fb-product-demo__app-bubble--mailchimp"><b>MC</b>Mailchimp</span>
                                        </div>
                                        <div class="fb-product-demo__center-mark" aria-hidden="true">
                                            <img src="{{ asset($brandMarkPath) }}?v={{ $brandAssetVersion }}" alt="" />
                                        </div>
                                    </div>
                                    <div class="fb-product-demo__problem-actions">
                                        <button type="button" class="fb-product-demo__problem-button">THE PROBLEM</button>
                                        <button type="button" class="fb-btn fb-btn-primary" data-demo-start-solution>Let's put everything in one place.</button>
                                    </div>
                                </div>

                                <div class="fb-product-demo__stage fb-product-demo__stage--explainer" data-demo-stage-panel="explainer" hidden>
                                    <div class="fb-product-demo__slides" aria-live="polite">
                                        <article class="fb-product-demo__story-slide is-active" data-demo-story-slide="0">
                                            <div class="fb-product-demo__story-copy">
                                                <p class="fb-section-kicker">Slide 1 of 6</p>
                                                <h3>We know the ten-app headache.</h3>
                                                <p>We are small business owners too. Ten apps, ten billings, ten logins, and somehow the one thing you need is still hiding.</p>
                                            </div>
                                            <div class="fb-product-demo__story-picture fb-product-demo__story-picture--apps" aria-hidden="true">
                                                <span>Shopify</span>
                                                <span>Gmail</span>
                                                <span>QuickBooks</span>
                                                <span>Instagram</span>
                                                <span>Calendar</span>
                                                <span>Phone</span>
                                            </div>
                                        </article>
                                        <article class="fb-product-demo__story-slide" data-demo-story-slide="1" hidden>
                                            <div class="fb-product-demo__story-copy">
                                                <p class="fb-section-kicker">Slide 2 of 6</p>
                                                <h3>Business got complicated. We simplify it.</h3>
                                                <p>Not by forcing you into another generic tool. Everbranch custom makes the workspace around how your business actually runs.</p>
                                            </div>
                                            <div class="fb-product-demo__story-picture fb-product-demo__story-picture--build" aria-hidden="true">
                                                <span>Messy</span>
                                                <span>Custom fit</span>
                                                <span>Simple</span>
                                            </div>
                                        </article>
                                        <article class="fb-product-demo__story-slide" data-demo-story-slide="2" hidden>
                                            <div class="fb-product-demo__story-copy">
                                                <p class="fb-section-kicker">Slide 3 of 6</p>
                                                <h3>All your branches, one dashboard.</h3>
                                                <p>Invoicing, supplies, customers, employees, and messaging live together so your next move is easier to see.</p>
                                            </div>
                                            <div class="fb-product-demo__story-picture fb-product-demo__story-picture--branches" aria-hidden="true">
                                                <span>Invoicing</span>
                                                <span>Supplies</span>
                                                <span>Customers</span>
                                                <img src="{{ asset($brandMarkPath) }}?v={{ $brandAssetVersion }}" alt="" />
                                                <span>Employees</span>
                                                <span>Messaging</span>
                                                <span>Files</span>
                                            </div>
                                        </article>
                                        <article class="fb-product-demo__story-slide" data-demo-story-slide="3" hidden>
                                            <div class="fb-product-demo__story-copy">
                                                <p class="fb-section-kicker">Slide 4 of 6</p>
                                                <h3>Stop trying apps that do not fit.</h3>
                                                <p>Do not be another lost business owner hopping from subscription to subscription. Let us design the system around you.</p>
                                            </div>
                                            <div class="fb-product-demo__story-picture fb-product-demo__story-picture--fit" aria-hidden="true">
                                                <span>Generic app</span>
                                                <span>Wrong fit</span>
                                                <strong>Everbranch fit</strong>
                                            </div>
                                        </article>
                                        <article class="fb-product-demo__story-slide" data-demo-story-slide="4" hidden>
                                            <div class="fb-product-demo__story-copy">
                                                <p class="fb-section-kicker">Slide 5 of 6</p>
                                                <h3>Your online app and phone app stay together.</h3>
                                                <p>Use the dashboard at your desk, then keep the same customers, jobs, notes, and next steps in your pocket.</p>
                                            </div>
                                            <div class="fb-product-demo__story-picture fb-product-demo__story-picture--devices" aria-hidden="true">
                                                <span>Desktop</span>
                                                <span>Phone</span>
                                            </div>
                                        </article>
                                        <article class="fb-product-demo__story-slide" data-demo-story-slide="5" hidden>
                                            <div class="fb-product-demo__story-copy">
                                                <p class="fb-section-kicker">Slide 6 of 6</p>
                                                <h3>Pick what matters first.</h3>
                                                <p>Website, app, sales conversations, billing, customers, jobs, files, employees, and more can all become part of one Everbranch home.</p>
                                            </div>
                                            <div class="fb-product-demo__story-picture fb-product-demo__story-picture--modules" aria-hidden="true">
                                                <span>Website</span>
                                                <span>App</span>
                                                <span>Sales</span>
                                                <span>Billing</span>
                                                <span>Customers</span>
                                                <span>Jobs</span>
                                                <span>Files</span>
                                                <span>Team</span>
                                            </div>
                                        </article>
                                    </div>
                                    <div class="fb-product-demo__slide-controls">
                                        <button type="button" class="fb-btn fb-btn-secondary" data-demo-slide-prev>Back</button>
                                        <div class="fb-product-demo__slide-dots" aria-label="Explainer slide progress">
                                            <button type="button" class="is-active" aria-label="Open slide 1" data-demo-slide-dot="0"></button>
                                            <button type="button" aria-label="Open slide 2" data-demo-slide-dot="1"></button>
                                            <button type="button" aria-label="Open slide 3" data-demo-slide-dot="2"></button>
                                            <button type="button" aria-label="Open slide 4" data-demo-slide-dot="3"></button>
                                            <button type="button" aria-label="Open slide 5" data-demo-slide-dot="4"></button>
                                            <button type="button" aria-label="Open slide 6" data-demo-slide-dot="5"></button>
                                        </div>
                                        <button type="button" class="fb-btn fb-btn-primary" data-demo-slide-next>Next</button>
                                        <button type="button" class="fb-btn fb-btn-primary" data-demo-show-solution hidden>Show me Everbranch</button>
                                    </div>
                                </div>

                                <div class="fb-product-demo__stage fb-product-demo__stage--solution" data-demo-stage-panel="solution" hidden>
                                    <div class="fb-product-demo__solution-copy">
                                        <p class="fb-section-kicker">The solution</p>
                                        <h3>Everbranch gives you one place for your brain to focus</h3>
                                    </div>
                                    <div class="fb-product-demo__solution-grid" aria-live="polite">
                                        <div class="fb-product-demo__frame">
                                            <div class="fb-product-demo__topbar">
                                                <img src="{{ asset($brandMarkPath) }}?v={{ $brandAssetVersion }}" alt="" />
                                                <strong>{{ $productName }}</strong>
                                                <em data-product-demo-field="type">Retail</em>
                                            </div>
                                            <div class="fb-product-demo__workspace">
                                        <nav class="fb-product-demo__sidebar" aria-label="Example workspace sections">
                                                    <button type="button" class="is-active" data-product-demo-pane="home">Home</button>
                                                    <button type="button" data-product-demo-pane="customers">Customers</button>
                                                    <button type="button" data-product-demo-pane="work">Work</button>
                                                    <button type="button" data-product-demo-pane="tasks">Tasks</button>
                                                    <button type="button" data-product-demo-pane="files">Files</button>
                                        </nav>
                                        <section class="fb-product-demo__record" aria-label="Example record">
                                            <div class="fb-product-demo__record-head">
                                                <div>
                                                    <h3 data-product-demo-field="customer">Pine &amp; Porch</h3>
                                                </div>
                                                <span data-product-demo-field="primary">Wholesale request</span>
                                            </div>
                                            <div class="fb-product-demo__stats" aria-label="Example workspace metrics" data-product-demo-pane-panel="home customers reports">
                                                <button type="button" class="fb-product-demo__stat" data-product-demo-stat>
                                                    <small data-product-demo-stat-label>Active buyers</small>
                                                    <strong data-product-demo-stat-value>18</strong>
                                                    <span data-product-demo-stat-meta>4 ready to reorder</span>
                                                </button>
                                                <button type="button" class="fb-product-demo__stat" data-product-demo-stat>
                                                    <small data-product-demo-stat-label>Queued follow-ups</small>
                                                    <strong data-product-demo-stat-value>6</strong>
                                                    <span data-product-demo-stat-meta>2 due today</span>
                                                </button>
                                                <button type="button" class="fb-product-demo__stat" data-product-demo-stat>
                                                    <small data-product-demo-stat-label>This week revenue</small>
                                                    <strong data-product-demo-stat-value>$6.8k</strong>
                                                    <span data-product-demo-stat-meta>92% on time</span>
                                                </button>
                                            </div>
                                            <p data-product-demo-field="note" data-product-demo-pane-panel="home customers work">Buyer, order, inventory, and follow-up in one view.</p>
                                            <div class="fb-product-demo__record-grid" data-product-demo-pane-panel="home customers files tasks">
                                                <div class="fb-product-demo__task-card" data-product-demo-pane-panel="home tasks">
                                                    <span>Next step</span>
                                                    <strong data-product-demo-field="task">Send line sheet and approve wholesale access</strong>
                                                    <small>Assigned to <b data-product-demo-field="owner">Sarah</b></small>
                                                </div>
                                                <div class="fb-product-demo__related-card" data-product-demo-pane-panel="home customers files">
                                                    <p>Related links</p>
                                                    <div class="fb-product-demo__link-list">
                                                        <button type="button" class="fb-product-demo__mini-link" data-product-demo-link>
                                                            <strong data-product-demo-link-title>Pine &amp; Porch buyer</strong>
                                                            <span data-product-demo-link-meta>Customer record</span>
                                                        </button>
                                                        <button type="button" class="fb-product-demo__mini-link" data-product-demo-link>
                                                            <strong data-product-demo-link-title>Fall Harvest reorder</strong>
                                                            <span data-product-demo-link-meta>Follow-up task</span>
                                                        </button>
                                                        <button type="button" class="fb-product-demo__mini-link" data-product-demo-link>
                                                            <strong data-product-demo-link-title>Case pricing sheet</strong>
                                                            <span data-product-demo-link-meta>Shared file</span>
                                                        </button>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="fb-product-demo__jobs-card" data-product-demo-pane-panel="home work tasks">
                                                <p>Live work</p>
                                                <div class="fb-product-demo__job-list">
                                                    <button type="button" class="fb-product-demo__job-item" data-product-demo-job>
                                                        <strong data-product-demo-job-title>Approve wholesale login</strong>
                                                        <span data-product-demo-job-meta>Due today</span>
                                                    </button>
                                                    <button type="button" class="fb-product-demo__job-item" data-product-demo-job>
                                                        <strong data-product-demo-job-title>Prep market reorder set</strong>
                                                        <span data-product-demo-job-meta>Assigned to Omar</span>
                                                    </button>
                                                    <button type="button" class="fb-product-demo__job-item" data-product-demo-job>
                                                        <strong data-product-demo-job-title>Friday reorder reminder</strong>
                                                        <span data-product-demo-job-meta>Scheduled</span>
                                                    </button>
                                                </div>
                                            </div>
                                        </section>
                                    </div>
                                        </div>
                                        <aside class="fb-product-demo__phone" aria-label="Everbranch mobile app preview">
                                            <div class="fb-product-demo__phone-top">
                                                <span></span>
                                            </div>
                                            <div class="fb-product-demo__phone-app">
                                                <img src="{{ asset($brandMarkPath) }}?v={{ $brandAssetVersion }}" alt="" />
                                                <strong>{{ $productName }}</strong>
                                            </div>
                                            <div class="fb-product-demo__phone-screen">
                                                <p data-product-demo-mobile-line="0">Retail dashboard</p>
                                                <h4 data-product-demo-mobile-line="1">6 follow-ups due</h4>
                                                <ul>
                                                    <li data-product-demo-mobile-line="2">Wholesale request ready</li>
                                                    <li data-product-demo-mobile-line="3">Inventory question flagged</li>
                                                </ul>
                                            </div>
                                        </aside>
                                    </div>
                                    <div class="fb-product-demo__solution-actions">
                                        <button type="button" class="fb-btn fb-btn-secondary" data-demo-back-categories>Choose another business</button>
                                        <a class="fb-btn fb-btn-primary" href="{{ route('login') }}">Lets get started</a>
                                    </div>
                                </div>
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

        <div class="fb-bud" data-public-bud data-bud-endpoint="{{ route('platform.bud.conversations') }}">
            <button type="button" class="fb-bud__toggle" data-bud-toggle aria-expanded="false" aria-controls="fb-bud-panel">
                <span class="fb-bud__toggle-dot"></span>
                <span>Chat with Bud</span>
            </button>

            <section id="fb-bud-panel" class="fb-bud__panel" data-bud-panel hidden aria-label="Chat with Bud">
                <div class="fb-bud__head">
                    <div>
                        <p class="fb-bud__eyebrow">Bud</p>
                        <h2>Ask Bud anything about Everbranch.</h2>
                    </div>
                    <button type="button" class="fb-bud__close" data-bud-close aria-label="Close Bud">Close</button>
                </div>

                <div class="fb-bud__messages" data-bud-messages>
                    <article class="fb-bud__message fb-bud__message--bud">
                        <p>I’m Bud. I can explain what Everbranch is, help you think through business workflows, and I’ll be honest when I don’t know enough to answer precisely.</p>
                    </article>
                </div>

                <div class="fb-bud__prompts">
                    <button type="button" class="fb-bud__prompt" data-bud-prompt="What is Everbranch?">What is Everbranch?</button>
                    <button type="button" class="fb-bud__prompt" data-bud-prompt="Who is Everbranch best for?">Who is it best for?</button>
                    <button type="button" class="fb-bud__prompt" data-bud-prompt="Could Everbranch help my business?">Could it help my business?</button>
                    <button type="button" class="fb-bud__prompt" data-bud-prompt="What would Bud help me organize first?">What would Bud organize first?</button>
                </div>

                <form class="fb-bud__composer" data-bud-form>
                    <label class="sr-only" for="bud-question">Ask Bud</label>
                    <input id="bud-question" type="text" class="fb-bud__input" data-bud-input placeholder="Ask Bud about customers, jobs, tasks, follow-ups, or your business..." />
                    <button type="submit" class="fb-bud__send">Send</button>
                </form>
            </section>
        </div>
    </div>
</body>
</html>

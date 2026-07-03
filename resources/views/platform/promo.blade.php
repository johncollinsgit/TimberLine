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
                                <h2>Watch scattered work become one clear next step.</h2>
                                <p>Choose a business moment. The problem stays visible first, then the workspace pulls the details together.</p>
                            </div>

                            <aside class="fb-product-demo fb-product-demo--wide" data-demo-mode="problem" data-premium-surface data-public-product-demo aria-label="Everbranch workflow example">
                                <div class="fb-product-demo__tabs" role="tablist" aria-label="Small business examples">
                                    <button type="button" class="is-active" role="tab" aria-selected="true" data-product-demo-scenario="retail" data-demo-customer="Pine &amp; Porch" data-demo-type="Retail buyer" data-demo-primary="Wholesale request" data-demo-note="Asked for event bestsellers, case pricing, and a fall reorder reminder." data-demo-task="Send line sheet and approve wholesale access" data-demo-owner="Sarah" data-demo-followup="Line sheet sent, wholesale access approved, and reorder follow-up ready for next Friday." data-demo-feed-one="Buyer details captured from text and email" data-demo-feed-two="Wholesale task assigned to Sarah" data-demo-feed-three="Fall reorder reminder queued" data-demo-step-one="Buyer captured" data-demo-step-two="Line sheet organized" data-demo-step-three="Task assigned" data-demo-step-four="Reorder ready" data-demo-problems="Text: Can you resend pricing?|Wholesale request buried in email|Inventory row missing owner|Event photo note from buyer|Notebook: call back Friday|Old line sheet in downloads|Customer asked twice|No one owns reorder|Follow-up date in memory|Case pricing question" data-demo-stats="Active buyers::18::4 ready to reorder|Queued follow-ups::6::2 due today|This week revenue::$6.8k::92% on time" data-demo-links="Pine &amp; Porch buyer::Customer record|Fall Harvest reorder::Follow-up task|Case pricing sheet::Shared file" data-demo-jobs="Approve wholesale login::Due today|Prep market reorder set::Assigned to Omar|Friday reorder reminder::Scheduled" data-demo-team="Sarah::Sales ops::3 open items|Omar::Markets::2 follow-ups|Nina::Inventory::Count requested" data-demo-chart="Mon::4|Tue::6|Wed::5|Thu::8">
                                        <span>Retail</span>
                                    </button>
                                    <button type="button" role="tab" aria-selected="false" data-product-demo-scenario="trades" data-demo-customer="Monroe Ave Service Call" data-demo-type="Electrical &amp; plumbing" data-demo-primary="Job note" data-demo-note="Breaker panel photo came in with a parts question and a customer timing note." data-demo-task="Confirm parts and assign crew next step" data-demo-owner="Eli" data-demo-followup="Panel photo, parts question, customer timing, and crew next step are tied to the service call." data-demo-feed-one="Panel photo saved to the job" data-demo-feed-two="Parts question routed to Eli" data-demo-feed-three="Customer update due before 3 PM" data-demo-step-one="Job note saved" data-demo-step-two="Parts checked" data-demo-step-three="Crew assigned" data-demo-step-four="Customer updated" data-demo-problems="Employee needs address|Panel photo in text thread|Parts question in the field|Customer changed appointment|Notebook says call before 3|Old estimate in folder|Crew asked twice|No owner for permit note|Breaker size in memory|How much wire should I order?" data-demo-stats="Open service calls::14::3 parts questions|Visits today::9::2 arrivals pending|On-time arrival::96%::Last 7 days" data-demo-links="Monroe Ave customer::Service record|40A breaker quote::Estimate|Panel photo set::Job files" data-demo-jobs="Confirm breaker size::Due 11 AM|Dispatch crew next step::Assigned to Eli|Customer update::Before 3 PM" data-demo-team="Eli::Lead tech::2 calls active|Rosa::Office::Waiting on part cost|Jules::Dispatch::Crew window held" data-demo-chart="Mon::5|Tue::7|Wed::6|Thu::4">
                                        <span>Trades</span>
                                    </button>
                                    <button type="button" role="tab" aria-selected="false" data-product-demo-scenario="construction" data-demo-customer="Maple Street Remodel" data-demo-type="Construction project" data-demo-primary="Approval needed" data-demo-note="Client approved the fixture change, but material timing and punch-list items need one place." data-demo-task="Update materials and owner punch-list" data-demo-owner="Maya" data-demo-followup="Fixture approval, material timing, and punch-list review are visible for Friday." data-demo-feed-one="Fixture approval captured" data-demo-feed-two="Material timing organized" data-demo-feed-three="Punch-list item assigned to Maya" data-demo-step-one="Approval saved" data-demo-step-two="Materials updated" data-demo-step-three="Owner assigned" data-demo-step-four="Review ready" data-demo-problems="Customer changed order|Fixture approval in email|Material count in spreadsheet|Subcontractor texted delay|Photo note from walkthrough|Permit file in wrong folder|Punch item has no owner|Friday review in memory|Which version is final?|How much tile should I order?" data-demo-stats="Active punch items::27::5 ownerless|Material holds::5::2 vendor replies pending|This week booked::$42k::3 change orders" data-demo-links="Maple Street client::Project record|Fixture approval::Change order|Tile schedule::Material note" data-demo-jobs="Update fixture takeoff::Assigned to Maya|Confirm supplier lead time::Due Friday|Owner walkthrough recap::Queued" data-demo-team="Maya::Project manager::7 open items|Luis::Field lead::2 site notes|Ari::Procurement::Awaiting vendor reply" data-demo-chart="Mon::3|Tue::5|Wed::7|Thu::6">
                                        <span>Projects</span>
                                    </button>
                                    <button type="button" role="tab" aria-selected="false" data-product-demo-scenario="service" data-demo-customer="Northline Maintenance" data-demo-type="Service business" data-demo-primary="Client record" data-demo-note="Recurring appointment, open question, and handoff note are tied to the same customer." data-demo-task="Schedule visit and send reminder" data-demo-owner="Jordan" data-demo-followup="Recurring visit, open question, and reminder are ready for Monday morning." data-demo-feed-one="Client record updated" data-demo-feed-two="Appointment added to schedule" data-demo-feed-three="Reminder prepared for Jordan" data-demo-step-one="Client found" data-demo-step-two="Visit scheduled" data-demo-step-three="Question routed" data-demo-step-four="Reminder ready" data-demo-problems="Appointment moved again|Customer asked twice|Open question in voicemail|Recurring visit not scheduled|Handoff note in memory|Invoice draft in email|File in the wrong folder|Task has no owner|Text thread: pricing?|Missing phone number" data-demo-stats="Active accounts::32::9 visits today|Reminder hit rate::91%::Last 30 days|Open questions::4::1 overdue reply" data-demo-links="Northline maintenance::Customer record|Monday visit block::Schedule|Open question::Handoff note" data-demo-jobs="Confirm gate access::Before 8 AM|Send visit reminder::Assigned to Jordan|Route recurring call::Queued" data-demo-team="Jordan::Account lead::4 follow-ups|Tess::Scheduler::2 visits moved|Micah::Field tech::Route ready" data-demo-chart="Mon::2|Tue::4|Wed::6|Thu::5">
                                        <span>Service</span>
                                    </button>
                                </div>

                                <div class="fb-product-demo__frame" aria-live="polite">
                                    <div class="fb-product-demo__topbar">
                                        <img src="{{ asset($brandMarkPath) }}?v={{ $brandAssetVersion }}" alt="" />
                                        <strong>{{ $productName }} workspace</strong>
                                        <button type="button" class="fb-product-demo__search-trigger" data-product-demo-bud-search aria-label="Ask Bud what you want to do">
                                            <span>Search or ask what you want to do...</span><kbd>Cmd K</kbd>
                                        </button>
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
                                        <span class="fb-product-demo__mess-item fb-product-demo__mess-item--text" data-product-demo-problem-item="0">Text: Can you resend pricing?</span>
                                        <span class="fb-product-demo__mess-item fb-product-demo__mess-item--sheet" data-product-demo-problem-item="1">Wholesale request buried in email</span>
                                        <span class="fb-product-demo__mess-item fb-product-demo__mess-item--note" data-product-demo-problem-item="2">Inventory row missing owner</span>
                                        <span class="fb-product-demo__mess-item fb-product-demo__mess-item--photo" data-product-demo-problem-item="3">Event photo note from buyer</span>
                                        <span class="fb-product-demo__mess-item fb-product-demo__mess-item--memory" data-product-demo-problem-item="4">Notebook: call back Friday</span>
                                        <span class="fb-product-demo__mess-item fb-product-demo__mess-item--email" data-product-demo-problem-item="5">Old line sheet in downloads</span>
                                        <span class="fb-product-demo__mess-item fb-product-demo__mess-item--file" data-product-demo-problem-item="6">Customer asked twice</span>
                                        <span class="fb-product-demo__mess-item fb-product-demo__mess-item--owner" data-product-demo-problem-item="7">No one owns reorder</span>
                                        <span class="fb-product-demo__mess-item fb-product-demo__mess-item--calendar" data-product-demo-problem-item="8">Follow-up date in memory</span>
                                        <span class="fb-product-demo__mess-item fb-product-demo__mess-item--handoff" data-product-demo-problem-item="9">Case pricing question</span>
                                    </div>
                                    <div class="fb-product-demo__workspace">
                                        <nav class="fb-product-demo__sidebar" aria-label="Example workspace sections">
                                            <button type="button" class="is-active" data-product-demo-pane="home">Home</button>
                                            <button type="button" data-product-demo-pane="customers">Customers</button>
                                            <button type="button" data-product-demo-pane="work">Work</button>
                                            <button type="button" data-product-demo-pane="tasks">Tasks</button>
                                            <button type="button" data-product-demo-pane="files">Files</button>
                                            <button type="button" data-product-demo-pane="reports">Reports</button>
                                        </nav>
                                        <section class="fb-product-demo__record" aria-label="Example record">
                                            <div class="fb-product-demo__record-head">
                                                <div>
                                                    <p>Open record</p>
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
                                            <p data-product-demo-field="note" data-product-demo-pane-panel="home customers work">Asked for event bestsellers, case pricing, and a fall reorder reminder.</p>
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
                                        <section class="fb-product-demo__activity" aria-label="Example activity">
                                            <p>Activity</p>
                                            <ul data-product-demo-pane-panel="home work">
                                                <li><button type="button" class="fb-product-demo__feed-button" data-product-demo-feed="one">Buyer details captured</button></li>
                                                <li><button type="button" class="fb-product-demo__feed-button" data-product-demo-feed="two">Task assigned to Sarah</button></li>
                                                <li><button type="button" class="fb-product-demo__feed-button" data-product-demo-feed="three">Follow-up reminder queued</button></li>
                                            </ul>
                                            <div class="fb-product-demo__team-card" data-product-demo-pane-panel="home work tasks">
                                                <p>Team</p>
                                                <div class="fb-product-demo__team-list">
                                                    <button type="button" class="fb-product-demo__team-item" data-product-demo-team>
                                                        <span class="fb-product-demo__avatar" data-product-demo-team-avatar>SA</span>
                                                        <span class="fb-product-demo__team-copy">
                                                            <strong data-product-demo-team-name>Sarah</strong>
                                                            <small data-product-demo-team-role>Sales ops</small>
                                                        </span>
                                                        <em data-product-demo-team-status>3 open items</em>
                                                    </button>
                                                    <button type="button" class="fb-product-demo__team-item" data-product-demo-team>
                                                        <span class="fb-product-demo__avatar" data-product-demo-team-avatar>OM</span>
                                                        <span class="fb-product-demo__team-copy">
                                                            <strong data-product-demo-team-name>Omar</strong>
                                                            <small data-product-demo-team-role>Markets</small>
                                                        </span>
                                                        <em data-product-demo-team-status>2 follow-ups</em>
                                                    </button>
                                                    <button type="button" class="fb-product-demo__team-item" data-product-demo-team>
                                                        <span class="fb-product-demo__avatar" data-product-demo-team-avatar>NI</span>
                                                        <span class="fb-product-demo__team-copy">
                                                            <strong data-product-demo-team-name>Nina</strong>
                                                            <small data-product-demo-team-role>Inventory</small>
                                                        </span>
                                                        <em data-product-demo-team-status>Count requested</em>
                                                    </button>
                                                </div>
                                            </div>
                                            <div class="fb-product-demo__chart-card" data-product-demo-pane-panel="home work reports">
                                                <p>Workload trend</p>
                                                <div class="fb-product-demo__chart">
                                                    <button type="button" class="fb-product-demo__chart-bar" data-product-demo-chart>
                                                        <span class="fb-product-demo__chart-track"><span class="fb-product-demo__chart-fill" data-product-demo-chart-fill></span></span>
                                                        <strong data-product-demo-chart-value>4</strong>
                                                        <span data-product-demo-chart-label>Mon</span>
                                                    </button>
                                                    <button type="button" class="fb-product-demo__chart-bar" data-product-demo-chart>
                                                        <span class="fb-product-demo__chart-track"><span class="fb-product-demo__chart-fill" data-product-demo-chart-fill></span></span>
                                                        <strong data-product-demo-chart-value>6</strong>
                                                        <span data-product-demo-chart-label>Tue</span>
                                                    </button>
                                                    <button type="button" class="fb-product-demo__chart-bar" data-product-demo-chart>
                                                        <span class="fb-product-demo__chart-track"><span class="fb-product-demo__chart-fill" data-product-demo-chart-fill></span></span>
                                                        <strong data-product-demo-chart-value>5</strong>
                                                        <span data-product-demo-chart-label>Wed</span>
                                                    </button>
                                                    <button type="button" class="fb-product-demo__chart-bar" data-product-demo-chart>
                                                        <span class="fb-product-demo__chart-track"><span class="fb-product-demo__chart-fill" data-product-demo-chart-fill></span></span>
                                                        <strong data-product-demo-chart-value>8</strong>
                                                        <span data-product-demo-chart-label>Thu</span>
                                                    </button>
                                                </div>
                                            </div>
                                        </section>
                                    </div>
                                    <div class="fb-product-demo__workflow" aria-label="Example workflow progress" data-product-demo-pane-panel="home work tasks">
                                        <ol>
                                            <li class="is-active" data-product-demo-step="0" data-product-demo-step-label="one">Buyer captured</li>
                                            <li data-product-demo-step="1" data-product-demo-step-label="two">Line sheet organized</li>
                                            <li data-product-demo-step="2" data-product-demo-step-label="three">Task assigned</li>
                                            <li data-product-demo-step="3" data-product-demo-step-label="four">Reorder ready</li>
                                        </ol>
                                        <p data-product-demo-field="followup">Reorder follow-up ready for next Friday</p>
                                    </div>
                                </div>
                                <p class="fb-product-demo__motion-note">Motion-safe version: detail captured, work organized, next step assigned, follow-up ready.</p>
                                <span class="sr-only">Work organized</span>
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

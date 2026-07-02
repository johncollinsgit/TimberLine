@php
    $content = is_array($promo ?? null) ? $promo : [];
    $cta = is_array($content['ctas'] ?? null) ? $content['ctas'] : [];
    $brandAssets = (array) config('everbranch.brand_assets', []);
    $brandAssetVersion = (string) ($brandAssets['cache_tag'] ?? 'eb1');
    $brandLockupPath = (string) ($brandAssets['lockup'] ?? 'brand/everbranch-lockup.svg');
    $brandMarkPath = (string) ($brandAssets['mark'] ?? 'brand/everbranch-mark.svg');
    $productName = (string) config('everbranch.product_name', 'Everbranch');
    $companyName = (string) config('everbranch.company_name', 'Evergrove');
    $headline = 'Give your company an app your team will actually use.';
    $summary = 'Everbranch gives customers, jobs, notes, tasks, follow-ups, and team handoffs one simple home.';
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    @include('partials.head', [
        'title' => $productName.' | One home for small-business work',
        'description' => $summary,
    ])
</head>
<body class="fb-public-body fb-public-body--showcase" data-premium-motion="public">
    @include('platform.partials.premium-motion')

    <div class="fb-public-shell fb-public-shell--wide">
        <div class="fb-site-nav-wrap">
            <nav class="fb-site-nav fb-site-nav--premium" aria-label="Primary navigation">
                <a href="#splash" class="fb-site-brand fb-site-brand--lockup">
                    <img src="{{ asset($brandLockupPath) }}?v={{ $brandAssetVersion }}" alt="{{ $productName }}" />
                </a>
                <div class="fb-site-links" role="tablist" aria-label="Everbranch story tabs">
                    <a id="tab-problem" href="#everbranch-public" role="tab" aria-selected="false" aria-controls="panel-problem" data-public-tab-trigger="problem">The Problem</a>
                    <a id="tab-plan" href="#everbranch-public" role="tab" aria-selected="false" aria-controls="panel-plan" data-public-tab-trigger="plan">The Plan</a>
                    <a id="tab-demo" href="#everbranch-public" class="is-active" role="tab" aria-selected="true" aria-controls="panel-demo" data-public-tab-trigger="demo">See It Work</a>
                    <a id="tab-industries" href="#everbranch-public" role="tab" aria-selected="false" aria-controls="panel-industries" data-public-tab-trigger="industries">Who It Helps</a>
                    <a id="tab-contact" href="#everbranch-public" role="tab" aria-selected="false" aria-controls="panel-contact" data-public-tab-trigger="contact">Contact</a>
                </div>
                <div class="fb-hero-cta fb-hero-cta--nav">
                    <a href="{{ route('login') }}" class="fb-btn fb-btn-secondary">Login</a>
                    @if(is_array($cta['start_client'] ?? null) && filled($cta['start_client']['href'] ?? null))
                        <a href="{{ $cta['start_client']['href'] }}" class="fb-btn fb-btn-primary">{{ $cta['start_client']['label'] ?? 'Request access' }}</a>
                    @else
                        <a href="{{ route('platform.start') }}" class="fb-btn fb-btn-primary">Request access</a>
                    @endif
                </div>
            </nav>
        </div>

        <main id="everbranch-public" class="fb-public-main" tabindex="-1">
            <header id="splash" class="fb-showcase-hero" aria-label="Everbranch introduction">
                <div class="fb-showcase-hero__mark">
                    <img src="{{ asset($brandMarkPath) }}?v={{ $brandAssetVersion }}" alt="" />
                    <span>Everbranch</span>
                </div>
                <p class="fb-section-kicker">Small-business work, finally in one place</p>
                <h1>{{ $headline }}</h1>
                <p>{{ $summary }}</p>
                <div class="fb-hero-cta fb-hero-cta--center">
                    <a href="{{ route('platform.start') }}" class="fb-btn fb-btn-primary">Request access</a>
                    <a href="#product-theater" class="fb-btn fb-btn-secondary" data-product-demo-jump>See Everbranch in action</a>
                    <a href="{{ route('login') }}" class="fb-btn fb-btn-secondary">Login</a>
                </div>
            </header>

            <section class="fb-public-tabs fb-public-tabs--showcase" aria-label="Everbranch overview tabs" data-public-tabs data-reveal>
                <div class="fb-public-tabs__panels">
                    <article id="panel-problem" class="fb-public-tab-panel" role="tabpanel" aria-labelledby="tab-problem" data-public-tab-panel="problem" hidden>
                        <section class="fb-story-panel fb-story-panel--problem" aria-label="The problem">
                            <div>
                                <p class="fb-section-kicker">The Problem</p>
                                <h2>Different businesses. Same mess.</h2>
                                <p>Texts, notebooks, spreadsheets, photos, invoices, and memory all hold tiny pieces of the truth. That is how follow-ups get missed and owners become the search bar.</p>
                            </div>
                            <div class="fb-scattered-board" aria-label="Scattered work examples">
                                <span class="fb-paper-note fb-paper-note--amber">"Can you resend pricing?"</span>
                                <span class="fb-paper-note fb-paper-note--blue">Parts photo from the van</span>
                                <span class="fb-paper-note fb-paper-note--coral">Customer waiting on estimate</span>
                                <span class="fb-paper-note fb-paper-note--cream">Spreadsheet: no owner</span>
                                <span class="fb-paper-note fb-paper-note--moss">Remember to call Friday</span>
                            </div>
                        </section>
                    </article>

                    <article id="panel-plan" class="fb-public-tab-panel" role="tabpanel" aria-labelledby="tab-plan" data-public-tab-panel="plan" hidden>
                        <section class="fb-story-panel fb-story-panel--plan" aria-label="The plan">
                            <div>
                                <p class="fb-section-kicker">The Plan</p>
                                <h2>Give the work a home, then move it forward.</h2>
                                <p>Everbranch keeps the path simple: capture the detail, organize the record, assign the next step, and know what needs attention.</p>
                            </div>
                            <ol class="fb-plan-cards">
                                <li><span>1</span><strong>Capture the detail</strong><p>Customer, job, photo, note, request, or reminder.</p></li>
                                <li><span>2</span><strong>Organize the work</strong><p>Put it on the right customer, job, invoice, or task.</p></li>
                                <li><span>3</span><strong>Assign the next move</strong><p>Make the owner, date, and outcome visible.</p></li>
                            </ol>
                        </section>
                    </article>

                    <article id="panel-demo" class="fb-public-tab-panel is-active" role="tabpanel" aria-labelledby="tab-demo" data-public-tab-panel="demo">
                        <section id="product-theater" class="fb-product-theater" data-public-product-demo data-demo-mode="problem" data-active-step="0" aria-label="Interactive Everbranch product example">
                            <div class="fb-theater-header">
                                <p class="fb-section-kicker">See It Work</p>
                                <h2>Watch scattered work become a clear next step.</h2>
                                <p>Click a mode below. This is a public example workspace, not live customer data.</p>
                            </div>

                            <div class="fb-theater-modes" role="tablist" aria-label="Everbranch example modes">
                                <button
                                    type="button"
                                    class="is-active"
                                    role="tab"
                                    aria-selected="true"
                                    data-product-demo-scenario="customer"
                                    data-demo-title="Add a customer"
                                    data-demo-label="Customer record"
                                    data-demo-customer="Pine &amp; Porch"
                                    data-demo-context="Retail buyer"
                                    data-demo-problem="Wholesale request lives in email, event notes, and someone's memory."
                                    data-demo-solution="One customer record holds the request, note, owner, and follow-up."
                                    data-demo-primary="Wholesale request"
                                    data-demo-note="Asked for event bestsellers, case pricing, and a fall reorder reminder."
                                    data-demo-task="Send line sheet and approve wholesale access"
                                    data-demo-owner="Sarah"
                                    data-demo-money="$1,840 potential reorder"
                                    data-demo-profit="Next Friday follow-up ready"
                                    data-demo-feed-one="Buyer details captured"
                                    data-demo-feed-two="Task assigned to Sarah"
                                    data-demo-feed-three="Follow-up reminder queued"
                                >
                                    <span>Add a customer</span>
                                    <strong>Request -> record -> follow-up</strong>
                                </button>
                                <button
                                    type="button"
                                    role="tab"
                                    aria-selected="false"
                                    data-product-demo-scenario="team"
                                    data-demo-title="Assign your team"
                                    data-demo-label="Service job"
                                    data-demo-customer="Monroe Ave Service Call"
                                    data-demo-context="Electrical &amp; plumbing"
                                    data-demo-problem="The photo, parts question, customer timing, and crew note are in four places."
                                    data-demo-solution="The job card shows the note, parts question, owner, and next field step."
                                    data-demo-primary="Panel inspection"
                                    data-demo-note="Breaker panel photo came in with a parts question and a customer timing note."
                                    data-demo-task="Confirm parts and assign crew next step"
                                    data-demo-owner="Eli"
                                    data-demo-money="2 open parts questions"
                                    data-demo-profit="Customer update due before 3 PM"
                                    data-demo-feed-one="Job note saved"
                                    data-demo-feed-two="Parts question added"
                                    data-demo-feed-three="Crew next step assigned"
                                >
                                    <span>Assign your team</span>
                                    <strong>Job note -> owner -> next step</strong>
                                </button>
                                <button
                                    type="button"
                                    role="tab"
                                    aria-selected="false"
                                    data-product-demo-scenario="invoice"
                                    data-demo-title="Create an invoice"
                                    data-demo-label="Project invoice"
                                    data-demo-customer="Maple Street Remodel"
                                    data-demo-context="Construction project"
                                    data-demo-problem="Approved change, material note, and invoice line are disconnected."
                                    data-demo-solution="The project keeps approval context next to the invoice-ready work."
                                    data-demo-primary="Change request"
                                    data-demo-note="Client approved fixture change; material timing and punch-list items need a home."
                                    data-demo-task="Prepare example invoice and update materials"
                                    data-demo-owner="Maya"
                                    data-demo-money="$2,450 invoice draft"
                                    data-demo-profit="Punch-list review ready for Friday"
                                    data-demo-feed-one="Approval captured"
                                    data-demo-feed-two="Material note organized"
                                    data-demo-feed-three="Invoice draft queued"
                                >
                                    <span>Create an invoice</span>
                                    <strong>Approval -> work -> invoice</strong>
                                </button>
                                <button
                                    type="button"
                                    role="tab"
                                    aria-selected="false"
                                    data-product-demo-scenario="profit"
                                    data-demo-title="See net profit"
                                    data-demo-label="Owner snapshot"
                                    data-demo-customer="Northline Maintenance"
                                    data-demo-context="Service business"
                                    data-demo-problem="Revenue, parts, labor notes, and open follow-ups are hard to see together."
                                    data-demo-solution="The example snapshot shows work, cost notes, and what needs attention next."
                                    data-demo-primary="Monthly service"
                                    data-demo-note="Recurring appointment, open question, and handoff note are tied to the same client."
                                    data-demo-task="Schedule visit and send reminder"
                                    data-demo-owner="Jordan"
                                    data-demo-money="$780 example net"
                                    data-demo-profit="Margin looks healthy after parts"
                                    data-demo-feed-one="Client record updated"
                                    data-demo-feed-two="Appointment added"
                                    data-demo-feed-three="Reminder prepared"
                                >
                                    <span>See net profit</span>
                                    <strong>Work -> cost -> owner view</strong>
                                </button>
                            </div>

                            <div class="fb-demo-stage">
                                <div class="fb-demo-stage__glow" aria-hidden="true"></div>
                                <div class="fb-demo-chaos" data-product-demo-problem aria-label="Scattered work before Everbranch">
                                    <span class="fb-chaos-pill fb-chaos-pill--one">Text thread: pricing?</span>
                                    <span class="fb-chaos-pill fb-chaos-pill--two">Notebook: call back Friday</span>
                                    <span class="fb-chaos-pill fb-chaos-pill--three">Spreadsheet row missing owner</span>
                                    <span class="fb-chaos-pill fb-chaos-pill--four">Photo note from the field</span>
                                    <span class="fb-chaos-pill fb-chaos-pill--five">Invoice draft in email</span>
                                </div>

                                <div class="fb-demo-app-shell">
                                    <aside class="fb-demo-sidebar" aria-label="Example app navigation">
                                        <div class="fb-demo-brand">
                                            <img src="{{ asset($brandMarkPath) }}?v={{ $brandAssetVersion }}" alt="" />
                                            <strong>Everbranch</strong>
                                        </div>
                                        <nav>
                                            <span class="is-active">Home</span>
                                            <span>Customers</span>
                                            <span>Work</span>
                                            <span>Tasks</span>
                                            <span>Money</span>
                                            <span>Reports</span>
                                        </nav>
                                    </aside>

                                    <section class="fb-demo-workspace" aria-label="Example Everbranch workspace">
                                        <header class="fb-demo-topbar">
                                            <div>
                                                <p>Example workspace</p>
                                                <strong data-product-demo-field="title">Add a customer</strong>
                                            </div>
                                            <div class="fb-demo-search">
                                                <img src="{{ asset($brandMarkPath) }}?v={{ $brandAssetVersion }}" alt="" />
                                                <span>Search or ask what you want to do...</span>
                                                <kbd>Cmd K</kbd>
                                            </div>
                                            <button type="button">Bud</button>
                                        </header>

                                        <div class="fb-demo-problem-solution">
                                            <button type="button" class="is-active" data-product-demo-mode="problem">
                                                <span>Problem</span>
                                                <strong data-product-demo-field="problem">Wholesale request lives in email, event notes, and someone's memory.</strong>
                                            </button>
                                            <button type="button" data-product-demo-mode="solution">
                                                <span>Solution</span>
                                                <strong data-product-demo-field="solution">One customer record holds the request, note, owner, and follow-up.</strong>
                                            </button>
                                        </div>

                                        <div class="fb-demo-canvas">
                                            <article class="fb-demo-record">
                                                <p data-product-demo-field="label">Customer record</p>
                                                <h3 data-product-demo-field="customer">Pine &amp; Porch</h3>
                                                <span data-product-demo-field="context">Retail buyer</span>
                                                <div class="fb-demo-record__note">
                                                    <small data-product-demo-field="primary">Wholesale request</small>
                                                    <p data-product-demo-field="note">Asked for event bestsellers, case pricing, and a fall reorder reminder.</p>
                                                </div>
                                                <div class="fb-demo-next-step">
                                                    <span>Next step</span>
                                                    <strong data-product-demo-field="task">Send line sheet and approve wholesale access</strong>
                                                    <small>Assigned to <b data-product-demo-field="owner">Sarah</b></small>
                                                </div>
                                            </article>

                                            <aside class="fb-demo-sidecards" aria-label="Example activity and money">
                                                <div>
                                                    <span>Activity</span>
                                                    <ul>
                                                        <li data-product-demo-feed="one">Buyer details captured</li>
                                                        <li data-product-demo-feed="two">Task assigned to Sarah</li>
                                                        <li data-product-demo-feed="three">Follow-up reminder queued</li>
                                                    </ul>
                                                </div>
                                                <div>
                                                    <span>Owner view</span>
                                                    <strong data-product-demo-field="money">$1,840 potential reorder</strong>
                                                    <p data-product-demo-field="profit">Next Friday follow-up ready</p>
                                                </div>
                                            </aside>
                                        </div>

                                        <footer class="fb-demo-progress" aria-label="Example workflow progress">
                                            <ol>
                                                <li class="is-active" data-product-demo-step="0">Detail captured</li>
                                                <li data-product-demo-step="1">Work organized</li>
                                                <li data-product-demo-step="2">Next step assigned</li>
                                                <li data-product-demo-step="3">Follow-up ready</li>
                                            </ol>
                                            <p>Motion-safe version: detail captured, work organized, next step assigned, follow-up ready.</p>
                                        </footer>
                                    </section>
                                </div>
                            </div>
                        </section>
                    </article>

                    <article id="panel-industries" class="fb-public-tab-panel" role="tabpanel" aria-labelledby="tab-industries" data-public-tab-panel="industries" hidden>
                        <section class="fb-story-panel fb-story-panel--industries" aria-label="Who Everbranch helps">
                            <div>
                                <p class="fb-section-kicker">Who It Helps</p>
                                <h2>Built for the messy middle of small business.</h2>
                                <p>Everbranch does not replace the way your business works. It gives that work a home.</p>
                            </div>
                            <div class="fb-industry-switcher fb-industry-switcher--visual" aria-label="Everbranch industry examples">
                                <details class="fb-industry-card" data-clickable-details-card open>
                                    <summary><span>Retail &amp; product brands</span><strong>Wholesale requests, inventory questions, event prep, reorders, and follow-ups.</strong></summary>
                                    <div class="fb-industry-preview"><p>Capture the buyer request, assign the follow-up, and keep reorder context attached.</p></div>
                                </details>
                                <details class="fb-industry-card" data-clickable-details-card>
                                    <summary><span>Electrical &amp; plumbing</span><strong>Job details, estimates, parts, scheduling notes, and crew next steps.</strong></summary>
                                    <div class="fb-industry-preview"><p>Keep the customer, job note, parts question, and crew handoff together.</p></div>
                                </details>
                                @if((bool) config('features.customer_electrician_tutorial', false))
                                    <details class="fb-industry-card" data-clickable-details-card>
                                        <summary><span>Electrician</span><strong>Starter example for customers, service jobs, materials, photos, and team handoffs.</strong></summary>
                                        <div class="fb-industry-preview"><p>Use the same customer-and-work pattern for a field-service team.</p></div>
                                    </details>
                                @endif
                                <details class="fb-industry-card" data-clickable-details-card>
                                    <summary><span>Construction &amp; project work</span><strong>Approvals, materials, change requests, subcontractor updates, and punch lists.</strong></summary>
                                    <div class="fb-industry-preview"><p>Give project changes and open decisions one visible place.</p></div>
                                </details>
                                <details class="fb-industry-card" data-clickable-details-card>
                                    <summary><span>Service businesses</span><strong>Client records, appointments, recurring work, handoffs, and reminders.</strong></summary>
                                    <div class="fb-industry-preview"><p>Make the next appointment, reminder, and handoff easy to find.</p></div>
                                </details>
                            </div>
                        </section>
                    </article>

                    <article id="panel-contact" class="fb-public-tab-panel" role="tabpanel" aria-labelledby="tab-contact" data-public-tab-panel="contact" hidden>
                        <section class="fb-story-panel fb-story-panel--contact" aria-label="Contact Everbranch">
                            <div>
                                <p class="fb-section-kicker">Contact</p>
                                <h2>Bring the messy version. We will help shape the clean one.</h2>
                                <p>Tell us what your team keeps losing track of. We will point you to the right starting place.</p>
                            </div>
                            <div class="fb-contact-panel">
                                <a href="{{ route('platform.start') }}" class="fb-btn fb-btn-primary">Request access</a>
                                <a href="{{ route('platform.contact') }}" class="fb-btn fb-btn-secondary">Contact Everbranch</a>
                                <a href="mailto:{{ config('mail.from.address') }}" class="fb-btn fb-btn-secondary">Email the team</a>
                            </div>
                        </section>
                    </article>
                </div>
            </section>
        </main>

        <footer class="fb-footer fb-footer--public" aria-label="Footer">
            <div>
                <img src="{{ asset($brandLockupPath) }}?v={{ $brandAssetVersion }}" alt="{{ $productName }}" />
                <span>{{ $productName }} by {{ $companyName }}</span>
            </div>
            <nav aria-label="Footer navigation">
                <a href="{{ route('platform.contact') }}">Contact</a>
                <a href="{{ route('login') }}">Login</a>
            </nav>
        </footer>
    </div>
</body>
</html>

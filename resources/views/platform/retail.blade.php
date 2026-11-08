@php
    $walkthrough = route('platform.contact', ['intent' => 'walkthrough']);
    $brand = (array) config('everbranch.brand_assets', []);
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    @include('partials.head', [
        'title' => 'Everbranch for retailers | Keep Square or move in phases',
        'description' => 'Bring your shop, customer relationships, rewards, birthdays and event planning together. Keep Square at the counter or plan a phased move with Everbranch.',
        'retail_public_assets' => true,
    ])
</head>
<body class="fb-public-body eb-studio-body eb-retail-page" data-premium-motion="public">
    <a class="eb-skip-link" href="#retail-content">Skip to retail overview</a>
    @include('platform.partials.studio-support-nav')
    <main id="retail-content">
        <section class="eb-retail-hero eb-studio-container" aria-labelledby="retail-title">
            <div class="eb-retail-hero__copy">
                <p class="eb-studio-eyebrow">For independent shops &amp; product brands</p>
                <h1 id="retail-title">A great shop is more than its checkout.</h1>
                <p class="eb-retail-lede">Bring the whole shop together: sell, bring customers back, organize your team’s time and understand what each event earns.</p>
                <p>Keep Square at the counter, or bring more of your business into Everbranch in stages. We help you choose a useful starting point and a clear launch plan.</p>
                <div class="eb-retail-actions">
                    <a class="eb-studio-button eb-studio-button--dark" href="{{ $walkthrough }}">Plan my retail setup <span aria-hidden="true">↗</span></a>
                    <a class="eb-retail-text-link" href="#choose-your-path">Find your starting point ↓</a>
                </div>
            </div>
            <figure class="eb-retail-hero__photo">
                <img src="{{ asset('images/public-site/everbranch-retail-offer-1080.webp') }}" srcset="{{ asset('images/public-site/everbranch-retail-offer-640.webp') }} 640w, {{ asset('images/public-site/everbranch-retail-offer-1080.webp') }} 1080w" sizes="(max-width: 760px) calc(100vw - 32px), 45vw" alt="An independent shop owner preparing products for customers" fetchpriority="high" />
                <figcaption><span>Your shop. Your customer relationships.</span><strong>Room for what comes next.</strong></figcaption>
            </figure>
        </section>

        <div class="eb-retail-flow eb-studio-container" aria-label="The connected retail journey">
            <span>Sell</span><span>Build loyalty</span><span>Plan the crew &amp; time</span><span>Review pay &amp; costs</span><span>Know your event profit</span>
        </div>

        <nav class="eb-retail-section-nav eb-studio-container" aria-label="Retail overview sections">
            <a href="#choose-your-path">Keep Square or move</a><a href="#customer-experience">Customers &amp; rewards</a>@if($hasTeamsOffer)<a href="#retail-team">Teams, time &amp; event pay</a>@endif<a href="#retail-tools">Website &amp; operations</a><a href="#launch-plan">Your launch plan</a>
        </nav>

        <section id="choose-your-path" class="eb-retail-section eb-studio-container" aria-labelledby="paths-title">
            <div class="eb-retail-section-heading">
                <p class="eb-studio-eyebrow">Start where you are</p>
                <h2 id="paths-title">Two paths. A plan that fits your shop.</h2>
                <p>You can improve the customer experience without changing every tool at once.</p>
            </div>
            <div class="eb-retail-paths">
                <article class="eb-retail-path">
                    <span class="eb-retail-number">01 / WORK ALONGSIDE</span>
                    <h3>Keep Square. Add the bigger picture.</h3>
                    <p>Keep taking payments with Square while Everbranch helps you organize customer relationships, follow-up and event decisions.</p>
                    <ul><li>Review an authorized import of supported Square customer and purchase records.</li><li>Refresh connected history as configured, with its source and coverage visible.</li><li>Link reviewed records to the right customer and product, with recorded locations where available.</li></ul>
                    <p class="eb-retail-detail">Square stays your register. Connecting its records does not change your checkout or automatically move rewards between systems.</p>
                    <a href="{{ $walkthrough }}">Talk through keeping Square <span aria-hidden="true">↗</span></a>
                </article>
                <article class="eb-retail-path eb-retail-path--green">
                    <span class="eb-retail-number">02 / MOVE IN PHASES</span>
                    <h3>Bring more of your business together.</h3>
                    <p>Start with your website or customer experience, then review the next parts of your retail operation with us.</p>
                    <ul><li>Plan product pages, customer accounts, pickup, shipping and checkout around your business.</li><li>Choose your own rewards and birthday program before introducing it to customers.</li><li>Review in-person Pay pilot options and team workflows when the time is right.</li></ul>
                    <p class="eb-retail-detail">Each connection and payment route is reviewed and tested before launch. Existing systems can stay in place during the transition.</p>
                    <a href="{{ $walkthrough }}">Plan a phased move <span aria-hidden="true">↗</span></a>
                </article>
            </div>
        </section>


        <section id="customer-experience" class="eb-retail-relationship" aria-labelledby="relationships-title">
            <div class="eb-studio-container eb-retail-split">
                <div>
                    <p class="eb-studio-eyebrow">Make the next visit feel familiar</p>
                    <h2 id="relationships-title">Remember the person behind the purchase.</h2>
                    <p>See linked online and in-person purchases in one customer profile, alongside recorded products, locations, rewards and preferences.</p>
                    <p>Your team can prepare a more personal email or text using that context. Consent, your contact limits and a person’s review still guide what gets sent.</p>
                    <p class="eb-retail-detail">Records are linked deliberately. Missing or unconnected history stays visible, and a card payment never identifies a customer automatically.</p>
                </div>
                <aside class="eb-retail-customer-example" aria-label="Fictional customer illustration">
                    <div class="eb-retail-example-label">ILLUSTRATION · FICTIONAL DATA</div>
                    <div class="eb-retail-customer-name"><span aria-hidden="true">AG</span><div><h3>Avery Green</h3><p>One customer, a little more context</p></div></div>
                    <dl><div><dt>Online purchase</dt><dd>Amber mug · October</dd></div><div><dt>Market visit</dt><dd>Eucalyptus soap · Saturday market</dd></div><div><dt>Your rewards program</dt><dd>River Perks · rules chosen by the shop</dd></div><div><dt>Customer choices</dt><dd>Favorites, birthday and contact preferences</dd></div></dl>
                    <p>Linked records shown for illustration. A real profile identifies its sources and any missing history.</p>
                </aside>
            </div>
            <div class="eb-studio-container eb-retail-programs">
                <article><span class="eb-retail-label">YOUR NAME. YOUR RULES.</span><h3>Rewards that belong to your shop.</h3><p>Choose a program name, how customers earn, what they can redeem and who qualifies. Review the rules, balances and supported checkout journey before announcing it.</p></article>
                <article><span class="eb-retail-label">A REASON TO COME BACK</span><h3>Make birthdays feel personal.</h3><p>Plan birthday signup, gifts, timing and customer reminders through the Birthdays / Lifecycle branch. Customer dates and messaging choices stay part of the setup.</p></article>
                <article><span class="eb-retail-label">A PLACE FOR CUSTOMERS, TOO</span><h3>Connect the customer experience.</h3><p>Bring account and reward information into supported customer-facing pages and app experiences. A branded customer app and its birthday experience are scoped with you; availability depends on the app and integrations.</p></article>
            </div>
        </section>

        @if($hasTeamsOffer)
            <section id="retail-team" class="eb-retail-team" aria-labelledby="retail-team-title">
                <div class="eb-studio-container">
                    <div class="eb-retail-team-heading"><p class="eb-studio-eyebrow eb-studio-eyebrow--light">Teams for the work behind the sales</p><h2 id="retail-team-title">Your event.<br>Your company’s pay rules.<br>A clearer picture of profit.</h2><p>A busy booth is only part of the story. Bring crew planning, employee time, event pay calculations and the real costs of showing up into the same rollout plan.</p></div>
                    <div class="eb-retail-team-grid">
                        <article><span>01 / PEOPLE</span><h3>Know who’s working, and when.</h3><p>Plan employee schedules across setup, selling, travel and teardown, including events with gaps between selling days. Event opening hours and a person’s shift stay distinct.</p></article>
                        <article><span>02 / TIME</span><h3>Record time. Review the hours.</h3><p>Include employee timekeeping in your setup: clock-in and out, breaks, corrections and approved hours. We review the workforce configuration your team needs before introducing it.</p></article>
                        <article><span>03 / YOUR PAY MODEL</span><h3>Calculate event pay your way.</h3><p>Model hourly pay or commission, reviewed minimums, setup and breakdown allowances, per diem and mileage. Scope company pay profiles and exceptions around the way you actually compensate people.</p></article>
                        <article><span>04 / THE WHOLE COST</span><h3>See what the show needs to earn.</h3><p>Add booth fees, hotel, meals, gifts and other custom costs when they apply. Keep each reviewed budget version, estimate break-even and compare recorded event results without rewriting past budgets.</p></article>
                    </div>
                    <div class="eb-retail-team-bottom"><p>Teams is separately priced and introduced through assisted setup. Pay calculations are estimates for owner approval; they do not process payroll or turn show hours into paid staff time.</p><a class="eb-studio-button eb-studio-button--light" href="{{ $walkthrough }}">See how my team could work <span aria-hidden="true">↗</span></a></div>
                </div>
            </section>
        @endif


        <section class="eb-retail-section eb-studio-container" aria-labelledby="retail-example-title" data-retail-calculator>
            <div class="eb-retail-section-heading"><p class="eb-studio-eyebrow">Try the planning view · illustrative example</p><h2 id="retail-example-title">What would this event need to earn?</h2><p>Change the assumptions and see the trade-offs. This is a fictional hourly-pay example in USD, before tax. It does not predict sales, authorize payroll or show a customer result.</p></div>
            <div class="eb-retail-calculator">
                <form class="eb-retail-assumptions" aria-label="Illustrative event assumptions">
                    <label>Assumed net sales ($)<input name="sales" type="number" min="0" max="1000000" step="50" value="2400" /></label>
                    <label>Booth &amp; fixed fees ($)<input name="booth" type="number" min="0" max="100000" step="10" value="180" /></label>
                    <label>Total paid staff hours<input name="hours" type="number" min="0" max="2000" step="0.5" value="24" /></label>
                    <label>Company hourly rate ($)<input name="pay" type="number" min="0" max="1000" step="0.5" value="15" /></label>
                    <label>Eligible miles<input name="miles" type="number" min="0" max="100000" step="10" value="220" /></label>
                    <label>Company mileage rate ($/mile)<input name="mileageRate" type="number" min="0" max="10" step="0.01" value="0.67" /></label>
                    <label>Product cost (% of sales)<input name="cogs" type="number" min="0" max="99" step="1" value="40" /></label>
                    <label>Processing cost (% of sales)<input name="processing" type="number" min="0" max="99" step="0.1" value="3" /></label>
                    <label class="eb-retail-hotel-choice"><span><input name="includeHotel" type="checkbox" checked /> Include a hotel</span><input aria-label="Hotel total dollars" name="hotel" type="number" min="0" max="100000" step="10" value="200" /></label>
                    <p>Enter total paid hours deliberately; show opening hours do not fill this field. Your company sets the mileage rate. Real event policies can also include commission, minimums and reviewed allowances.</p>
                </form>
                <div class="eb-retail-example-results">
                    <p class="eb-retail-example-notice" data-retail-example-status aria-live="polite">Illustrative assumptions. Change any field to explore.</p>
                    <div class="eb-retail-result-cards"><div><span>Break-even net sales</span><strong data-retail-result="breakEven">$1,556.85</strong></div><div><span>Estimated total costs</span><strong data-retail-result="costs">$1,919.40</strong></div><div><span>Estimated surplus / shortfall</span><strong data-retail-result="surplus">$480.60</strong></div></div>
                    <svg viewBox="0 0 640 200" role="img" aria-labelledby="retail-cost-title retail-cost-description">
                        <title id="retail-cost-title">Illustrative sales and event cost comparison</title><desc id="retail-cost-description" data-retail-chart-description>Assumed sales are $2,400. Estimated costs are $1,919.40. The table below gives the same values.</desc>
                        <text x="0" y="52">Sales</text><rect x="100" y="27" width="460" height="38" rx="4" fill="#315b4a" data-retail-sales-bar /><text x="0" y="125">Costs</text><rect x="100" y="100" width="367.9" height="38" rx="4" fill="#a65937" data-retail-costs-bar /><text x="100" y="178">Illustrative USD · before tax</text>
                    </svg>
                    <table class="eb-retail-value-table"><caption>Illustrative event calculation</caption><tbody>
                        <tr><th scope="row">Assumed net sales</th><td data-retail-table="sales">$2,400.00</td></tr>
                        <tr><th scope="row">Fixed fees</th><td data-retail-table="booth">$180.00</td></tr><tr><th scope="row">Staff hours × company rate</th><td data-retail-table="staff">$360.00</td></tr><tr><th scope="row">Eligible miles × company rate</th><td data-retail-table="mileage">$147.40</td></tr><tr><th scope="row">Hotel, when selected</th><td data-retail-table="hotel">$200.00</td></tr><tr><th scope="row">Product + processing costs</th><td data-retail-table="variable">$1,032.00</td></tr>
                        <tr><th scope="row">Estimated total costs</th><td data-retail-table="costs">$1,919.40</td></tr><tr><th scope="row">Estimated surplus / shortfall</th><td data-retail-table="surplus">$480.60</td></tr>
                    </tbody></table>
                    <p class="eb-retail-detail">Break-even covers the costs entered here. Missing taxes, expenses or qualifications can change the real result. Nothing is saved or sent.</p>
                    <noscript><p>This fixed example remains readable without JavaScript. Enable JavaScript to change the illustrative assumptions.</p></noscript>
                </div>
            </div>
        </section>

        <section class="eb-retail-comparison" aria-labelledby="retail-comparison-title">
            <div class="eb-studio-container eb-retail-split">
                <figure class="eb-retail-maker-photo"><img src="{{ asset('images/public-site/everbranch-retail-maker-1080.webp') }}" srcset="{{ asset('images/public-site/everbranch-retail-maker-640.webp') }} 640w, {{ asset('images/public-site/everbranch-retail-maker-1080.webp') }} 1080w" sizes="(max-width: 760px) calc(100vw - 32px), 45vw" alt="A maker planning a product collection in a sunlit studio" loading="lazy" /><figcaption>More time for the work you care about.</figcaption></figure>
                <div><p class="eb-studio-eyebrow">Learn from each show</p><h2 id="retail-comparison-title">Put this year beside last year.</h2><p>Review sales, recorded costs and profit for the occurrences you have checked. Keep the original budget version, explain what changed and leave missing costs unknown.</p>
                    <figure class="eb-retail-year-chart"><figcaption>Fictional comparison · illustrative USD · before tax</figcaption><svg viewBox="0 0 600 190" role="img" aria-labelledby="retail-year-title retail-year-desc"><title id="retail-year-title">Fictional two-year sales and cost comparison</title><desc id="retail-year-desc">In this illustration, the prior year has $1,800 net sales, $1,500 costs and $300 profit. The later year has $2,200 net sales, $1,700 costs and $500 profit. These are fictional examples, not customer results.</desc><text x="0" y="49">Prior year</text><rect x="105" y="24" width="300" height="38" fill="#315b4a" /><rect x="405" y="24" width="60" height="38" fill="#a65937" /><text x="0" y="119">Later year</text><rect x="105" y="94" width="340" height="38" fill="#315b4a" /><rect x="445" y="94" width="100" height="38" fill="#a65937" /><rect x="105" y="160" width="12" height="12" fill="#315b4a" /><text x="127" y="172">Costs</text><rect x="245" y="160" width="12" height="12" fill="#a65937" /><text x="267" y="172">Profit</text></svg>
                        <table class="eb-retail-value-table"><caption>Same fictional values in a table</caption><thead><tr><th scope="col">Example</th><th scope="col">Net sales</th><th scope="col">Costs</th><th scope="col">Profit</th></tr></thead><tbody><tr><th scope="row">Prior year</th><td>$1,800</td><td>$1,500</td><td>$300</td></tr><tr><th scope="row">Later year</th><td>$2,200</td><td>$1,700</td><td>$500</td></tr></tbody></table>
                    </figure>
                    <p class="eb-retail-detail">Illustration only. Real comparisons require reviewed source records and complete enough cost coverage; no return is guaranteed.</p>
                </div>
            </div>
        </section>

        <section id="retail-tools" class="eb-retail-section eb-studio-container" aria-labelledby="tools-title">
            <div class="eb-retail-section-heading"><p class="eb-studio-eyebrow">From the shop floor to the next show</p><h2 id="tools-title">Give the whole operation some breathing room.</h2></div>
            <div class="eb-retail-tools">
                <article><span class="eb-retail-label">WEBSITE · ASSISTED SETUP</span><h3>An online home worth visiting.</h3><p>Tell your story and make the next customer action clear. Start with the website, then review retail catalog, accounts, pickup, shipping and ecommerce options for your launch.</p><p class="eb-retail-detail">Retail checkout and payment options are reviewed separately. We confirm the supported customer journey before taking orders.</p></article>
                <article><span class="eb-retail-label">EVERBRANCH PAY · CONTROLLED PILOT</span><h3>Explore a connected counter.</h3><p>Ask about in-person Everbranch Pay as part of your retail plan. Pilot setup covers the merchant account, compatible device, products, tax, receipts and supported rewards.</p><p class="eb-retail-detail">Pay is currently a limited guided pilot for approved merchants and devices. Broader access is not yet available.</p></article>
                @if($hasTeamsOffer)
                    <article><span class="eb-retail-label">TEAMS · SEPARATE PREMIUM SOFTWARE</span><h3>Know what an event needs to earn.</h3><p>Plan setup, selling and teardown dates with your crew. Build custom costs for booth fees, travel, hotels and staffing, then review the budget, break-even estimate and recorded results.</p><p class="eb-retail-detail">Event intelligence can compare reviewed history and observed customer return visits. Missing costs and incomplete history stay visible.</p></article>
                @endif
                <article><span class="eb-retail-label">A HUMAN NEXT STEP</span><h3>Follow up with a reason.</h3><p>Prepare customer-specific messages from recorded purchases and explicit preferences. Set the pace of promotional contact across email and text, with approvals before delivery.</p><p class="eb-retail-detail">Personalized outreach is introduced through a reviewed setup. Importing records does not sign anyone up for marketing.</p></article>
            </div>
        </section>

        <section id="launch-plan" class="eb-retail-launch" aria-labelledby="launch-title">
            <div class="eb-studio-container">
                <div class="eb-retail-section-heading"><p class="eb-studio-eyebrow">A useful first launch</p><h2 id="launch-title">Move at the pace your business can handle.</h2><p>Choose one customer journey, prove it works for your shop, then build on it.</p></div>
                <ol class="eb-retail-steps">
                    <li><span>01</span><h3>Map what you have.</h3><p>Tell us about your register, website, product catalog, customer history and current rewards. Decide what stays and what needs to improve.</p></li>
                    <li><span>02</span><h3>Review the records.</h3><p>Connect authorized sources, check the import scope and resolve customer or product matches. Agree what history is available and what remains unknown.</p></li>
                    <li><span>03</span><h3>Rehearse one journey.</h3><p>Review your website, program terms, customer choices and checkout route. Test a purchase, receipt and any supported reward or birthday flow.</p></li>
                    <li><span>04</span><h3>Launch, then grow.</h3><p>Approve the ready parts, train the team and keep existing tools where useful. Review the next stage together.</p></li>
                </ol>
                <a class="eb-studio-button eb-studio-button--dark" href="{{ $walkthrough }}">Talk through my launch plan <span aria-hidden="true">↗</span></a>
            </div>
        </section>

        <section class="eb-retail-section eb-studio-container eb-retail-faq" aria-labelledby="retail-faq-title">
            <div><p class="eb-studio-eyebrow">Before you switch anything</p><h2 id="retail-faq-title">A few practical questions.</h2></div>
            <div>
                <details><summary>Can I keep using Square?</summary><p>Yes. A reviewed Square connection can bring supported records into Everbranch while Square continues to run your checkout. We confirm the import scope, refresh process and available history during setup.</p></details>
                <details><summary>Does importing history enable rewards inside Square POS?</summary><p>No. Importing records does not add Everbranch reward earning or redemption to an unmodified Square POS. Checkout rewards depend on the supported integration and approved program for that route.</p></details>
                <details><summary>Will all my old customers and purchases appear?</summary><p>Coverage depends on the records your connected sources provide, your authorized scope and reviewed identity links. We show what is connected and flag missing history; a matching card or name is not enough to join customer records.</p></details>
                <details><summary>Can customers use rewards on every website checkout?</summary><p>Reward redemption depends on the checkout adapter. Native Everbranch Website reward redemption is not yet offered as a general checkout feature. We review the supported route for your shop before promising a customer journey.</p></details>
                <details><summary>Can I have a birthday program and a customer app?</summary><p>Birthdays / Lifecycle and Rewards provide the program foundation. Customer-facing pages and app experiences depend on your setup. We scope the gift, eligibility, expiry, customer choices and app availability with you rather than assuming every store has the same program.</p></details>
                <details><summary>What does getting started commit me to?</summary><p>A request starts a conversation about your needs. We review the scope, package, usage terms and any separately priced software before access, billing or activation. <a href="{{ route('platform.plans') }}">See the current plans.</a></p></details>
            </div>
        </section>

        @if(count($retailBranches))
            <section class="eb-retail-branches eb-studio-container" aria-labelledby="retail-branches-title">
                <p class="eb-studio-eyebrow">Explore the public branch library</p><h2 id="retail-branches-title">Choose the parts that help your shop.</h2>
                <div>@foreach($retailBranches as $branch)<a href="{{ route('platform.modules.show', ['module' => $branch['key']]) }}"><span>{{ $branch['display_name'] }}</span><small>{{ $branch['lifecycle_label'] }} · {{ $branch['setup_effort_label'] }}</small><span aria-hidden="true">↗</span></a>@endforeach</div>
            </section>
        @endif

        <section class="eb-retail-final eb-studio-container" aria-labelledby="retail-next-title">
            <p class="eb-studio-eyebrow">Your shop, with a clearer next step</p><h2 id="retail-next-title">Keep what works.<br>Make room for what’s next.</h2>
            <div class="eb-retail-actions"><a class="eb-studio-button eb-studio-button--dark" href="{{ route('platform.start') }}">Get started with Everbranch <span aria-hidden="true">↗</span></a><a class="eb-retail-text-link" href="{{ route('platform.industry-demo', ['discipline' => 'retail']) }}">Explore a fictional retail example ↗</a></div>
            <p>Core branches are chosen for your business. @if($hasTeamsOffer)Teams is priced separately. @endif<a href="{{ route('platform.plans') }}">Review plans and setup terms.</a></p>
        </section>
    </main>
    <footer class="eb-retail-footer"><div class="eb-studio-container"><a href="{{ route('platform.promo') }}"><img src="{{ asset($brand['lockup'] ?? 'brand/everbranch-lockup.svg') }}" alt="Everbranch" /></a><span>One place for the work behind your shop.</span><nav aria-label="Retail footer"><a href="{{ route('platform.contact') }}">Contact</a><a href="{{ route('legal.privacy') }}">Privacy</a><a href="{{ route('legal.terms') }}">Terms</a></nav></div></footer>
    @include('platform.partials.premium-motion')
</body>
</html>

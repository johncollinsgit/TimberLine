@php
    $document = $document ?? 'privacy';
    $isPrivacy = $document === 'privacy';
    $isSupport = $document === 'support';
    $isSms = $document === 'sms';
    $title = match ($document) {
        'support' => 'Everbranch Support',
        'terms' => 'Terms of Use and End-User License Agreement',
        'sms' => 'SMS Messaging Terms',
        default => 'Privacy Policy',
    };
    $effectiveDate = 'October 26, 2026';
    $brandAssets = is_array($brandAssets ?? null) ? $brandAssets : [];
    $assetVersion = (string) ($brandAssets['cache_tag'] ?? 'eb1');
    $lockup = asset((string) ($brandAssets['lockup'] ?? 'brand/everbranch-lockup.svg')).'?v='.$assetVersion;
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    @include('partials.head', [
        'app_name' => $brandName,
        'title' => $title,
        'description' => $title.' for Everbranch and Evergrove Software services.',
        'brand_assets' => $brandAssets,
    ])
</head>
<body class="eg-public-body eg-public-body--launch eg-legal-body">
    <header class="eg-legal-header">
        <a href="{{ $homeUrl }}" class="eg-legal-brand" aria-label="{{ $brandName }} home">
            <img src="{{ $lockup }}" alt="{{ $brandName }}" />
        </a>
        <nav aria-label="Support and legal pages">
            <a href="{{ route('legal.support') }}" @if($isSupport) aria-current="page" @endif>Support</a>
            <a href="{{ route('legal.privacy') }}" @if($isPrivacy) aria-current="page" @endif>Privacy</a>
            <a href="{{ route('legal.terms') }}" @if(!$isPrivacy && !$isSupport && !$isSms) aria-current="page" @endif>Terms</a>
            <a href="{{ route('legal.sms') }}" @if($isSms) aria-current="page" @endif>SMS terms</a>
            <a href="{{ $homeUrl }}">Home</a>
        </nav>
    </header>

    <main class="eg-legal-main">
        <header class="eg-legal-intro">
            <p class="eg-kicker">Everbranch by Evergrove Software LLC</p>
            <h1>{{ $title }}</h1>
            @if($isSupport)
                <p>Help for Everbranch workspace owners, managers, and field teams.</p>
            @else
                <p>Effective {{ $effectiveDate }}</p>
            @endif
        </header>

        @if($isPrivacy)
            <section>
                <h2>Our privacy commitment</h2>
                <p>Evergrove Software LLC operates Everbranch, a tenant-scoped workspace for customers, jobs, field activity, communication, reporting, and connected business tools. This policy also covers our corporate website at evergrovesoftware.com. This policy explains what we collect, why we use it, and the choices available to workspace owners and users.</p>
            </section>

            <section>
                <h2>Information we collect</h2>
                <ul>
                    <li><strong>Account and workspace information:</strong> names, email addresses, roles, authentication records, workspace settings, and support correspondence.</li>
                    <li><strong>Business operations data:</strong> customers, jobs, service addresses, notes, photos, assignments, schedules, estimates, invoices, items, materials, and related records submitted by authorized users.</li>
                    <li><strong>Connected-service data:</strong> information an administrator authorizes Everbranch to retrieve from services such as QuickBooks Online or Shopify.</li>
                    <li><strong>Technical data:</strong> device, browser, IP address, request, security, error, and audit information used to operate and protect the service.</li>
                </ul>
            </section>

            <section>
                <h2>QuickBooks Online</h2>
                <p>When a workspace administrator connects QuickBooks Online, Everbranch may retrieve company metadata, customers, estimates, invoices, and items or services for tenant-scoped import and analysis. The current integration is read/import only: it does not initiate payments, charge payment methods, or write transactions back to QuickBooks. Access is limited to the company and permissions approved during Intuit's authorization flow.</p>
                <p>Everbranch stores encrypted connection tokens and imported records needed to provide the requested workspace features. Disconnecting QuickBooks stops future API access. An authorized workspace administrator may request deletion of stored connection tokens and imported QuickBooks data by contacting us.</p>
            </section>

            <section>
                <h2>How we use information</h2>
                <p>We use information to provide tenant-scoped workspaces, import authorized records, support job and customer workflows, secure accounts, troubleshoot problems, improve requested features, communicate service notices, and comply with law. We do not sell personal information or use connected QuickBooks data for advertising.</p>
            </section>

            <section>
                <h2>How information is shared</h2>
                <p>We share information only with service providers needed to host, secure, monitor, support, or deliver Everbranch; with connected services at an authorized user's direction; when required by law; or as part of a business transfer subject to appropriate safeguards. Providers receive only the information reasonably needed for their function.</p>
            </section>

            <section>
                <h2>SMS information and consent</h2>
                <p>When you choose text updates, the sending business collects your mobile number and a record of your choice, including the consent wording, purpose, source and time. Consent applies only to the identified sender and message purpose. Providing a phone number for account, billing, emergency-contact or service purposes does not by itself authorize texts or marketing. Text enrollment is optional and is not a condition of purchase or service. Marketing requires a separate express written opt-in.</p>
                <p>We do not sell, rent, trade or share mobile numbers or SMS consent with other businesses for their marketing. All the above categories exclude text messaging originator opt-in data and consent; this information won’t be shared with any third parties. The general sharing and business-transfer provisions above do not authorize transferring SMS consent. Delivery providers process the minimum information needed to carry out the identified sender’s messaging instructions, not for independent marketing.</p>
                <p>Reply STOP to stop a sender’s texts or HELP for assistance. You may also contact the sending business or <a href="mailto:{{ $contactEmail }}">{{ $contactEmail }}</a>. See our <a href="{{ route('legal.sms') }}">SMS Messaging Terms</a>. Each customer business using Everbranch must provide its own sender-specific privacy and messaging disclosures.</p>
            </section>

            <section>
                <h2>Tenant separation and security</h2>
                <p>Workspace records are scoped by tenant and access role. We use encryption in transit, encrypted integration credentials, access controls, audit records, backups, and operational monitoring. No system is perfectly secure, but we work to prevent unauthorized access, disclosure, alteration, and loss.</p>
            </section>

            <section>
                <h2>Retention, access, and deletion</h2>
                <p>We retain information while it is needed to provide the service, satisfy workspace instructions, maintain security and audit records, resolve disputes, or meet legal obligations. Workspace administrators may request access, correction, export, disconnection, or deletion. Some records may be retained when legally required or necessary to protect the service and its users.</p>
            </section>

            <section>
                <h2>Children and policy changes</h2>
                <p>Everbranch is a business service and is not directed to children under 13. We may update this policy as the product or applicable requirements change. Material changes will be identified by a revised effective date and, when appropriate, an in-product or email notice.</p>
            </section>
        @elseif($isSms)
            <section>
                <h2>Who sends the messages</h2>
                <p>Evergrove Software LLC provides Everbranch. Our own optional text program covers requested account, appointment, project and service updates. When a customer business uses Everbranch, that business is the sender and must identify itself in its consent form and messages. Its consent does not enroll you in Evergrove’s program or another business’s program.</p>
            </section>
            <section>
                <h2>Your choice to enroll</h2>
                <p>You enroll only by an affirmative, optional choice that identifies the sender, the mobile number, and the type of texts you want. Checkboxes must start unchecked. Agreeing to general terms, registering, supplying an emergency number or making a purchase is not SMS consent. You may use the service without subscribing to texts. Consent is specific to the sender and purpose and cannot be sold or transferred. Marketing and promotional messages require a separate express written opt-in and the applicable sender registration.</p>
            </section>
            <section>
                <h2>Frequency, rates and delivery</h2>
                <p>Message frequency varies with the account or service activity you requested. Message and data rates may apply. Carriers are not liable for delayed or undelivered messages. Texts are not an emergency service. Program availability remains subject to provider verification and the sending business’s approved use case.</p>
            </section>
            <section>
                <h2>STOP, HELP and support</h2>
                <p>Reply STOP to the sending number to cancel that program at any time. You may receive one confirmation of your opt-out, after which messages stop unless you expressly opt in again. Reply HELP for assistance, or email <a href="mailto:{{ $contactEmail }}">{{ $contactEmail }}</a>. Contact the identified customer business for its program; Evergrove can help route a request if the sender is unclear. Tell the sender if you change or give up your mobile number.</p>
                <p>Our <a href="{{ route('legal.privacy') }}">Privacy Policy</a> explains how mobile information and consent records are protected. Our <a href="{{ route('legal.terms') }}">Terms of Use</a> also apply.</p>
            </section>
        @elseif($isSupport)
            <section>
                <h2>Get help</h2>
                <p>The fastest way to reach us is inside Everbranch. Open <strong>Account</strong>, choose <strong>Help and support</strong>, and create a ticket. Your ticket keeps the issue and replies together without exposing another workspace's information.</p>
                <p>If you cannot sign in, email <a href="mailto:{{ $contactEmail }}?subject=Everbranch%20support">{{ $contactEmail }}</a>. Include your workspace name, the device you are using, and a short description of what happened. Do not send passwords, verification codes, or full payment information.</p>
            </section>

            <section>
                <h2>Common requests</h2>
                <ul>
                    <li><strong>Sign-in or invitation help:</strong> tell us the email address that received the workspace invitation and the workspace you are trying to join.</li>
                    <li><strong>Jobs, time, photos, or messages:</strong> include the job name and the action you were trying to complete.</li>
                    <li><strong>QuickBooks connection:</strong> a workspace owner can disconnect the integration or ask us to remove stored connection data.</li>
                    <li><strong>Privacy, export, or deletion:</strong> use <strong>Account → Request account deletion</strong> in the app, or email us from the address on your account.</li>
                </ul>
            </section>

            <section>
                <h2>Before contacting support</h2>
                <p>Confirm that Everbranch is up to date, reopen the app, and check that your phone has an internet connection. If an action was queued while offline, leave the app open briefly after reconnecting so it can finish syncing.</p>
            </section>

            <section>
                <h2>Safety and emergencies</h2>
                <p>Everbranch organizes field work but is not an emergency service. For an immediate safety, electrical, medical, or other emergency, follow your employer's safety procedure and contact the appropriate emergency service.</p>
            </section>
        @else
            <section>
                <h2>Agreement and license</h2>
                <p>These terms govern access to Everbranch and related Evergrove Software services. By creating an account, joining a workspace, or using the service, you agree to these terms. We grant authorized users a limited, revocable, non-exclusive, non-transferable license to use the service for the workspace's internal business operations.</p>
            </section>

            <section>
                <h2>Accounts and workspace administration</h2>
                <p>Users must provide accurate account information, protect authentication credentials, and use only workspaces they are authorized to access. Workspace administrators control memberships, roles, connected services, and submitted business records. Administrators are responsible for obtaining permissions needed to provide customer, employee, job, communication, and financial records to the service.</p>
            </section>

            <section>
                <h2>Your data</h2>
                <p>You retain ownership of data submitted to a workspace. You grant Evergrove Software permission to host, process, copy, transmit, and display that data only as needed to provide, secure, support, and improve the requested service. You are responsible for the accuracy, legality, and backup or export of your records.</p>
            </section>

            <section>
                <h2>QuickBooks and other connected services</h2>
                <p>Third-party integrations are governed by the provider's terms in addition to these terms. A workspace administrator must expressly authorize each connection. The current QuickBooks integration is read/import only and does not process payments or write transactions back to QuickBooks. Connected-service availability and data are controlled by the applicable provider.</p>
            </section>

            <section>
                <h2>Acceptable use</h2>
                <p>You may not use the service to violate law or another person's rights; access another tenant without authorization; distribute malware; probe or bypass security; overload the service; scrape or resell the service; or send unlawful, deceptive, or non-consensual communications. Messaging features must be used with appropriate consent and in compliance with applicable email and telephone laws.</p>
                <p>The <a href="{{ route('legal.sms') }}">SMS Messaging Terms</a> apply to optional texts. Customer businesses must obtain and retain sender- and purpose-specific consent, honor opt-outs, and complete applicable business, brand, campaign and sender verification before sending. Buying, importing or possessing a contact list does not establish SMS consent. Operational consent cannot be reused for marketing or a different business.</p>
            </section>

            <section>
                <h2>No professional advice</h2>
                <p>Everbranch helps organize business information and workflows. It does not provide accounting, tax, legal, payroll, electrical, safety, or financial advice. Estimates, reports, imported records, and automated suggestions must be reviewed by a qualified person before they are relied upon.</p>
            </section>

            <section>
                <h2>Service changes, suspension, and termination</h2>
                <p>We may update features, correct errors, perform maintenance, or suspend access needed to protect users and the service. Either party may end use of the service subject to any separate written order or subscription terms. On termination, administrators should request or complete available exports. We may retain limited records as described in the Privacy Policy.</p>
            </section>

            <section>
                <h2>Disclaimers and limitation of liability</h2>
                <p>To the maximum extent permitted by law, the service is provided "as is" and "as available" without warranties of uninterrupted operation or fitness for a particular purpose. Evergrove Software is not liable for indirect, incidental, special, consequential, or punitive damages, lost profits, lost business, or loss caused by third-party services. Any direct liability is limited to amounts paid for the affected service during the three months before the event giving rise to the claim.</p>
            </section>

            <section>
                <h2>General terms</h2>
                <p>These terms and any signed service order are the agreement between you and Evergrove Software for the service. If a provision cannot be enforced, the remaining provisions continue. South Carolina law governs these terms without regard to conflict-of-law rules, unless applicable law requires otherwise.</p>
            </section>
        @endif

        <section class="eg-legal-contact">
            <h2>Contact us</h2>
            <p>Questions, privacy requests, data export requests, or QuickBooks disconnection and deletion requests can be sent to <a href="mailto:{{ $contactEmail }}">{{ $contactEmail }}</a>.</p>
        </section>
    </main>

    <footer class="eg-legal-footer">
        <span>&copy; {{ now()->year }} Evergrove Software LLC</span>
        <a href="{{ route('legal.support') }}">Support</a>
        <a href="{{ route('legal.privacy') }}">Privacy</a>
        <a href="{{ route('legal.terms') }}">Terms</a>
        <a href="{{ route('legal.sms') }}">SMS terms</a>
    </footer>
</body>
</html>

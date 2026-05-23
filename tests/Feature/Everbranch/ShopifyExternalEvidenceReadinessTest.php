<?php

beforeEach(function (): void {
    config()->set('commercial.billing_readiness.checkout_active', false);
    config()->set('commercial.billing_readiness.lifecycle_mutations_enabled', false);
});

function shopifyExternalEvidencePacket(): string
{
    return (string) file_get_contents(base_path('docs/operations/evidence/shopify/2026-05-21/README.md'));
}

function shopifyScopeBrandingDecisionRecord(): string
{
    return (string) file_get_contents(base_path('docs/operations/shopify-scope-branding-decision-record.md'));
}

function shopifyExternalReadinessAudit(): string
{
    return (string) file_get_contents(base_path('docs/operations/everbranch-shopify-readiness-audit.md'));
}

function shopifyEvidenceFile(string $filename): string
{
    return (string) file_get_contents(base_path("docs/operations/evidence/shopify/2026-05-21/{$filename}"));
}

test('dated shopify evidence packet exists and separates captured partial evidence from pending evidence', function (): void {
    expect(file_exists(base_path('docs/operations/evidence/shopify/2026-05-21/README.md')))->toBeTrue();

    $packet = shopifyExternalEvidencePacket();

    expect($packet)->toContain('PR 18 partial external evidence captured')
        ->and($packet)->toContain('shopify version -> 3.92.1')
        ->and($packet)->toContain('shopify app info --path . --client-id 197d01d6597c938c96b3b35fae6a087c --no-color')
        ->and($packet)->toContain('No `shopify app deploy` was run.')
        ->and($packet)->toContain('No app version was created or released.')
        ->and($packet)->toContain('No privacy webhook trigger was sent.')
        ->and($packet)->toContain('Shopify Partner/dev dashboard app name')
        ->and($packet)->toContain('Modern Forestry Backstage')
        ->and($packet)->toContain('modernforestrybackstage')
        ->and($packet)->toContain('modernforestry.myshopify.com')
        ->and($packet)->toContain('Everbranch naming is not expected inside the Partner Dashboard yet')
        ->and($packet)->toContain('Partner Dashboard | Pending')
        ->and($packet)->toContain('app-proxy-evidence.md')
        ->and($packet)->toContain('scope-review-evidence.md')
        ->and($packet)->toContain('Direct unsigned canonical route returns `401`')
        ->and($packet)->toContain('returns `200` JSON')
        ->and($packet)->toContain('Pending live delivery')
        ->and($packet)->toContain('Pending evidence')
        ->and($packet)->toContain('Current packet has no screenshots, live Partner Dashboard output, live deploy output, live webhook delivery output, or dev-store install logs.')
        ->and($packet)->toContain('It does include read-only CLI app-info evidence and live app-proxy health evidence.')
        ->and($packet)->not->toContain('External verification complete')
        ->and($packet)->not->toContain('Partner Dashboard evidence complete')
        ->and($packet)->not->toContain('Dev-store install evidence complete');
});

test('shopify evidence packet contains required capture files with honest status markers', function (): void {
    $files = [
        'evidence-summary.md',
        'cli-evidence.md',
        'partner-dashboard-evidence.md',
        'dev-store-install-evidence.md',
        'app-proxy-evidence.md',
        'privacy-webhook-delivery-evidence.md',
        'scope-review-evidence.md',
        'screenshot-manifest.md',
        'operator-checklist.md',
    ];

    foreach ($files as $file) {
        expect(file_exists(base_path("docs/operations/evidence/shopify/2026-05-21/{$file}")))->toBeTrue();
    }

    expect(shopifyEvidenceFile('evidence-summary.md'))->toContain('partial external evidence captured')
        ->and(shopifyEvidenceFile('cli-evidence.md'))->toContain('Status: captured for read-only CLI discovery')
        ->and(shopifyEvidenceFile('cli-evidence.md'))->toContain('Modern Forestry Backstage')
        ->and(shopifyEvidenceFile('cli-evidence.md'))->toContain('modernforestry.myshopify.com')
        ->and(shopifyEvidenceFile('partner-dashboard-evidence.md'))->toContain('Status: pending operator screenshot/manual verification')
        ->and(shopifyEvidenceFile('dev-store-install-evidence.md'))->toContain('Status: pending operator execution')
        ->and(shopifyEvidenceFile('app-proxy-evidence.md'))->toContain('Status: captured partial live storefront evidence')
        ->and(shopifyEvidenceFile('app-proxy-evidence.md'))->toContain('http_code=200')
        ->and(shopifyEvidenceFile('privacy-webhook-delivery-evidence.md'))->toContain('Status: pending operator approval')
        ->and(shopifyEvidenceFile('scope-review-evidence.md'))->toContain('Status: captured initial code-search evidence; final scope decision remains pending')
        ->and(shopifyEvidenceFile('screenshot-manifest.md'))->toContain('Status: pending operator screenshot capture')
        ->and(shopifyEvidenceFile('operator-checklist.md'))->toContain('Status: pending operator execution')
        ->and(shopifyEvidenceFile('evidence-summary.md'))->toContain('Shopify app name was not changed.')
        ->and(shopifyEvidenceFile('evidence-summary.md'))->toContain('Shopify Billing was not activated.');
});

test('shopify screenshot manifest and operator checklist define remaining non mutating evidence pack', function (): void {
    $manifest = shopifyEvidenceFile('screenshot-manifest.md');
    $checklist = shopifyEvidenceFile('operator-checklist.md');
    $summary = shopifyEvidenceFile('evidence-summary.md');

    foreach ([
        '01-partner-app-overview.png',
        '02-partner-app-urls-redirects.png',
        '03-partner-app-proxy.png',
        '04-partner-app-scopes.png',
        '05-partner-webhooks-privacy.png',
        '06-partner-embedded-app-setting.png',
        '07-partner-billing-status.png',
        '08-dev-store-installed-apps.png',
        '09-embedded-app-open.png',
        '10-app-proxy-health-primary-domain.png',
        '11-privacy-webhook-event-row.png',
        '12-scope-review-notes.png',
    ] as $screenshot) {
        expect($manifest)->toContain($screenshot)
            ->and($checklist)->toContain($screenshot)
            ->and($summary)->toContain($screenshot);
    }

    expect($manifest)->toContain('Do not run `shopify app deploy`')
        ->and($manifest)->toContain('Do not run `shopify app release`')
        ->and($manifest)->toContain('Do not run `shopify app webhook trigger`')
        ->and($manifest)->toContain('Do not run `shopify app dev`')
        ->and($checklist)->toContain('Stop if the Partner Dashboard app identity does not match these values. Do not deploy or release.')
        ->and($checklist)->toContain('Operator must explicitly approve the exact command before it is run.')
        ->and($checklist)->toContain('Privacy webhook evidence remains pending until rows exist.')
        ->and($summary)->toContain('Deploy/release remains blocked until the operator explicitly approves the exact command.')
        ->and(shopifyEvidenceFile('app-proxy-evidence.md'))->toContain('https://theforestrystudio.com/apps/forestry/health')
        ->and(shopifyEvidenceFile('app-proxy-evidence.md'))->toContain('https://modernforestry.myshopify.com/apps/forestry/health` redirects to the primary domain')
        ->and($summary)->not->toContain('Partner Dashboard evidence complete')
        ->and($summary)->not->toContain('External verification complete');
});

test('scope and branding decision record captures current app identity and scopes', function (): void {
    expect(file_exists(base_path('docs/operations/shopify-scope-branding-decision-record.md')))->toBeTrue();

    $record = shopifyScopeBrandingDecisionRecord();

    expect($record)->toContain('Modern Forestry Backstage')
        ->and($record)->toContain('modernforestrybackstage')
        ->and($record)->toContain('modernforestry.myshopify.com')
        ->and($record)->toContain('Confirmed current target app for internal/alpha evidence')
        ->and($record)->toContain('Use `Modern Forestry Backstage` when looking for the app in the Shopify Partner/dev dashboard.')
        ->and($record)->toContain('Everbranch remains the platform/product direction, but the public Shopify App Store app branding is not decided yet.')
        ->and($record)->toContain('197d01d6597c938c96b3b35fae6a087c')
        ->and($record)->toContain('customer_read_customers')
        ->and($record)->toContain('read_orders')
        ->and($record)->toContain('write_themes')
        ->and($record)->toContain('Runtime default from `config/services.php`')
        ->and($record)->toContain('Decision: pending')
        ->and($record)->toContain('PR 18 Scope Evidence Captured')
        ->and($record)->toContain('No Shopify app deploy/release was run.')
        ->and($record)->toContain('Do not change scopes in PR 18')
        ->and($record)->toContain('Keep the current TOML app name/handle unchanged in PR 18')
        ->and($record)->not->toContain('App name | `Everbranch`');
});

test('shopify readiness audit links evidence packet and decision record without claiming external completion', function (): void {
    $audit = shopifyExternalReadinessAudit();

    expect($audit)->toContain('docs/operations/evidence/shopify/2026-05-21/README.md')
        ->and($audit)->toContain('docs/operations/shopify-scope-branding-decision-record.md')
        ->and($audit)->toContain('No Shopify app deploy/release or webhook trigger was run in PR 15 or PR 18')
        ->and($audit)->toContain('The Shopify Partner/dev dashboard app to use is currently named `Modern Forestry Backstage`.')
        ->and($audit)->toContain('Use `Modern Forestry Backstage`, not Everbranch, when looking in Shopify for the current evidence pass.')
        ->and($audit)->toContain('PR 18 evidence capture')
        ->and($audit)->toContain('Live app proxy health was partially captured')
        ->and($audit)->toContain('Explicit pending evidence files for Partner Dashboard screenshots, install/reinstall, and live privacy webhook delivery.')
        ->and($audit)->toContain('Partner Dashboard screenshots are still pending')
        ->and($audit)->toContain('Dev-store privacy webhook delivery rows in production/staging `shopify_privacy_webhook_events` are still pending')
        ->and($audit)->toContain('No scopes were changed')
        ->and($audit)->not->toContain('Partner Dashboard verification complete')
        ->and($audit)->not->toContain('Live dev-store evidence complete');
});

test('billing remains disabled while external evidence and scope decisions are pending', function (): void {
    expect(config('commercial.billing_readiness.checkout_active'))->toBeFalse()
        ->and(config('commercial.billing_readiness.lifecycle_mutations_enabled'))->toBeFalse()
        ->and((string) file_get_contents(base_path('shopify.app.toml')))->not->toContain('billing');
});

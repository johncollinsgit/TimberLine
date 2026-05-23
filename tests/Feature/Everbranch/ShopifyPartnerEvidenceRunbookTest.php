<?php

function shopifyPartnerEvidenceRunbook(): string
{
    return (string) file_get_contents(base_path('docs/operations/shopify-partner-dashboard-evidence-runbook.md'));
}

test('shopify partner evidence runbook captures canonical app configuration values', function (): void {
    $runbook = shopifyPartnerEvidenceRunbook();

    expect($runbook)->toContain('https://app.theeverbranch.com/shopify/app')
        ->and($runbook)->toContain('https://app.theeverbranch.com/shopify/callback/retail')
        ->and($runbook)->toContain('https://app.theeverbranch.com/shopify/callback/wholesale')
        ->and($runbook)->toContain('https://app.theeverbranch.com/shopify/marketing/v1')
        ->and($runbook)->toContain('https://app.theeverbranch.com/webhooks/shopify/customers/data-request')
        ->and($runbook)->toContain('https://app.theeverbranch.com/webhooks/shopify/customers/redact')
        ->and($runbook)->toContain('https://app.theeverbranch.com/webhooks/shopify/shop/redact')
        ->and($runbook)->toContain('Embedded app')
        ->and($runbook)->toContain('Billing')
        ->and($runbook)->toContain('Disabled/not active for now')
        ->and($runbook)->toContain('Modern Forestry Backstage')
        ->and($runbook)->toContain('modernforestrybackstage');
});

test('shopify partner evidence runbook includes cli deployment and webhook trigger commands', function (): void {
    $runbook = shopifyPartnerEvidenceRunbook();

    expect($runbook)->toContain('shopify app deploy')
        ->and($runbook)->toContain('--allow-updates')
        ->and($runbook)->toContain('shopify app webhook trigger')
        ->and($runbook)->toContain('--topic customers/data_request')
        ->and($runbook)->toContain('--topic customers/redact')
        ->and($runbook)->toContain('--topic shop/redact')
        ->and($runbook)->toContain('Never commit or paste the secret into this repo');
});

test('shopify partner evidence runbook includes manual partner dashboard and privacy review requirements', function (): void {
    $runbook = shopifyPartnerEvidenceRunbook();

    expect($runbook)->toContain('Partner Dashboard Manual Checklist')
        ->and($runbook)->toContain('Compliance/privacy webhooks')
        ->and($runbook)->toContain('Dev-Store Test Evidence')
        ->and($runbook)->toContain('modernforestry.myshopify.com')
        ->and($runbook)->toContain('Privacy Manual Review Runbook')
        ->and($runbook)->toContain('shopify_privacy_webhook_events')
        ->and($runbook)->toContain('do not delete or anonymize until an approved deletion/anonymization policy exists')
        ->and($runbook)->toContain('Partner Dashboard and Shopify CLI deployment evidence is pending');
});

test('shopify partner evidence runbook includes scope review and evidence storage conventions', function (): void {
    $runbook = shopifyPartnerEvidenceRunbook();

    expect($runbook)->toContain('Scope Review Runbook')
        ->and($runbook)->toContain('Do not change scopes in PR 12')
        ->and($runbook)->toContain('scope matrix')
        ->and($runbook)->toContain('docs/operations/evidence/shopify/YYYY-MM-DD/')
        ->and($runbook)->toContain('Do not create empty evidence folders');
});

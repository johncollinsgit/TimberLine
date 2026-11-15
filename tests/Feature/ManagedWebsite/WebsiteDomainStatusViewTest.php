<?php

use App\Models\Tenant;
use App\Models\TenantSite;
use App\Models\TenantSiteDomain;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\ViewErrorBag;

test('domain controls pin the original workspace and render a fresh recovery GET', function (string $status): void {
    Http::preventStrayRequests();
    $tenant = (new Tenant)->forceFill(['id' => 987, 'slug' => 'fictional-domain-owner']);
    $site = (new TenantSite)->forceFill(['id' => 5, 'tenant_id' => 987, 'status' => 'draft', 'public_enabled' => false]);
    $domain = (new TenantSiteDomain)->forceFill(['id' => 7, 'tenant_id' => 987, 'tenant_site_id' => 5, 'hostname' => 'www.fictional.example', 'status' => $status]);
    $html = view('managed-website.domain-guide', [
        'tenant' => $tenant, 'site' => $site, 'siteDomains' => collect([$domain]),
        'platformUrl' => 'https://fictional.theeverbranch.example',
        'domainsEnabled' => true, 'automaticDomains' => true,
        'domainConnectionRecords' => collect(), 'errors' => new ViewErrorBag,
    ])->render();

    $dom = new DOMDocument;
    $previous = libxml_use_internal_errors(true);
    try {
        $dom->loadHTML($html);
    } finally {
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
    }
    $xpath = new DOMXPath($dom);
    $links = $xpath->query('//*[@data-domain-recovery]/a');
    if ($status !== 'active') {
        expect($links->length)->toBe(1);
        $href = $links->item(0)->getAttribute('href');
        expect($href)->toBe(route('managed-website.index', ['tenant' => $tenant->slug]))
            ->and(parse_url($href, PHP_URL_FRAGMENT))->toBeNull()
            ->and($xpath->query('//*[@data-domain-status-url]')->item(0)->getAttribute('data-domain-status-url'))
            ->toBe(route('managed-website.domains.status', ['domain' => $domain, 'tenant' => $tenant->slug]));
    }
    foreach ($xpath->query('//form') as $form) {
        parse_str((string) parse_url($form->getAttribute('action'), PHP_URL_QUERY), $query);
        expect($query['tenant'] ?? null)->toBe($tenant->slug);
    }
    Http::assertNothingSent();
})->with(['pending', 'verified', 'active']);

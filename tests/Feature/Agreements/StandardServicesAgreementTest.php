<?php

use App\Models\Tenant;
use App\Services\Agreements\AgreementDocumentRenderer;
use App\Services\Agreements\AgreementManagementService;
use App\Services\Agreements\AgreementTerminationService;
use App\Services\Agreements\StandardServicesAgreementTemplate;

beforeEach(function () {
    $this->withoutVite();
});

test('standard agreement freezes canonical flat prices and requires confirmed scope while preserving the existing separately approved work-order rate', function () {
    $tenant = Tenant::create(['name' => 'Avery Knifeworks', 'slug' => 'avery-knifeworks']);
    $payload = app(StandardServicesAgreementTemplate::class)->build($tenant);
    $html = app(AgreementDocumentRenderer::class)->render($payload);
    expect($html)->toContain('$299.00', '$499.00', '$50.00', 'Written notice before the next monthly renewal')->not->toContain('$89', '$149', '30 days’ notice');
    expect($payload['subscription']['initial_invoice_amount_cents'])->toBe(79800)
        ->and($payload['subscription']['canonical_plan_key'])->toBeNull()
        ->and($payload['subscription']['initial_collection_method'])->toBe('manual_invoice');
    $service = app(AgreementManagementService::class);
    $first = $service->prepareStandard($tenant, null);
    $hash = $first->currentVersion->content_hash;
    expect($service->prepareStandard($tenant, null)->id)->toBe($first->id)
        ->and($first->versions()->count())->toBe(1);
    $first->update(['status' => 'sent']);
    config()->set('module_catalog.offers.standard.monthly_cents', 99900);
    expect($service->prepareStandard($tenant, null)->currentVersion->content_hash)->toBe($hash);
    $first->update(['status' => 'accepted']);
    config()->set('module_catalog.offers.standard.monthly_cents', 99900);
    expect($service->prepareStandard($tenant, null)->currentVersion->content_hash)->toBe($hash);
});

test('standard cancellation requires the verified paid-period end instead of imposing the legacy 30-day default', function () {
    $tenant = Tenant::create(['name' => 'Avery', 'slug' => 'avery-knifeworks']);
    $agreement = app(AgreementManagementService::class)->prepareStandard($tenant, null);
    expect(fn () => app(AgreementTerminationService::class)->request($agreement, null))->toThrow(InvalidArgumentException::class, 'current paid service period');
});

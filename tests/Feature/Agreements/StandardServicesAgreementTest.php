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
        ->and($payload['subscription']['initial_collection_method'])->toBe('manual_invoice')
        ->and($payload['subscription']['usage_policy']['initial_usage_charge_cents'])->toBe(0)
        ->and($payload['subscription']['usage_policy']['overage_rates_micros']['email'])->toBe(1490)
        ->and($payload['subscription']['usage_policy']['ai_provider_cost_multiplier'])->toBe(2)
        ->and($payload['subscription']['usage_policy']['voice_provider_cost_multiplier'])->toBe(2)
        ->and($payload['subscription']['usage_policy']['memory_feature_access_cents'])->toBe(0);
    expect($html)->not->toContain('2×', '2x actual')->toContain('Everbranch model rates');
    expect($html)->toContain('Prepaid balance', 'not billed again', 'no usage fees', 'planned release', '$1.49');
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

test('Avery freezes approved exit support and jurisdiction terms without activating billing', function () {
    $tenant = Tenant::create(['name' => 'Avery Knifeworks', 'slug' => 'avery-knifeworks']);
    $payload = app(StandardServicesAgreementTemplate::class)->build($tenant);
    $html = app(AgreementDocumentRenderer::class)->render($payload);
    expect(app(\App\Services\Agreements\AveryAgreementTerms::class)->apply($payload))->toBe($payload);
    expect($payload['scope']['support_policy'])->toMatchArray(['requests_per_calendar_month' => 2, 'revision_rounds_per_request' => 1, 'acknowledgment_business_days' => 3, 'rollover' => false]);
    expect($payload['scope']['capacity_commitment'])->toMatchArray(['named_users' => 5, 'activation_requires_verified_capacity' => true]);
    expect($payload['termination']['settlement'])->toMatchArray(['authorized_incurred_usage_remains_payable' => true, 'already_deducted_usage_billed_again' => false, 'purchased_credit_refundable' => true, 'promotional_credit_cash_refundable' => false, 'automatic_card_collection_authorized' => false]);
    expect($payload['termination']['export'])->toMatchArray(['request_during_notice' => true, 'request_after_termination_days' => 30, 'included_standard_packages' => 1, 'delivery_target_business_days' => 10, 'basic_exit_fee_cents' => 0]);
    expect($html)->toContain('Pickens County, South Carolina', 'Final usage billing', '15 calendar days after delivery', 'no cash-refund value', 'within 30 calendar days after termination', 'Shared platform numbers', '10 business days to cure', 'gross negligence', 'no separate exit fee', 'three business days');
    expect($html)->not->toContain('2×', '2x actual', 'Reasonable export and handoff arrangements will be agreed on cancellation');
    expect($payload['subscription']['initial_invoice_amount_cents'])->toBe(79800)->and($payload['subscription']['usage_policy']['initial_usage_charge_cents'])->toBe(0)->and($payload['subscription']['usage_policy']['initial_preload_cents'])->toBe(0)->and($payload['subscription']['canonical_plan_key'])->toBeNull()->and($payload['termination']['early_termination_fee_cents'])->toBe(0);
});

test('negotiated Avery clauses never change another Standard customer or an issued version silently', function () {
    $other = Tenant::create(['name' => 'Other Standard', 'slug' => 'other-standard']);
    $payload = app(StandardServicesAgreementTemplate::class)->build($other);
    expect($payload['scope'])->not->toHaveKey('support_policy')->and($payload['termination'])->not->toHaveKey('settlement');
    $tenant = Tenant::create(['name' => 'Avery Knifeworks', 'slug' => 'avery-knifeworks']);
    $service = app(AgreementManagementService::class);
    $agreement = $service->prepareStandard($tenant, null);
    $agreement->update(['status' => 'viewed']);
    $original = $agreement->currentVersion->content_hash;
    expect($service->prepareStandard($tenant, null, 'Different scope')->currentVersion->content_hash)->toBe($original);
    $revised = $service->prepareStandard($tenant, null, 'Reviewed additional scope', reviseIssued: true);
    expect($revised->versions()->count())->toBe(2)->and($revised->currentVersion->scope_payload['support_policy']['requests_per_calendar_month'])->toBe(2);
    $revised->update(['status' => 'active']);
    $acceptedHash = $revised->currentVersion->content_hash;
    expect($service->prepareStandard($tenant, null, 'Later proposed change', reviseIssued: true)->currentVersion->content_hash)->toBe($acceptedHash);
});

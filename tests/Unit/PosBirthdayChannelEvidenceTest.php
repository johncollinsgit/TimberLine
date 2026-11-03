<?php

use App\Services\Pos\PosBirthdayChannelEvidence;
use Carbon\CarbonImmutable;

uses(Tests\TestCase::class);

function birthdayEvidenceFixture(): array
{
    $identifiers = ['tenant_id' => 7, 'issuance_id' => 9, 'cycle_year' => 2026, 'store_key' => 'fictional',
        'discount_node_id' => 'gid://shopify/DiscountCodeNode/123'];

    return [$identifiers, [...$identifiers, 'retrieved_at' => '2026-11-01T12:00:00Z', 'active' => false, 'usage_count' => 0],
        CarbonImmutable::parse('2026-11-01T12:00:30Z')];
}

it('keeps cross-channel birthday evidence default off and never makes a benefit redeemable', function (): void {
    [$issuance, $observation, $now] = birthdayEvidenceFixture();
    $service = app(PosBirthdayChannelEvidence::class);
    config(['pos_live.birthday_channel_evidence_enabled' => false]);
    expect($service->classify($issuance, $observation, $now))->toBe(['state' => 'disabled', 'redeemable' => false]);
    config(['pos_live.birthday_channel_evidence_enabled' => true]);
    expect($service->classify($issuance, $observation, $now))->toBe(['state' => 'channel_fence_required', 'redeemable' => false]);
});

it('routes mismatched, stale, active, used, and uncertain provider evidence to review', function (): void {
    config(['pos_live.birthday_channel_evidence_enabled' => true]);
    [$issuance, $observation, $now] = birthdayEvidenceFixture();
    $service = app(PosBirthdayChannelEvidence::class);
    foreach (['tenant_id' => 8, 'issuance_id' => 10, 'cycle_year' => 2027, 'store_key' => 'other', 'discount_node_id' => 'other'] as $field => $value) {
        expect($service->classify($issuance, [...$observation, $field => $value], $now)['state'])->toBe('identity_review_required');
    }
    foreach ([['retrieved_at' => '2026-11-01T11:58:00Z'], ['retrieved_at' => '2026-11-01T12:01:00Z'],
        ['retrieved_at' => 'not-a-time'], ['usage_count' => null], ['usage_count' => '0'], ['active' => null]] as $bad) {
        expect($service->classify($issuance, [...$observation, ...$bad], $now)['state'])->toBe('provider_review_required');
    }
    expect($service->classify($issuance, [...$observation, 'active' => true], $now)['state'])->toBe('online_code_active')
        ->and($service->classify($issuance, [...$observation, 'usage_count' => 1], $now)['state'])->toBe('usage_review_required')
        ->and($service->classify([...$issuance, 'pos_consumed' => true], $observation, $now)['state'])->toBe('usage_review_required');
});

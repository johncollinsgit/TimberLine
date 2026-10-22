<?php

use App\Enterprise\GmailConnections;
use App\Enterprise\Trial\ReviewerTrial;
use App\Enterprise\Trial\TrialStore;
use App\Enterprise\Trial\TrialTransport;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    $this->withoutVite();
    Http::preventStrayRequests();
    config(['app.debug' => false, 'app.url' => 'https://app.theeverbranch.com',
        'tenancy.domains.canonical.landlord_host' => 'app.theeverbranch.com',
        'tenancy.landlord.hosts' => ['app.theeverbranch.com'], 'session.driver' => 'database',
        'enterprise.runtime' => false, 'enterprise.reviewer_demo_enabled' => true,
        'enterprise.reviewer_trial_destination' => '', 'enterprise.reviewer_trial_destination_approved' => false]);
    app('session')->forgetDrivers();
    foreach ([TrialStore::class, ReviewerTrial::class, TrialTransport::class, GmailConnections::class] as $service) {
        app()->bind($service, fn () => throw new LogicException('Public presentation must not resolve operational services.'));
    }
});

afterEach(function (): void {
    Http::assertNothingSent();
});

function reviewerTrialEntryPage(object $test, string $url = 'https://app.theeverbranch.com/platform/demos/lees-mcrae'): string
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    try {
        $response = $test->get($url)->assertOk();
        $queries = DB::getQueryLog();
    } finally {
        DB::disableQueryLog();
        DB::flushQueryLog();
    }
    expect($queries)->toBe([])->and($response->headers->getCookies())->toBe([])
        ->and($response->headers->get('Content-Security-Policy'))->toContain("connect-src 'none'", "form-action 'none'", "base-uri 'none'");

    return $response->getContent();
}

test('approved root and prefixed public trial entry is server supplied without SQL cookies or provider resolution', function (): void {
    foreach (['/enterprise/trial', '/enterprise-campus/enterprise/trial', '/clients/synthetic-campus/enterprise/trial'] as $path) {
        $destination = 'https://app.theeverbranch.com'.$path;
        config(['enterprise.reviewer_trial_destination_approved' => true, 'enterprise.reviewer_trial_destination' => $destination]);
        expect(reviewerTrialEntryPage($this))->toContain('"trial":'.json_encode($destination, JSON_THROW_ON_ERROR));
    }
    expect(config('enterprise.trial.enabled'))->toBeFalse()->and(config('enterprise.gmail.connection_enabled'))->toBeFalse();
});

test('public trial entry stays disconnected unless both exact destination and explicit approval exist', function (): void {
    expect(reviewerTrialEntryPage($this))->not->toContain('"trial":');
    config(['enterprise.reviewer_trial_destination' => 'https://app.theeverbranch.com/enterprise-campus/enterprise/trial']);
    expect(reviewerTrialEntryPage($this))->not->toContain('"trial":');
    config(['enterprise.reviewer_trial_destination_approved' => true, 'enterprise.reviewer_trial_destination' => '']);
    expect(reviewerTrialEntryPage($this))->not->toContain('"trial":');
    // A direct string config value is not an explicit boolean approval.
    config(['enterprise.reviewer_trial_destination_approved' => 'true', 'enterprise.reviewer_trial_destination' => 'https://app.theeverbranch.com/enterprise/trial']);
    expect(reviewerTrialEntryPage($this))->not->toContain('"trial":');
});

test('public trial destination refuses alternate origin ports credentials queries encoded traversal and noncanonical paths', function (): void {
    config(['enterprise.reviewer_trial_destination_approved' => true]);
    foreach ([null, [], '/enterprise/trial', '//app.theeverbranch.com/enterprise/trial',
        'http://app.theeverbranch.com/enterprise/trial', 'https://elsewhere.example.invalid/enterprise/trial',
        'https://app.theeverbranch.com.evil.invalid/enterprise/trial', 'https://app.theeverbranch.com:443/enterprise/trial',
        'https://app.theeverbranch.com:8443/enterprise/trial', 'https://user:password@app.theeverbranch.com/enterprise/trial',
        'https://app.theeverbranch.com/enterprise/trial?', 'https://app.theeverbranch.com/enterprise/trial?reviewer_code=private',
        'https://app.theeverbranch.com/enterprise/trial#private', 'https://app.theeverbranch.com//enterprise/trial',
        'https://app.theeverbranch.com/enterprise-campus//enterprise/trial', 'https://app.theeverbranch.com/../enterprise/trial',
        'https://app.theeverbranch.com/campus/./enterprise/trial', 'https://app.theeverbranch.com/%65nterprise/trial',
        'https://app.theeverbranch.com/campus%2fother/enterprise/trial', 'https://app.theeverbranch.com/campus\\other/enterprise/trial',
        'https://app.theeverbranch.com/enterprise/trial/', 'https://app.theeverbranch.com/enterprise/trial/task/opaque',
    ] as $destination) {
        config(['enterprise.reviewer_trial_destination' => $destination]);
        expect(reviewerTrialEntryPage($this))->not->toContain('"trial":');
    }
});

test('public request query cannot override an approved destination and different request schemes or ports omit it', function (): void {
    $destination = 'https://app.theeverbranch.com/enterprise-campus/enterprise/trial';
    config(['enterprise.reviewer_trial_destination_approved' => true, 'enterprise.reviewer_trial_destination' => $destination]);
    foreach (['trial=https%3A%2F%2Fevil.invalid', 'trial_destination=%2Fenterprise%2Ftrial', 'reviewer_trial_destination_approved=true'] as $query) {
        $this->get('https://app.theeverbranch.com/platform/demos/lees-mcrae?'.$query)->assertNotFound()->assertDontSee('"trial":', false);
    }
    expect(reviewerTrialEntryPage($this, 'http://app.theeverbranch.com/platform/demos/lees-mcrae'))->not->toContain('"trial":');
    expect(reviewerTrialEntryPage($this, 'https://app.theeverbranch.com:8443/platform/demos/lees-mcrae'))->not->toContain('"trial":');
    config(['app.url' => 'https://app.theeverbranch.com/private']);
    expect(reviewerTrialEntryPage($this))->not->toContain('"trial":');
});

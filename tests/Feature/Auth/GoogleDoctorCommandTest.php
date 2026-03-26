<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config()->set('services.google.enabled', true);
    config()->set('services.google.client_id', 'login-client-id');
    config()->set('services.google.client_secret', 'login-client-secret');
    config()->set('services.google.redirect', 'https://backstage.example.com/auth/google/callback');

    config()->set('services.google_gbp.client_id', 'gbp-client-id');
    config()->set('services.google_gbp.client_secret', 'gbp-client-secret');
});

test('auth doctor google exits non-zero when required login config is missing', function () {
    config()->set('services.google.client_id', '');
    config()->set('services.google.client_secret', '');
    config()->set('services.google.redirect', '');

    $this->artisan('auth:doctor-google')
        ->expectsOutputToContain('error=services.google.client_id is empty.')
        ->expectsOutputToContain('error=services.google.client_secret is empty.')
        ->expectsOutputToContain('error=services.google.redirect is empty.')
        ->assertExitCode(1);
});

test('auth doctor google token smoke classifies invalid_grant as accepted credentials', function () {
    Http::fake([
        'https://oauth2.googleapis.com/token' => Http::response([
            'error' => 'invalid_grant',
            'error_description' => 'Malformed auth code.',
        ], 400),
    ]);

    $this->artisan('auth:doctor-google', ['--token-smoke' => true])
        ->expectsOutputToContain('token_smoke.failure_class=invalid_grant')
        ->expectsOutputToContain('token_smoke.result=credentials_accepted_invalid_grant_expected')
        ->assertExitCode(0);
});

test('auth doctor google token smoke fails on invalid_client', function () {
    Http::fake([
        'https://oauth2.googleapis.com/token' => Http::response([
            'error' => 'invalid_client',
            'error_description' => 'The provided client secret is invalid.',
        ], 401),
    ]);

    $this->artisan('auth:doctor-google', ['--token-smoke' => true])
        ->expectsOutputToContain('token_smoke.failure_class=invalid_client')
        ->expectsOutputToContain('error=Token smoke classified as invalid_client (client ID/secret pair is rejected).')
        ->assertExitCode(1);
});

test('auth doctor google fails when login credentials match gbp credentials', function () {
    config()->set('services.google_gbp.client_id', 'login-client-id');
    config()->set('services.google_gbp.client_secret', 'login-client-secret');

    $this->artisan('auth:doctor-google')
        ->expectsOutputToContain('error=Google login client_id matches GOOGLE_GBP client_id; keep login and GBP credentials distinct.')
        ->expectsOutputToContain('error=Google login client_secret matches GOOGLE_GBP client_secret; keep login and GBP credentials distinct.')
        ->assertExitCode(1);
});

test('auth doctor google output never includes raw secret values', function () {
    config()->set('services.google.client_secret', 'super-secret-raw-value');
    config()->set('services.google_gbp.client_secret', 'super-secret-raw-value-2');

    Artisan::call('auth:doctor-google');
    $output = Artisan::output();

    expect($output)->not->toContain('super-secret-raw-value')
        ->and($output)->not->toContain('super-secret-raw-value-2');
});


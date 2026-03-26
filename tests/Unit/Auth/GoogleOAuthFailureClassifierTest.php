<?php

use App\Support\Auth\GoogleOAuthFailureClassifier;

test('google oauth failure classifier maps invalid_client from error code', function () {
    $result = GoogleOAuthFailureClassifier::classify('invalid_client', 'The provided client secret is invalid.');

    expect($result)->toBe(GoogleOAuthFailureClassifier::INVALID_CLIENT);
});

test('google oauth failure classifier maps invalid_grant from error description', function () {
    $result = GoogleOAuthFailureClassifier::classify('', 'Malformed auth code. invalid_grant');

    expect($result)->toBe(GoogleOAuthFailureClassifier::INVALID_GRANT);
});

test('google oauth failure classifier maps redirect uri mismatch from throwable message', function () {
    $result = GoogleOAuthFailureClassifier::classify(
        errorCode: '',
        errorMessage: '',
        exception: new RuntimeException('Google returned redirect_uri_mismatch for this callback'),
    );

    expect($result)->toBe(GoogleOAuthFailureClassifier::REDIRECT_URI_MISMATCH);
});

test('google oauth failure classifier prioritizes state error', function () {
    $result = GoogleOAuthFailureClassifier::classify(
        errorCode: 'invalid_client',
        errorMessage: 'The provided client secret is invalid.',
        stateError: true,
    );

    expect($result)->toBe(GoogleOAuthFailureClassifier::STATE_ERROR);
});

test('google oauth failure classifier returns unknown for unmatched errors', function () {
    $result = GoogleOAuthFailureClassifier::classify('temporarily_unavailable', 'provider unavailable');

    expect($result)->toBe(GoogleOAuthFailureClassifier::UNKNOWN_OAUTH_FAILURE);
});


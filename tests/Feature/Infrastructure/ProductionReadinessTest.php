<?php

test('production readiness verifies application dependencies without exposing configuration', function (): void {
    config()->set('app.release_id', 'test-release-123');

    $this->get('/ready')
        ->assertOk()
        ->assertExactJson(['status' => 'ok', 'release' => 'test-release-123']);
});

test('production readiness fails closed when required configuration is unavailable', function (): void {
    config()->set('cache.default', '');

    $this->get('/ready')
        ->assertStatus(503)
        ->assertExactJson(['status' => 'unavailable']);
});

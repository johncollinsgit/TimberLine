<?php

use App\Support\Auth\PasswordResetUrlFactory;
use Illuminate\Http\Request;

test('password reset url factory uses current auth request host and scheme', function (): void {
    $factory = app(PasswordResetUrlFactory::class);
    $request = Request::create('http://app.theeverbranch.com/forgot-password', 'POST');

    $url = $factory->make('token123', 'john@example.com', $request);

    expect($url)->toStartWith('http://app.theeverbranch.com/reset-password/token123?email=john%40example.com');
});

test('password reset url factory falls back to flagship host when request is unavailable', function (): void {
    config()->set('tenancy.auth.flagship_hosts', ['app.theeverbranch.com', 'app.theeverbranch.com']);
    config()->set('app.url', 'https://app.theeverbranch.com');

    $factory = app(PasswordResetUrlFactory::class);
    $request = \Mockery::mock(Request::class);
    $request->shouldReceive('getHost')->andReturn('');
    $request->shouldReceive('getScheme')->andReturn('');
    $url = $factory->make('token456', 'jane@example.com', $request);

    expect($url)->toStartWith('https://app.theeverbranch.com/reset-password/token456?email=jane%40example.com');
});

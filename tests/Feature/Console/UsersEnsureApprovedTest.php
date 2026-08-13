<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

test('ensure approved requires an operator and a meaningful reason', function (): void {
    $this->artisan('users:ensure-approved', [
        'email' => 'operator@example.test',
        'password' => 'example-password',
    ])
        ->expectsOutput('A printable 8-500 character reason is required.')
        ->assertFailed();

    expect(User::query()->where('email', 'operator@example.test')->exists())->toBeFalse();
});

test('ensure approved records an accountable audit event without logging the password', function (): void {
    Log::spy();

    $this->artisan('users:ensure-approved', [
        'email' => 'operator@example.test',
        'password' => 'example-password',
        '--name' => 'Approved Operator',
        '--role' => 'manager',
        '--reason' => 'INC-1234 approved access recovery',
        '--actor' => 'github-actions:test-operator',
    ])
        ->expectsOutput('Approved user ensured: operator@example.test')
        ->assertSuccessful();

    $user = User::query()->where('email', 'operator@example.test')->firstOrFail();

    expect($user)
        ->name->toBe('Approved Operator')
        ->role->toBe('manager')
        ->is_active->toBeTrue()
        ->email_verified_at->not->toBeNull();
    expect(Hash::check('example-password', (string) $user->password))->toBeTrue();

    Log::shouldHaveReceived('notice')
        ->once()
        ->with('users.ensure_approved.executed', [
            'actor' => 'github-actions:test-operator',
            'email' => 'operator@example.test',
            'role' => 'manager',
            'reason' => 'INC-1234 approved access recovery',
            'user_id' => $user->id,
        ]);
});

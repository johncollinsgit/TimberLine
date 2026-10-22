<?php

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

beforeEach(function (): void {
    Cache::flush();
    Notification::fake();
});

it('limits reset requests across different addresses at one IP before notifications', function (): void {
    for ($i = 0; $i < 5; $i++) {
        $user = User::factory()->create();
        $this->postJson(route('password.email'), ['email' => $user->email])->assertSuccessful();
    }
    $blocked = User::factory()->create();
    $this->postJson(route('password.email'), ['email' => $blocked->email])->assertStatus(429)->assertHeader('Retry-After');
    Notification::assertNotSentTo($blocked, ResetPassword::class);
    Notification::assertSentTimes(ResetPassword::class, 5);
});

it('limits a normalized reset recipient across IPs before the password broker', function (): void {
    $user = User::factory()->create(['email' => 'fixture@example.test']);
    for ($i = 0; $i < 3; $i++) {
        DB::table('password_reset_tokens')->delete();
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.'.($i + 1)])
            ->postJson(route('password.email'), ['email' => $user->email])->assertSuccessful();
    }
    $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.99'])
        ->postJson(route('password.email'), ['email' => ' FIXTURE@example.test '])->assertStatus(429);
    Notification::assertSentTimes(ResetPassword::class, 3);
    $this->travel(61)->minutes();
    $this->postJson(route('password.email'), ['email' => $user->email])->assertSuccessful();
    Notification::assertSentTimes(ResetPassword::class, 4);
});

it('counts nonexistent recipients and does not throttle reset form reads', function (): void {
    for ($i = 0; $i < 3; $i++) {
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.'.($i + 1)])
            ->postJson(route('password.email'), ['email' => 'missing@example.test'])->assertStatus(422);
    }
    $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.99'])
        ->postJson(route('password.email'), ['email' => 'missing@example.test'])->assertStatus(429);
    $this->get(route('password.request'))->assertOk();
    Notification::assertNothingSent();
});

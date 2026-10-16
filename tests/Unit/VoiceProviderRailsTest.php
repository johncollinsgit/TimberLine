<?php

use App\Services\Voice\CallLifecycle;
use App\Services\Voice\CallRoutingPolicy;
use App\Services\Voice\FakeCallEventAdapter;
use App\Services\Voice\TwilioCallEventAdapter;
use Carbon\CarbonImmutable;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(Tests\TestCase::class);

test('strict Twilio form verification matches the published SDK test vector and never bypasses in testing', function () {
    $adapter = new TwilioCallEventAdapter;
    // Twilio SDK RequestValidatorTest: fixed public test credential/vector.
    $url = 'https://mycompany.com/myapp.php?foo=1&bar=2';
    $form = ['CallSid' => 'CA1234567890ABCDE', 'Caller' => '+14158675309', 'Digits' => '1234', 'From' => '+14158675309', 'To' => '+18005551212'];
    expect($adapter->verifies($url, $form, 'RSOYDt4T1cUTdK1PDd93/VVr8B8=', '12345'))->toBeTrue()
        ->and($adapter->verifies($url.'changed', $form, 'RSOYDt4T1cUTdK1PDd93/VVr8B8=', '12345'))->toBeFalse()
        ->and($adapter->verifies($url, [...$form, 'Digits' => '9999'], 'RSOYDt4T1cUTdK1PDd93/VVr8B8=', '12345'))->toBeFalse()
        ->and($adapter->verifies($url, $form, '', ''))->toBeFalse()
        ->and($adapter->verifies($url, [...$form, 'Repeated' => ['x']], 'bad', '12345'))->toBeFalse()
        ->and($adapter->capabilities()['live_dispatch'])->toBeFalse();
});

test('Twilio events bind the verified account and owned number without trusting caller identity', function () {
    $identity = ['tenant_id' => 17, 'provider' => 'twilio', 'verified' => true, 'active' => true, 'account_id' => 'AC'.str_repeat('1', 32), 'number' => '+12025550100'];
    $form = ['AccountSid' => $identity['account_id'], 'CallSid' => 'CA'.str_repeat('2', 32), 'CallStatus' => 'completed', 'Direction' => 'inbound',
        'To' => $identity['number'], 'From' => '+12025550101', 'SequenceNumber' => '3', 'CallDuration' => '45', 'CallerName' => 'UNTRUSTED CLAIM'];
    $url = 'https://fictional.example/voice/status';
    $sign = function ($input) use ($url) {
        ksort($input);
        $data = $url;
        foreach ($input as $key => $value) {
            $data .= $key.$value;
        }

        return base64_encode(hash_hmac('sha1', $data, 'test-only', true));
    };
    $adapter = new TwilioCallEventAdapter;
    $event = $adapter->event($identity, $url, $form, $sign($form), 'test-only');
    expect($event['tenant_id'])->toBe(17)->and($event['status'])->toBe('completed')->and($event['duration_seconds'])->toBe(45)
        ->and($event['provider_cost_micros'])->toBeNull()->and(json_encode($event))->not->toContain('UNTRUSTED CLAIM', '+12025550101');
    expect(fn () => $adapter->event([...$identity, 'number' => '+12025550109'], $url, $form, $sign($form), 'test-only'))->toThrow(HttpException::class);
    expect(fn () => $adapter->event([...$identity, 'account_id' => 'AC'.str_repeat('9', 32)], $url, $form, $sign($form), 'test-only'))->toThrow(HttpException::class);
});

test('call reducer handles duplicate late and contradictory events without billing or resurrection', function () {
    $adapter = new FakeCallEventAdapter;
    $identity = ['tenant_id' => 17, 'provider' => 'fake', 'account_id' => 'fictional-17', 'simulated' => true];
    $make = fn ($status, $sequence, $duration = null) => $adapter->event($identity, 'fictional://practice', ['call_id' => 'fictional-00000000-0000-4000-8000-000000000000', 'status' => $status, 'sequence' => $sequence, 'duration_seconds' => $duration], 'fictional-only', '');
    $reducer = new CallLifecycle;
    $state = $reducer->apply(null, $make('active', 2));
    expect($reducer->apply($state, $make('active', 2)))->toBe($state);
    $state = $reducer->apply($state, $make('completed', 3, 45));
    expect($reducer->apply($state, $make('ringing', 1))['status'])->toBe('completed')
        ->and($reducer->apply($state, $make('failed', 4))['requires_review'])->toBeTrue()
        ->and($reducer->apply($state, $make('completed', 3, 50))['requires_review'])->toBeTrue()
        ->and($state['duration_seconds'])->toBe(45)->and($state['provider_cost_micros'])->toBeNull();
    expect(fn () => $reducer->apply($state, [...$make('active', 4), 'tenant_id' => 99]))->toThrow(HttpException::class);
});

test('routing uses business timezone holiday exceptions availability and direct-loop protection', function () {
    $router = new CallRoutingPolicy;
    $policy = ['version' => 4, 'timezone' => 'America/New_York', 'hours' => [1 => [['09:00', '17:00']]], 'holidays' => ['2026-10-26' => []],
        'targets' => [['kind' => 'staff', 'user_id' => 8], ['kind' => 'staff', 'user_id' => 8]], 'fallback' => 'receptionist'];
    $route = fn ($at, $available = [8], $ready = true, $rules = null) => $router->route($rules ?? $policy, CarbonImmutable::parse($at), $available, ['+12025550100'], $ready);
    expect($route('2026-10-19T13:00:00Z')['route'])->toBe('ring_group')->and($route('2026-10-19T13:00:00Z')['targets'])->toHaveCount(1)
        ->and($route('2026-10-19T21:00:00Z')['route'])->toBe('voicemail')->and($route('2026-10-26T14:00:00Z')['route'])->toBe('voicemail')
        ->and($route('2026-10-19T15:00:00Z', [])['route'])->toBe('receptionist')->and($route('2026-10-19T15:00:00Z', [], false)['route'])->toBe('voicemail');
    expect(fn () => $route('2026-10-19T15:00:00Z', [], true, [...$policy, 'targets' => [['kind' => 'forward', 'number' => '+12025550100']]]))->toThrow(HttpException::class);
});

test('routing handles both daylight-saving repeats and requires explicit split overnight hours', function () {
    $router = new CallRoutingPolicy;
    $policy = ['version' => 1, 'timezone' => 'America/New_York', 'hours' => [7 => [['01:00', '02:00']]], 'targets' => [], 'fallback' => 'receptionist'];
    foreach (['2026-11-01T05:30:00Z', '2026-11-01T06:30:00Z'] as $at) {
        expect($router->route($policy, CarbonImmutable::parse($at), [], [], true)['route'])->toBe('receptionist');
    }
    expect(fn () => $router->validate([...$policy, 'hours' => [1 => [['22:00', '06:00']]]]))->toThrow(HttpException::class);
    expect(fn () => $router->validate([...$policy, 'hours' => [1 => [['09:00', '12:00'], ['11:00', '17:00']]]]))->toThrow(HttpException::class);
});

<?php

namespace App\Proposals;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/** Same-origin session JSON only. CSRF is deliberately checked even in fixture environments. */
final class ReviewerAiHttpPolicy
{
    public function body(Request $request, array $fields): array
    {
        abort_unless($request->isMethod('POST') && strtolower(trim(explode(';', $request->header('Content-Type', ''))[0])) === 'application/json'
            && strlen($request->getContent()) <= 65536 && $request->query->count() === 0, 400);
        abort_unless($request->hasSession() && is_string($request->header('X-CSRF-TOKEN'))
            && $request->session()->token() !== '' && hash_equals($request->session()->token(), $request->header('X-CSRF-TOKEN')), 419);
        $value = json_decode($request->getContent(), false, 32, JSON_THROW_ON_ERROR);
        abort_unless($value instanceof \stdClass, 422);
        $keys = array_keys(get_object_vars($value));
        sort($keys);
        sort($fields);
        abort_unless($keys === $fields, 422);

        return json_decode($request->getContent(), true, 32, JSON_THROW_ON_ERROR);
    }

    public function rate(Request $request, array $scope, string $action): void
    {
        $limit = match ($action) {
            'preview' => 6, 'confirm' => 3, 'cancel' => 6, 'status' => 20
        };
        $key = 'proposal-ai:'.hash('sha256', $request->getHost().'|'.$request->ip().'|'.$scope['browser_hash'].'|'.$action);
        abort_if(RateLimiter::tooManyAttempts($key, $limit), 429);
        RateLimiter::hit($key, 60);
        // A second IP ceiling prevents unlimited per-session browser resets from bypassing the request bound.
        $ipKey = 'proposal-ai-ip:'.hash('sha256', $request->getHost().'|'.$request->ip());
        abort_if(RateLimiter::tooManyAttempts($ipKey, 40), 429);
        RateLimiter::hit($ipKey, 60);
    }
}

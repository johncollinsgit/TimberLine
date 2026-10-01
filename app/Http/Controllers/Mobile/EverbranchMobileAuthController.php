<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Models\MobileAuthorizationCode;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Laravel\Fortify\Fortify;

class EverbranchMobileAuthController extends Controller
{
    public function password(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string'],
            'two_factor_code' => ['nullable', 'string', 'max:100'],
            'device_name' => ['nullable', 'string', 'max:160'],
        ]);

        $user = User::query()->where('email', Str::lower($validated['email']))->first();
        if (! $user instanceof User || ! Hash::check($validated['password'], (string) $user->password)) {
            throw ValidationException::withMessages(['email' => 'The email or password is incorrect.']);
        }
        if ($user->is_active === false) {
            throw ValidationException::withMessages(['email' => 'This account is disabled. Contact an administrator.']);
        }
        if (! $user->hasVerifiedEmail()) {
            throw ValidationException::withMessages(['email' => 'Verify your email address before signing in.']);
        }

        if ($user->hasEnabledTwoFactorAuthentication()) {
            $code = trim((string) ($validated['two_factor_code'] ?? ''));
            if ($code === '') {
                return response()->json(['requires_two_factor' => true], 202);
            }

            $validAuthenticatorCode = app(TwoFactorAuthenticationProvider::class)->verify(
                Fortify::currentEncrypter()->decrypt($user->two_factor_secret),
                $code
            );
            if (! $validAuthenticatorCode) {
                $validRecoveryCode = DB::transaction(function () use ($user, $code): bool {
                    $locked = User::query()->whereKey($user->id)->lockForUpdate()->first();
                    if (! $locked instanceof User || ! $locked->two_factor_recovery_codes) {
                        return false;
                    }
                    foreach ($locked->recoveryCodes() as $recoveryCode) {
                        if (hash_equals($recoveryCode, $code)) {
                            $locked->replaceRecoveryCode($recoveryCode);

                            return true;
                        }
                    }

                    return false;
                });
                if (! $validRecoveryCode) {
                    throw ValidationException::withMessages(['two_factor_code' => 'The authenticator or recovery code is incorrect.']);
                }
            }
        }

        return $this->tokenResponse($user, $validated['device_name'] ?? 'Everbranch mobile');
    }

    public function exchange(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'client_id' => ['required', 'in:everbranch-mobile'],
            'redirect_uri' => ['required', 'in:everbranch://auth/callback'],
            'code' => ['required', 'string', 'max:255'],
            'code_verifier' => ['required', 'string', 'min:43', 'max:128', 'regex:/^[A-Za-z0-9._~-]+$/'],
            'device_name' => ['nullable', 'string', 'max:160'],
        ]);

        $authorization = DB::transaction(function () use ($validated): MobileAuthorizationCode {
            $row = MobileAuthorizationCode::query()
                ->where('code_hash', hash('sha256', $validated['code']))
                ->lockForUpdate()
                ->first();

            $challenge = rtrim(strtr(base64_encode(hash('sha256', $validated['code_verifier'], true)), '+/', '-_'), '=');
            if (! $row instanceof MobileAuthorizationCode
                || $row->consumed_at !== null
                || $row->expires_at?->isPast()
                || ! hash_equals((string) $row->code_challenge, $challenge)
                || ! hash_equals((string) $row->client_id, $validated['client_id'])
                || ! hash_equals((string) $row->redirect_uri, $validated['redirect_uri'])) {
                throw ValidationException::withMessages(['code' => 'This mobile authorization code is invalid or expired.']);
            }

            $row->forceFill(['consumed_at' => now()])->save();

            return $row;
        });

        $user = $authorization->user;
        abort_unless($user instanceof User && $user->is_active !== false, 403);

        return $this->tokenResponse($user, $validated['device_name'] ?? $authorization->device_name ?? 'Everbranch mobile');
    }

    public function refresh(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->is_active !== false, 401);

        $name = trim((string) $request->input('device_name', $user->currentAccessToken()?->name ?? 'Everbranch mobile'));
        $oldToken = $user->currentAccessToken();
        $response = $this->tokenResponse($user, $name !== '' ? $name : 'Everbranch mobile');
        $oldToken?->delete();

        return $response;
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()?->currentAccessToken()?->delete();

        return response()->json(['ok' => true]);
    }

    public function sessions(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $currentId = $user->currentAccessToken()?->id;

        return response()->json([
            'sessions' => $user->tokens()
                ->where('name', 'like', 'Everbranch mobile:%')
                ->latest('id')
                ->get()
                ->map(fn ($token): array => [
                    'id' => (int) $token->id,
                    'name' => Str::after((string) $token->name, 'Everbranch mobile:'),
                    'current' => (int) $token->id === (int) $currentId,
                    'last_used_at' => optional($token->last_used_at)->toIso8601String(),
                    'expires_at' => optional($token->expires_at)->toIso8601String(),
                ])
                ->values(),
        ]);
    }

    public function revokeSession(Request $request, int $token): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $deleted = $user->tokens()->whereKey($token)->where('name', 'like', 'Everbranch mobile:%')->delete();
        abort_unless($deleted === 1, 404);

        return response()->json(['ok' => true]);
    }

    protected function tokenResponse(User $user, string $deviceName): JsonResponse
    {
        $expiresAt = now()->addDays(30);
        $token = $user->createToken(
            'Everbranch mobile:'.mb_substr(trim($deviceName), 0, 120),
            ['mobile:read', 'mobile:write'],
            $expiresAt
        );

        return response()->json([
            'token_type' => 'Bearer',
            'access_token' => $token->plainTextToken,
            'expires_at' => $expiresAt->toIso8601String(),
            'user' => [
                'id' => (int) $user->id,
                'name' => (string) $user->name,
                'email' => (string) $user->email,
            ],
        ]);
    }
}

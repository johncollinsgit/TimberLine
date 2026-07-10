<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Models\MobileAuthorizationCode;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class EverbranchMobileAuthController extends Controller
{
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

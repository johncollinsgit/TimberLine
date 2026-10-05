<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Auth\HomeRedirect;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class ConfirmEmailController extends Controller
{
    public function __invoke(Request $request, int $id, string $hash): Response|RedirectResponse
    {
        $user = User::query()->find($id);
        abort_unless($user instanceof User
            && hash_equals(sha1($user->getEmailForVerification()), $hash), 403);

        if (! $user->hasVerifiedEmail() && $user->markEmailAsVerified()) {
            event(new Verified($user));
        }

        if ($request->user()?->is($user)) {
            $home = HomeRedirect::pathFor($user);
            $verifiedHome = $home.(str_contains($home, '?') ? '&' : '?').'verified=1';
            $intended = $request->session()->get('url.intended');
            $path = is_string($intended) ? parse_url($intended, PHP_URL_PATH) : null;
            $host = is_string($intended) ? parse_url($intended, PHP_URL_HOST) : null;

            // Only the mobile authorization handoff needs its pre-verification
            // destination. Other stale intended URLs can point a member at an
            // admin-only page such as /dashboard after successful confirmation.
            if ($path === '/mobile/authorize'
                && ($host === null || strcasecmp((string) $host, $request->getHost()) === 0)) {
                return redirect()->intended($verifiedHome);
            }

            $request->session()->forget('url.intended');

            return redirect()->to($verifiedHome);
        }

        return response()->view('pages::auth.email-confirmed', [
            'signedInAsDifferentAccount' => $request->user() !== null,
        ])->header('Cache-Control', 'no-store, private');
    }
}

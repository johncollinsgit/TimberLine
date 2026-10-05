<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Laravel\Fortify\Fortify;

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
            return redirect()->intended(Fortify::redirects('email-verification').'?verified=1');
        }

        return response()->view('pages::auth.email-confirmed', [
            'signedInAsDifferentAccount' => $request->user() !== null,
        ])->header('Cache-Control', 'no-store, private');
    }
}

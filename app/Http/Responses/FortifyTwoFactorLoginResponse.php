<?php

namespace App\Http\Responses;

use App\Support\Auth\PostLoginRedirectResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Fortify\Contracts\TwoFactorLoginResponse as TwoFactorLoginResponseContract;

class FortifyTwoFactorLoginResponse implements TwoFactorLoginResponseContract
{
    public function __construct(
        protected PostLoginRedirectResolver $redirectResolver,
    ) {
    }

    public function toResponse($request)
    {
        if ($request->wantsJson()) {
            return new JsonResponse('', 204);
        }

        /** @var Request $request */
        $user = $request->user();
        if (! $user) {
            return redirect()->route('login');
        }

        $target = $this->redirectResolver->resolve(
            request: $request,
            user: $user,
            authMethod: 'password_two_factor',
        );

        return redirect()->to($target);
    }
}

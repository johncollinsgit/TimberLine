<?php

namespace App\Http\Responses;

use App\Support\Auth\AuthTenantIntentStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Fortify\Contracts\PasswordResetResponse as PasswordResetResponseContract;
use Laravel\Fortify\Fortify;

class FortifyPasswordResetResponse implements PasswordResetResponseContract
{
    public function __construct(
        protected string $status,
        protected AuthTenantIntentStore $intentStore,
    ) {
    }

    public function toResponse($request)
    {
        if ($request->wantsJson()) {
            return new JsonResponse(['message' => trans($this->status)], 200);
        }

        /** @var Request $request */
        $this->intentStore->markPreserveOnLogin($request);

        return redirect(Fortify::redirects(
            'password-reset',
            config('fortify.views', true) ? route('login', absolute: false) : null
        ))->with('status', trans($this->status));
    }
}

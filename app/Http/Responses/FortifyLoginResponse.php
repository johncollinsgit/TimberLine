<?php

namespace App\Http\Responses;

use App\Support\Auth\HomeRedirect;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;

class FortifyLoginResponse implements LoginResponseContract
{
    public function toResponse($request)
    {
        if ($request->wantsJson()) {
            return new JsonResponse(['two_factor' => false]);
        }

        /** @var Request $request */
        $target = HomeRedirect::pathFor($request->user());

        if (($request->user()?->role ?? null) === 'pouring') {
            return redirect()->to($target);
        }

        return redirect()->intended($target);
    }
}

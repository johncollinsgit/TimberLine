<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Support\Auth\MobileAuthorizationClients;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

class TeamsMobileAvailabilityController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $available = config('team_work.enabled') === true && config('team_work.premium_enabled') === true && config('team_operations.enabled') === true;

        try {
            MobileAuthorizationClients::assertAllowed(MobileAuthorizationClients::TEAMS_CLIENT, MobileAuthorizationClients::TEAMS_REDIRECT);
        } catch (ValidationException) {
            $available = false;
        }

        // Public installation readiness only. This cannot authorize a user,
        // select a tenant, create a login attempt, or issue a device token.
        return response()->json([
            'contract_version' => 1,
            'client_id' => MobileAuthorizationClients::TEAMS_CLIENT,
            'redirect_uri' => MobileAuthorizationClients::TEAMS_REDIRECT,
            'available' => $available,
            'auth_methods' => $available ? ['email', 'google'] : [],
        ])->header('Cache-Control', 'no-store, private');
    }
}

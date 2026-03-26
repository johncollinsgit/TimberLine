<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureLandlordOperator
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            abort(403);
        }

        if (! (bool) $request->attributes->get('is_landlord_mode', false)) {
            abort(404);
        }

        if ($user->getAttribute('is_active') === false) {
            abort(403);
        }

        $role = strtolower(trim((string) ($user->role ?? '')));

        // Interim guard: no dedicated landlord flag exists yet, so we use a
        // landlord-specific allowlist based on global user role.
        if (! in_array($role, $this->allowedRoles(), true)) {
            abort(403);
        }

        $allowedEmails = $this->allowedEmails();
        if ($allowedEmails !== []) {
            $email = strtolower(trim((string) ($user->email ?? '')));

            if ($email === '' || ! in_array($email, $allowedEmails, true)) {
                abort(403);
            }
        }

        return $next($request);
    }

    /**
     * @return array<int,string>
     */
    protected function allowedRoles(): array
    {
        $configured = config('tenancy.landlord.operator_roles', ['admin']);
        if (! is_array($configured)) {
            return ['admin'];
        }

        $roles = [];

        foreach ($configured as $candidate) {
            $normalized = strtolower(trim((string) $candidate));
            if ($normalized !== '') {
                $roles[] = $normalized;
            }
        }

        $roles = array_values(array_unique($roles));

        return $roles === [] ? ['admin'] : $roles;
    }

    /**
     * @return array<int,string>
     */
    protected function allowedEmails(): array
    {
        $configured = config('tenancy.landlord.operator_emails', []);
        if (! is_array($configured)) {
            return [];
        }

        $emails = [];

        foreach ($configured as $candidate) {
            $normalized = strtolower(trim((string) $candidate));
            if ($normalized !== '') {
                $emails[] = $normalized;
            }
        }

        return array_values(array_unique($emails));
    }
}

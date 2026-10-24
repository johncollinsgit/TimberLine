<?php

namespace App\Enterprise;

use App\Enterprise\Provisioning\ClientSilo;
use App\Proposals\RateLimits;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/** Proposal ownership is a separate, explicitly bound business runtime, not campus employee access. */
final class ProposalRuntimeBoundary
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(config('service_proposals.enabled'), 404);
        abort_unless(config('enterprise.runtime') === true && config('service_proposals.runtime_purpose') === 'proposal_owner'
            && is_string(config('service_proposals.owner_client_id')) && Str::isUuid(config('service_proposals.owner_client_id'))
            && is_string(config('service_proposals.owner_silo_id')) && Str::isUuid(config('service_proposals.owner_silo_id')), 503,
            'A separately configured proposal owner runtime is required.');
        try {
            $owner = app(ClientSilo::class)->assertReady();
        } catch (Throwable) {
            abort(503, 'The proposal owner silo is not configured.');
        }
        abort_unless($owner->client_id === config('service_proposals.owner_client_id')
            && $owner->silo_id === config('service_proposals.owner_silo_id')
            && config('service_proposals.owner_workspaces') === [(int) $owner->tenant_id => 'enterprise'], 503,
            'The proposal owner binding does not match this runtime.');

        // Cached routes do not execute the shared route file's limiter registration.
        RateLimits::register();

        return $next($request);
    }
}

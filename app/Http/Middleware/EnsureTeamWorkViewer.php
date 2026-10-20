<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Optional client page binding is a refusal fence, never an authorization grant. */
class EnsureTeamWorkViewer
{
    public function handle(Request $request, Closure $next): Response
    {
        $viewer = (string) $request->user()->id;
        $workspace = (string) $request->attributes->get('current_tenant')?->id;
        if (($request->hasHeader('X-Team-Viewer') && $request->header('X-Team-Viewer') !== $viewer)
            || ($request->hasHeader('X-Team-Workspace') && $request->header('X-Team-Workspace') !== $workspace)) {
            return response()->json(['error' => 'page_identity_changed', 'saved' => false], 403)->header('Cache-Control', 'private, no-store');
        }

        $response = $next($request);
        $response->headers->set('X-Team-Viewer', $viewer);
        $response->headers->set('X-Team-Workspace', $workspace);

        return $response;
    }
}

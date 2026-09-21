<?php

namespace App\Http\Middleware;

use App\Http\Controllers\ConnectedWebsiteController;
use App\Models\Tenant;
use App\Models\TenantSite;
use App\Services\ManagedWebsite\ConnectedWebsiteService;
use Closure;
use Illuminate\Http\Request;

class UseConnectedWebsiteEditor
{
    public function handle(Request $request, Closure $next)
    {
        $tenant = $request->attributes->get('current_tenant');
        $site = $tenant instanceof Tenant ? TenantSite::query()->forTenant($tenant)->first() : null;
        $service = app(ConnectedWebsiteService::class);
        if (! $service->connected($site)) {
            return $next($request);
        }
        if ($request->routeIs('managed-website.index', 'managed-website.editor', 'managed-website.products.index', 'managed-website.services.index')) {
            if ($request->route('page')) {
                $page = $request->route('page');
                abort_unless((int) $page->tenant_id === $tenant->id, 404);
            }

            return app(ConnectedWebsiteController::class)->index($request, $service);
        }
        if ($request->routeIs('managed-website.editor.preview', 'managed-website.editor.preview.site')) {
            abort_unless((int) $request->route('page')->tenant_id === $tenant->id, 404);

            return redirect()->away($service->previewUrl($site, $request->user()))->header('Cache-Control', 'private, no-store');
        }
        if (! $request->isMethod('GET') && ! $request->isMethod('HEAD') && $request->routeIs(
            'managed-website.create', 'managed-website.setup.*', 'managed-website.themes.*', 'managed-website.domains.*',
            'managed-website.editor.*', 'managed-website.pages.*', 'managed-website.publish',
            'managed-website.products.*', 'managed-website.services.*', 'managed-website.commerce.imports.*'
        )) {
            abort(409, 'Use Website to edit and publish this connected site.');
        }

        return $next($request);
    }
}

<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class TeamAppPackageController extends Controller
{
    public function manifest(Request $request)
    {
        $tenant = $request->query('tenant', '');
        abort_unless(is_string($tenant) && preg_match('/^[a-z0-9-]{0,100}$/D', $tenant), 422);
        $start = '/teams/app?'.http_build_query(['app' => 'events', 'tenant' => $tenant]);

        return response()->json(['id' => '/teams/app?tenant='.rawurlencode($tenant), 'name' => 'Everbranch Teams', 'short_name' => 'Teams',
            'start_url' => $start, 'scope' => '/teams/', 'display' => 'standalone', 'background_color' => '#f3f6ef', 'theme_color' => '#244d3b',
            'icons' => [['src' => '/brand/everbranch-mark.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any']]])
            ->header('Content-Type', 'application/manifest+json')->header('Cache-Control', 'private, no-store');
    }

    public function worker()
    {
        return response()->file(resource_path('js/team/teams-sw.js'), ['Content-Type' => 'application/javascript', 'Service-Worker-Allowed' => '/teams/', 'Cache-Control' => 'no-cache']);
    }

    public function offline()
    {
        return response('<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Teams · Offline</title><body style="font:18px/1.6 system-ui;padding:32px;max-width:600px;margin:auto"><h1>You are offline</h1><p>Reconnect to open your event and confirm its latest Asana information. Saves require a connection.</p><p><a href="javascript:location.reload()">Try again</a></p></body></html>')
            ->header('Cache-Control', 'public, max-age=3600');
    }
}

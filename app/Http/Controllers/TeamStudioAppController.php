<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Services\Team\Operations\OperationsAccess;
use Illuminate\Http\Request;

class TeamStudioAppController extends Controller
{
    public function __invoke(Request $request, OperationsAccess $access)
    {
        $tenant = $request->attributes->get('current_tenant');
        abort_unless($tenant instanceof Tenant, 403);
        $access->actor($tenant, $request->user());
        $file = resource_path('team-app/index.html');
        abort_unless(is_file($file), 503, 'The shared Teams application package has not been installed.');
        $boot = json_encode(['tenant' => ['id' => (string) $tenant->id, 'slug' => $tenant->slug, 'name' => $tenant->name], 'user' => ['id' => (string) $request->user()->id, 'name' => $request->user()->name], 'csrf' => csrf_token()], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR);
        $html = str_replace('<!--teams-boot-->', '<script id="teams-boot" type="application/json">'.$boot.'</script>', file_get_contents($file));

        return response($html)->header('Cache-Control', 'private, no-store');
    }
}

<?php

namespace App\Http\Controllers\Marketplace;

use App\Http\Controllers\Controller;
use App\Models\IntegrationConnection;
use App\Models\Marketplace\MakerVideo;
use App\Services\Marketplace\MakerSocialImport;
use App\Services\Marketplace\MarketplaceAccess;
use Illuminate\Http\Request;

class SocialVideoImportController extends Controller
{
    public function accounts(Request $request, int $id, MarketplaceAccess $access)
    {
        $tenant = $this->tenant($request, $id, $access);
        $accounts = IntegrationConnection::forTenantId($tenant)->where('status', 'connected')->whereIn('provider', ['facebook', 'instagram'])
            ->get(['id', 'provider', 'scopes'])->filter(fn ($connection) => in_array($connection->provider === 'facebook' ? 'pages_read_engagement' : 'instagram_business_basic', $connection->scopes ?? [], true))
            ->map(fn ($connection) => ['id' => $connection->id, 'platform' => $connection->provider])->values();

        return response()->json($accounts)->header('Cache-Control', 'private, no-store');
    }

    public function clips(Request $request, int $id, string $platform, int $account, MarketplaceAccess $access, MakerSocialImport $import)
    {
        $clips = $import->clips($this->tenant($request, $id, $access), $account, $platform);

        return response()->json(array_values(array_map(fn ($clip) => \Illuminate\Support\Arr::except($clip, 'source'), $clips)))->header('Cache-Control', 'private, no-store');
    }

    public function file(Request $request, int $id, string $platform, int $account, string $clip, MarketplaceAccess $access, MakerSocialImport $import)
    {
        $clip = $import->clips($this->tenant($request, $id, $access), $account, $platform)[$clip] ?? null;
        abort_unless($clip, 404);

        return $import->download($clip['source']);
    }

    private function tenant(Request $request, int $id, MarketplaceAccess $access): int
    {
        $maker = $access->maker($request, $id);
        abort_unless($maker->status === 'approved' && MakerVideo::invited($id) && $request->user() && $maker->tenant_id, 403);

        return (int) $access->assertWorkspaceManager($request->user(), (int) $maker->tenant_id)->id;
    }
}

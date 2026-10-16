<?php

namespace App\Services\Marketing\Desk;

use App\Models\IntegrationConnection;
use Illuminate\Support\Facades\Http;

class MetaMarketingClient
{
    public function connection(int $tenantId, int $id, string $platform, string $capability): IntegrationConnection
    {
        abort_unless(config('marketing_desk.meta.execution_enabled'), 503, 'Meta publishing, insights and Messenger are awaiting provider review and pilot verification.');
        $connection = IntegrationConnection::forTenantId($tenantId)->where('provider', $platform)->where('status', 'connected')->findOrFail($id);
        abort_if($connection->isExpired() || blank($connection->access_token) || blank($connection->external_account_secret), 422, 'Reconnect this social account.');
        $scope = match ([$platform, $capability]) {
            ['instagram', 'publish'] => 'instagram_business_content_publish',
            ['instagram', 'reply'] => 'instagram_business_manage_messages',
            ['instagram', 'insights'] => 'instagram_business_manage_insights',
            ['facebook', 'publish'] => 'pages_manage_posts',
            ['facebook', 'reply'] => 'pages_messaging',
            ['facebook', 'insights'] => 'read_insights',
            default => '',
        };
        abort_unless($scope !== '' && in_array($scope, $connection->scopes ?? [], true), 422, 'This connection needs permission for '.$capability.'.');
        abort_unless(IntegrationConnection::forAllTenants()->where('provider', $platform)->where('external_account_id', $connection->external_account_id)->where('status', 'connected')->count() === 1, 409, 'This social account has conflicting workspace ownership.');

        return $connection;
    }

    public function request(IntegrationConnection $connection, string $method, string $path, array $data = []): array
    {
        abort_unless(preg_match('/^[a-zA-Z0-9_\/,.-]+$/', $path), 422);
        $base = $connection->provider === 'instagram' ? 'https://graph.instagram.com/' : 'https://graph.facebook.com/';
        $http = Http::withToken($connection->access_token)->acceptJson()->connectTimeout(5)->timeout(25);
        $url = $base.trim((string) config('marketing_desk.meta.version'), '/').'/'.ltrim($path, '/');
        $response = $method === 'GET' ? $http->get($url, $data) : $http->post($url, $data);
        $result = $response->throw()->json();
        abort_unless(is_array($result), 502);

        return $result;
    }

    public function account(IntegrationConnection $connection): string
    {
        $id = (string) $connection->external_account_secret;
        abort_unless(preg_match('/^[a-zA-Z0-9_-]+$/', $id), 422);

        return $id;
    }
}

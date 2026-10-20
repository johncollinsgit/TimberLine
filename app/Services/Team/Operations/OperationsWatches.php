<?php

namespace App\Services\Team\Operations;

use App\Models\TeamProviderConnection;
use Illuminate\Support\Str;

class OperationsWatches
{
    public function renew(TeamProviderConnection $c): array
    {
        $provider = app(OperationsProviders::class);
        $settings = $c->settings;
        if ($c->provider === 'gmail') {
            abort_unless(config('team_operations.google.pubsub_topic') && config('team_operations.google.push_service_account') && config('team_operations.google.push_audience'), 503, 'Configure Gmail Pub/Sub delivery before enabling push. Scheduled reconciliation remains available.');
            $response = $provider->http($c, 'POST', 'https://gmail.googleapis.com/gmail/v1/users/me/watch', ['topicName' => config('team_operations.google.pubsub_topic')]);
            $settings['watch'] = ['expires_at' => (int) ($response['expiration'] ?? 0), 'renewed_at' => now()->toIso8601String()];
        } elseif ($c->provider === 'sheets') {
            $file = $settings['source']['spreadsheet_id'] ?? '';
            abort_unless(preg_match('/^[A-Za-z0-9_-]{10,160}$/D', $file), 422);
            $callback = route('team.operations.push', ['connection' => $c->id]);
            abort_unless(str_starts_with($callback, 'https://'), 503, 'Drive push requires a public HTTPS callback. Reconciliation remains available.');
            $watch = ['id' => (string) Str::uuid(), 'type' => 'web_hook', 'address' => $callback, 'token' => Str::random(64), 'expiration' => (string) (now()->addHours(23)->timestamp * 1000)];
            $response = $provider->http($c, 'POST', 'https://www.googleapis.com/drive/v3/files/'.$file.'/watch', $watch);
            $settings['watch'] = ['id' => $watch['id'], 'token' => $watch['token'], 'resource_id' => $response['resourceId'] ?? '', 'expires_at' => (int) ($response['expiration'] ?? $watch['expiration']), 'renewed_at' => now()->toIso8601String()];
        } else {
            abort(422, 'This source uses scheduled refresh.');
        }
        $c->update(['settings' => $settings]);

        return ['status' => 'watching', 'expires_at' => $settings['watch']['expires_at']];
    }
}

<?php

namespace App\Services\ManagedWebsite;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/** The API is platform-owned; customer DNS credentials never enter the editor. */
class CloudflareWebsiteDomains
{
    public function ready(): bool
    {
        return (bool) config('managed_website.cloudflare.enabled', false)
            && preg_match('/^[a-f0-9]{32}$/i', (string) config('managed_website.cloudflare.zone_id')) === 1
            && trim((string) config('managed_website.cloudflare.api_token')) !== '';
    }

    /** @return array{ready:bool,message:string} */
    public function check(string $hostname, bool $create = false): array
    {
        if (! $this->ready()) {
            return ['ready' => false, 'message' => 'Secure domain setup needs Everbranch assistance. Your Everbranch address still works.'];
        }

        try {
            $request = Http::withToken((string) config('managed_website.cloudflare.api_token'))
                ->acceptJson()->connectTimeout(5)->timeout(15)->withoutRedirecting();
            $url = 'https://api.cloudflare.com/client/v4/zones/'.config('managed_website.cloudflare.zone_id').'/custom_hostnames';
            $response = $request->get($url, ['hostname' => $hostname, 'per_page' => 50]);
            if (! $response->successful() || $response->json('success') !== true || ! is_array($response->json('result'))) {
                throw new RuntimeException('Domain lookup unavailable.');
            }
            $matches = collect($response->json('result'))->filter(fn ($record): bool => is_array($record) && ($record['hostname'] ?? null) === $hostname)->values();
            // Never create after an ambiguous/incomplete provider lookup.
            if ($matches->count() > 1 || (int) $response->json('result_info.total_pages', 1) > 1) {
                throw new RuntimeException('Ambiguous domain lookup.');
            }
            $record = $matches->first();
            if (! $record && $create) {
                $response = $request->post($url, ['hostname' => $hostname, 'ssl' => ['method' => 'http', 'type' => 'dv']]);
                if (! $response->successful() || $response->json('success') !== true) {
                    throw new RuntimeException('Domain preparation unavailable.');
                }
                $record = $response->json('result');
            }
            if (! is_array($record) || ($record['hostname'] ?? null) !== $hostname) {
                return ['ready' => false, 'message' => 'Check the connection to prepare this domain for secure publishing.'];
            }
            $ready = ($record['status'] ?? null) === 'active' && data_get($record, 'ssl.status') === 'active';

            return ['ready' => $ready, 'message' => $ready ? 'Routing and security certificate are ready.' : 'Ownership confirmed. Routing or the security certificate is still pending; check again shortly.'];
        } catch (\Throwable) {
            // Provider error bodies may contain account details. Keep them off the
            // customer surface and preserve the original pending hostname on retry.
            return ['ready' => false, 'message' => 'We could not check secure domain setup. Try again or ask Everbranch for help.'];
        }
    }
}

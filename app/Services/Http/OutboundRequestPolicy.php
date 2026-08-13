<?php

namespace App\Services\Http;

use Closure;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;

/**
 * Guard requests to public URLs whose host can originate in tenant data.
 *
 * Callers must use the returned pending request rather than constructing a
 * separate Http request, so redirects cannot turn a public URL into an
 * internal request after validation.
 */
class OutboundRequestPolicy
{
    /** @var Closure(string): array<int,string> */
    protected Closure $addressResolver;

    /**
     * @param  null|Closure(string): array<int,string>  $addressResolver
     */
    public function __construct(?Closure $addressResolver = null)
    {
        $this->addressResolver = $addressResolver ?? function (string $host): array {
            $records = dns_get_record($host, DNS_A | DNS_AAAA) ?: [];

            return collect($records)
                ->map(fn (array $record): ?string => $record['ip'] ?? $record['ipv6'] ?? null)
                ->filter(fn (?string $address): bool => is_string($address) && $address !== '')
                ->values()
                ->all();
        };
    }

    /**
     * @param  array<string,string>  $headers
     */
    public function request(string $url, int $timeout = 10, int $connectTimeout = 5, array $headers = []): PendingRequest
    {
        $this->assertPublicHttpsUrl($url);

        return Http::timeout($timeout)
            ->connectTimeout($connectTimeout)
            ->withoutRedirecting()
            ->withHeaders($headers);
    }

    public function assertPublicHttpsUrl(string $url): string
    {
        $url = trim($url);
        $parts = parse_url($url);
        if (! is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || ! isset($parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])
            || (isset($parts['port']) && (int) $parts['port'] !== 443)
        ) {
            throw new InvalidArgumentException('Outbound requests must use an HTTPS URL without credentials or a custom port.');
        }

        $host = strtolower(rtrim((string) $parts['host'], '.'));
        if ($host === ''
            || filter_var($host, FILTER_VALIDATE_IP)
            || ! filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)
            || in_array($host, ['localhost', 'metadata.google.internal'], true)
            || str_ends_with($host, '.localhost')
            || str_ends_with($host, '.local')
            || str_ends_with($host, '.internal')
            || str_ends_with($host, '.test')
        ) {
            throw new InvalidArgumentException('Outbound requests must target a public hostname.');
        }

        $addresses = ($this->addressResolver)($host);
        if ($addresses === [] || collect($addresses)->contains(fn (string $address): bool => ! $this->isPublicIpAddress($address))) {
            throw new InvalidArgumentException('Outbound requests must resolve only to public IP addresses.');
        }

        return $url;
    }

    protected function isPublicIpAddress(string $address): bool
    {
        return filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }
}

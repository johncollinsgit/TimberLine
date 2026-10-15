<?php

namespace App\Services\ManagedWebsite;

use App\Models\IntegrationConnection;
use App\Models\WebsiteShipment;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/** Original tenant credentials remain the authority for an existing shipment. */
class WebsiteEasyPostService
{
    public function connection(WebsiteShipment $shipment): IntegrationConnection
    {
        abort_unless($shipment->provider === 'easypost' && data_get($shipment->parcel, '_native.version') === 1, 422, 'This shipment requires a separate provider review.');
        $connection = IntegrationConnection::query()->forTenantId($shipment->tenant_id)->where('provider', 'easypost')
            ->findOrFail((int) data_get($shipment->parcel, '_native.connection_id'));
        abort_unless(filled($connection->access_token)
            && hash_equals((string) data_get($shipment->parcel, '_native.account_fingerprint'), app(WebsiteShippingPlanService::class)->accountFingerprint($connection)), 423, 'The original carrier credentials changed. Review this shipment before continuing.');

        return $connection;
    }

    public function client(WebsiteShipment $shipment): PendingRequest
    {
        $connection = $this->connection($shipment);

        return Http::baseUrl((string) config('managed_website.easypost_api_base'))->withBasicAuth($connection->access_token, '')
            ->acceptJson()->asJson()->timeout(20);
    }

    public function retrieve(WebsiteShipment $shipment): array
    {
        abort_unless(preg_match('/^shp_[A-Za-z0-9_]+$/', (string) $shipment->provider_shipment_id), 422);
        $response = $this->client($shipment)->get('/shipments/'.$shipment->provider_shipment_id);
        abort_unless($response->successful() && is_array($response->json()) && $response->json('id') === $shipment->provider_shipment_id, 502, 'Carrier verification is unavailable. This shipment remains unresolved.');

        return $response->json();
    }

    /** Validate the untouched request body. Invalid v2 must never downgrade to legacy. */
    public function validSignature(string $body, array $headers, string $method, string $path, string $secret): bool
    {
        $headers = array_change_key_case($headers, CASE_LOWER);
        $get = fn ($key) => is_array($headers[$key] ?? null) ? ($headers[$key][0] ?? '') : ($headers[$key] ?? '');
        if ($secret === '') {
            return false;
        }
        if (class_exists(\Normalizer::class)) {
            $secret = \Normalizer::normalize($secret, \Normalizer::FORM_KD) ?: '';
        } elseif (preg_match('/[^\x00-\x7f]/', $secret)) {
            return false;
        }
        if ($secret === '') {
            return false;
        }
        if (array_key_exists('x-hmac-signature-v2', $headers)) {
            $timestamp = $get('x-timestamp');
            $signedPath = $get('x-path');
            if (! is_string($timestamp) || strlen($timestamp) > 80 || ! is_string($signedPath) || $signedPath !== $path
                || ! preg_match('/^(?:Mon|Tue|Wed|Thu|Fri|Sat|Sun), \d{1,2} [A-Z][a-z]{2} \d{4} \d{2}:\d{2}:\d{2} [+-]\d{4}$/', $timestamp)) {
                return false;
            }
            $date = \DateTimeImmutable::createFromFormat('D, d M Y H:i:s O', $timestamp);
            $errors = \DateTimeImmutable::getLastErrors();
            if (! $date || ($errors !== false && ($errors['warning_count'] || $errors['error_count']))
                || now()->timestamp - $date->getTimestamp() > 60 || $date->getTimestamp() - now()->timestamp > 30) {
                return false;
            }
            $signature = $get('x-hmac-signature-v2');
            $expected = 'hmac-sha256-hex='.hash_hmac('sha256', $timestamp.strtoupper($method).$signedPath.$body, $secret);

            return is_string($signature) && hash_equals($expected, strtolower($signature));
        }
        $signature = $get('x-hmac-signature');

        return is_string($signature) && hash_equals('hmac-sha256-hex='.hash_hmac('sha256', $body, $secret), $signature);
    }

    public function cents(mixed $value): int
    {
        abort_unless(is_scalar($value) && preg_match('/^\d{1,7}(?:\.\d{1,6})?$/', (string) $value), 502, 'Carrier amount is invalid.');
        [$whole, $fraction] = array_pad(explode('.', (string) $value, 2), 2, '');

        return (int) $whole * 100 + (int) str_pad(substr($fraction, 0, 2), 2, '0') + ((int) ($fraction[2] ?? 0) >= 5 ? 1 : 0);
    }
}

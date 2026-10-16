<?php

namespace App\Services\Subscriptions;

use App\Models\SubscriptionRechargeConnection;
use App\Models\SubscriptionRechargeCredentialRevision;
use Illuminate\Support\Facades\Http;

/** Fixed GETs only. No servicing, charge, notification or arbitrary URL method exists. */
class SubscriptionRechargeTransport
{
    public const API_VERSION = '2021-11';

    public const MAX_BYTES = 2097152;

    public function read(SubscriptionRechargeConnection $connection, SubscriptionRechargeCredentialRevision $revision, string $operation, array $variables = []): array
    {
        [$path, $query] = $this->route($operation, $variables);
        if ($connection->api_version !== self::API_VERSION || $revision->tenant_id !== $connection->tenant_id || $revision->connection_id !== $connection->id
            || $revision->revoked_at || $revision->expires_at?->isPast()) {
            $this->fail('recharge_credential_unavailable');
        }
        $token = $revision->credentials['access_token'] ?? null;
        if (! is_string($token) || $token === '' || strlen($token) > 4096 || preg_match('/[\r\n]/', $token)) {
            $this->fail('recharge_credential_unavailable');
        }
        for ($attempt = 0; $attempt < 2; $attempt++) {
            try {
                $started = microtime(true);
                $response = Http::withHeaders(['X-Recharge-Version' => self::API_VERSION, 'X-Recharge-Access-Token' => $token, 'Accept' => 'application/json'])
                    ->connectTimeout(3)->timeout(12)->withOptions(['allow_redirects' => false, 'stream' => true, 'read_timeout' => 3])
                    ->get('https://api.rechargeapps.com'.$path, $query);
                $stream = $response->toPsrResponse()->getBody();
                $raw = '';
                while (! $stream->eof()) {
                    if (microtime(true) - $started > 12) {
                        $this->fail('recharge_read_timeout');
                    }
                    $raw .= $stream->read(min(8192, self::MAX_BYTES + 1 - strlen($raw)));
                    if (strlen($raw) > self::MAX_BYTES) {
                        $this->fail('recharge_response_too_large');
                    }
                    if (microtime(true) - $started > 12) {
                        $this->fail('recharge_read_timeout');
                    }
                }
                $stream->close();
                if ($response->status() === 429 && $attempt === 0) {
                    usleep(100000);

                    continue;
                }
                if (in_array($response->status(), [401, 403], true)) {
                    $this->fail('recharge_access_denied');
                }
                if ($response->status() === 404) {
                    $this->fail('recharge_record_not_found');
                }
                if (! $response->successful()) {
                    $this->fail('recharge_http_error');
                }
                // Recharge documents a request version header, not a mandatory
                // response analogue. If present, mismatched evidence is rejected.
                $actualVersion = $response->header('X-Recharge-Version');
                if ($actualVersion && $actualVersion !== self::API_VERSION) {
                    $this->fail('recharge_version_mismatch');
                }
                $decoded = json_decode($raw, true, 64, JSON_BIGINT_AS_STRING | JSON_THROW_ON_ERROR);
                if (! is_array($decoded) || isset($decoded['errors']) || isset($decoded['error'])) {
                    $this->fail('recharge_invalid_response');
                }

                return $decoded;
            } catch (SubscriptionProviderReadException $e) {
                throw $e;
            } catch (\Throwable) {
                $this->fail('recharge_transport_error');
            }
        }
        $this->fail('recharge_throttled');
    }

    private function route(string $operation, array $variables): array
    {
        if (in_array($operation, ['store', 'token_information'], true)) {
            if ($variables) {
                $this->fail('recharge_invalid_query');
            }

            return ['/'.$operation, []];
        }
        if (in_array($operation, ['customers', 'addresses', 'subscriptions', 'payment_methods'], true)) {
            if (array_diff(array_keys($variables), ['cursor', 'limit', 'customer_id'])) {
                $this->fail('recharge_invalid_query');
            }
            $limit = $variables['limit'] ?? 50;
            if (! is_int($limit) || $limit < 1 || $limit > 250) {
                $this->fail('recharge_invalid_query');
            }
            $query = ['limit' => $limit];
            if (($variables['cursor'] ?? null) !== null) {
                $cursor = $variables['cursor'];
                if (! is_string($cursor) || $cursor === '' || strlen($cursor) > 4096 || preg_match('/[\x00-\x1f]/', $cursor)) {
                    $this->fail('recharge_invalid_query');
                }
                $query['cursor'] = $cursor;
            }
            if (isset($variables['customer_id'])) {
                $query['customer_id'] = self::id($variables['customer_id']);
            }

            return ['/'.$operation, $query];
        }
        if (in_array($operation, ['customer', 'address', 'subscription', 'payment_method'], true) && array_keys($variables) === ['id']) {
            $collection = match ($operation) {
                'customer' => 'customers', 'address' => 'addresses', 'subscription' => 'subscriptions', 'payment_method' => 'payment_methods'
            };

            return ['/'.$collection.'/'.rawurlencode(self::id($variables['id'])), []];
        }
        $this->fail('recharge_unsupported_query');
    }

    public static function id(mixed $value): string
    {
        if (is_int($value) && $value > 0) {
            $value = (string) $value;
        }
        if (! is_string($value) || ! preg_match('/^[a-zA-Z0-9_-]{1,100}$/D', $value) || $value === '0') {
            throw new SubscriptionProviderReadException('recharge_invalid_identifier');
        }

        return $value;
    }

    private function fail(string $code): never
    {
        throw new SubscriptionProviderReadException($code);
    }
}

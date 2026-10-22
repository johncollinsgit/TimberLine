<?php

namespace App\SoftwareSupport\Transport;

final class TestCampusClient
{
    public function __construct(private readonly CampusAuthorization $authorization, private readonly CampusOutbox $outbox,
        private readonly TestSigner $signer, private readonly TestVerifier $responseVerifier,
        private readonly CampusTransport $transport, private readonly string $ownerAudience) {}

    public function dispatch(string $department, string $requestKey, string $operation, array $payload): array
    {
        abort_unless(app()->environment('testing') && config('software_support.test_transport_enabled', false), 404);
        abort_unless(in_array($operation, ['submit', 'read', 'reply'], true), 422);
        // This adapter belongs inside the campus runtime. Caller-supplied principal/tenant/role is excluded.
        $identity = $this->authorization->identity($department, $operation);
        $command = $this->outbox->freeze(['version' => 1, 'issuer' => $identity['issuer'], 'audience' => $this->ownerAudience,
            'campus_workspace' => $identity['campus_workspace'], 'department' => $identity['department'], 'principal' => $identity['principal'],
            'request_key' => $requestKey, 'operation' => $operation, 'payload' => $payload]);
        $body = json_encode($command, JSON_THROW_ON_ERROR);
        try {
            $packet = $this->transport->exchange($this->signer->sign('campus-request', $body)->wire());
            $response = $this->responseVerifier->verify('owner-response', $identity['issuer'], $packet);
            abort_unless(($response['version'] ?? null) === 1 && ($response['issuer'] ?? null) === $this->ownerAudience
                && hash_equals(hash('sha256', $body), (string) ($response['request_hash'] ?? '')) && is_array($response['result'] ?? null), 401);
            $this->outbox->acknowledge($command);

            return $response['result'];
        } catch (\Throwable $error) {
            // Lost/invalid response is uncertain. No replacement key or automatic resend is generated.
            $this->outbox->uncertain($command);
            throw $error;
        }
    }
}

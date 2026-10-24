<?php

namespace App\Proposals;

use App\Enterprise\Conversation\Connection;
use Throwable;

/** Synchronous, single-dispatch protocol. Every transaction commits before HTTP; retries only read the original receipt. */
final class ReviewerAiOrchestrator
{
    public function __construct(private ReviewerAiReceipts $receipts, private ReviewerAiBudget $budget,
        private ReviewerAiConnection $connection, private ReviewerAiResponses $provider, private ReviewerAiRecovery $recovery) {}

    private function fresh(array $original, array $settings, callable $authority): array
    {
        $scope = $authority(); // Re-fetch recipient, unlock, manifest and original browser, never reuse a cached Version.
        $next = $this->connection->settings($scope);
        abort_unless($scope['version']->id === $original['version']->id && $scope['browser_hash'] === $original['browser_hash']
            && hash_equals($settings['settings_sha256'], $next['settings_sha256']), 409);

        return $scope;
    }

    public function preview(array $scope, string $question, array $snapshot, string $requestId, callable $authority, array $history = []): array
    {
        $settings = $this->connection->settings($scope);
        $scope = $this->fresh($scope, $settings, $authority);
        $preview = $this->receipts->prepare($scope, $question, $snapshot, $requestId, $history);
        $this->fresh($scope, $settings, $authority);

        return ['status' => 'prepared', 'preview' => $preview];
    }

    public function status(array $scope, array $receipt, callable $authority): array
    {
        $settings = $this->connection->settings($scope);
        $scope = $this->fresh($scope, $settings, $authority);
        $value = $this->receipts->show($scope, $receipt);
        $final = $this->fresh($scope, $settings, $authority);
        if (($final['workspace_stale'] ?? false) === true
            || isset($value['preview']['content_expires_at']) && now()->timestamp >= $value['preview']['content_expires_at']) {
            return array_intersect_key($value, array_flip(['preview_id', 'request_id', 'digest', 'snapshot_digest', 'status']))
                + ['content_expired' => true];
        }

        return $value;
    }

    public function cancel(array $scope, array $receipt, callable $authority): array
    {
        $settings = $this->connection->settings($scope);
        $scope = $this->fresh($scope, $settings, $authority);
        $value = $this->receipts->cancel($scope, $receipt);
        $this->fresh($scope, $settings, $authority);

        return $value;
    }

    public function confirm(array $scope, array $receipt, callable $authority): array
    {
        $settings = $this->connection->settings($scope);
        $scope = $this->fresh($scope, $settings, $authority);
        $existing = $this->receipts->show($scope, $receipt);
        if ($existing['status'] !== 'prepared') {
            $this->fresh($scope, $settings, $authority);

            return $existing; // A lost response is NOT consent to submit another paid action.
        }
        $pins = $this->recovery->pins($settings);
        $chargeId = null;
        $claimed = false;
        try {
            $scope = $this->fresh($scope, $settings, $authority);
            $claimedPreview = $this->receipts->claim($scope, $receipt)['preview'];
            $claimed = true;
            $scope = $this->fresh($scope, $settings, $authority);
            $charge = $this->budget->reserve('ai', 'proposal-review-ai:'.$claimedPreview['id'], $claimedPreview['digest'], $claimedPreview['maximum_microusd']);
            $chargeId = $charge['id'];
            abort_if($charge['replayed'], 409); // Crash-gap recovery must be explicitly reconciled, never redispatched.
            $scope = $this->fresh($scope, $settings, $authority);
            $this->receipts->attachCharge($scope, $receipt, $chargeId);
            $scope = $this->fresh($scope, $settings, $authority);
            $this->budget->claimDispatch($chargeId, $claimedPreview['digest']);
            $scope = $this->fresh($scope, $settings, $authority);
            $this->receipts->markDispatched($scope, $receipt);
            $scope = $this->fresh($scope, $settings, $authority);
            // No lock or DB transaction is open here. Provider must never retry or follow redirects.
            $payload = $this->receipts->dispatchPayload($scope, $receipt);
            $result = $this->provider->complete($settings, $payload, $claimedPreview['request_id']);
            $scope = $this->fresh($scope, $settings, $authority);
            $cost = Connection::ceilingCost($result['input_tokens'], $result['output_tokens'], $settings);
            $this->budget->settle($chargeId, 'accepted', ['provider_id' => $result['provider_id']], $cost);
            $scope = $this->fresh($scope, $settings, $authority);
            $value = $this->receipts->finish($scope, $receipt, 'accepted', $result['value']);
            $this->fresh($scope, $settings, $authority);

            return $value;
        } catch (Throwable $failure) {
            if ($claimed) {
                try {
                    $this->recovery->hold($pins, $receipt['preview_id'], $receipt['digest'], $chargeId);
                } catch (Throwable) {
                    // Never refund an original reservation when recovery itself cannot verify its exact anchor.
                }
                abort(503, 'The original request requires reconciliation. No automatic retry is permitted.');
            }
            throw $failure;
        }
    }
}

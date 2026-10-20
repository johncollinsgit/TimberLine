<?php

namespace App\Services\Marketplace;

use App\Models\Marketplace\Operation;
use Illuminate\Support\Str;

/** Durable original-account intent. Ambiguous provider writes are never blindly replayed. */
class MarketplaceNativeOperation
{
    public function run(string $uuid, string $kind, int $subject, array $payload, callable $call, ?int $actor = null): array
    {
        abort_unless(Str::isUuid($uuid), 422);
        $op = Operation::firstOrCreate(['uuid' => $uuid], ['connection_id' => null, 'kind' => $kind, 'subject_id' => $subject, 'status' => 'prepared', 'payload' => $payload, 'actor_user_id' => $actor]);
        abort_unless($op->kind === $kind && $op->subject_id === $subject && $op->payload === $payload, 409, 'This operation belongs to different payment terms.');
        if ($op->status === 'completed') {
            return $op->result;
        }
        abort_unless(Operation::whereKey($op->id)->where('status', 'prepared')->update(['status' => 'sending']), 409, 'This payment operation needs reconciliation before another attempt.');
        try {
            $result = $call();
            $op->update(['status' => 'completed', 'result' => $result]);

            return $result;
        } catch (\Throwable $e) {
            Operation::whereKey($op->id)->where('status', 'sending')->update(['status' => 'unknown']);
            throw $e;
        }
    }

    public function key(string $purpose): string
    {
        return \Ramsey\Uuid\Uuid::uuid5(\Ramsey\Uuid\Uuid::NAMESPACE_URL, 'marketplace:'.$purpose)->toString();
    }
}

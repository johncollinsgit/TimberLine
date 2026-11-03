<?php

declare(strict_types=1);

namespace App\Services\PaymentsCore\Quotes;

use App\Services\PaymentsCore\Contract;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Database\Connection;
use Illuminate\Database\UniqueConstraintViolationException;
use Throwable;

/** Immutable encrypted rows; no provider call, stock mutation or outer transaction required. */
final class DatabaseQuoteRepository implements QuoteRepository
{
    public function __construct(private Connection $database, private Encrypter $encrypter) {}

    public function findByRequest(int $tenantId, string $requestId): ?TrustedQuote
    {
        return $this->restore($this->database->table('payment_core_quotes')->where('tenant_id', $tenantId)
            ->where('request_id', ItemSelection::uuid($requestId))->first());
    }

    public function findById(int $tenantId, string $quoteId): ?TrustedQuote
    {
        return $this->restore($this->database->table('payment_core_quotes')->where('tenant_id', $tenantId)
            ->where('quote_id', ItemSelection::uuid($quoteId))->first());
    }

    public function saveIfAbsent(TrustedQuote $quote): TrustedQuote
    {
        $payload = Contract::json($quote->snapshot());
        try {
            $this->database->table('payment_core_quotes')->insert(['quote_id' => $quote->id, 'sale_id' => $quote->saleId,
                'tenant_id' => $quote->binding->tenantId, 'register_id' => $quote->binding->registerId, 'actor_id' => $quote->actorId,
                'site_id' => $quote->siteId, 'request_id' => $quote->requestId, 'version' => $quote->version,
                'binding_fingerprint' => $quote->binding->fingerprint(), 'scope_hash' => $quote->scopeHash(),
                'selection_hash' => $quote->selectionHash(), 'payload_hash' => hash('sha256', $payload),
                'snapshot' => $this->encrypter->encryptString($payload),
                'created_at' => $quote->createdAt->format('Y-m-d H:i:s'), 'expires_at' => $quote->expiresAt->format('Y-m-d H:i:s')]);
        } catch (UniqueConstraintViolationException $exception) {
            // A current read sees the winning committed row even if an outer MySQL RR
            // transaction had already observed the key as absent. No provider runs here.
            $existing = $this->restore($this->database->table('payment_core_quotes')->where('tenant_id', $quote->binding->tenantId)
                ->where('request_id', $quote->requestId)->lockForUpdate()->first());
            if (! $existing || $existing->scopeHash() !== $quote->scopeHash() || $existing->selectionHash() !== $quote->selectionHash()) {
                throw new QuoteException('quote_conflict', 409);
            }

            return $existing;
        }

        return $quote;
    }

    private function restore(?object $row): ?TrustedQuote
    {
        if ($row === null) {
            return null;
        }
        try {
            $payload = $this->encrypter->decryptString($row->snapshot);
            if (! hash_equals($row->payload_hash, hash('sha256', $payload))) {
                throw new QuoteException('quote_evidence_invalid', 503);
            }
            $quote = TrustedQuote::restore(json_decode($payload, true, 64, JSON_THROW_ON_ERROR));
            if ($quote->id !== $row->quote_id || $quote->saleId !== $row->sale_id || $quote->requestId !== $row->request_id
                || $quote->binding->tenantId !== (int) $row->tenant_id || $quote->binding->registerId !== $row->register_id
                || $quote->actorId !== (int) $row->actor_id || $quote->siteId !== (int) $row->site_id || $quote->version !== (int) $row->version
                || $quote->binding->fingerprint() !== $row->binding_fingerprint || $quote->scopeHash() !== $row->scope_hash
                || $quote->selectionHash() !== $row->selection_hash
                || $quote->createdAt->format('Y-m-d H:i:s') !== $row->created_at || $quote->expiresAt->format('Y-m-d H:i:s') !== $row->expires_at) {
                throw new QuoteException('quote_evidence_invalid', 503);
            }

            return $quote;
        } catch (Throwable $exception) {
            throw new QuoteException('quote_evidence_invalid', 503);
        }
    }
}

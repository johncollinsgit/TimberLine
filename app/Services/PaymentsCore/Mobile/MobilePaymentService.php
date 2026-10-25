<?php

declare(strict_types=1);

namespace App\Services\PaymentsCore\Mobile;

use App\Services\PaymentsCore\Inventory\InventoryUnavailableException;
use App\Services\PaymentsCore\MerchantBinding;
use App\Services\PaymentsCore\PaymentCore;
use App\Services\PaymentsCore\PaymentJournal;
use App\Services\PaymentsCore\ProviderUnavailableException;
use App\Services\PaymentsCore\Quotes\PaymentQuoteService;
use App\Services\PaymentsCore\Quotes\QuoteException;
use App\Services\PaymentsCore\Quotes\WebsitePaymentCatalog;
use DomainException;

final class MobilePaymentService
{
    public function __construct(
        private PaymentCore $core,
        private PaymentJournal $journal,
        private PaymentQuoteService $quotes,
        private WebsitePaymentCatalog $catalog,
        private MobilePaymentAccess $access,
        private MobileRegisterDirectory $registers,
    ) {}

    public function envelope(array $value): array
    {
        return ['contract_version' => 1, 'mode' => $this->access->fixtureMode() ? 'fixture' : 'unavailable', ...$value];
    }

    public function registers(): array
    {
        $actor = $this->access->actor('read');
        $items = [];
        foreach ($this->registers->all($actor['tenant_id']) as $id => $entry) {
            try {
                $this->access->register((string) $id, 'read');
            } catch (MobilePaymentException $error) {
                if ($error->httpStatus === 404) {
                    continue;
                }
                throw $error;
            }
            $items[] = ['register_id' => (string) $id, 'name' => mb_substr((string) ($entry['name'] ?? 'Register'), 0, 100),
                'collection_available' => false, 'blockers' => ['stock_commit_unavailable']];
            if (count($items) === 50) {
                break;
            }
        }

        return $this->envelope(['registers' => $items]);
    }

    public function catalog(string $registerId, array $input): array
    {
        $scope = $this->access->register($registerId, 'catalog');

        return $this->envelope($this->catalog->listing($scope['tenant_id'], $scope['site_id'],
            $input['cursor'] ?? null, (int) ($input['limit'] ?? 25), $input['sku'] ?? null));
    }

    public function quote(string $registerId, array $input): array
    {
        $scope = $this->access->register($registerId, 'quote');
        $binding = $this->registers->binding($scope['tenant_id'], $registerId);
        $quote = $this->quotes->createQuote($binding, $scope['actor_id'], $scope['site_id'], $input['request_id'], $input['items']);
        $this->access->register($registerId, 'quote');

        return $this->envelope(['quote' => $quote->projection()]);
    }

    public function start(string $registerId, array $input): array
    {
        $this->access->actor('collect');
        // An exact retry is an inspection, even if collection/catalog were disabled
        // or the quote expired after the original durable attempt was created.
        $scope = $this->access->register($registerId, 'read');
        try {
            $existing = $this->journal->findByRequest($scope['tenant_id'], $input['request_id']);
        } catch (DomainException) {
            throw new MobilePaymentException('payment_journal_unavailable', 503);
        }
        if ($existing !== null) {
            if ($existing['register_id'] !== $registerId || $existing['kind'] !== 'payment') {
                throw new MobilePaymentException('request_conflict', 409);
            }
            $binding = MerchantBinding::restore($existing['binding']);
            try {
                $quote = $this->quotes->loadIdentity($binding, $scope['actor_id'], $scope['site_id'], $input['quote_id'], $input['quote_version']);
            } catch (QuoteException) {
                throw new MobilePaymentException('request_conflict', 409);
            }
            if ($quote->saleId !== $existing['sale_id']) {
                throw new MobilePaymentException('request_conflict', 409);
            }

            $row = $this->core->inspect($binding, $existing['id']);
            $this->access->actor('collect');

            return $this->envelope(['attempt' => $this->projection($row)]);
        }
        $scope = $this->access->register($registerId, 'collect');
        $binding = $this->registers->binding($scope['tenant_id'], $registerId);
        $this->access->assertAllowed($binding, 'collect');
        $quote = $this->quotes->loadForAttempt($binding, $scope['actor_id'], $scope['site_id'], $input['quote_id'], $input['quote_version']);
        try {
            // The quote is a server-owned typed value. The journal creates its
            // stock holds and intent together before any provider dispatch.
            $row = $this->core->startQuoted($quote, $input['request_id']);
        } catch (InventoryUnavailableException) {
            throw new MobilePaymentException('stock_commit_unavailable', 503);
        } catch (ProviderUnavailableException) {
            throw new MobilePaymentException('provider_unavailable', 503);
        } catch (DomainException) {
            throw new MobilePaymentException('attempt_conflict', 409);
        }

        $this->access->assertAllowed($binding, 'collect');

        return $this->envelope(['attempt' => $this->projection($row)]);
    }

    public function inspect(string $registerId, string $attemptId): array
    {
        [$binding] = $this->original($registerId, $attemptId, 'inspect');

        return $this->envelope(['attempt' => $this->projection($this->core->inspect($binding, $attemptId))]);
    }

    public function reconcile(string $registerId, string $attemptId): array
    {
        [$binding] = $this->original($registerId, $attemptId, 'recover');
        try {
            $row = $this->core->recover($binding, $attemptId);
        } catch (DomainException) {
            throw new MobilePaymentException('original_provider_unavailable', 503);
        }
        $this->access->assertAllowed($binding, 'recover');

        return $this->envelope(['attempt' => $this->projection($row)]);
    }

    public function refund(string $registerId, string $attemptId, array $input): array
    {
        [$binding] = $this->original($registerId, $attemptId, 'refund');
        try {
            $row = $this->core->refund($binding, $attemptId, $input['request_id'], $input['amount_minor'], $input['reason']);
        } catch (ProviderUnavailableException) {
            throw new MobilePaymentException('original_provider_unavailable', 503);
        } catch (DomainException) {
            throw new MobilePaymentException('refund_conflict', 409);
        }
        $this->access->assertAllowed($binding, 'refund');

        return $this->envelope(['attempt' => $this->projection($row)]);
    }

    private function original(string $registerId, string $attemptId, string $operation): array
    {
        $scope = $this->access->register($registerId, $operation);
        try {
            $row = $this->journal->get($scope['tenant_id'], $attemptId);
        } catch (DomainException) {
            throw new MobilePaymentException('operation_unavailable', 404);
        }
        if ($row['register_id'] !== $registerId) {
            throw new MobilePaymentException('operation_unavailable', 404);
        }
        $binding = MerchantBinding::restore($row['binding']);
        $this->access->assertAllowed($binding, $operation);

        return [$binding, $row];
    }

    private function projection(array $row): array
    {
        return ['attempt_id' => $row['id'], 'request_id' => $row['request_id'], 'sale_id' => $row['sale_id'],
            'kind' => $row['kind'], 'state' => $row['state'], 'amount_minor' => $row['amount_minor'],
            'currency' => $row['currency'], 'version' => $row['version'],
            'canonical_confirmation' => $row['state'] === 'succeeded',
            'allowed_next_actions' => config('evergrove_payments.servicing_enabled') === true
                && in_array($row['state'], ['reserved', 'processing', 'unknown', 'review'], true) ? ['reconcile'] : []];
    }
}

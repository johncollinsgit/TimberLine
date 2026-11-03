<?php

declare(strict_types=1);

namespace App\Services\PaymentsCore;

use DomainException;

/** Server-priced and tax-reviewed terms; service/postage never imply stock. */
final readonly class SaleSnapshot
{
    public int $amountMinor;

    public array $lines;

    public function __construct(public string $saleId, public string $currency, array $lines)
    {
        Contract::id($saleId);
        if (! preg_match('/^[a-z]{3}$/D', $currency) || count($lines) < 1 || count($lines) > 50) {
            throw new DomainException('Invalid currency or lines.');
        }
        $ids = [];
        $amount = 0;
        foreach ($lines as $line) {
            $keys = array_keys($line);
            sort($keys);
            if ($keys !== ['kind', 'line_id', 'quantity', 'tax_amount_minor', 'unit_amount_minor']) {
                throw new DomainException('Unexpected line fields. Card/customer data is unsupported.');
            }
            Contract::id($line['line_id']);
            if (isset($ids[$line['line_id']]) || ! in_array($line['kind'], ['merchandise', 'printing_service', 'postage'], true)
                || ! is_int($line['quantity']) || $line['quantity'] < 1 || $line['quantity'] > 99
                || ! is_int($line['unit_amount_minor']) || ! is_int($line['tax_amount_minor'])) {
                throw new DomainException('Invalid line semantics.');
            }
            $ids[$line['line_id']] = true;
            Contract::minor($line['unit_amount_minor']);
            Contract::minor($line['tax_amount_minor'], true);
            $amount += $line['quantity'] * $line['unit_amount_minor'] + $line['tax_amount_minor'];
            Contract::minor($amount);
        }
        usort($lines, fn (array $a, array $b) => strcmp($a['line_id'], $b['line_id']));
        $this->lines = array_map(fn (array $line) => ['line_id' => $line['line_id'], 'kind' => $line['kind'],
            'quantity' => $line['quantity'], 'unit_amount_minor' => $line['unit_amount_minor'], 'tax_amount_minor' => $line['tax_amount_minor']], $lines);
        $this->amountMinor = $amount;
    }

    public function snapshot(): array
    {
        return ['sale_id' => $this->saleId, 'currency' => $this->currency,
            'amount_minor' => $this->amountMinor, 'lines' => $this->lines];
    }
}

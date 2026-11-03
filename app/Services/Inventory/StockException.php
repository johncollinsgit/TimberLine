<?php

declare(strict_types=1);

namespace App\Services\Inventory;

final class StockException extends \DomainException
{
    public function __construct(public readonly string $reason, public readonly int $status = 409)
    {
        parent::__construct($reason);
    }

    public function render(): \Illuminate\Http\JsonResponse
    {
        return response()->json(['error' => ['code' => $this->reason]], $this->status, ['Cache-Control' => 'no-store, private']);
    }
}

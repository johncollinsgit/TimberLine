<?php

declare(strict_types=1);

namespace App\Services\Pos;

final class PosException extends \DomainException
{
    public function __construct(public readonly string $blocker, public readonly int $status = 409)
    {
        parent::__construct($blocker);
    }

    public function render(): \Illuminate\Http\JsonResponse
    {
        return response()->json(['error' => ['code' => $this->blocker]], $this->status, ['Cache-Control' => 'no-store, private']);
    }
}

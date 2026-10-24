<?php

declare(strict_types=1);

namespace App\Services\Pos;

use App\Services\PaymentsCore\PaymentProvider;

final class PosProviders
{
    public function all(): array
    {
        return ['square_native' => app(SquareNativeProvider::class), 'stripe_terminal' => app(StripeTerminalProvider::class)];
    }

    public function get(string $provider): PaymentProvider
    {
        return $this->all()[$provider] ?? throw new PosException('original_provider_unavailable', 503);
    }

    public function verify(object $connection, bool $save = true): void
    {
        match ($connection->provider ?? 'square_native') {
            'square_native' => app(SquareClient::class)->verify($connection, $save),
            'stripe_terminal' => app(StripeTerminalClient::class)->verify($connection, $save),
            default => throw new PosException('original_provider_unavailable', 503),
        };
    }
}

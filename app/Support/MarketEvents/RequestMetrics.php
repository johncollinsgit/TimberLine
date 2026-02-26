<?php

namespace App\Support\MarketEvents;

class RequestMetrics
{
    protected static int $externalHttpCalls = 0;

    /** @var list<array<string,mixed>> */
    protected static array $externalHttpCallLog = [];

    public static function reset(): void
    {
        self::$externalHttpCalls = 0;
        self::$externalHttpCallLog = [];
    }

    /**
     * @param  array<string,mixed>  $context
     */
    public static function recordExternalHttpCall(string $service, array $context = []): void
    {
        self::$externalHttpCalls++;
        self::$externalHttpCallLog[] = [
            'service' => $service,
            'at' => now()->toIso8601String(),
            'context' => $context,
        ];
    }

    public static function externalHttpCalls(): int
    {
        return self::$externalHttpCalls;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public static function externalHttpCallLog(): array
    {
        return self::$externalHttpCallLog;
    }
}

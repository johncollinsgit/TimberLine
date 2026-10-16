<?php

namespace App\Services\Voice\Contracts;

/** Provider boundary only. A future ingress must resolve ownership before use. */
interface CallEventAdapter
{
    public function key(): string;

    /** Declares only implemented adapter operations, not launch readiness. */
    public function capabilities(): array;

    /** $identity comes from a verified server-side number/account mapping. */
    public function event(array $identity, string $canonicalUrl, array $form, string $signature, string $secret): array;
}

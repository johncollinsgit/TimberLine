<?php

namespace App\Services\Replacements\Contracts;

use App\Models\ReplacementModule;

interface ReplacementActivationAdapter
{
    public function supports(ReplacementModule $module): bool;

    /** @return array<string,mixed> */
    public function activate(ReplacementModule $module): array;

    /** @param array<string,mixed> $rollbackPayload */
    public function rollback(ReplacementModule $module, array $rollbackPayload): void;

    /** @return array<string,mixed> */
    public function smokeTest(ReplacementModule $module): array;
}

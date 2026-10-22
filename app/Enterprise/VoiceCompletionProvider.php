<?php

namespace App\Enterprise;

/** Transport only. Authorization, reservation and domain validation stay outside adapters. */
interface VoiceCompletionProvider
{
    public function complete(string $key, array $input, array $settings, string $instructions): array;

    public function simulated(): bool;
}

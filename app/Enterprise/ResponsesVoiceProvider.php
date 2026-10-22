<?php

namespace App\Enterprise;

use App\Services\AiBranch\ResponsesTransport;

class ResponsesVoiceProvider implements VoiceCompletionProvider
{
    public function __construct(private ResponsesTransport $transport) {}

    public function complete(string $key, array $input, array $settings, string $instructions): array
    {
        return $this->transport->complete($key, $input, $settings, $instructions);
    }

    public function simulated(): bool
    {
        return false;
    }
}

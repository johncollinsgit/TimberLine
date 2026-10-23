<?php

namespace App\Enterprise;

/** Default runtime has no model connection. Binding a paid provider needs a reviewed usage gate. */
class AskModel
{
    public function select(array $context, string $question): ?array
    {
        return null;
    }
}

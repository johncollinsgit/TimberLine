<?php

namespace App\Services\Agreements;

use Illuminate\Support\Facades\View;

class AgreementDocumentRenderer
{
    /** @param array<string,mixed> $payload */
    public function render(array $payload): string
    {
        return View::make('agreements.document', ['document' => $payload])->render();
    }

    public function hash(string $renderedContent): string
    {
        return hash('sha256', $renderedContent);
    }
}

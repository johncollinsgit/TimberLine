<?php

namespace App\Services\Automation\V2;

use Illuminate\Support\Str;

class WorkflowRunSummaryRedactor
{
    /**
     * @param  array<string,mixed>  $values
     * @return array<string,mixed>
     */
    public function redact(array $values): array
    {
        return $this->redactArray($values, 0);
    }

    public function safeError(\Throwable|string $error): string
    {
        $message = $error instanceof \Throwable ? $error->getMessage() : $error;
        $message = preg_replace(
            '/\\b(?:Bearer\\s+)?[A-Za-z0-9_\\-.]{24,}\\b/',
            '[redacted]',
            $message
        ) ?? $message;
        $message = preg_replace(
            '/[A-Z0-9._%+\\-]+@[A-Z0-9.\\-]+\\.[A-Z]{2,}/i',
            '[redacted email]',
            $message
        ) ?? $message;

        return Str::limit(trim($message), 1_000);
    }

    /**
     * @param  array<string,mixed>  $values
     * @return array<string,mixed>
     */
    protected function redactArray(array $values, int $depth): array
    {
        if ($depth >= 4) {
            return ['summary' => '[nested data omitted]'];
        }

        $redacted = [];
        foreach (array_slice($values, 0, 50, true) as $key => $value) {
            $name = strtolower((string) $key);
            if (preg_match(
                '/token|secret|password|body|note|description|email|phone|address|recipient|payload|reply_to|(?:^|_)(?:customer|name|title|subject|location|left|right|value|url)(?:_|$)/',
                $name,
            ) === 1) {
                $redacted[$key] = '[redacted]';

                continue;
            }
            if (is_array($value)) {
                $redacted[$key] = $this->redactArray($value, $depth + 1);
            } elseif (is_string($value)) {
                $redacted[$key] = preg_match(
                    '/(?:Bearer\\s+)?[A-Za-z0-9_\\-.]{24,}|[A-Z0-9._%+\\-]+@[A-Z0-9.\\-]+\\.[A-Z]{2,}/i',
                    $value,
                ) === 1
                    ? '[redacted]'
                    : Str::limit($value, 300);
            } elseif (is_scalar($value) || $value === null) {
                $redacted[$key] = $value;
            } else {
                $redacted[$key] = '['.get_debug_type($value).']';
            }
        }

        return $redacted;
    }
}

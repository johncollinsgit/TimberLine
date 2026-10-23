<?php

namespace App\Services\AiBranch;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/** Shared fixed-endpoint transport; callers own authorization, reservation and instructions. */
class ResponsesTransport
{
    public function complete(string $key, array $input, array $settings, string $instructions): array
    {
        // No retries: a timeout can already have incurred provider cost.
        $response = Http::withToken($key)->acceptJson()->connectTimeout(5)->timeout(40)
            ->post('https://api.openai.com/v1/responses', [
                'model' => $settings['model'], 'instructions' => $instructions,
                'input' => $input, 'store' => false, 'max_output_tokens' => $settings['max_output'], 'service_tier' => 'default',
                ...(isset($settings['reasoning']) ? ['reasoning' => ['effort' => $settings['reasoning']]] : ($settings['model'] === 'gpt-6-luna' ? ['reasoning' => ['effort' => 'none']] : [])),
            ])->throw()->json();
        $reply = collect($response['output'] ?? [])->where('type', 'message')->flatMap(fn ($item) => $item['content'] ?? [])
            ->where('type', 'output_text')->pluck('text')->implode("\n");
        $in = data_get($response, 'usage.input_tokens');
        $out = data_get($response, 'usage.output_tokens');
        $cached = data_get($response, 'usage.input_tokens_details.cached_tokens', 0);
        if (($response['status'] ?? '') !== 'completed' || trim($reply) === '' || strlen($reply) > 65536
            || ! is_int($in) || ! is_int($out) || ! is_int($cached) || $cached < 0 || $cached > $in || $in < 0 || $out < 0 || $in > $settings['max_input'] || $out > $settings['max_output']) {
            throw new RuntimeException('Unverifiable AI response.');
        }

        return ['reply' => $reply, 'input_tokens' => $in, 'output_tokens' => $out, 'cached_input_tokens' => $cached, 'provider_id' => mb_substr((string) ($response['id'] ?? ''), 0, 120)];
    }
}

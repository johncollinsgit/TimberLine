<?php

namespace Tests\Support;

use App\Enterprise\VoiceCompletionProvider;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Http;

/** Deterministic provider transport only. Never substitutes authorization or cost reservations. */
final class EnterpriseBrowserVoiceProvider implements VoiceCompletionProvider
{
    public static function install(Application $app): void
    {
        if (PHP_SAPI !== 'cli-server' || getenv('ENTERPRISE_APP_ENV') !== 'testing'
            || ($_SERVER['REMOTE_ADDR'] ?? '') !== '127.0.0.1'
            || getenv('ENTERPRISE_TEST_VOICE_PROVIDER') !== '1'
            || getenv('ENTERPRISE_TEST_OPERATIONS') !== '1'
            || getenv('ENTERPRISE_OPERATIONS_SCENARIO') !== 'voice-generation'
            || ! $app->environment('testing') || ! config('enterprise.runtime')
            || ! config('enterprise.voice_generation_enabled') || ! config('enterprise.voice_jobs_enabled')) {
            throw new \RuntimeException('The synthetic voice provider is restricted to its explicit loopback browser scenario.');
        }
        $testingRoot = realpath(dirname(__DIR__, 2).'/apps/enterprise/storage/framework/testing');
        $configuredDatabase = config('database.connections.enterprise.database');
        $database = is_string($configuredDatabase) && $configuredDatabase !== '' ? realpath($configuredDatabase) : false;
        $sameDirectory = $testingRoot !== false && $database !== false
            && (DIRECTORY_SEPARATOR === '\\' ? strcasecmp(dirname($database), $testingRoot) === 0 : dirname($database) === $testingRoot);
        if (config('database.default') !== 'enterprise' || config('database.connections.enterprise.driver') !== 'sqlite'
            || ! $sameDirectory || ! is_file($database)
            || ! preg_match('/^enterprise-browser-[A-Za-z0-9_-]+\.sqlite$/D', basename($database))) {
            throw new \RuntimeException('The synthetic voice provider requires this worktree\'s disposable browser SQLite database.');
        }
        Http::preventStrayRequests();
        config(['enterprise.intelligence' => ['enabled' => true, 'api_key' => 'synthetic-browser-fixture-not-a-credential',
            'model' => 'synthetic-voice-browser', 'input_rate_microusd_per_million' => 100000,
            'output_rate_microusd_per_million' => 500000, 'max_input' => 12000, 'max_output' => 800,
            'lifetime_cap_microusd' => 100000, 'rates_valid_until' => now()->addDay()->toIso8601String(), 'tenant_allowlist' => ['1']],
            'enterprise.voice_rates' => ['input_ceiling_microusd_per_million' => 125000, 'output_ceiling_microusd_per_million' => 500000]]);
        $app->instance(VoiceCompletionProvider::class, new self);
    }

    public function complete(string $key, array $input, array $settings, string $instructions): array
    {
        $payload = json_decode($input[0]['content'], true, 32, JSON_THROW_ON_ERROR)['data'];
        $sources = $payload['sources'];
        $source = null;
        foreach ($sources as $candidate) {
            if ($source === null || $candidate['ref'] === ($payload['focus'] ?? null)) {
                $source = $candidate;
            }
        }
        $value = ['intent' => 'clarify', 'answer' => 'No permitted task facts were supplied to the simulated provider.', 'refs' => [], 'draft' => null];
        if ($source !== null) {
            $question = mb_strtolower($payload['question']);
            $title = $source['title'];
            $location = $source['location'] ?? 'No location recorded';
            $value = ['intent' => 'answer', 'answer' => 'Simulated answer: '.$title.'. Recorded location: '.$location.'. Prior messages: '.count($payload['history']).'.',
                'refs' => [$source['ref']], 'draft' => null];
            if (str_starts_with($question, 'show ')) {
                $value['intent'] = 'open_job';
            } elseif (str_contains($question, 'draft')) {
                $sms = str_contains($question, 'sms') || str_contains($question, 'text');
                $value['intent'] = $sms ? 'draft_sms' : 'draft_email';
                $value['answer'] = 'Simulated proposed text from the permitted task; nothing was sent.';
                $value['draft'] = ['subject' => $sms ? '' : 'Synthetic job update',
                    'body' => 'Synthetic draft only. '.$title.'. Next action: '.($source['next_action'] ?? 'Not recorded').'.'];
            }
        }

        return ['reply' => json_encode($value, JSON_THROW_ON_ERROR), 'input_tokens' => 10, 'output_tokens' => 5,
            'provider_id' => 'synthetic-voice-browser-receipt'];
    }

    public function simulated(): bool
    {
        return true;
    }
}

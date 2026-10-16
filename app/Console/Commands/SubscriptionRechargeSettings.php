<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Subscriptions\SubscriptionRechargeManualSettingsService;
use Illuminate\Console\Command;

class SubscriptionRechargeSettings extends Command
{
    protected $signature = 'subscriptions:recharge-settings {action : summary or import}
        {--tenant=} {--actor=} {--connection=} {--request=} {--file= : Private 0600 local JSON evidence file}';

    protected $description = 'Retain reviewed Recharge settings and exact isolated local drafts; never changes source or live configuration.';

    public function handle(SubscriptionRechargeManualSettingsService $settings): int
    {
        try {
            $tenantId = $this->positive('tenant');
            $actor = User::query()->findOrFail($this->positive('actor'));
            $connectionId = $this->positive('connection');
            $settings->summary($tenantId, $actor, $connectionId); // Authorize before opening a private input file.
            $result = match ($this->argument('action')) {
                'summary' => $settings->summary($tenantId, $actor, $connectionId),
                'import' => $settings->record($tenantId, $actor, $connectionId, (string) $this->option('request'), $this->document()),
                default => throw new \InvalidArgumentException,
            };
            $this->line(json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        } catch (\Throwable) {
            $this->error('Recharge settings evidence unavailable. Check explicit IDs, manager access, private file permissions, source identity and prior version.');

            return self::FAILURE;
        }
    }

    private function document(): array
    {
        $path = $this->option('file');
        if (! is_string($path) || ! str_starts_with($path, '/') || is_link($path)) {
            throw new \InvalidArgumentException;
        }
        $before = lstat($path);
        if (! $before || ($before['mode'] & 0170000) !== 0100000 || ($before['mode'] & 0777) !== 0600
            || (function_exists('posix_geteuid') && $before['uid'] !== posix_geteuid())
            || $before['size'] > SubscriptionRechargeManualSettingsService::MAX_DOCUMENT_BYTES) {
            throw new \InvalidArgumentException;
        }
        $stream = fopen($path, 'rb');
        if ($stream === false) {
            throw new \InvalidArgumentException;
        }
        try {
            $opened = fstat($stream);
            if (! $before || ! $opened || $before['ino'] !== $opened['ino'] || $before['dev'] !== $opened['dev']
                || ($opened['mode'] & 0170000) !== 0100000 || ($opened['mode'] & 0777) !== 0600
                || (function_exists('posix_geteuid') && $opened['uid'] !== posix_geteuid())
                || $opened['size'] > SubscriptionRechargeManualSettingsService::MAX_DOCUMENT_BYTES) {
                throw new \InvalidArgumentException;
            }
            $json = stream_get_contents($stream, SubscriptionRechargeManualSettingsService::MAX_DOCUMENT_BYTES + 1);
            if ($json === false || strlen($json) > SubscriptionRechargeManualSettingsService::MAX_DOCUMENT_BYTES) {
                throw new \InvalidArgumentException;
            }
            $document = json_decode($json, true, 12, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
            if (! is_array($document) || array_is_list($document)) {
                throw new \InvalidArgumentException;
            }

            return $document;
        } finally {
            fclose($stream);
        }
    }

    private function positive(string $option): int
    {
        $value = $this->option($option);
        if (! is_string($value) || ! preg_match('/^[1-9][0-9]{0,9}$/D', $value)) {
            throw new \InvalidArgumentException;
        }

        return (int) $value;
    }
}

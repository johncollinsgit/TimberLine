<?php

namespace App\Proposals;

use Carbon\CarbonImmutable;

/** Actual loopback VERSION evidence only. This does not install, reload or activate ClamAV. */
class ReviewerWorkspaceScannerEvidence
{
    public function version(array $config): ?string
    {
        if (config('service_proposals.reviewer_workspace.image_access.scan_enabled') !== true || config('team_work.scanner.enabled') !== true
            || ($config['host'] ?? null) !== '127.0.0.1' || ! is_int($config['port'] ?? null) || $config['port'] < 1 || $config['port'] > 65535) {
            return null;
        }
        $socket = @stream_socket_client('tcp://127.0.0.1:'.$config['port'], $code, $reason, 3);
        if (! is_resource($socket)) {
            return null;
        }
        try {
            stream_set_timeout($socket, 3);
            if (fwrite($socket, "zVERSION\0") !== 9) {
                return null;
            }
            $reply = '';
            while (! feof($socket) && strlen($reply) <= 512 && ! str_contains($reply, "\0")) {
                $part = fread($socket, 128);
                if ($part === false || $part === '') {
                    break;
                }
                $reply .= $part;
            }
            if (strlen($reply) > 512 || ! str_ends_with($reply, "\0") || substr_count($reply, "\0") !== 1) {
                return null;
            }

            return substr($reply, 0, -1);
        } finally {
            fclose($socket);
        }
    }

    /** Parse only an authentic adapter reply; callers cannot submit this evidence through an HTTP body. */
    public function parse(?string $reply, array $config): ?array
    {
        if (! is_string($reply) || strlen($reply) > 512 || ! preg_match('/^ClamAV ([0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}(?:\.[0-9]{1,3})?)\/([1-9][0-9]{0,9})\/([A-Z][a-z]{2} [A-Z][a-z]{2} {1,2}[0-9]{1,2} [0-9]{2}:[0-9]{2}:[0-9]{2} [0-9]{4})$/D', $reply, $m)) {
            return null;
        }
        try {
            $at = CarbonImmutable::createFromFormat('!D M j H:i:s Y', preg_replace('/ +/', ' ', $m[3]), 'UTC');
            if (! $at || $at->format('D M j H:i:s Y') !== preg_replace('/ +/', ' ', $m[3]) || $m[1] !== $config['engine_version']
                || (int) $m[2] < $config['minimum_database_serial'] || $at->isAfter(now()->addMinutes(5))
                || $at->isBefore(now()->subSeconds($config['maximum_definition_age_seconds']))) {
                return null;
            }

            return ['engine' => $m[1], 'database_serial' => (int) $m[2], 'database_at' => $at->utc()->format('Y-m-d\TH:i:s\Z'),
                'reply_sha256' => hash('sha256', $reply)];
        } catch (\Throwable) {
            return null;
        }
    }
}

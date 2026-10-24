<?php

namespace App\Proposals;

/** Separate PDF gate, existing loopback VERSION transport, exact inherited parser. No daemon activation. */
class ReviewerWorkspaceDownloadEvidence extends ReviewerWorkspaceScannerEvidence
{
    public function version(array $config): ?string
    {
        if (config('service_proposals.reviewer_workspace.downloads.scan_enabled') !== true || config('team_work.scanner.enabled') !== true
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
}

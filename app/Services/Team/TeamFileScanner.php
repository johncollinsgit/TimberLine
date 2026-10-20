<?php

namespace App\Services\Team;

/** Trusted scanner seam. An unavailable scanner NEVER makes uploaded bytes downloadable. */
class TeamFileScanner
{
    public function scan(string $privateAbsolutePath, string $mimeType, string $sha256): string
    {
        if (! config('team_work.scanner.enabled')) {
            return 'unavailable';
        }
        if (! is_file($privateAbsolutePath) || ! hash_equals($sha256, hash_file('sha256', $privateAbsolutePath)) || filesize($privateAbsolutePath) > config('team_work.file_max_bytes')) {
            return 'rejected';
        }
        $host = (string) config('team_work.scanner.host');
        $port = (int) config('team_work.scanner.port');
        if (! preg_match('/^[a-zA-Z0-9.:-]{1,253}$/D', $host) || $port < 1 || $port > 65535) {
            return 'unavailable';
        }
        $socket = @stream_socket_client('tcp://'.$host.':'.$port, $error, $reason, 3);
        if (! $socket) {
            return 'unavailable';
        }
        $file = null;
        try {
            stream_set_timeout($socket, 10);
            $file = fopen($privateAbsolutePath, 'rb');
            $write = function (string $bytes) use ($socket): bool {
                while ($bytes !== '') {
                    $written = fwrite($socket, $bytes);
                    if (! $written) {
                        return false;
                    } $bytes = substr($bytes, $written);
                }

                return true;
            };
            if (! $file || ! $write("zINSTREAM\0")) {
                return 'unavailable';
            }
            while (! feof($file)) {
                $chunk = fread($file, 65536);
                if ($chunk === false) {
                    return 'unavailable';
                } if ($chunk === '') {
                    break;
                } if (! $write(pack('N', strlen($chunk)).$chunk)) {
                    return 'unavailable';
                }
            }
            if (! $write(pack('N', 0))) {
                return 'unavailable';
            }
            $reply = '';
            while (! feof($socket) && strlen($reply) < 4096 && ! str_contains($reply, "\0")) {
                $part = fread($socket, 1024);
                if ($part === false || $part === '') {
                    break;
                } $reply .= $part;
            }
            if (! hash_equals($sha256, hash_file('sha256', $privateAbsolutePath))) {
                return 'rejected';
            }
            if (trim($reply, "\0\r\n ") === 'stream: OK') {
                return 'clean';
            }

            return str_contains($reply, ' FOUND') ? 'rejected' : 'unavailable';
        } finally {
            if (is_resource($file)) {
                fclose($file);
            } fclose($socket);
        }
    }
}

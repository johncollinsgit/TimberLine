<?php

namespace App\Services\CampusFacilities;

use RuntimeException;

/** Public fictional demo only; never opens tenant, user or provider records. */
class DemoStore
{
    public function directory(): string
    {
        $path = (string) config('campus_facilities.storage_path');
        if (! is_dir($path) && ! mkdir($path, 0700, true) && ! is_dir($path)) {
            throw new RuntimeException('The demo workspace is temporarily unavailable.');
        }

        return $path;
    }

    public function access(callable $callback, bool $write = true): mixed
    {
        $directory = $this->directory();
        $lock = fopen($directory.'/workspace.lock', 'c');
        if (! $lock || ! flock($lock, LOCK_EX)) {
            throw new RuntimeException('The demo workspace is busy. Please retry.');
        }
        try {
            $path = $directory.'/workspace.json';
            $exists = is_file($path);
            $state = $exists ? json_decode(file_get_contents($path), true, 64, JSON_THROW_ON_ERROR) : $this->seed();
            $state += ['participants' => [], 'comment_receipts' => [], 'verification_requests' => [], 'mail_usage' => []];
            $upgraded = $this->addBuildingSamples($state);
            $result = $callback($state);
            if ($write || ! $exists || $upgraded) {
                $json = json_encode($state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
                if (strlen($json) > 8_000_000) {
                    throw new RuntimeException('The shared demo is full. Ask the demo administrator to reset it.');
                }
                $temporary = tempnam($directory, '.workspace-');
                try {
                    chmod($temporary, 0600);
                    if (file_put_contents($temporary, $json) !== strlen($json) || ! rename($temporary, $path)) {
                        throw new RuntimeException('The update could not be saved. Your draft is preserved.');
                    }
                } finally {
                    if (is_file($temporary)) {
                        unlink($temporary);
                    }
                }
            }

            return $result;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function seed(): array
    {
        $tickets = json_decode(file_get_contents(resource_path('data/campus-facilities/tickets.json')), true, 64, JSON_THROW_ON_ERROR);
        $keys = [];
        foreach ($tickets as $ticket) {
            $keys['seed-'.$ticket['id']] = ['id' => $ticket['id'], 'digest' => null];
        }

        $state = ['schema' => 1, 'tickets' => array_column($tickets, null, 'id'), 'request_keys' => $keys, 'attachments' => [], 'usage' => []];
        $this->addBuildingSamples($state);

        return $state;
    }

    /** Add this reviewed fixture set once, preserving every existing ticket and history. */
    private function addBuildingSamples(array &$state): bool
    {
        if (($state['building_samples'] ?? null) === 1) {
            return false;
        }
        $samples = json_decode(file_get_contents(resource_path('data/campus-facilities/building-jobs.json')), true, 64, JSON_THROW_ON_ERROR);
        foreach ($samples as $ticket) {
            if (! isset($state['tickets'][$ticket['id']]) && count($state['tickets']) < 200) {
                $state['tickets'][$ticket['id']] = $ticket;
                $state['request_keys']['seed-'.$ticket['id']] = ['id' => $ticket['id'], 'digest' => null];
            }
        }
        $state['building_samples'] = 1;

        return true;
    }
}

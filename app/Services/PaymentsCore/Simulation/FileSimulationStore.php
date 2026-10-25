<?php

declare(strict_types=1);

namespace App\Services\PaymentsCore\Simulation;

use DomainException;
use RuntimeException;

/** Explicit private local fixture storage. Never a production provider ledger. */
final class FileSimulationStore
{
    public function __construct(private string $directory)
    {
        $this->assertFixture();
        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException('Fixture storage unavailable.');
        }
        if (is_link($directory) || ! is_writable($directory)) {
            throw new RuntimeException('Fixture storage unavailable.');
        }
    }

    /** Serialized durable create/read; no network, accounts, or raw card data. */
    public function locked(callable $action): mixed
    {
        $this->assertFixture();
        $lock = fopen($this->directory.'/fixture.lock', 'c+');
        if ($lock === false || ! flock($lock, LOCK_EX)) {
            throw new RuntimeException('Fixture lock unavailable.');
        }
        try {
            $path = $this->directory.'/fixture.json';
            $state = is_file($path) ? json_decode(file_get_contents($path), true, 32, JSON_THROW_ON_ERROR) : [];
            if (! is_array($state)) {
                throw new RuntimeException('Fixture state invalid.');
            }
            $result = $action($state);
            $temporary = tempnam($this->directory, 'fixture-');
            if ($temporary === false) {
                throw new RuntimeException('Fixture storage unavailable.');
            }
            try {
                if (file_put_contents($temporary, json_encode($state, JSON_THROW_ON_ERROR), LOCK_EX) === false
                    || ! chmod($temporary, 0600) || ! rename($temporary, $path)) {
                    throw new RuntimeException('Fixture state could not be persisted.');
                }
            } finally {
                if (is_file($temporary)) {
                    unlink($temporary);
                }
            }

            return $result;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function assertFixture(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new DomainException('Simulation is unavailable outside explicit local/testing composition.');
        }
    }
}

<?php

namespace App\Console\Commands;

use App\Services\Pos\PosUncollectedClosure;
use Illuminate\Console\Command;

final class PosCloseUncollectedCommand extends Command
{
    protected $signature = 'pos:close-uncollected {--tenant=} {--sale=} {--actor=} {--device-evidence=} {--apply : Cancel the verified unpaid legacy order and retire its register}';

    protected $description = 'Preview-first operational review; preserves unknown financial evidence and original-provider recovery';

    public function handle(PosUncollectedClosure $review): int
    {
        try {
            $file = (string) $this->option('device-evidence');
            if (! is_file($file) || is_link($file) || (fileperms($file) & 0077) !== 0 || filesize($file) > 20000 || ! preg_match('/^[a-f0-9]{32}$/D', (string) $this->option('sale'))) {
                throw new \DomainException('Private review arguments required.');
            }
            $result = $review->review((int) $this->option('tenant'), (string) $this->option('sale'), (int) $this->option('actor'),
                json_decode(file_get_contents($file), true, 32, JSON_THROW_ON_ERROR), (bool) $this->option('apply'));
            $this->line(json_encode($result, JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (\App\Services\Pos\PosException $error) {
            $this->error($error->blocker);
        } catch (\Throwable) {
            $this->error('Legacy review could not be completed. Original evidence is retained.');
        }

        return self::FAILURE;
    }
}

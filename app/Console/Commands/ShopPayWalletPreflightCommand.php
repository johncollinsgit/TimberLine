<?php

namespace App\Console\Commands;

use App\Services\Billing\ShopPayWalletPreflight;
use Illuminate\Console\Command;
use RuntimeException;

class ShopPayWalletPreflightCommand extends Command
{
    protected $signature = 'payments:shop-pay-preflight
        {--credentials= : Owner-only JSON file containing the merchant Wallet credentials}
        {--probe-session : Create a fictional $1 unpaid session; never submit a payment}';

    protected $description = 'Verify a merchant-owned Shop Pay Wallet integration without charging, publishing, or enabling checkout';

    public function handle(): int
    {
        try {
            $path = (string) $this->option('credentials');
            if ($path === '' || is_link($path) || ! is_file($path) || ! is_readable($path)
                || filesize($path) > 65536 || (fileperms($path) & 0077) !== 0) {
                throw new RuntimeException('Provide an owner-only regular JSON file (chmod 600).');
            }
            try {
                $credentials = json_decode(file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                throw new RuntimeException('The credential file must contain valid JSON.');
            }
            if (! is_array($credentials)) {
                throw new RuntimeException('The credential file must contain a JSON object.');
            }
            $wallet = new ShopPayWalletPreflight($credentials);
            $result = $this->option('probe-session') ? $wallet->probeSession() : $wallet->inspect();
            $this->line(json_encode($result, JSON_THROW_ON_ERROR));
            $this->info('No payment submitted, tenant connected, or checkout enabled.');

            return self::SUCCESS;
        } catch (RuntimeException $error) {
            $this->error($error->getMessage());

            return self::FAILURE;
        }
    }
}

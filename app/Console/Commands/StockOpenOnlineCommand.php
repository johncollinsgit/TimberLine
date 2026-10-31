<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Inventory\StockException;
use App\Services\Inventory\StockOnlineOpening;
use Illuminate\Console\Command;

final class StockOpenOnlineCommand extends Command
{
    protected $signature = 'stock:open-online {--tenant=} {--actor=} {--review=} {--apply}';

    protected $description = 'Preview reviewed physical opening counts, exact Shopify mappings and current online commitments';

    public function handle(StockOnlineOpening $opening): int
    {
        try {
            $tenant = (int) $this->option('tenant');
            $actor = (int) $this->option('actor');
            $owner = User::find($actor);
            $file = (string) $this->option('review');
            if (! $owner?->is_active || ! $owner->email_verified_at || ! $owner->tenants()->where('tenants.id', $tenant)->wherePivot('membership_active', true)->wherePivotIn('role', ['owner', 'admin'])->exists()
                || ! is_file($file) || is_link($file) || (fileperms($file) & 0077) !== 0 || filesize($file) > 150000) {
                throw new StockException('stock_owner_review_required', 403);
            }
            $input = json_decode(file_get_contents($file), true, 32, JSON_THROW_ON_ERROR);
            $result = $opening->review($tenant, $actor, $input, (bool) $this->option('apply'));
            $this->line(json_encode($result, JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (StockException $error) {
            $this->error($error->reason);
        } catch (\Throwable) {
            $this->error('Opening review could not be completed. No provider quantity was set.');
        }

        return self::FAILURE;
    }
}

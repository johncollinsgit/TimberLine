<?php

namespace App\Console\Commands;

use App\Services\ManagedWebsite\WebsiteDropshipDraftHandoff;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use RuntimeException;

class WebsiteDropshipDraft extends Command
{
    protected $signature = 'website:dropship-draft
        {tenant : Seller tenant ID}
        {site : Seller site ID}
        {order : Native Website order ID}
        {--submit : Approve sending the shipping address and items to the configured supplier once}
        {--reconcile : Find an existing supplier draft without creating one}';

    protected $description = 'Submit or reconcile a paid Website order as a private, zero-dollar supplier draft';

    public function handle(WebsiteDropshipDraftHandoff $handoff): int
    {
        if ($this->option('submit') === $this->option('reconcile')) {
            $this->error('Choose exactly one of --submit and --reconcile.');

            return self::INVALID;
        }

        $ids = array_map(fn (string $key) => filter_var($this->argument($key), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]), ['tenant', 'site', 'order']);
        if (in_array(false, $ids, true)) {
            $this->error('Provide positive seller tenant, site and order IDs.');

            return self::INVALID;
        }

        try {
            $id = $this->option('submit') ? $handoff->submit(...$ids) : $handoff->reconcile(...$ids);
        } catch (QueryException) {
            $this->error('The local supplier draft claim could not be read or recorded. Check the database and reconcile before resubmitting.');

            return self::FAILURE;
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info('Supplier draft: '.$id);

        return self::SUCCESS;
    }
}

<?php

namespace App\Console\Commands;

use App\Models\CustomerAccessRequest;
use App\Services\Shopify\ShopifyStores;
use App\Services\Shopify\ShopifyWholesaleCustomerApprovalService;
use Illuminate\Console\Command;
use Throwable;

class ShopifyBackfillWholesaleApprovals extends Command
{
    protected $signature = 'shopify:backfill-wholesale-approvals
        {--limit= : Soft maximum number of approved requests to process}
        {--chunk=100 : Number of requests to inspect per chunk}
        {--dry-run : Preview the backfill without writing to Shopify}';

    protected $description = 'Backfill approved wholesale applicants into Shopify and ensure the wholesale tag is applied.';

    public function handle(ShopifyWholesaleCustomerApprovalService $service): int
    {
        $store = ShopifyStores::find('wholesale');
        if (! is_array($store)) {
            $issues = ShopifyStores::unresolvedMessages('wholesale');
            foreach ($issues !== [] ? $issues : ['Wholesale Shopify store is not configured.'] as $issue) {
                $this->error($issue);
            }

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $chunkSize = max(1, (int) ($this->option('chunk') ?: 100));
        $limitOption = $this->option('limit');
        $limit = is_numeric($limitOption) ? max(1, (int) $limitOption) : null;

        $summary = [
            'examined' => 0,
            'processed' => 0,
            'created_tagged' => 0,
            'updated_tagged' => 0,
            'already_tagged' => 0,
            'skipped' => 0,
            'failed' => 0,
        ];

        $query = CustomerAccessRequest::query()
            ->with('user')
            ->where('status', 'approved')
            ->orderBy('id');

        $query->chunkById($chunkSize, function ($requests) use (&$summary, $dryRun, $limit, $service): bool {
            foreach ($requests as $request) {
                if ($limit !== null && $summary['processed'] >= $limit) {
                    return false;
                }

                $summary['examined']++;

                $email = strtolower(trim((string) $request->email));
                if ($email === '') {
                    $summary['skipped']++;

                    continue;
                }

                if ($dryRun) {
                    $summary['processed']++;
                    $this->line('would_sync request_id='.(int) $request->id.' email='.$email);

                    continue;
                }

                try {
                    $result = $service->syncByEmail($email, [
                        'name' => trim((string) ($request->name ?: $request->user?->name ?: '')),
                    ]);

                    $summary['processed']++;
                    $status = (string) ($result['status'] ?? 'updated_tagged');
                    if (array_key_exists($status, $summary)) {
                        $summary[$status]++;
                    }
                } catch (Throwable $e) {
                    $summary['failed']++;
                    $this->error('request_id='.(int) $request->id.' email='.$email.' failed: '.$e->getMessage());
                }
            }

            return true;
        });

        $this->info(sprintf(
            'examined=%d processed=%d created_tagged=%d updated_tagged=%d already_tagged=%d skipped=%d failed=%d dry_run=%s',
            $summary['examined'],
            $summary['processed'],
            $summary['created_tagged'],
            $summary['updated_tagged'],
            $summary['already_tagged'],
            $summary['skipped'],
            $summary['failed'],
            $dryRun ? 'yes' : 'no'
        ));

        return $summary['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}

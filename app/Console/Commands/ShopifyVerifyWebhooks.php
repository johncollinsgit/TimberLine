<?php

namespace App\Console\Commands;

use App\Services\Shopify\ShopifyStores;
use App\Services\Shopify\ShopifyWebhookSubscriptionService;
use Illuminate\Console\Command;

class ShopifyVerifyWebhooks extends Command
{
    protected $signature = 'shopify:webhooks:verify
        {store? : Store key (retail|wholesale|all)}
        {--store= : Store key override (retail|wholesale|all)}
        {--repair : Create missing required subscriptions and repair mismatched callbacks}';

    protected $description = 'Verify and optionally repair required Shopify webhook subscriptions for installed stores.';

    public function handle(ShopifyWebhookSubscriptionService $subscriptionService): int
    {
        $storeArg = $this->option('store') ?: $this->argument('store');
        $stores = ShopifyStores::resolve(is_string($storeArg) && trim($storeArg) !== '' ? trim($storeArg) : null);
        $repair = (bool) $this->option('repair');

        if ($stores === []) {
            $this->renderStoreResolutionErrors($storeArg);

            return self::FAILURE;
        }

        $totals = [
            'stores' => 0,
            'failed_stores' => 0,
            'drift_stores' => 0,
            'required_topics' => 0,
            'ok' => 0,
            'missing' => 0,
            'mismatch' => 0,
            'created' => 0,
            'repaired' => 0,
            'failed_topics' => 0,
            'duplicates' => 0,
        ];

        foreach ($stores as $store) {
            $result = $subscriptionService->verifyStore($store, $repair);
            $totals['stores']++;

            $status = (string) ($result['status'] ?? 'failed');
            $storeKey = (string) ($result['store_key'] ?? ($store['key'] ?? 'unknown'));
            $this->line("store={$storeKey}");
            $this->line('mode=' . ($repair ? 'repair' : 'verify'));
            $this->line("status={$status}");

            $counts = is_array($result['counts'] ?? null) ? $result['counts'] : [];
            $requiredCount = (int) ($result['required_count'] ?? 0);
            $totals['required_topics'] += $requiredCount;

            foreach ([
                'ok' => 'ok',
                'missing' => 'missing',
                'mismatch' => 'mismatch',
                'created' => 'created',
                'repaired' => 'repaired',
                'failed' => 'failed_topics',
                'duplicates' => 'duplicates',
            ] as $key => $totalKey) {
                $value = (int) ($counts[$key] ?? 0);
                $this->line("{$key}={$value}");
                $totals[$totalKey] += $value;
            }

            $topics = is_array($result['topics'] ?? null) ? $result['topics'] : [];
            foreach ($topics as $topicResult) {
                if (! is_array($topicResult)) {
                    continue;
                }

                $topic = (string) ($topicResult['topic'] ?? '');
                $topicStatus = (string) ($topicResult['status'] ?? 'unknown');
                $callback = (string) ($topicResult['callback'] ?? '');
                $existing = (string) ($topicResult['existing_callback'] ?? '');
                $this->line("topic={$topic} state={$topicStatus}");
                if ($callback !== '') {
                    $this->line("topic_callback={$callback}");
                }
                if ($existing !== '') {
                    $this->line("existing_callback={$existing}");
                }
                if (! empty($topicResult['error'])) {
                    $this->line('error=' . (string) $topicResult['error']);
                }
            }

            if ($status === 'failed') {
                $totals['failed_stores']++;
                $this->error("Webhook verification failed for store '{$storeKey}'.");
            } elseif ($status === 'drift') {
                $totals['drift_stores']++;
                $this->warn("Webhook drift detected for store '{$storeKey}'. Run with --repair to fix.");
            }
        }

        $this->line('summary_stores=' . $totals['stores']);
        $this->line('summary_failed_stores=' . $totals['failed_stores']);
        $this->line('summary_drift_stores=' . $totals['drift_stores']);
        $this->line('summary_required_topics=' . $totals['required_topics']);
        $this->line('summary_ok=' . $totals['ok']);
        $this->line('summary_missing=' . $totals['missing']);
        $this->line('summary_mismatch=' . $totals['mismatch']);
        $this->line('summary_created=' . $totals['created']);
        $this->line('summary_repaired=' . $totals['repaired']);
        $this->line('summary_failed_topics=' . $totals['failed_topics']);
        $this->line('summary_duplicates=' . $totals['duplicates']);

        if ($totals['failed_stores'] > 0 || $totals['failed_topics'] > 0) {
            return self::FAILURE;
        }

        if (! $repair && $totals['drift_stores'] > 0) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    protected function renderStoreResolutionErrors(mixed $storeArg): void
    {
        $normalized = is_string($storeArg) && trim($storeArg) !== '' ? trim($storeArg) : null;
        $issues = ShopifyStores::unresolvedMessages($normalized);

        if ($issues === []) {
            $this->error('No valid Shopify store configuration found for the given store key.');

            return;
        }

        foreach ($issues as $issue) {
            $this->error($issue);
        }
    }
}

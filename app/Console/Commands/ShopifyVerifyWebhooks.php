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
        {--required-only : Verify only required Shopify stores configured for launch gating}
        {--repair : Create missing required subscriptions and repair mismatched callbacks}';

    protected $description = 'Verify and optionally repair required Shopify webhook subscriptions for installed stores.';

    public function handle(ShopifyWebhookSubscriptionService $subscriptionService): int
    {
        $storeArg = $this->option('store') ?: $this->argument('store');
        $normalizedStoreArg = is_string($storeArg) && trim($storeArg) !== '' ? trim($storeArg) : null;
        $requiredOnly = (bool) $this->option('required-only');
        [$stores, $resolutionIssues] = $this->resolveStoresForRun($normalizedStoreArg, $requiredOnly);
        $repair = (bool) $this->option('repair');

        if ($stores === []) {
            $this->renderStoreResolutionErrors($normalizedStoreArg, $resolutionIssues);

            return self::FAILURE;
        }

        $requiredStoreKeys = ShopifyStores::requiredStoreKeys();
        $requiredLookup = array_fill_keys($requiredStoreKeys, true);

        $totals = [
            'stores' => 0,
            'required_stores' => 0,
            'optional_stores' => 0,
            'failed_stores' => 0,
            'drift_stores' => 0,
            'required_failed_stores' => 0,
            'optional_failed_stores' => 0,
            'required_drift_stores' => 0,
            'optional_drift_stores' => 0,
            'required_topics' => 0,
            'ok' => 0,
            'missing' => 0,
            'mismatch' => 0,
            'created' => 0,
            'repaired' => 0,
            'failed_topics' => 0,
            'required_failed_topics' => 0,
            'optional_failed_topics' => 0,
            'duplicates' => 0,
            'required_resolution_issues' => 0,
            'optional_resolution_issues' => 0,
        ];

        foreach ($resolutionIssues as $issue) {
            $issueStoreKey = $this->extractStoreKeyFromIssue($issue);
            $isRequiredIssue = $issueStoreKey !== null
                ? isset($requiredLookup[$issueStoreKey])
                : $requiredOnly;

            if ($isRequiredIssue) {
                $totals['required_resolution_issues']++;
                $this->error("required_store_issue={$issue}");
            } else {
                $totals['optional_resolution_issues']++;
                $this->warn("optional_store_issue={$issue}");
            }
        }

        foreach ($stores as $store) {
            $result = $subscriptionService->verifyStore($store, $repair);
            $totals['stores']++;

            $status = (string) ($result['status'] ?? 'failed');
            $storeKey = (string) ($result['store_key'] ?? ($store['key'] ?? 'unknown'));
            $isRequiredStore = isset($requiredLookup[$storeKey]);
            $storeRole = $isRequiredStore ? 'required' : 'optional';
            $this->line("store={$storeKey}");
            $this->line("store_role={$storeRole}");
            $this->line('mode=' . ($repair ? 'repair' : 'verify'));
            $this->line("status={$status}");

            if ($isRequiredStore) {
                $totals['required_stores']++;
            } else {
                $totals['optional_stores']++;
            }

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
            $failedTopics = (int) ($counts['failed'] ?? 0);
            if ($isRequiredStore) {
                $totals['required_failed_topics'] += $failedTopics;
            } else {
                $totals['optional_failed_topics'] += $failedTopics;
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
                if ($isRequiredStore) {
                    $totals['required_failed_stores']++;
                    $this->error("Webhook verification failed for required store '{$storeKey}'.");
                } else {
                    $totals['optional_failed_stores']++;
                    $this->warn("Webhook verification failed for optional store '{$storeKey}' (non-blocking).");
                }
            } elseif ($status === 'drift') {
                $totals['drift_stores']++;
                if ($isRequiredStore) {
                    $totals['required_drift_stores']++;
                    $this->warn("Webhook drift detected for required store '{$storeKey}'. Run with --repair to fix.");
                } else {
                    $totals['optional_drift_stores']++;
                    $this->warn("Webhook drift detected for optional store '{$storeKey}' (non-blocking). Run with --repair to fix.");
                }
            }
        }

        $this->line('summary_stores=' . $totals['stores']);
        $this->line('summary_required_stores=' . $totals['required_stores']);
        $this->line('summary_optional_stores=' . $totals['optional_stores']);
        $this->line('summary_failed_stores=' . $totals['failed_stores']);
        $this->line('summary_drift_stores=' . $totals['drift_stores']);
        $this->line('summary_required_failed_stores=' . $totals['required_failed_stores']);
        $this->line('summary_optional_failed_stores=' . $totals['optional_failed_stores']);
        $this->line('summary_required_drift_stores=' . $totals['required_drift_stores']);
        $this->line('summary_optional_drift_stores=' . $totals['optional_drift_stores']);
        $this->line('summary_required_topics=' . $totals['required_topics']);
        $this->line('summary_ok=' . $totals['ok']);
        $this->line('summary_missing=' . $totals['missing']);
        $this->line('summary_mismatch=' . $totals['mismatch']);
        $this->line('summary_created=' . $totals['created']);
        $this->line('summary_repaired=' . $totals['repaired']);
        $this->line('summary_failed_topics=' . $totals['failed_topics']);
        $this->line('summary_required_failed_topics=' . $totals['required_failed_topics']);
        $this->line('summary_optional_failed_topics=' . $totals['optional_failed_topics']);
        $this->line('summary_duplicates=' . $totals['duplicates']);
        $this->line('summary_required_resolution_issues=' . $totals['required_resolution_issues']);
        $this->line('summary_optional_resolution_issues=' . $totals['optional_resolution_issues']);

        if (
            $totals['optional_failed_stores'] > 0
            || $totals['optional_drift_stores'] > 0
            || $totals['optional_failed_topics'] > 0
            || $totals['optional_resolution_issues'] > 0
        ) {
            $this->warn('Optional Shopify stores have webhook/auth drift. Launch gating remains based on required stores only.');
        }

        if (
            $totals['required_failed_stores'] > 0
            || $totals['required_failed_topics'] > 0
            || $totals['required_resolution_issues'] > 0
        ) {
            return self::FAILURE;
        }

        if (! $repair && $totals['required_drift_stores'] > 0) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @return array{0:array<int,array<string,mixed>>,1:array<int,string>}
     */
    protected function resolveStoresForRun(?string $storeArg, bool $requiredOnly): array
    {
        $normalized = is_string($storeArg) ? trim($storeArg) : null;
        $requestedAll = $normalized === null || $normalized === '' || strtolower($normalized) === 'all';

        if (! $requiredOnly || ! $requestedAll) {
            $stores = ShopifyStores::resolve($normalized !== '' ? $normalized : null);
            $issues = ShopifyStores::unresolvedMessages($normalized !== '' ? $normalized : null);

            return [$stores, $issues];
        }

        $requiredKeys = ShopifyStores::requiredStoreKeys();
        if ($requiredKeys === []) {
            return [[], ['No required Shopify store keys are configured.']];
        }

        $stores = [];
        $issues = [];

        foreach ($requiredKeys as $requiredKey) {
            $resolved = ShopifyStores::resolve($requiredKey);
            if ($resolved === []) {
                $issues = array_merge($issues, ShopifyStores::unresolvedMessages($requiredKey));
                continue;
            }

            foreach ($resolved as $store) {
                $stores[] = $store;
            }
        }

        return [$stores, array_values(array_unique($issues))];
    }

    protected function renderStoreResolutionErrors(mixed $storeArg, array $issues = []): void
    {
        if ($issues === []) {
            $normalized = is_string($storeArg) && trim($storeArg) !== '' ? trim($storeArg) : null;
            $issues = ShopifyStores::unresolvedMessages($normalized);
        }

        if ($issues === []) {
            $this->error('No valid Shopify store configuration found for the given store key.');

            return;
        }

        foreach ($issues as $issue) {
            $this->error($issue);
        }
    }

    protected function extractStoreKeyFromIssue(string $issue): ?string
    {
        if (preg_match('/^(retail|wholesale)\b/i', trim($issue), $matches) !== 1) {
            return null;
        }

        return strtolower((string) ($matches[1] ?? ''));
    }
}

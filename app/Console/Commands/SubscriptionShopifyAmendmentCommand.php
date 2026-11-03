<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Models\User;
use App\Services\Subscriptions\SubscriptionShopifyReviewService;
use App\Services\Subscriptions\SubscriptionShopifyUpdateService;
use Illuminate\Console\Command;
use Symfony\Component\HttpKernel\Exception\HttpException;

class SubscriptionShopifyAmendmentCommand extends Command
{
    protected $signature = 'subscriptions:shopify-amend {--tenant=} {--actor=} {--agreement=} {--action=preview} {--apply} {--file=} {--request-id=} {--revision=} {--reviewed-hash=}';

    protected $description = 'Preview, inspect, explicitly close or approve and reconcile one original native Shopify delivery amendment without charging.';

    public function handle(SubscriptionShopifyUpdateService $service): int
    {
        try {
            $tenant = Tenant::query()->where('slug', (string) $this->option('tenant'))->firstOrFail();
            $actor = User::findOrFail((int) $this->option('actor'));
            $id = (int) $this->option('agreement');
            $action = (string) $this->option('action');
            abort_unless(in_array($action, ['preview', 'calculate', 'commit', 'reconcile', 'abort', 'inspect', 'close-review'], true), 422, 'Choose a supported native amendment action.');
            if ($action === 'inspect' || $action === 'close-review' && ! $this->option('apply')) {
                $result = app(SubscriptionShopifyReviewService::class)->inspect($tenant->id, $actor, $id, (string) $this->option('request-id'));
            } elseif (! $this->option('apply') || $action === 'preview') {
                $result = $service->preview($tenant->id, $actor, $id);
            } else {
                $uuid = (string) $this->option('request-id');
                $revision = (int) $this->option('revision');
                $result = match ($action) {
                    'calculate' => $service->calculate($tenant->id, $actor, $id, $this->input('change'), $uuid, $revision),
                    'commit' => $service->commit($tenant->id, $actor, $id, $uuid, $revision, (string) $this->option('reviewed-hash'), $this->input('approval')),
                    'reconcile' => $service->reconcile($tenant->id, $actor, $id, $uuid),
                    'abort' => $service->abort($tenant->id, $actor, $id, $uuid),
                    'close-review' => app(SubscriptionShopifyReviewService::class)->close($tenant->id, $actor, $id, $uuid, $revision,
                        (string) $this->option('reviewed-hash'), $this->input('closure')),
                };
            }
            $this->line(json_encode($result, JSON_THROW_ON_ERROR));

            return in_array($result['status'] ?? null, ['calculation_unknown', 'calculation_failed', 'review_stale', 'commit_unknown', 'commit_closed', 'review_invalid', 'recovery_required'], true)
                ? self::FAILURE : self::SUCCESS;
        } catch (\Throwable $error) {
            $this->error($error instanceof HttpException ? $error->getMessage() : 'The original native amendment could not be verified.');

            return self::FAILURE;
        }
    }

    private function input(string $key): array
    {
        $path = (string) $this->option('file');
        abort_unless(str_starts_with($path, '/') && ! is_link($path) && is_file($path) && (fileperms($path) & 0777) === 0600
            && filesize($path) <= 65536, 422, 'Use a private regular 0600 JSON file at most 64 KiB.');
        $input = json_decode(file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
        abort_unless(is_array($input) && array_keys($input) === [$key] && is_array($input[$key]), 422, 'Provide only the requested change, approval or closure object.');

        return $input[$key];
    }
}

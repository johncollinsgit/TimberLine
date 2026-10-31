<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Models\User;
use App\Services\Subscriptions\SubscriptionMembershipReviewService;
use Illuminate\Console\Command;

class SubscriptionMembershipCommand extends Command
{
    protected $signature = 'subscriptions:membership {--tenant=} {--actor=} {--agreement=} {--file=} {--reviewed-hash=} {--request-id=} {--revision=} {--apply : Append the reviewed membership supplement only}';

    protected $description = 'Preview existing Candle Club membership review without changing payment authorization or billing.';

    public function handle(SubscriptionMembershipReviewService $service): int
    {
        try {
            $tenant = Tenant::query()->where('slug', (string) $this->option('tenant'))->firstOrFail();
            $actor = User::findOrFail((int) $this->option('actor'));
            $path = (string) $this->option('file');
            abort_unless(str_starts_with($path, '/') && ! is_link($path) && is_file($path)
                && (fileperms($path) & 0777) === 0600 && filesize($path) <= 65536, 422, 'Use a private regular 0600 JSON file at most 64 KiB.');
            $input = json_decode(file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
            abort_unless(is_array($input), 422);
            $result = $service->preview($tenant->id, $actor, (int) $this->option('agreement'), $input);
            if ($this->option('apply')) {
                $action = $service->record($tenant->id, $actor, (int) $this->option('agreement'), $input,
                    (string) $this->option('reviewed-hash'), (string) $this->option('request-id'), (int) $this->option('revision'));
                $result['action_id'] = $action->id;
                $result['review_hash'] = $action->after_state['review_hash'];
                $result['reviewed_revision'] = $action->expected_revision;
            }
            $result['status'] = $this->option('apply') ? 'membership_recorded' : 'preview_only';
            $this->line(json_encode($result, JSON_PRETTY_PRINT));

            return self::SUCCESS;
        } catch (\Throwable $error) {
            $this->error($error instanceof \Symfony\Component\HttpKernel\Exception\HttpException ? $error->getMessage()
                : 'The original membership review needs attention. No payment or provider operation was attempted.');

            return self::FAILURE;
        }
    }
}

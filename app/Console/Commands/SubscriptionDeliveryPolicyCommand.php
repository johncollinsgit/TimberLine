<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Models\User;
use App\Services\Subscriptions\SubscriptionDeliveryPolicyService;
use Illuminate\Console\Command;

class SubscriptionDeliveryPolicyCommand extends Command
{
    protected $signature = 'subscriptions:delivery-policy {--tenant=} {--actor=} {--agreement=} {--file=} {--reviewed-hash=} {--revision=} {--request-id=} {--record : Record the explicitly reviewed merchant delivery policy; does not activate customer changes or billing}';

    protected $description = 'Preview or record merchant-reviewed fixed shipping coverage and available membership scents.';

    public function handle(SubscriptionDeliveryPolicyService $service): int
    {
        try {
            $tenant = Tenant::query()->where('slug', (string) $this->option('tenant'))->firstOrFail();
            $actor = User::findOrFail((int) $this->option('actor'));
            $path = (string) $this->option('file');
            abort_unless(str_starts_with($path, '/') && ! is_link($path) && is_file($path) && (fileperms($path) & 0777) === 0600
                && filesize($path) <= 65536, 422, 'Use a private regular 0600 JSON file at most 64 KiB.');
            $input = json_decode(file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
            abort_unless(is_array($input), 422);
            if ($this->option('record')) {
                $action = $service->record($tenant->id, $actor, (int) $this->option('agreement'), $input,
                    (string) $this->option('reviewed-hash'), (string) $this->option('request-id'), (int) $this->option('revision'));
                $result = ['status' => 'merchant_policy_recorded', 'action_id' => $action->id, 'enabled' => $action->request['policy']['enabled']];
            } else {
                $result = ['status' => 'offline_merchant_policy_review'] + $service->preview($tenant->id, $actor, (int) $this->option('agreement'), $input);
            }
            $this->line(json_encode($result + ['charges_customer' => false, 'changes_billing' => false], JSON_PRETTY_PRINT));

            return self::SUCCESS;
        } catch (\Throwable $error) {
            $this->error($error instanceof \Symfony\Component\HttpKernel\Exception\HttpException ? $error->getMessage() : 'The merchant delivery policy needs review. No billing change was attempted.');

            return self::FAILURE;
        }
    }
}

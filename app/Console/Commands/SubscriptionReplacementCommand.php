<?php

namespace App\Console\Commands;

use App\Models\SubscriptionBillingAgreement;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Subscriptions\SubscriptionAccessService;
use App\Services\Subscriptions\SubscriptionBillingAgreementService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SubscriptionReplacementCommand extends Command
{
    protected $signature = 'subscriptions:replacement {action=summary} {--tenant=} {--actor=} {--member=} {--agreement=} {--file=} {--reviewed-hash=} {--request-id=} {--revision=} {--apply : Save the reviewed local preparation or schedule change}';

    protected $description = 'Preview controlled Recharge replacement agreements and original-anchor schedule changes without provider writes.';

    public function handle(SubscriptionBillingAgreementService $service): int
    {
        try {
            $tenant = Tenant::query()->where('slug', (string) $this->option('tenant'))->first();
            if (! $tenant && ctype_digit((string) $this->option('tenant'))) {
                $tenant = Tenant::find((int) $this->option('tenant'));
            }
            abort_unless($tenant, 422, 'Choose an existing tenant.');
            $actor = User::findOrFail((int) $this->option('actor'));
            app(SubscriptionAccessService::class)->assertManager($tenant->id, $actor);
            $action = $this->argument('action');
            abort_unless(in_array($action, ['summary', 'prepare', 'pause', 'resume', 'skip', 'cancel'], true), 422, 'Choose summary, prepare, pause, resume, skip or cancel.');
            if ($action === 'summary') {
                $rows = Schema::hasTable('subscription_billing_agreements')
                    ? SubscriptionBillingAgreement::query()->forTenantId($tenant->id)->orderBy('id')->get() : collect();
                $this->line(json_encode(['agreements' => $rows->map(fn ($row) => ['id' => $row->id, 'member_id' => $row->member_id] + $service->state($row)),
                    'provider_requests' => false, 'collection_implemented' => false], JSON_PRETTY_PRINT));

                return self::SUCCESS;
            }
            if ($action === 'prepare') {
                $path = (string) $this->option('file');
                abort_unless(str_starts_with($path, '/') && ! is_link($path) && is_file($path)
                    && (fileperms($path) & 0777) === 0600 && filesize($path) <= 65536, 422, 'Use a private regular 0600 JSON file at most 64 KiB.');
                $input = json_decode(file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
                abort_unless(is_array($input), 422);
                $review = $service->review($tenant->id, $actor, (int) $this->option('member'), $input);
                if ($this->option('apply')) {
                    $row = $service->prepare($tenant->id, $actor, (int) $this->option('member'), $input, (string) $this->option('reviewed-hash'));
                    $review += ['id' => $row->id, 'status' => 'saved_local_only'];
                } else {
                    $review += ['status' => 'preview_only'];
                }
                $this->line(json_encode($review, JSON_PRETTY_PRINT));

                return self::SUCCESS;
            }
            DB::beginTransaction();
            try {
                $result = $service->change($tenant->id, $actor, (int) $this->option('agreement'), $action,
                    (string) $this->option('request-id'), (int) $this->option('revision'));
                $state = $result->after_state;
                if ($this->option('apply')) {
                    DB::commit();
                } else {
                    DB::rollBack();
                }
            } catch (\Throwable $error) {
                DB::rollBack();
                throw $error;
            }
            $this->line(json_encode(['status' => $this->option('apply') ? 'saved_local_only' : 'preview_only', 'state' => $state, 'provider_requests' => false], JSON_PRETTY_PRINT));

            return self::SUCCESS;
        } catch (\Throwable $error) {
            $this->error($error instanceof \Symfony\Component\HttpKernel\Exception\HttpException ? $error->getMessage() : 'Replacement input or setup needs review. No provider operation was attempted.');

            return self::FAILURE;
        }
    }
}

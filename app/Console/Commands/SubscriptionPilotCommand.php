<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Models\User;
use App\Services\Subscriptions\SubscriptionPilotService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SubscriptionPilotCommand extends Command
{
    protected $signature = 'subscriptions:pilot {action=summary} {--tenant=} {--actor=} {--member=} {--file=} {--apply : Save local preparation or attributed observations}';

    protected $description = 'Review or prepare a tenant subscription pilot without provider writes or customer messages.';

    public function handle(SubscriptionPilotService $pilots): int
    {
        try {
            $tenant = Tenant::query()->where('slug', (string) $this->option('tenant'))->first();
            if (! $tenant && ctype_digit((string) $this->option('tenant'))) {
                $tenant = Tenant::query()->find((int) $this->option('tenant'));
            }
            abort_unless($tenant, 422, 'Select an existing tenant.');
            $actor = User::query()->findOrFail((int) $this->option('actor'));
            $action = $this->argument('action');
            abort_unless(in_array($action, ['summary', 'prepare', 'observe'], true), 422, 'Use summary, prepare or observe.');
            if ($action === 'summary') {
                $report = $pilots->dashboard($tenant->id, $actor);
                $this->line(json_encode(['available' => $report['available'], 'members' => array_map(fn ($m) => array_intersect_key($m,
                    array_flip(['id', 'name', 'status', 'billing_owner', 'target_date', 'payment_status', 'shopify_status', 'duplicate_status'])), $report['members']),
                    'payment_ready' => $report['payment_ready'] ?? false, 'payment_blockers' => $report['payment_blockers'] ?? []], JSON_PRETTY_PRINT));

                return self::SUCCESS;
            }
            $path = (string) $this->option('file');
            abort_unless($path !== '' && str_starts_with($path, '/') && ! is_link($path) && is_file($path)
                && (fileperms($path) & 0777) === 0600 && filesize($path) <= 65536, 422, 'Use a private regular 0600 JSON file, at most 64 KiB.');
            $document = json_decode(file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
            abort_unless(is_array($document), 422);
            DB::beginTransaction();
            try {
                $row = $action === 'prepare' ? $pilots->prepare($tenant->id, $actor, $document)
                    : $pilots->observe($tenant->id, $actor, (int) $this->option('member'), $document);
                $id = $row->id;
                if ($this->option('apply')) {
                    DB::commit();
                } else {
                    DB::rollBack();
                }
            } catch (\Throwable $e) {
                DB::rollBack();
                throw $e;
            }
            $this->line(json_encode(['status' => $this->option('apply') ? 'saved_local_only' : 'preview_only', 'id' => $this->option('apply') ? $id : null,
                'billing_owner' => 'recharge', 'provider_writes' => false, 'customer_messages' => false]));

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error($e instanceof \Illuminate\Validation\ValidationException ? 'Pilot input requires review: '.implode(' ', $e->validator->errors()->all())
                : ($e instanceof \Symfony\Component\HttpKernel\Exception\HttpException ? $e->getMessage() : 'Pilot preparation could not be completed. No provider operation was attempted.'));

            return self::FAILURE;
        }
    }
}

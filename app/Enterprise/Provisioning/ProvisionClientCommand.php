<?php

namespace App\Enterprise\Provisioning;

use Illuminate\Console\Command;
use Throwable;

final class ProvisionClientCommand extends Command
{
    protected $signature = 'enterprise:client {action=plan : plan, apply or status} {--manifest= : Local data-only JSON manifest} {--plan-hash= : Exact reviewed dry-run hash}';

    protected $description = 'Plan or install isolated Enterprise client configuration without creating access.';

    public function handle(ClientProvisioner $provisioner, ClientSilo $silo): int
    {
        try {
            $action = $this->argument('action');
            if (! in_array($action, ['plan', 'apply', 'status'], true)) {
                throw new \RuntimeException('Choose plan, apply or status.');
            }
            $result = match ($action) {
                'status' => $silo->status(),
                'plan' => $provisioner->plan(ClientManifest::read((string) $this->option('manifest'))),
                'apply' => $provisioner->apply(ClientManifest::read((string) $this->option('manifest')), (string) $this->option('plan-hash')),
            };
            $this->line(json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        } catch (Throwable $error) {
            // Validation errors contain schema messages, never a dumped manifest or environment.
            $this->error($error->getMessage());

            return self::FAILURE;
        }
    }
}
